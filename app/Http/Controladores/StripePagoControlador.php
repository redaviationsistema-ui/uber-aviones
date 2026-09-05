<?php

namespace App\Http\Controladores;

use App\Enumeraciones\EstadoSolicitudVuelo;
use App\Modelos\Cotizacion;
use App\Modelos\Pago;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use App\Servicios\Aeronaves\AircraftAvailabilityService;
use App\Servicios\Aeronaves\AircraftEligibilityService;
use App\Servicios\Pagos\PaymentFeeCalculationServicio;
use App\Servicios\Pagos\ReservationPaymentAuthorizationService;
use App\Servicios\Reservas\CommercialSnapshotService;
use App\Servicios\Vuelos\FlightRouteService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Throwable;

class StripePagoControlador extends ControladorBase
{
    public function __construct(
        private readonly AircraftAvailabilityService $aircraftAvailabilityService,
        private readonly AircraftEligibilityService $aircraftEligibilityService,
        private readonly PaymentFeeCalculationServicio $paymentFeeCalculationServicio,
        private readonly FlightRouteService $flightRouteService,
        private readonly ReservationPaymentAuthorizationService $paymentAuthorizationService,
        private readonly CommercialSnapshotService $commercialSnapshotService,
    ) {}

    public function confirmFlightRequestPayment(Request $request)
    {
        return response()->json([
            'success' => false,
            'code' => 'CLIENT_PAYMENT_CONFIRMATION_FORBIDDEN',
            'message' => 'El pago solo puede confirmarse mediante un webhook firmado de Stripe.',
        ], 422);

        if ($response = $this->ensureStripeIsConfigured()) {
            return $response;
        }

        $data = $request->validate([
            'flight_request_id' => ['required', 'integer', 'exists:flight_requests,id'],
            'payment_intent_id' => ['nullable', 'string', 'max:255'],
            'checkout_session_id' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['nullable', 'string', 'max:120'],
            'brand' => ['nullable', 'string', 'max:120'],
        ]);

        $flightRequest = SolicitudVuelo::query()
            ->with([
                'reservation.payments' => fn ($query) => $query->latest('id'),
                'quotes',
            ])
            ->findOrFail($data['flight_request_id']);

        abort_if($flightRequest->client_id !== $request->user()->id, 403, 'No puedes confirmar el pago de esta solicitud.');

        $reservation = $this->ensureReservationForFlightRequest($flightRequest, $request->user()->id);
        $paymentAvailability = $this->aircraftAvailabilityService->evaluateReservationPaymentAvailability(
            $reservation->fresh(['flightRequest.legs', 'legs', 'quote', 'latestPayment', 'contract'])
        );

        if (! ($paymentAvailability['can_pay'] ?? false)) {
            return response()->json([
                'success' => false,
                'can_pay' => false,
                'hold_valid' => (bool) ($paymentAvailability['hold_valid'] ?? false),
                'reservation_booked' => (bool) ($paymentAvailability['reservation_booked'] ?? false),
                'reason' => $paymentAvailability['invalid_reason'] ?? 'hold_not_found',
                'invalid_reason' => $paymentAvailability['invalid_reason'] ?? 'hold_not_found',
                'message' => $this->reservationPaymentAvailabilityMessage(
                    (string) ($paymentAvailability['invalid_reason'] ?? '')
                ),
                'availability' => $paymentAvailability['availability'] ?? null,
                'hold' => $paymentAvailability['hold'] ?? null,
            ], 409);
        }

        $checkoutSessionId = trim((string) ($data['checkout_session_id'] ?? ''));
        $paymentMethod = trim((string) ($data['payment_method'] ?? ''));

        if ($checkoutSessionId !== '' || $paymentMethod === 'stripe_checkout') {
            $payment = $this->findStoredReservationStripePayment(
                reservationId: (int) $reservation->id,
                flightRequestId: (int) $flightRequest->id,
                sessionId: $checkoutSessionId,
            );

            if ($payment) {
                $checkoutSessionId = $checkoutSessionId !== '' ? $checkoutSessionId : trim((string) $payment->stripe_checkout_session_id);

                if ($checkoutSessionId !== '') {
                    $this->syncCheckoutSessionPayment($payment, $checkoutSessionId);
                    $payment->refresh();
                }

                if ($payment->status !== 'paid') {
                    $this->finalizePendingStoredStripePayment($payment);
                    $payment->refresh();
                }
            }

            $reservation->refresh()->load(['contract', 'payments', 'flightRequest']);
            $flightRequest->refresh();

            if ($flightRequest->payment_status === 'paid' || in_array($reservation->status, ['paid', 'confirmed'], true)) {
                return $this->confirmedReservationPaymentResponse($flightRequest, $reservation);
            }
        }

        $response = $this->finalizeSuccessfulPayment(
            flightRequest: $flightRequest,
            reservation: $reservation,
            paymentIntentId: $data['payment_intent_id'] ?? null,
            brandOverride: $data['brand'] ?? null,
            paymentMethod: $paymentMethod !== '' ? $paymentMethod : 'card',
        );

        $this->auditStripeAction($request->user()->id, 'stripe_payment_confirmation_requested', 'Cliente solicito confirmar el pago Stripe de una solicitud.', [
            'flight_request_id' => $flightRequest->id,
            'reservation_id' => $reservation->id,
            'payment_intent_id' => $data['payment_intent_id'] ?? null,
            'checkout_session_id' => $data['checkout_session_id'] ?? null,
        ], $request);

        return $response;
    }

    public function confirmReservationPayment(Request $request, mixed $reservation)
    {
        $ownedReservation = $reservation instanceof Reserva ? $reservation : Reserva::query()->findOrFail($reservation);
        abort_if($ownedReservation->client_id !== $request->user()->id, 403, 'No puedes consultar esta reserva.');

        return response()->json([
            'success' => false,
            'code' => 'CLIENT_PAYMENT_CONFIRMATION_FORBIDDEN',
            'message' => 'El pago solo puede confirmarse mediante un webhook firmado de Stripe.',
        ], 422);

        if ($response = $this->ensureStripeIsConfigured()) {
            return $response;
        }

        $reservation = $reservation instanceof Reserva
            ? $reservation->load(['flightRequest', 'payments' => fn ($query) => $query->latest('id')])
            : Reserva::with(['flightRequest', 'payments' => fn ($query) => $query->latest('id')])
                ->findOrFail($reservation);

        abort_if($reservation->client_id !== $request->user()->id, 403, 'No puedes confirmar el pago de esta reserva.');

        $data = $request->validate([
            'payment_intent_id' => ['nullable', 'string', 'max:255'],
            'flight_request_id' => ['nullable', 'integer', 'exists:flight_requests,id'],
            'checkout_session_id' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['nullable', 'string', 'max:120'],
            'brand' => ['nullable', 'string', 'max:120'],
        ]);

        $flightRequest = $reservation->flightRequest;
        abort_if(! $flightRequest, 404, 'La reserva no tiene una solicitud de vuelo asociada.');

        $checkoutSessionId = trim((string) ($data['checkout_session_id'] ?? ''));
        $paymentMethod = trim((string) ($data['payment_method'] ?? ''));

        if ($checkoutSessionId !== '' || $paymentMethod === 'stripe_checkout') {
            $payment = $this->findStoredReservationStripePayment(
                reservationId: (int) $reservation->id,
                flightRequestId: (int) $flightRequest->id,
                sessionId: $checkoutSessionId,
            );

            if ($payment) {
                $checkoutSessionId = $checkoutSessionId !== '' ? $checkoutSessionId : trim((string) $payment->stripe_checkout_session_id);

                if ($checkoutSessionId !== '') {
                    $this->syncCheckoutSessionPayment($payment, $checkoutSessionId);
                    $payment->refresh();
                }

                if ($payment->status !== 'paid') {
                    $this->finalizePendingStoredStripePayment($payment);
                    $payment->refresh();
                }
            }

            $reservation->refresh()->load(['contract', 'payments', 'flightRequest']);
            $flightRequest->refresh();

            if ($flightRequest->payment_status === 'paid' || in_array($reservation->status, ['paid', 'confirmed'], true)) {
                return $this->confirmedReservationPaymentResponse($flightRequest, $reservation);
            }
        }

        $response = $this->finalizeSuccessfulPayment(
            flightRequest: $flightRequest,
            reservation: $reservation,
            paymentIntentId: $data['payment_intent_id'] ?? null,
            brandOverride: $data['brand'] ?? null,
            paymentMethod: $paymentMethod !== '' ? $paymentMethod : 'card',
        );

        $this->auditStripeAction($request->user()->id, 'stripe_payment_confirmation_requested', 'Cliente solicito confirmar el pago Stripe de una reserva.', [
            'flight_request_id' => $flightRequest->id,
            'reservation_id' => $reservation->id,
            'payment_intent_id' => $data['payment_intent_id'] ?? null,
            'checkout_session_id' => $data['checkout_session_id'] ?? null,
        ], $request);

        return $response;
    }

    public function createCheckout(Request $request)
    {
        if ($response = $this->ensureStripeIsConfigured()) {
            return $response;
        }

        $data = $request->validate([
            'flight_request_id' => ['required', 'exists:flight_requests,id'],
            'contact_email' => ['nullable', 'email'],
            'success_url' => ['nullable', 'string', 'max:2048'],
            'cancel_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $flightRequest = SolicitudVuelo::with(['quotes', 'reservation'])->findOrFail($data['flight_request_id']);
        abort_if($flightRequest->client_id !== $request->user()->id, 403, 'No puedes pagar esta solicitud.');

        $pricingBreakdown = $this->resolveFlightRequestPricingBreakdown($flightRequest);
        $amount = (float) ($pricingBreakdown['total_amount'] ?? $this->resolveFlightRequestAmount($flightRequest));
        abort_if($amount <= 0, 422, 'La solicitud no tiene un monto valido para cobrar.');

        if (in_array($flightRequest->payment_status, ['paid', 'bank_confirmed'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Esta solicitud ya aparece como pagada.',
            ], 409);
        }

        $reservation = $this->ensureReservationForFlightRequest($flightRequest, $request->user()->id);
        try {
            $reservation = $this->commercialSnapshotService->persistIfMissing($reservation);
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'code' => 'COMMERCIAL_SNAPSHOT_MISMATCH',
                'message' => 'El snapshot comercial de la reserva no superó la validación de integridad.',
            ], 409);
        }
        $snapshot = $reservation->commercial_snapshot ?? [];
        $amount = (float) ($snapshot['total_amount'] ?? 0);
        abort_if($amount <= 0, 422, 'La reserva no tiene un snapshot comercial valido para cobrar.');

        $authorization = $this->paymentAuthorizationService->evaluate($reservation, true);
        if (! $authorization['authorized']) {
            $code = in_array('AIRCRAFT_NOT_AVAILABLE', $authorization['blocking_reasons'], true)
                ? 'AIRCRAFT_NOT_AVAILABLE'
                : 'PAYMENT_NOT_AUTHORIZED';

            return response()->json([
                'success' => false,
                'code' => $code,
                'message' => $code === 'AIRCRAFT_NOT_AVAILABLE'
                    ? 'La aeronave seleccionada ya no está disponible.'
                    : 'La reserva todavía no cumple los requisitos para pagar.',
                ...$authorization,
            ], 409);
        }
        try {
            $this->ensureReservationAircraftHold($flightRequest, $reservation, (int) $request->user()->id);
        } catch (RuntimeException $exception) {
            abort(409, $exception->getMessage());
        }

        $reusablePayment = $this->findStoredReservationStripePayment(
            reservationId: (int) $reservation->id,
            flightRequestId: (int) $flightRequest->id,
        );

        if ($reusablePayment && in_array((string) $reusablePayment->status, ['pending', 'processing'], true)) {
            $existingCheckoutUrl = trim((string) (
                data_get($reusablePayment->gateway_response, 'checkout_url')
                ?? data_get($reusablePayment->gateway_response, 'url')
                ?? data_get($reusablePayment->gateway_response, 'checkout_session.url')
                ?? ''
            ));
            $existingSessionId = trim((string) ($reusablePayment->stripe_checkout_session_id ?: $reusablePayment->transaction_reference));

            if ($existingCheckoutUrl !== '' && $existingSessionId !== '') {
                $canReuseCheckout = true;

                try {
                    $session = $this->retrieveCheckoutSession($existingSessionId);
                    $paymentIntent = $this->resolvePaymentIntentFromCheckoutSession($session);
                    $paymentStatus = strtolower((string) ($session->payment_status ?? ''));
                    $sessionStatus = strtolower((string) ($session->status ?? ''));
                    $paymentIntentStatus = strtolower((string) ($paymentIntent->status ?? ''));
                    $checkoutIsPaid = in_array($paymentStatus, ['paid', 'no_payment_required'], true)
                        || $sessionStatus === 'complete'
                        || $paymentIntentStatus === 'succeeded';

                    if ($checkoutIsPaid) {
                        $this->reconcileStripeCheckoutSuccessBySession(
                            sessionId: $existingSessionId,
                            userId: (int) $request->user()->id,
                            reservation: $reservation,
                            flightRequest: $flightRequest,
                            payment: $reusablePayment,
                        );

                        $reservation->refresh();
                        $flightRequest->refresh();

                        if ($flightRequest->payment_status === 'paid' || in_array((string) $reservation->status, ['paid', 'confirmed'], true)) {
                            return $this->confirmedReservationPaymentResponse($flightRequest, $reservation);
                        }

                        $canReuseCheckout = false;
                    } elseif (! ($sessionStatus === 'open' && in_array($paymentStatus, ['unpaid', 'no_payment_required'], true))) {
                        $canReuseCheckout = false;
                    }
                } catch (Throwable $exception) {
                    Log::warning('No fue posible reutilizar la sesion pendiente de Stripe Checkout; se generara una nueva.', [
                        'flight_request_id' => $flightRequest->id,
                        'reservation_id' => $reservation->id,
                        'payment_id' => $reusablePayment->id,
                        'checkout_session_id' => $existingSessionId,
                        'message' => $exception->getMessage(),
                    ]);
                    $canReuseCheckout = false;
                }

                if ($canReuseCheckout) {
                    $this->auditStripeAction($request->user()->id, 'stripe_checkout_reused', 'Se reutilizo una sesion pendiente de Stripe Checkout para evitar duplicados.', [
                        'flight_request_id' => $flightRequest->id,
                        'reservation_id' => $reservation->id,
                        'payment_id' => $reusablePayment->id,
                        'checkout_session_id' => $existingSessionId,
                    ], $request);

                    return $this->ok([
                        'checkout_url' => $existingCheckoutUrl,
                        'checkout_session_id' => $existingSessionId,
                        'reservation_id' => $reservation->id,
                        'payment_status' => (string) ($flightRequest->payment_status ?: $reusablePayment->status ?: 'pending'),
                        'reused_checkout' => true,
                    ]);
                }
            }
        }

        Stripe::setApiKey((string) config('services.stripe.secret'));

        $successUrl = $data['success_url'] ?? rtrim((string) config('services.stripe.frontend_url'), '/')."/cliente/reserva-confirmada/{$flightRequest->id}?checkout=success&session_id={CHECKOUT_SESSION_ID}";
        $cancelUrl = $data['cancel_url'] ?? rtrim((string) config('services.stripe.frontend_url'), '/')."/cliente/pago/{$flightRequest->id}?checkout=cancelled&session_id={CHECKOUT_SESSION_ID}";
        $validatedSuccessUrl = str_replace('{CHECKOUT_SESSION_ID}', 'checkout_session_id', (string) $successUrl);
        $validatedCancelUrl = str_replace('{CHECKOUT_SESSION_ID}', 'checkout_session_id', (string) $cancelUrl);
        abort_unless(filter_var($validatedSuccessUrl, FILTER_VALIDATE_URL), 422, 'The success url field must be a valid URL.');
        abort_unless(filter_var($validatedCancelUrl, FILTER_VALIDATE_URL), 422, 'The cancel url field must be a valid URL.');

        $idempotencyKey = trim((string) $request->header(
            'Idempotency-Key',
            'checkout:reservation:'.$reservation->id,
        ));
        $session = Session::create([
            'mode' => 'payment',
            'customer_email' => $data['contact_email'] ?? $request->user()->email,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower((string) ($flightRequest->currency ?: 'USD')),
                    'product_data' => [
                        'name' => 'Reserva de vuelo privado #'.$flightRequest->id,
                    ],
                    'unit_amount' => (int) round($amount * 100),
                ],
                'quantity' => 1,
            ]],
            'metadata' => [
                'flight_request_id' => (string) $flightRequest->id,
                'client_id' => (string) $request->user()->id,
            ],
            'payment_intent_data' => [
                'metadata' => [
                    'flight_request_id' => (string) $flightRequest->id,
                    'client_id' => (string) $request->user()->id,
                ],
            ],
        ], ['idempotency_key' => $idempotencyKey]);

        DB::transaction(function () use ($request, $flightRequest, $reservation, $session, $amount, $pricingBreakdown) {
            $flightRequest->update([
                'payment_method' => 'stripe_checkout',
                'payment_status' => 'pending',
                'stripe_checkout_session_id' => $session->id,
                'workflow_status' => 'pago pendiente',
                'status' => 'reserved',
                'final_price' => $amount,
                'pricing_context' => $this->mergeFlightRequestPricingContext($flightRequest, $pricingBreakdown),
            ]);

            $reservation->update([
                'status' => 'pending_payment',
                'total_amount' => $amount,
                'currency' => $reservation->currency ?: ($flightRequest->currency ?: 'USD'),
            ]);

            Pago::updateOrCreate(
                [
                    'reservation_id' => $reservation->id,
                    'flight_request_id' => $flightRequest->id,
                    'payment_type' => 'reservation',
                    'provider' => 'stripe',
                    'status' => 'pending',
                ],
                [
                    'user_id' => $request->user()->id,
                    'amount' => $amount,
                    'currency' => $flightRequest->currency ?: 'USD',
                    'transaction_reference' => $session->id,
                    'stripe_checkout_session_id' => $session->id,
                    'gateway_response' => [
                        'checkout_url' => $session->url,
                        'pricing' => $pricingBreakdown,
                    ],
                ],
            );
        });

        $this->auditStripeAction($request->user()->id, 'stripe_checkout_created', 'Se creo una sesion de Stripe Checkout para reserva de cliente.', [
            'flight_request_id' => $flightRequest->id,
            'reservation_id' => $reservation->id,
            'checkout_session_id' => $session->id,
            'amount' => $amount,
        ], $request);

        return $this->ok([
            'checkout_url' => $session->url,
            'checkout_session_id' => $session->id,
            'reservation_id' => $reservation->id,
            'payment_status' => 'pending',
        ]);
    }

    public function createPaymentIntent(Request $request)
    {
        if ($response = $this->ensureStripeIsConfigured()) {
            return $response;
        }

        $data = $request->validate([
            'flight_request_id' => ['required', 'exists:flight_requests,id'],
            'contact_email' => ['nullable', 'email'],
        ]);

        $flightRequest = SolicitudVuelo::with(['quotes', 'reservation'])->findOrFail($data['flight_request_id']);
        abort_if($flightRequest->client_id !== $request->user()->id, 403, 'No puedes pagar esta solicitud.');

        $pricingBreakdown = $this->resolveFlightRequestPricingBreakdown($flightRequest);
        $amount = (float) ($pricingBreakdown['total_amount'] ?? $this->resolveFlightRequestAmount($flightRequest));
        abort_if($amount <= 0, 422, 'La solicitud no tiene un monto valido para cobrar.');

        if (in_array($flightRequest->payment_status, ['paid', 'bank_confirmed'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Esta solicitud ya aparece como pagada.',
            ], 409);
        }

        $reservation = $this->ensureReservationForFlightRequest($flightRequest, $request->user()->id);
        try {
            $this->ensureReservationAircraftHold($flightRequest, $reservation, (int) $request->user()->id);
        } catch (RuntimeException $exception) {
            abort(409, $exception->getMessage());
        }

        $reusablePayment = $this->findStoredReservationStripePayment(
            reservationId: (int) $reservation->id,
            flightRequestId: (int) $flightRequest->id,
        );

        if ($reusablePayment && in_array((string) $reusablePayment->status, ['pending', 'processing'], true)) {
            $existingCheckoutUrl = trim((string) (
                data_get($reusablePayment->gateway_response, 'checkout_url')
                ?? data_get($reusablePayment->gateway_response, 'url')
                ?? data_get($reusablePayment->gateway_response, 'checkout_session.url')
                ?? ''
            ));
            $existingSessionId = trim((string) ($reusablePayment->stripe_checkout_session_id ?: $reusablePayment->transaction_reference));

            if ($existingCheckoutUrl !== '' && $existingSessionId !== '') {
                $canReuseCheckout = true;

                try {
                    $session = $this->retrieveCheckoutSession($existingSessionId);
                    $paymentIntent = $this->resolvePaymentIntentFromCheckoutSession($session);
                    $paymentStatus = strtolower((string) ($session->payment_status ?? ''));
                    $sessionStatus = strtolower((string) ($session->status ?? ''));
                    $paymentIntentStatus = strtolower((string) ($paymentIntent->status ?? ''));
                    $checkoutIsPaid = in_array($paymentStatus, ['paid', 'no_payment_required'], true)
                        || $sessionStatus === 'complete'
                        || $paymentIntentStatus === 'succeeded';

                    if ($checkoutIsPaid) {
                        $this->reconcileStripeCheckoutSuccessBySession(
                            sessionId: $existingSessionId,
                            userId: (int) $request->user()->id,
                            reservation: $reservation,
                            flightRequest: $flightRequest,
                            payment: $reusablePayment,
                        );

                        $reservation->refresh();
                        $flightRequest->refresh();

                        if ($flightRequest->payment_status === 'paid' || in_array((string) $reservation->status, ['paid', 'confirmed'], true)) {
                            return $this->confirmedReservationPaymentResponse($flightRequest, $reservation);
                        }

                        $canReuseCheckout = false;
                    } elseif (! ($sessionStatus === 'open' && in_array($paymentStatus, ['unpaid', 'no_payment_required'], true))) {
                        $canReuseCheckout = false;
                    }
                } catch (Throwable $exception) {
                    Log::warning('No fue posible reutilizar la sesion pendiente de Stripe Checkout para PaymentIntent; se generara un intento nuevo.', [
                        'flight_request_id' => $flightRequest->id,
                        'reservation_id' => $reservation->id,
                        'payment_id' => $reusablePayment->id,
                        'checkout_session_id' => $existingSessionId,
                        'message' => $exception->getMessage(),
                    ]);
                    $canReuseCheckout = false;
                }

                if ($canReuseCheckout) {
                    $this->auditStripeAction($request->user()->id, 'stripe_checkout_reused', 'Se reutilizo una sesion pendiente de Stripe Checkout para evitar duplicados.', [
                        'flight_request_id' => $flightRequest->id,
                        'reservation_id' => $reservation->id,
                        'payment_id' => $reusablePayment->id,
                        'checkout_session_id' => $existingSessionId,
                    ], $request);

                    return $this->ok([
                        'checkout_url' => $existingCheckoutUrl,
                        'checkout_session_id' => $existingSessionId,
                        'reservation_id' => $reservation->id,
                        'payment_status' => (string) ($flightRequest->payment_status ?: $reusablePayment->status ?: 'pending'),
                        'reused_checkout' => true,
                    ]);
                }
            }
        }

        Stripe::setApiKey((string) config('services.stripe.secret'));

        $paymentIntent = PaymentIntent::create([
            'amount' => (int) round($amount * 100),
            'currency' => strtolower((string) ($flightRequest->currency ?: 'USD')),
            'automatic_payment_methods' => ['enabled' => true],
            'receipt_email' => $data['contact_email'] ?? $request->user()->email,
            'metadata' => [
                'flight_request_id' => (string) $flightRequest->id,
                'client_id' => (string) $request->user()->id,
            ],
        ]);

        DB::transaction(function () use ($request, $flightRequest, $reservation, $paymentIntent, $amount, $pricingBreakdown) {
            $flightRequest->update([
                'payment_method' => 'card',
                'payment_status' => 'pending',
                'stripe_payment_intent_id' => $paymentIntent->id,
                'workflow_status' => 'pago pendiente',
                'status' => 'reserved',
                'final_price' => $amount,
                'pricing_context' => $this->mergeFlightRequestPricingContext($flightRequest, $pricingBreakdown),
            ]);

            $reservation->update([
                'status' => 'pending_payment',
                'total_amount' => $amount,
                'currency' => $reservation->currency ?: ($flightRequest->currency ?: 'USD'),
            ]);

            Pago::updateOrCreate(
                [
                    'reservation_id' => $reservation->id,
                    'flight_request_id' => $flightRequest->id,
                    'payment_type' => 'reservation',
                    'provider' => 'stripe',
                    'status' => 'pending',
                ],
                [
                    'user_id' => $request->user()->id,
                    'amount' => $amount,
                    'currency' => $flightRequest->currency ?: 'USD',
                    'transaction_reference' => $paymentIntent->id,
                    'stripe_payment_intent_id' => $paymentIntent->id,
                    'gateway_response' => [
                        'client_secret_available' => true,
                        'pricing' => $pricingBreakdown,
                    ],
                ],
            );
        });

        $this->auditStripeAction($request->user()->id, 'stripe_payment_intent_created', 'Se creo un PaymentIntent de Stripe para reserva de cliente.', [
            'flight_request_id' => $flightRequest->id,
            'reservation_id' => $reservation->id,
            'payment_intent_id' => $paymentIntent->id,
            'amount' => $amount,
        ], $request);

        return $this->ok([
            'client_secret' => $paymentIntent->client_secret,
            'payment_intent_id' => $paymentIntent->id,
            'publishable_key' => config('services.stripe.publishable'),
            'reservation_id' => $reservation->id,
            'payment_status' => 'pending',
        ]);
    }

    public function createWireIntent(Request $request)
    {
        $data = $request->validate([
            'flight_request_id' => ['required', 'exists:flight_requests,id'],
            'contact_email' => ['nullable', 'email'],
        ]);

        $flightRequest = SolicitudVuelo::with(['quotes', 'reservation'])->findOrFail($data['flight_request_id']);
        abort_if($flightRequest->client_id !== $request->user()->id, 403, 'No puedes pagar esta solicitud.');

        $pricingBreakdown = $this->resolveFlightRequestPricingBreakdown($flightRequest);
        $amount = (float) ($pricingBreakdown['total_amount'] ?? $this->resolveFlightRequestAmount($flightRequest));
        abort_if($amount <= 0, 422, 'La solicitud no tiene un monto valido para transferencia.');

        $reservation = $this->ensureReservationForFlightRequest($flightRequest, $request->user()->id);

        $reference = 'WIRE-'.Str::upper(Str::random(10));

        DB::transaction(function () use ($request, $flightRequest, $reservation, $amount, $reference, $data, $pricingBreakdown) {
            $flightRequest->update([
                'payment_method' => 'wire',
                'payment_status' => 'pending_bank_confirmation',
                'workflow_status' => 'pago pendiente',
                'status' => 'reserved',
                'final_price' => $amount,
                'pricing_context' => $this->mergeFlightRequestPricingContext($flightRequest, $pricingBreakdown),
            ]);

            $reservation->update([
                'status' => 'pending_payment',
                'total_amount' => $amount,
                'currency' => $reservation->currency ?: ($flightRequest->currency ?: 'USD'),
            ]);

            Pago::updateOrCreate(
                [
                    'reservation_id' => $reservation->id,
                    'flight_request_id' => $flightRequest->id,
                    'payment_type' => 'reservation',
                    'provider' => 'bank_transfer',
                    'status' => 'pending',
                ],
                [
                    'user_id' => $request->user()->id,
                    'amount' => $amount,
                    'currency' => $flightRequest->currency ?: 'USD',
                    'transaction_reference' => $reference,
                    'gateway_response' => [
                        'contact_email' => $data['contact_email'] ?? $request->user()->email,
                        'reference' => $reference,
                        'pricing' => $pricingBreakdown,
                    ],
                ],
            );
        });

        $this->auditStripeAction($request->user()->id, 'wire_payment_intent_created', 'Se genero una referencia de transferencia para reserva de cliente.', [
            'flight_request_id' => $flightRequest->id,
            'reservation_id' => $reservation->id,
            'reference' => $reference,
            'amount' => $amount,
        ], $request);

        return $this->ok([
            'reservation_id' => $reservation->id,
            'payment_status' => 'pending_bank_confirmation',
            'reference' => $reference,
            'wire_instructions' => [
                'bank_name' => config('services.stripe.bank_name', 'Banco por configurar'),
                'beneficiary' => config('services.stripe.bank_beneficiary', 'Red Aviation'),
                'account_number' => config('services.stripe.bank_account', 'Por configurar'),
                'clabe' => config('services.stripe.bank_clabe', 'Por configurar'),
                'swift' => config('services.stripe.bank_swift', ''),
                'reference' => $reference,
                'amount' => number_format($amount, 2).' '.strtoupper((string) ($flightRequest->currency ?: 'USD')),
            ],
        ]);
    }

    public function success(Request $request): JsonResponse
    {
        if ($response = $this->ensureStripeIsConfigured()) {
            return $response;
        }

        try {
            $data = $request->validate([
                'session_id' => ['nullable', 'string', 'max:255'],
                'checkout_session_id' => ['nullable', 'string', 'max:255'],
                'stripe_checkout_session_id' => ['nullable', 'string', 'max:255'],
                'reservation_id' => ['nullable', 'integer'],
                'booking_id' => ['nullable', 'integer'],
                'flight_request_id' => ['nullable', 'integer'],
            ]);

            $sessionId = trim((string) ($data['session_id'] ?? $data['checkout_session_id'] ?? $data['stripe_checkout_session_id'] ?? ''));
            $reservationId = (int) ($data['reservation_id'] ?? $data['booking_id'] ?? 0);
            $flightRequestId = (int) ($data['flight_request_id'] ?? 0);
            $userId = (int) $request->user()->id;
            Log::info('Consulta checkout success Stripe.', [
                'session_id' => $sessionId,
                'reservation_id' => $reservationId,
                'flight_request_id' => $flightRequestId,
                'user_id' => $userId,
            ]);

            $payment = Pago::query()
                ->with(['reservation.flightRequest', 'flightRequest'])
                ->where('user_id', $userId)
                ->where('payment_type', 'reservation')
                ->where('provider', 'stripe')
                ->when($sessionId !== '', function ($query) use ($sessionId) {
                    $query->where(function ($nestedQuery) use ($sessionId) {
                        $nestedQuery->where('stripe_checkout_session_id', $sessionId)
                            ->orWhere('transaction_reference', $sessionId);
                    });
                })
                ->when($reservationId > 0, fn ($query) => $query->where('reservation_id', $reservationId))
                ->when($flightRequestId > 0, fn ($query) => $query->where('flight_request_id', $flightRequestId))
                ->latest('id')
                ->first();

            $reservation = null;
            $flightRequest = null;

            if ($reservationId > 0) {
                $reservation = Reserva::query()
                    ->with(['flightRequest', 'payments' => fn ($query) => $query->latest('id')])
                    ->where('client_id', $userId)
                    ->find($reservationId);
                $flightRequest = $reservation?->flightRequest;
            }

            if (! $flightRequest && $flightRequestId > 0) {
                $flightRequest = SolicitudVuelo::query()
                    ->with(['reservation.payments' => fn ($query) => $query->latest('id'), 'quotes'])
                    ->where('client_id', $userId)
                    ->find($flightRequestId);
                $reservation = $flightRequest?->reservation;
            }

            if (! $flightRequest && $sessionId !== '') {
                $flightRequest = SolicitudVuelo::query()
                    ->with(['reservation.payments' => fn ($query) => $query->latest('id'), 'quotes'])
                    ->where('client_id', $userId)
                    ->where('stripe_checkout_session_id', $sessionId)
                    ->latest('id')
                    ->first();
                $reservation = $reservation ?: $flightRequest?->reservation;
            }

            if (! $reservation && $sessionId !== '') {
                $reservation = Reserva::query()
                    ->with(['flightRequest', 'payments' => fn ($query) => $query->latest('id')])
                    ->where('client_id', $userId)
                    ->whereHas('flightRequest', fn ($query) => $query->where('stripe_checkout_session_id', $sessionId))
                    ->latest('id')
                    ->first();
                $flightRequest = $flightRequest ?: $reservation?->flightRequest;
            }

            if (! $payment && $sessionId !== '') {
                $payment = $this->findStoredReservationStripePayment(
                    reservationId: (int) ($reservation?->id ?? 0),
                    flightRequestId: (int) ($flightRequest?->id ?? 0),
                    sessionId: $sessionId,
                );
                $this->auditStripeAction($userId, 'stripe_checkout_success_reconciled', 'Se reconcilio el retorno exitoso de Stripe Checkout.', [
                    'session_id' => $sessionId,
                    'reservation_id' => $reservation?->id,
                    'flight_request_id' => $flightRequest?->id,
                    'payment_id' => $payment?->id,
                ], $request);
            }

            abort_if(
                ! $payment && ! $reservation && ! $flightRequest && $sessionId === '',
                404,
                'No encontramos el pago de reserva solicitado.'
            );

            if ($sessionId !== '') {
                $this->reconcileStripeCheckoutSuccessBySession(
                    sessionId: $sessionId,
                    userId: $userId,
                    reservation: $reservation,
                    flightRequest: $flightRequest,
                    payment: $payment,
                );
            }

            if ($payment) {
                $payment->refresh();
            }

            if (! $reservation && $payment?->reservation_id) {
                $reservation = Reserva::query()
                    ->with(['contract', 'payments' => fn ($query) => $query->latest('id'), 'flightRequest'])
                    ->find($payment->reservation_id);
            }

            if (! $flightRequest) {
                $flightRequest = $reservation?->flightRequest
                    ? $reservation->flightRequest->fresh(['reservation'])
                    : ($payment?->flightRequest?->fresh(['reservation']) ?? null);
            }

            if ($payment && ($sessionId !== '' || filled($payment->stripe_checkout_session_id))) {
                $this->syncCheckoutSessionPayment(
                    $payment,
                    $sessionId !== '' ? $sessionId : (string) $payment->stripe_checkout_session_id,
                );
                $payment->refresh();
            }

            if (
                $payment
                && $payment->status !== 'paid'
                && ! $this->isReservationPaymentAlreadyFinalized(
                    payment: $payment,
                    reservation: $reservation,
                    flightRequest: $flightRequest,
                )
            ) {
                $this->finalizePendingStoredStripePayment($payment);
                $payment->refresh();
            }

            $reservation = $reservation?->fresh(['contract', 'latestPayment', 'flightRequest'])
                ?? $payment?->reservation?->fresh(['contract', 'latestPayment', 'flightRequest']);
            $flightRequest = $flightRequest?->fresh(['reservation'])
                ?? $reservation?->flightRequest?->fresh(['reservation'])
                ?? $payment?->flightRequest?->fresh(['reservation']);
            $latestPayment = $reservation?->latestPayment ?? $payment?->fresh();
            $resolvedSessionId = $sessionId !== ''
                ? $sessionId
                : (string) ($flightRequest?->stripe_checkout_session_id ?? $latestPayment?->stripe_checkout_session_id ?? '');
            $resolvedPaymentIntentId = (string) ($flightRequest?->stripe_payment_intent_id ?? $latestPayment?->stripe_payment_intent_id ?? '');
            $resolvedPaymentStatus = (string) ($flightRequest?->payment_status ?? $latestPayment?->status ?? 'pending');
            $resolvedCheckoutUrl = (string) (
                data_get($latestPayment?->gateway_response, 'checkout_url')
                ?? data_get($latestPayment?->gateway_response, 'url')
                ?? data_get($latestPayment?->gateway_response, 'session.url')
                ?? data_get($latestPayment?->gateway_response, 'checkout_session.url')
                ?? ''
            );
            $checkoutGatewayResponse = is_array($latestPayment?->gateway_response)
                ? $latestPayment->gateway_response
                : (array) ($latestPayment?->gateway_response ?? []);
            $resolvedCheckoutSessionStatus = strtolower((string) (
                data_get($checkoutGatewayResponse, 'status')
                ?? data_get($checkoutGatewayResponse, 'checkout_session.status')
                ?? data_get($checkoutGatewayResponse, 'session.status')
                ?? ''
            ));
            $resolvedCheckoutPaymentStatus = strtolower((string) (
                data_get($checkoutGatewayResponse, 'payment_status')
                ?? data_get($checkoutGatewayResponse, 'checkout_session.payment_status')
                ?? data_get($checkoutGatewayResponse, 'session.payment_status')
                ?? ''
            ));
            $resolvedBookingStatus = in_array((string) ($reservation?->status ?? $flightRequest?->status ?? ''), ['confirmed'], true)
                || $resolvedPaymentStatus === 'paid'
                ? 'confirmed'
                : 'pending_payment';
            $checkoutReusable = $resolvedSessionId !== ''
                && $resolvedBookingStatus !== 'confirmed'
                && ! in_array($resolvedCheckoutSessionStatus, ['complete', 'completed', 'expired'], true)
                && ! in_array($resolvedCheckoutPaymentStatus, ['paid'], true);
            $requiresNewCheckout = $resolvedSessionId !== ''
                && $resolvedBookingStatus !== 'confirmed'
                && ! $checkoutReusable
                && (
                    in_array($resolvedCheckoutSessionStatus, ['complete', 'completed', 'expired'], true)
                    || in_array($resolvedCheckoutPaymentStatus, ['paid'], true)
                );

            return $this->ok([
                'payment_order' => $latestPayment,
                'reservation' => $reservation ? $this->appendReservationStripeState($reservation) : null,
                'reservation_id' => $reservation?->id,
                'flight_request' => $flightRequest ? $this->appendFlightRequestStripeState($flightRequest) : null,
                'flight_request_id' => $flightRequest?->id ?? $payment?->flight_request_id,
                'payment_status' => $resolvedPaymentStatus,
                'booking_status' => $resolvedBookingStatus,
                'status' => $resolvedBookingStatus === 'confirmed' ? 'confirmed' : 'pending_payment',
                'workflow_status' => $resolvedBookingStatus === 'confirmed' ? 'vuelo confirmado' : 'pago pendiente',
                'checkout_url' => $checkoutReusable && $resolvedCheckoutUrl !== '' ? $resolvedCheckoutUrl : null,
                'stripe_checkout_session_id' => $resolvedSessionId,
                'stripe_payment_intent_id' => $resolvedPaymentIntentId !== '' ? $resolvedPaymentIntentId : null,
                'checkout_reusable' => $checkoutReusable,
                'requires_new_checkout' => $requiresNewCheckout,
                'stripe_checkout_status' => $resolvedCheckoutSessionStatus !== '' ? $resolvedCheckoutSessionStatus : null,
                'stripe_checkout_payment_status' => $resolvedCheckoutPaymentStatus !== '' ? $resolvedCheckoutPaymentStatus : null,
            ]);
        } catch (Throwable $exception) {
            Log::error('Fallo en checkout success Stripe.', [
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
                'session_id' => $request->query('session_id', $request->query('checkout_session_id', $request->query('stripe_checkout_session_id', ''))),
                'reservation_id' => $request->query('reservation_id', $request->query('booking_id')),
                'flight_request_id' => $request->query('flight_request_id'),
                'user_id' => $request->user()?->id,
                'stripe_secret_configured' => trim((string) config('services.stripe.secret')) !== '',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No fue posible validar el checkout de Stripe.',
                'error' => $exception->getMessage(),
            ], 502);
        }
    }

    public function cancel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['nullable', 'string', 'max:255'],
            'checkout_session_id' => ['nullable', 'string', 'max:255'],
        ]);

        $sessionId = trim((string) ($data['session_id'] ?? $data['checkout_session_id'] ?? ''));
        $userId = (int) $request->user()->id;

        $payment = Pago::query()
            ->with(['reservation', 'flightRequest'])
            ->where('user_id', $userId)
            ->where('payment_type', 'reservation')
            ->where('provider', 'stripe')
            ->where('status', 'pending')
            ->when($sessionId !== '', function ($query) use ($sessionId) {
                $query->where(function ($nestedQuery) use ($sessionId) {
                    $nestedQuery->where('stripe_checkout_session_id', $sessionId)
                        ->orWhere('transaction_reference', $sessionId);
                });
            })
            ->latest('id')
            ->first();

        if ($payment) {
            $this->markCancelledCheckoutPayment($payment, $sessionId);
            $payment->refresh();
        }

        $this->auditStripeAction($userId, 'stripe_checkout_cancelled', 'El cliente cancelo el flujo de Stripe Checkout.', [
            'session_id' => $sessionId,
            'payment_id' => $payment?->id,
            'reservation_id' => $payment?->reservation_id,
            'flight_request_id' => $payment?->flight_request_id,
        ], $request);

        return $this->ok([
            'message' => 'Flujo de pago de reserva cancelado.',
            'payment_order' => $payment,
            'reservation' => $payment?->reservation?->fresh(['contract', 'payments', 'flightRequest']),
            'flight_request' => $payment?->flightRequest?->fresh(['reservation']),
        ]);
    }

    public function mobileReturn(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'checkout' => ['nullable', 'string', 'max:32'],
            'session_id' => ['nullable', 'string', 'max:255'],
            'checkout_session_id' => ['nullable', 'string', 'max:255'],
            'reservation_id' => ['nullable', 'integer'],
            'flight_request_id' => ['nullable', 'integer'],
        ]);

        $checkout = strtolower((string) ($data['checkout'] ?? 'success'));
        $sessionId = (string) ($data['session_id'] ?? $data['checkout_session_id'] ?? '');
        $reservationId = (int) ($data['reservation_id'] ?? 0);
        $flightRequestId = (int) ($data['flight_request_id'] ?? 0);

        if ($checkout !== 'cancelled' && $checkout !== 'cancel' && $sessionId !== '' && ! $this->ensureStripeIsConfigured()) {
            try {
                $payment = $this->findReservationCheckoutPayment(
                    sessionId: $sessionId,
                    reservationId: $reservationId,
                    flightRequestId: $flightRequestId,
                );

                if ($payment) {
                    $this->syncCheckoutSessionPayment($payment, $sessionId);
                    $payment->refresh();

                    if ($payment->status !== 'paid') {
                        $this->finalizePendingStoredStripePayment($payment);
                    }
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $query = http_build_query([
            'checkout' => $checkout === 'cancelled' ? 'cancel' : $checkout,
            'session_id' => $sessionId,
            'refresh' => 'reservation_payment',
            'reservation_id' => $data['reservation_id'] ?? null,
            'flight_request_id' => $data['flight_request_id'] ?? null,
        ]);

        return redirect()->away('redsky://cliente/pago?'.$query);
    }

    private function findReservationCheckoutPayment(
        string $sessionId,
        int $reservationId = 0,
        int $flightRequestId = 0,
    ): ?Pago {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return null;
        }

        return Pago::query()
            ->with(['reservation.flightRequest', 'flightRequest'])
            ->where('payment_type', 'reservation')
            ->where('provider', 'stripe')
            ->where(function ($query) use ($sessionId) {
                $query->where('stripe_checkout_session_id', $sessionId)
                    ->orWhere('transaction_reference', $sessionId);
            })
            ->when($reservationId > 0, fn ($query) => $query->where('reservation_id', $reservationId))
            ->when($flightRequestId > 0, fn ($query) => $query->where('flight_request_id', $flightRequestId))
            ->latest('id')
            ->first();
    }

    private function findStoredReservationStripePayment(
        int $reservationId = 0,
        int $flightRequestId = 0,
        string $sessionId = '',
    ): ?Pago {
        return Pago::query()
            ->with(['reservation.flightRequest', 'flightRequest'])
            ->where('payment_type', 'reservation')
            ->where('provider', 'stripe')
            ->when($reservationId > 0, fn ($query) => $query->where('reservation_id', $reservationId))
            ->when($flightRequestId > 0, fn ($query) => $query->where('flight_request_id', $flightRequestId))
            ->when(trim($sessionId) !== '', function ($query) use ($sessionId) {
                $query->where(function ($nestedQuery) use ($sessionId) {
                    $nestedQuery->where('stripe_checkout_session_id', trim($sessionId))
                        ->orWhere('transaction_reference', trim($sessionId));
                });
            })
            ->latest('id')
            ->first();
    }

    private function finalizePendingStoredStripePayment(Pago $payment): void
    {
        $payment->loadMissing(['reservation.latestPayment', 'flightRequest']);
        if ($this->isReservationPaymentAlreadyFinalized(
            payment: $payment,
            reservation: $payment->reservation,
            flightRequest: $payment->flightRequest,
        )) {
            return;
        }

        $paymentIntentId = trim((string) $payment->stripe_payment_intent_id);
        if ($paymentIntentId === '') {
            return;
        }

        $flightRequest = $payment->flightRequest;
        if (! $flightRequest && $payment->flight_request_id) {
            $flightRequest = SolicitudVuelo::query()
                ->with(['reservation.payments' => fn ($query) => $query->latest('id'), 'quotes'])
                ->find($payment->flight_request_id);
        }

        if (! $flightRequest) {
            return;
        }

        $reservation = $payment->reservation ?: $this->ensureReservationForFlightRequest($flightRequest, (int) $payment->user_id);

        $paymentMethod = filled($payment->stripe_checkout_session_id) || filled($flightRequest->stripe_checkout_session_id)
            ? 'stripe_checkout'
            : (trim((string) $flightRequest->payment_method) !== '' ? (string) $flightRequest->payment_method : 'card');

        try {
            $this->finalizeSuccessfulPayment(
                flightRequest: $flightRequest,
                reservation: $reservation,
                paymentIntentId: $paymentIntentId,
                paymentMethod: $paymentMethod,
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function resolveFlightRequestAmount(SolicitudVuelo $flightRequest): float
    {
        $pricingContext = is_array($flightRequest->pricing_context) ? $flightRequest->pricing_context : [];

        return (float) (
            $pricingContext['total_amount']
            ?? $flightRequest->final_price
            ?: ($pricingContext['selected_card_price'] ?? 0)
            ?: ($pricingContext['total'] ?? 0)
            ?: ($pricingContext['final_price'] ?? 0)
        );
    }

    private function resolveFlightRequestPricingBreakdown(SolicitudVuelo $flightRequest): array
    {
        $pricingContext = is_array($flightRequest->pricing_context) ? $flightRequest->pricing_context : [];
        $flightCost = (float) (
            $pricingContext['flight_cost']
            ?? $pricingContext['billable_flight_cost']
            ?? $pricingContext['base_amount']
            ?? 0
        );

        if ($flightCost > 0) {
            return $this->paymentFeeCalculationServicio->flightBreakdown($flightCost);
        }

        $totalAmount = (float) ($pricingContext['total_amount'] ?? $flightRequest->final_price ?? 0);

        return [
            'flight_cost' => round($flightCost, 2),
            'base_amount' => round($flightCost, 2),
            'stripe_fee' => 0.0,
            'administrative_fee' => 0.0,
            'total_amount' => round($totalAmount, 2),
        ];
    }

    private function mergeFlightRequestPricingContext(SolicitudVuelo $flightRequest, array $pricingBreakdown): array
    {
        $pricingContext = is_array($flightRequest->pricing_context) ? $flightRequest->pricing_context : [];

        return array_merge($pricingContext, $pricingBreakdown, [
            'selected_card_price' => (float) $pricingBreakdown['total_amount'],
            'total' => (float) $pricingBreakdown['total_amount'],
            'final_price' => (float) $pricingBreakdown['total_amount'],
        ]);
    }

    private function ensureReservationForFlightRequest(SolicitudVuelo $flightRequest, int $userId): Reserva
    {
        $existing = $flightRequest->reservation()->latest('id')->first();
        if ($existing) {
            $this->ensureReservationAircraftAvailability(
                (int) $existing->aircraft_id,
                $flightRequest,
                (int) $existing->id,
                $existing->quote_id ? (int) $existing->quote_id : null,
            );

            return $existing;
        }

        $acceptedQuote = $flightRequest->quotes()
            ->where('status', 'accepted')
            ->latest('id')
            ->first();

        $providerId = $acceptedQuote?->provider_id ?? $flightRequest->assigned_provider_id;
        $aircraftId = $acceptedQuote?->aircraft_id ?? $flightRequest->assigned_aircraft_id;
        $amount = $this->resolveFlightRequestAmount($flightRequest);

        abort_if(! $providerId || ! $aircraftId, 409, 'La solicitud aun no tiene proveedor y aeronave confirmados.');
        abort_if($amount <= 0, 422, 'La solicitud no tiene un monto valido para crear la reserva.');
        $this->ensureReservationAircraftAvailability(
            (int) $aircraftId,
            $flightRequest,
            null,
            $acceptedQuote?->id ? (int) $acceptedQuote->id : null,
        );

        return Reserva::create([
            'client_id' => $userId,
            'provider_id' => $providerId,
            'aircraft_id' => $aircraftId,
            'flight_request_id' => $flightRequest->id,
            'quote_id' => $acceptedQuote?->id,
            'reservation_code' => 'PV-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
            'status' => 'pending_payment',
            'total_amount' => $amount,
            'currency' => $acceptedQuote?->currency ?? $flightRequest->currency ?? 'USD',
        ]);
    }

    private function ensureReservationAircraftHold(SolicitudVuelo $flightRequest, Reserva $reservation, int $userId): void
    {
        $aircraft = $reservation->aircraft()->with(['provider', 'documents'])->first();
        $route = $this->flightRouteService->buildCanonicalRoute([
            'origin' => $flightRequest->origin,
            'destination' => $flightRequest->destination,
            'departure_datetime' => optional($flightRequest->departure_datetime)->toDateTimeString(),
            'return_datetime' => optional($flightRequest->return_datetime)->toDateTimeString(),
            'trip_type' => $flightRequest->trip_type,
            'requirements' => is_array($flightRequest->requirements) ? $flightRequest->requirements : [],
        ]);
        [$start, $end] = $this->aircraftAvailabilityService->resolveFlightRequestWindow($flightRequest);
        $eligibility = $this->aircraftEligibilityService->evaluate($aircraft, [
            'route' => $route,
            'passengers' => (int) $flightRequest->passengers,
            'trip_type' => $route['trip_type'],
            'preference' => $flightRequest->aircraft_type,
            'requested_start' => $start,
            'requested_end' => $end,
            'flight_request_id' => $flightRequest->id,
            'reservation_id' => $reservation->id,
            'quote_id' => $reservation->quote_id,
        ]);

        if (! $eligibility['commercially_eligible'] || ! $eligibility['operationally_eligible']) {
            throw new RuntimeException($eligibility['reasons'][0] ?? 'La aeronave ya no es elegible para el pago.');
        }

        $availability = $this->aircraftAvailabilityService->evaluateReservationPaymentAvailability($reservation, true);

        if ((bool) ($availability['can_pay'] ?? false)) {
            return;
        }

        throw new RuntimeException((string) (
            $availability['message']
            ?? match ((string) ($availability['invalid_reason'] ?? '')) {
                'reservation_missing_schedule', 'hold_dates_missing' => 'No se encontro una fecha y hora confirmadas para esta reserva.',
                'hold_expired' => 'La retencion vencio. Estamos verificando nuevamente la disponibilidad de la aeronave.',
                'aircraft_booked_by_other_reservation' => 'La aeronave ya fue reservada para ese horario. Selecciona otra opcion.',
                default => 'No fue posible validar la disponibilidad actual de la aeronave.',
            }
        ));
    }

    private function resolveAcceptedQuoteForFlightRequest(SolicitudVuelo $flightRequest, ?Reserva $reservation = null): ?Cotizacion
    {
        $quoteId = $reservation?->quote_id;
        if ($quoteId) {
            $quote = Cotizacion::query()->with('flightRequest.legs')->find($quoteId);
            if ($quote) {
                return $quote;
            }
        }

        return $flightRequest->quotes()
            ->where('status', 'accepted')
            ->latest('id')
            ->first();
    }

    private function finalizeSuccessfulPayment(
        SolicitudVuelo $flightRequest,
        Reserva $reservation,
        ?string $paymentIntentId = null,
        ?string $brandOverride = null,
        string $paymentMethod = 'card',
        ?PaymentIntent $resolvedPaymentIntent = null,
    ): JsonResponse {
        $paymentIntentId = trim((string) (
            $paymentIntentId
            ?? $flightRequest->stripe_payment_intent_id
            ?? $reservation->payments->first()?->stripe_payment_intent_id
            ?? ''
        ));

        abort_if($paymentIntentId === '', 422, 'No encontramos el PaymentIntent para confirmar este pago.');

        $paymentIntent = $resolvedPaymentIntent;
        if (! $paymentIntent || (string) ($paymentIntent->id ?? '') !== $paymentIntentId) {
            Stripe::setApiKey((string) config('services.stripe.secret'));
            $paymentIntent = PaymentIntent::retrieve($paymentIntentId);
        }

        abort_if(! $paymentIntent, 404, 'Stripe no devolvio informacion del PaymentIntent.');
        abort_if(($paymentIntent->status ?? '') !== 'succeeded', 409, 'Stripe aun no confirma este pago como exitoso.');

        $metadataFlightRequestId = (int) ($paymentIntent->metadata->flight_request_id ?? 0);
        if ($metadataFlightRequestId > 0) {
            abort_if($metadataFlightRequestId !== (int) $flightRequest->id, 409, 'El PaymentIntent no corresponde a esta reserva.');
        }

        $brand = trim((string) (
            $brandOverride
            ?? data_get($paymentIntent, 'payment_method_details.card.brand')
            ?? data_get($paymentIntent, 'charges.data.0.payment_method_details.card.brand')
            ?? ''
        ));

        $pricingBreakdown = $this->resolveFlightRequestPricingBreakdown($flightRequest);
        $flightRequestStatus = $this->resolveConfirmedFlightRequestStatus($flightRequest);

        DB::transaction(function () use ($flightRequest, $reservation, $paymentIntent, $brand, $pricingBreakdown, $paymentMethod, $flightRequestStatus) {
            app(\App\Servicios\RedAviation\ProviderFlightNotificationService::class)
                ->updateConfirmedPayment($flightRequest, [
                'payment_method' => $paymentMethod,
                'payment_status' => 'paid',
                'stripe_checkout_session_id' => $paymentMethod === 'stripe_checkout'
                    ? ($flightRequest->stripe_checkout_session_id ?: $reservation->payments->first()?->stripe_checkout_session_id)
                    : $flightRequest->stripe_checkout_session_id,
                'stripe_payment_intent_id' => $paymentIntent->id,
                'workflow_status' => 'vuelo confirmado',
                'status' => $flightRequestStatus,
                'final_price' => (float) $pricingBreakdown['total_amount'],
                'pricing_context' => $this->mergeFlightRequestPricingContext($flightRequest, $pricingBreakdown),
            ], $reservation);

            $reservation->update([
                'status' => 'confirmed',
                'confirmed_at' => $reservation->confirmed_at ?: now(),
                'total_amount' => (float) $pricingBreakdown['total_amount'],
                'currency' => $reservation->currency ?: strtoupper((string) ($paymentIntent->currency ?? $flightRequest->currency ?? 'USD')),
            ]);

            $payment = Pago::updateOrCreate(
                [
                    'reservation_id' => $reservation->id,
                    'flight_request_id' => $flightRequest->id,
                    'provider' => 'stripe',
                    'payment_type' => 'reservation',
                ],
                [
                    'user_id' => $flightRequest->client_id,
                    'amount' => ((int) ($paymentIntent->amount ?? 0)) / 100,
                    'currency' => strtoupper((string) ($paymentIntent->currency ?? $flightRequest->currency ?? 'USD')),
                    'transaction_reference' => $paymentIntent->id,
                    'stripe_checkout_session_id' => $paymentMethod === 'stripe_checkout'
                        ? ($flightRequest->stripe_checkout_session_id ?: $reservation->payments->first()?->stripe_checkout_session_id)
                        : null,
                    'stripe_payment_intent_id' => $paymentIntent->id,
                    'status' => 'paid',
                    'paid_at' => now(),
                    'failure_reason' => null,
                    'gateway_response' => [
                        'brand' => $brand ?: null,
                        'pricing' => $pricingBreakdown,
                        'payment_intent' => json_decode(json_encode($paymentIntent), true),
                    ],
                ],
            );

            if ($brand !== '') {
                $responsePayload = is_array($payment->gateway_response) ? $payment->gateway_response : [];
                $responsePayload['brand'] = $brand;
                $payment->update(['gateway_response' => $responsePayload]);
            }

            Pago::query()
                ->where('payment_type', 'reservation')
                ->whereIn('status', ['pending', 'processing'])
                ->where(function ($query) use ($reservation, $flightRequest) {
                    $query->where('reservation_id', $reservation->id)
                        ->orWhere('flight_request_id', $flightRequest->id);
                })
                ->where('id', '!=', $payment->id)
                ->update([
                    'status' => 'cancelled',
                    'failure_reason' => 'Reemplazado por pago Stripe confirmado.',
                    'updated_at' => now(),
                ]);

            $this->aircraftAvailabilityService->blockAircraftForPaidReservation($reservation->fresh(['flightRequest.legs', 'legs']));
        });

        $reservation = $reservation->fresh(['contract', 'latestPayment', 'flightRequest']);
        $paymentOrder = $reservation?->latestPayment;
        $flightRequest->refresh();

        Log::info('Pago Stripe confirmado manualmente/sincronizado.', [
            'flight_request_id' => $flightRequest->id,
            'reservation_id' => $reservation->id,
            'payment_intent_id' => $paymentIntent->id,
            'checkout_session_id' => (string) ($flightRequest->stripe_checkout_session_id ?? $paymentOrder?->stripe_checkout_session_id ?? ''),
            'payment_method' => $paymentMethod,
            'payment_status' => 'paid',
            'booking_status' => 'confirmed',
            'status' => 'confirmed',
        ]);

        $this->auditStripeAction($flightRequest->client_id, 'stripe_payment_confirmed', 'Stripe confirmo el pago de la reserva del cliente.', [
            'flight_request_id' => $flightRequest->id,
            'reservation_id' => $reservation->id,
            'payment_intent_id' => $paymentIntent->id,
            'checkout_session_id' => $flightRequest->stripe_checkout_session_id ?: $paymentOrder?->stripe_checkout_session_id,
            'payment_method' => $paymentMethod,
        ]);

        return $this->ok([
            'reservation' => $this->appendReservationStripeState($reservation),
            'reservation_id' => $reservation->id,
            'flight_request_id' => $flightRequest->id,
            'flight_request' => $this->appendFlightRequestStripeState($flightRequest),
            'payment_order' => [
                'status' => 'paid',
                'brand' => $brand ?: data_get($paymentOrder, 'gateway_response.brand'),
                'payment_intent_id' => $paymentIntent->id,
                'checkout_session_id' => $flightRequest->stripe_checkout_session_id ?: $paymentOrder?->stripe_checkout_session_id,
            ],
            'payment_status' => 'paid',
            'booking_status' => 'confirmed',
            'status' => 'confirmed',
            'workflow_status' => 'vuelo confirmado',
        ]);
    }

    private function resolveConfirmedFlightRequestStatus(SolicitudVuelo $flightRequest): string
    {
        $currentStatus = strtolower(trim((string) ($flightRequest->status ?? '')));
        $allowedStatuses = array_map(
            static fn (EstadoSolicitudVuelo $status) => $status->value,
            EstadoSolicitudVuelo::cases(),
        );

        if (in_array($currentStatus, $allowedStatuses, true)) {
            return in_array($currentStatus, [
                EstadoSolicitudVuelo::Cancelled->value,
                EstadoSolicitudVuelo::Expired->value,
            ], true)
                ? EstadoSolicitudVuelo::Reserved->value
                : $currentStatus;
        }

        return EstadoSolicitudVuelo::Reserved->value;
    }

    private function confirmedReservationPaymentResponse(SolicitudVuelo $flightRequest, Reserva $reservation): JsonResponse
    {
        $reservation = $reservation->fresh(['contract', 'latestPayment', 'flightRequest']);
        $paymentOrder = $reservation?->latestPayment;

        return $this->ok([
            'reservation' => $reservation ? $this->appendReservationStripeState($reservation) : null,
            'reservation_id' => $reservation?->id,
            'flight_request_id' => $flightRequest->id,
            'flight_request' => $this->appendFlightRequestStripeState($flightRequest->fresh(['reservation'])),
            'payment_order' => [
                'status' => 'paid',
                'brand' => data_get($paymentOrder, 'gateway_response.brand'),
                'payment_intent_id' => $paymentOrder?->stripe_payment_intent_id,
                'checkout_session_id' => $paymentOrder?->stripe_checkout_session_id,
            ],
            'payment_status' => 'paid',
            'booking_status' => 'confirmed',
            'status' => 'confirmed',
            'workflow_status' => 'vuelo confirmado',
        ]);
    }

    private function syncCheckoutSessionPayment(Pago $payment, string $sessionId): void
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return;
        }

        $payment->loadMissing(['reservation.latestPayment', 'flightRequest']);
        if ($this->isReservationPaymentAlreadyFinalized(
            payment: $payment,
            reservation: $payment->reservation,
            flightRequest: $payment->flightRequest,
        )) {
            return;
        }

        Stripe::setApiKey((string) config('services.stripe.secret'));
        $session = $this->retrieveCheckoutSession($sessionId);

        abort_if(! $session, 404, 'Stripe no devolvio informacion de la sesion de Checkout.');
        $paymentIntent = $this->resolvePaymentIntentFromCheckoutSession($session);
        $paymentStatus = strtolower((string) ($session->payment_status ?? ''));
        $sessionStatus = strtolower((string) ($session->status ?? ''));
        $paymentIntentStatus = strtolower((string) ($paymentIntent->status ?? ''));

        Log::info('Stripe session', [
            'session' => (string) ($session->id ?? $sessionId),
            'payment_intent_type' => is_object($paymentIntent) ? get_class($paymentIntent) : gettype($paymentIntent),
            'payment_intent_status' => $paymentIntent->status ?? null,
            'payment_status' => $session->payment_status ?? null,
        ]);

        $metadataFlightRequestId = (int) ($session->metadata->flight_request_id ?? 0);
        if ($metadataFlightRequestId > 0 && (int) $payment->flight_request_id > 0) {
            abort_if($metadataFlightRequestId !== (int) $payment->flight_request_id, 409, 'La sesion de Checkout no corresponde a esta reserva.');
        }

        if (
            in_array($paymentStatus, ['paid', 'no_payment_required'], true)
            || $sessionStatus === 'complete'
            || $paymentIntentStatus === 'succeeded'
        ) {
            $flightRequest = SolicitudVuelo::query()
                ->with(['reservation.payments' => fn ($query) => $query->latest('id'), 'quotes'])
                ->findOrFail((int) $payment->flight_request_id);
            $reservation = $payment->reservation ?: $this->ensureReservationForFlightRequest($flightRequest, (int) $payment->user_id);

            $this->finalizeSuccessfulPayment(
                flightRequest: $flightRequest,
                reservation: $reservation,
                paymentIntentId: (string) ($paymentIntent->id ?? $session->payment_intent ?? ''),
                brandOverride: (string) (
                    data_get($paymentIntent, 'payment_method_details.card.brand')
                    ?? data_get($paymentIntent, 'charges.data.0.payment_method_details.card.brand')
                    ?? ''
                ),
                paymentMethod: 'stripe_checkout',
                resolvedPaymentIntent: $paymentIntent,
            );

            return;
        }

        if (in_array($paymentStatus, ['unpaid', 'no_payment_required'], true) || in_array($sessionStatus, ['expired', 'open'], true)) {
            DB::transaction(function () use ($payment, $session, $sessionId) {
                $flightRequest = $payment->flightRequest;
                $reservation = $payment->reservation;

                $payment->update([
                    'status' => 'pending',
                    'stripe_checkout_session_id' => $sessionId,
                    'gateway_response' => json_decode(json_encode($session), true),
                ]);

                if ($flightRequest) {
                    $flightRequest->update([
                        'payment_status' => 'pending',
                        'stripe_checkout_session_id' => $sessionId,
                        'workflow_status' => 'pago pendiente',
                    ]);
                }

                if ($reservation) {
                    $reservation->update([
                        'status' => 'pending_payment',
                    ]);
                }
            });

            $this->auditStripeAction($payment->user_id, 'stripe_checkout_pending_synced', 'Se sincronizo una sesion de Stripe Checkout aun pendiente.', [
                'payment_id' => $payment->id,
                'reservation_id' => $payment->reservation_id,
                'flight_request_id' => $payment->flight_request_id,
                'session_id' => $sessionId,
                'session_status' => $session->status ?? null,
                'payment_status' => $session->payment_status ?? null,
            ]);
        }
    }

    private function markCancelledCheckoutPayment(Pago $payment, string $sessionId = ''): void
    {
        DB::transaction(function () use ($payment, $sessionId) {
            $flightRequest = $payment->flightRequest;
            $reservation = $payment->reservation;

            $payment->update([
                'status' => 'cancelled',
                'failure_reason' => 'Checkout cancelado por el cliente.',
                'stripe_checkout_session_id' => $sessionId !== '' ? $sessionId : $payment->stripe_checkout_session_id,
            ]);

            if ($flightRequest && $flightRequest->payment_status !== 'paid') {
                $flightRequest->update([
                    'payment_status' => 'cancelled',
                ]);
            }

            if ($reservation && $reservation->status !== 'paid') {
                $reservation->update([
                    'status' => 'pending_payment',
                ]);
            }

            if ($reservation) {
                $this->aircraftAvailabilityService->releaseReservationBlock($reservation->fresh(['flightRequest', 'latestPayment']));
            }
        });

        $this->auditStripeAction($payment->user_id, 'stripe_payment_cancelled', 'Se cancelo una orden Stripe pendiente de reserva.', [
            'payment_id' => $payment->id,
            'reservation_id' => $payment->reservation_id,
            'flight_request_id' => $payment->flight_request_id,
            'session_id' => $sessionId !== '' ? $sessionId : $payment->stripe_checkout_session_id,
        ]);
    }

    private function ensureReservationAircraftAvailability(
        int $aircraftId,
        ?SolicitudVuelo $flightRequest,
        ?int $ignoreReservationId = null,
        ?int $ignoreQuoteId = null,
    ): void {
        if ($aircraftId <= 0 || ! $flightRequest) {
            return;
        }

        [$requestedStart, $requestedEnd] = $this->aircraftAvailabilityService->resolveWindowFromPayload([
            'departure_datetime' => $flightRequest->departure_datetime,
            'return_datetime' => $flightRequest->return_datetime,
            'legs' => $flightRequest->legs()->get(['departure_datetime', 'arrival_datetime'])->map(
                fn ($leg) => [
                    'departure_datetime' => $leg->departure_datetime,
                    'arrival_datetime' => $leg->arrival_datetime,
                ]
            )->values()->all(),
        ]);

        if (! $this->aircraftAvailabilityService->aircraftHasConflictExcluding(
            $aircraftId,
            $requestedStart,
            $requestedEnd,
            $ignoreReservationId,
            null,
            $ignoreQuoteId,
            null,
        )) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'success' => false,
            'code' => 'AIRCRAFT_NOT_AVAILABLE',
            'message' => 'Esta aeronave ya no está disponible para el horario seleccionado.',
        ], 409));
    }

    private function ensureStripeIsConfigured(): ?JsonResponse
    {
        $secretKey = trim((string) config('services.stripe.secret'));
        $publishableKey = trim((string) config('services.stripe.publishable'));

        if ($secretKey === '') {
            return response()->json([
                'success' => false,
                'message' => 'Stripe no esta configurado en el backend. Falta STRIPE_SECRET_KEY en el entorno.',
            ], 503);
        }

        if ($publishableKey === '') {
            return response()->json([
                'success' => false,
                'message' => 'Stripe no esta configurado completamente. Falta STRIPE_PUBLISHABLE_KEY en el entorno.',
            ], 503);
        }

        return null;
    }

    private function appendReservationStripeState(Reserva $reservation): Reserva
    {
        $reservation->setAttribute('booking_status', $reservation->status === 'confirmed' ? 'confirmed' : $reservation->status);
        $reservation->setAttribute('payment_status', $reservation->flightRequest?->payment_status ?? $reservation->latestPayment?->status);

        return $reservation;
    }

    private function appendFlightRequestStripeState(SolicitudVuelo $flightRequest): SolicitudVuelo
    {
        $normalizedStatus = $flightRequest->payment_status === 'paid' ? 'confirmed' : $flightRequest->status;
        $flightRequest->setAttribute('booking_status', $normalizedStatus);
        $flightRequest->setAttribute('reservation_status', $normalizedStatus);
        $flightRequest->setAttribute('checkout_session_id', $flightRequest->stripe_checkout_session_id);
        $flightRequest->setAttribute('payment_intent_id', $flightRequest->stripe_payment_intent_id);

        return $flightRequest;
    }

    private function reconcileStripeCheckoutSuccessBySession(
        string $sessionId,
        int $userId,
        ?Reserva $reservation = null,
        ?SolicitudVuelo $flightRequest = null,
        ?Pago $payment = null,
    ): void {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return;
        }

        Stripe::setApiKey((string) config('services.stripe.secret'));
        $session = $this->retrieveCheckoutSession($sessionId);

        abort_if(! $session, 404, 'Stripe no devolvio informacion de la sesion de Checkout.');
        $paymentIntent = $this->resolvePaymentIntentFromCheckoutSession($session);
        $paymentStatus = strtolower((string) ($session->payment_status ?? ''));
        $sessionStatus = strtolower((string) ($session->status ?? ''));
        $paymentIntentStatus = strtolower((string) ($paymentIntent->status ?? ''));
        $paymentIntentId = (string) ($paymentIntent->id ?? $session->payment_intent ?? '');
        Log::info('Stripe session', [
            'session' => (string) ($session->id ?? $sessionId),
            'payment_intent_type' => is_object($paymentIntent) ? get_class($paymentIntent) : gettype($paymentIntent),
            'payment_intent_status' => $paymentIntent->status ?? null,
            'payment_status' => $session->payment_status ?? null,
        ]);
        Log::info('Stripe checkout success reconcile.', [
            'session_id' => $sessionId,
            'payment_intent_id' => $paymentIntentId,
            'payment_intent_status' => $paymentIntentStatus,
            'reservation_id' => $reservation?->id,
            'flight_request_id' => $flightRequest?->id,
            'payment_id' => $payment?->id,
            'user_id' => $userId,
        ]);

        if (! $flightRequest) {
            $metadataFlightRequestId = (int) ($session->metadata->flight_request_id ?? 0);
            if ($metadataFlightRequestId > 0) {
                $flightRequest = SolicitudVuelo::query()
                    ->with(['reservation.payments' => fn ($query) => $query->latest('id'), 'quotes'])
                    ->where('client_id', $userId)
                    ->find($metadataFlightRequestId);
            }
        }

        if (! $flightRequest && $paymentIntentId !== '') {
            $flightRequest = SolicitudVuelo::query()
                ->with(['reservation.payments' => fn ($query) => $query->latest('id'), 'quotes'])
                ->where('client_id', $userId)
                ->where('stripe_payment_intent_id', $paymentIntentId)
                ->latest('id')
                ->first();
        }

        if (! $reservation) {
            $reservation = $flightRequest?->reservation;
        }

        if (! $reservation && $sessionId !== '') {
            $reservation = Reserva::query()
                ->with(['flightRequest', 'payments' => fn ($query) => $query->latest('id')])
                ->where('client_id', $userId)
                ->whereHas('flightRequest', fn ($query) => $query->where('stripe_checkout_session_id', $sessionId))
                ->latest('id')
                ->first();
            $flightRequest = $flightRequest ?: $reservation?->flightRequest;
        }

        if (! $payment) {
            $payment = $this->findStoredReservationStripePayment(
                reservationId: (int) ($reservation?->id ?? 0),
                flightRequestId: (int) ($flightRequest?->id ?? 0),
                sessionId: $sessionId,
            );
        }

        if (! $payment && $paymentIntentId !== '') {
            $payment = Pago::query()
                ->with(['reservation.flightRequest', 'flightRequest'])
                ->where('user_id', $userId)
                ->where('payment_type', 'reservation')
                ->where('provider', 'stripe')
                ->where(function ($query) use ($paymentIntentId, $sessionId) {
                    $query->where('stripe_payment_intent_id', $paymentIntentId)
                        ->orWhere('transaction_reference', $paymentIntentId);
                    if ($sessionId !== '') {
                        $query->orWhere('stripe_checkout_session_id', $sessionId);
                    }
                })
                ->latest('id')
                ->first();
        }

        $checkoutIsPaid = in_array($paymentStatus, ['paid', 'no_payment_required'], true)
            || $sessionStatus === 'complete'
            || $paymentIntentStatus === 'succeeded';

        if ($checkoutIsPaid && $flightRequest) {
            $reservation = $reservation ?: $this->ensureReservationForFlightRequest($flightRequest, $userId);
            if ($this->isReservationPaymentAlreadyFinalized(
                payment: $payment,
                reservation: $reservation,
                flightRequest: $flightRequest,
            )) {
                return;
            }
            $this->finalizeSuccessfulPayment(
                flightRequest: $flightRequest,
                reservation: $reservation,
                paymentIntentId: $paymentIntentId,
                brandOverride: (string) (
                    data_get($paymentIntent, 'payment_method_details.card.brand')
                    ?? data_get($paymentIntent, 'charges.data.0.payment_method_details.card.brand')
                    ?? ''
                ),
                paymentMethod: 'stripe_checkout',
                resolvedPaymentIntent: $paymentIntent,
            );
        } elseif ($payment) {
            $this->syncCheckoutSessionPayment($payment, $sessionId);
        }
    }

    private function retrieveCheckoutSession(string $sessionId): Session
    {
        return Session::retrieve([
            'id' => $sessionId,
            'expand' => ['payment_intent'],
        ]);
    }

    private function resolvePaymentIntentFromCheckoutSession(Session $session): ?PaymentIntent
    {
        $paymentIntent = $session->payment_intent ?? null;

        if ($paymentIntent instanceof PaymentIntent) {
            return $paymentIntent;
        }

        if (is_string($paymentIntent) && trim($paymentIntent) !== '') {
            return PaymentIntent::retrieve(trim($paymentIntent));
        }

        if (is_object($paymentIntent) && isset($paymentIntent->id)) {
            return PaymentIntent::retrieve((string) $paymentIntent->id);
        }

        return null;
    }

    private function isReservationPaymentAlreadyFinalized(
        ?Pago $payment = null,
        ?Reserva $reservation = null,
        ?SolicitudVuelo $flightRequest = null,
    ): bool {
        $paymentStatus = strtolower(trim((string) ($payment?->status ?? '')));
        $reservationStatus = strtolower(trim((string) ($reservation?->status ?? '')));
        $flightRequestPaymentStatus = strtolower(trim((string) ($flightRequest?->payment_status ?? '')));
        $flightRequestStatus = strtolower(trim((string) ($flightRequest?->status ?? '')));

        return $paymentStatus === 'paid'
            || in_array($reservationStatus, ['paid', 'confirmed'], true)
            || $flightRequestPaymentStatus === 'paid'
            || $flightRequestStatus === 'confirmed';
    }

    private function auditStripeAction(
        ?int $userId,
        string $action,
        string $description,
        array $newValues = [],
        ?Request $request = null,
    ): void {
        $this->writeAuditEntry(
            $userId,
            $action,
            'reservation_payments',
            $description,
            ['new_values' => $newValues],
            $request?->ip(),
            $request?->userAgent(),
        );
    }

    private function reservationPaymentAvailabilityMessage(string $invalidReason): string
    {
        return match ($invalidReason) {
            'hold_expired' => 'La retencion vencio. Estamos verificando nuevamente la disponibilidad de la aeronave.',
            'hold_released' => 'La retencion ya fue liberada y necesitamos validar una nueva disponibilidad.',
            'hold_not_found' => 'No encontramos una retencion valida asociada a esta reserva.',
            'hold_dates_missing', 'reservation_missing_schedule' => 'No se encontro una fecha y hora confirmadas para esta reserva.',
            'hold_dates_mismatch' => 'La ventana de la retencion no coincide con la reserva actual.',
            'hold_aircraft_mismatch' => 'La retencion encontrada pertenece a otra aeronave.',
            'hold_reservation_mismatch' => 'La retencion encontrada no coincide con esta reserva.',
            'hold_quote_mismatch' => 'La retencion encontrada no coincide con la cotizacion aceptada.',
            'aircraft_booked_by_other_reservation' => 'La aeronave ya fue reservada para ese horario. Selecciona otra opcion.',
            'invalid_timezone' => 'No fue posible validar la zona horaria de esta reserva.',
            default => 'No fue posible validar la disponibilidad actual de la aeronave.',
        };
    }
}
