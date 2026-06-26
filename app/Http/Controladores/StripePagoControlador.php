<?php

namespace App\Http\Controladores;

use App\Modelos\Pago;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use Illuminate\Http\RedirectResponse;
use App\Servicios\Pagos\PaymentFeeCalculationServicio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;
use Stripe\Stripe;

class StripePagoControlador extends ControladorBase
{
    public function __construct(private readonly PaymentFeeCalculationServicio $paymentFeeCalculationServicio)
    {
    }

    public function confirmFlightRequestPayment(Request $request)
    {
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

        return $this->finalizeSuccessfulPayment(
            flightRequest: $flightRequest,
            reservation: $reservation,
            paymentIntentId: $data['payment_intent_id'] ?? null,
            brandOverride: $data['brand'] ?? null,
            paymentMethod: $paymentMethod !== '' ? $paymentMethod : 'card',
        );
    }

    public function confirmReservationPayment(Request $request, mixed $reservation)
    {
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

        return $this->finalizeSuccessfulPayment(
            flightRequest: $flightRequest,
            reservation: $reservation,
            paymentIntentId: $data['payment_intent_id'] ?? null,
            brandOverride: $data['brand'] ?? null,
            paymentMethod: $paymentMethod !== '' ? $paymentMethod : 'card',
        );
    }

    public function createCheckout(Request $request)
    {
        if ($response = $this->ensureStripeIsConfigured()) {
            return $response;
        }

        $data = $request->validate([
            'flight_request_id' => ['required', 'exists:flight_requests,id'],
            'contact_email' => ['nullable', 'email'],
            'success_url' => ['nullable', 'url'],
            'cancel_url' => ['nullable', 'url'],
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

        Stripe::setApiKey((string) config('services.stripe.secret'));

        $successUrl = $data['success_url'] ?? rtrim((string) config('services.stripe.frontend_url'), '/')."/cliente/reserva-confirmada/{$flightRequest->id}?checkout=success";
        $cancelUrl = $data['cancel_url'] ?? rtrim((string) config('services.stripe.frontend_url'), '/')."/cliente/pago/{$flightRequest->id}?checkout=cancelled";

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
        ]);

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

        $data = $request->validate([
            'session_id' => ['nullable', 'string', 'max:255'],
            'checkout_session_id' => ['nullable', 'string', 'max:255'],
            'stripe_checkout_session_id' => ['nullable', 'string', 'max:255'],
            'reservation_id' => ['nullable', 'integer', 'exists:reservations,id'],
            'booking_id' => ['nullable', 'integer', 'exists:reservations,id'],
            'flight_request_id' => ['nullable', 'integer', 'exists:flight_requests,id'],
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

        if (! $payment) {
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

            if (! $payment && $sessionId !== '') {
                $payment = $this->findStoredReservationStripePayment(
                    reservationId: (int) ($reservation?->id ?? 0),
                    flightRequestId: (int) ($flightRequest?->id ?? 0),
                    sessionId: $sessionId,
                );
            }
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

        if ($payment && $payment->status !== 'paid') {
            $this->finalizePendingStoredStripePayment($payment);
            $payment->refresh();
        }

        $reservation = $reservation?->fresh(['contract', 'payments', 'flightRequest'])
            ?? $payment?->reservation?->fresh(['contract', 'payments', 'flightRequest']);
        $flightRequest = $flightRequest?->fresh(['reservation'])
            ?? $reservation?->flightRequest?->fresh(['reservation'])
            ?? $payment?->flightRequest?->fresh(['reservation']);
        $latestPayment = $reservation?->payments?->sortByDesc('id')->first() ?? $payment?->fresh();
        $resolvedSessionId = $sessionId !== ''
            ? $sessionId
            : (string) ($flightRequest?->stripe_checkout_session_id ?? $latestPayment?->stripe_checkout_session_id ?? '');
        $resolvedPaymentIntentId = (string) ($flightRequest?->stripe_payment_intent_id ?? $latestPayment?->stripe_payment_intent_id ?? '');
        $resolvedPaymentStatus = (string) ($flightRequest?->payment_status ?? $latestPayment?->status ?? 'pending');
        $resolvedBookingStatus = in_array((string) ($reservation?->status ?? $flightRequest?->status ?? ''), ['confirmed'], true)
            || $resolvedPaymentStatus === 'paid'
            ? 'confirmed'
            : 'pending_payment';

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
            'stripe_checkout_session_id' => $resolvedSessionId,
            'stripe_payment_intent_id' => $resolvedPaymentIntentId !== '' ? $resolvedPaymentIntentId : null,
        ]);
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
            } catch (\Throwable $exception) {
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
        } catch (\Throwable $exception) {
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

    private function finalizeSuccessfulPayment(
        SolicitudVuelo $flightRequest,
        Reserva $reservation,
        ?string $paymentIntentId = null,
        ?string $brandOverride = null,
        string $paymentMethod = 'card',
    ): JsonResponse {
        $paymentIntentId = trim((string) (
            $paymentIntentId
            ?? $flightRequest->stripe_payment_intent_id
            ?? $reservation->payments->first()?->stripe_payment_intent_id
            ?? ''
        ));

        abort_if($paymentIntentId === '', 422, 'No encontramos el PaymentIntent para confirmar este pago.');

        Stripe::setApiKey((string) config('services.stripe.secret'));
        $paymentIntent = PaymentIntent::retrieve($paymentIntentId);

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

        DB::transaction(function () use ($flightRequest, $reservation, $paymentIntent, $brand, $pricingBreakdown, $paymentMethod) {
            $flightRequest->update([
                'payment_method' => $paymentMethod,
                'payment_status' => 'paid',
                'stripe_checkout_session_id' => $paymentMethod === 'stripe_checkout'
                    ? ($flightRequest->stripe_checkout_session_id ?: $reservation->payments->first()?->stripe_checkout_session_id)
                    : $flightRequest->stripe_checkout_session_id,
                'stripe_payment_intent_id' => $paymentIntent->id,
                'workflow_status' => 'vuelo confirmado',
                'status' => 'confirmed',
                'final_price' => (float) $pricingBreakdown['total_amount'],
                'pricing_context' => $this->mergeFlightRequestPricingContext($flightRequest, $pricingBreakdown),
            ]);

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
        });

        $reservation->refresh()->load(['contract', 'payments', 'flightRequest']);
        $paymentOrder = $reservation->payments->sortByDesc('id')->first();
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

    private function confirmedReservationPaymentResponse(SolicitudVuelo $flightRequest, Reserva $reservation): JsonResponse
    {
        $reservation = $reservation->fresh(['contract', 'payments', 'flightRequest']);
        $paymentOrder = $reservation?->payments?->sortByDesc('id')->first();

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

        Stripe::setApiKey((string) config('services.stripe.secret'));

        $session = Session::retrieve($sessionId, [
            'expand' => ['payment_intent'],
        ]);

        abort_if(! $session, 404, 'Stripe no devolvio informacion de la sesion de Checkout.');

        $metadataFlightRequestId = (int) ($session->metadata->flight_request_id ?? 0);
        if ($metadataFlightRequestId > 0 && (int) $payment->flight_request_id > 0) {
            abort_if($metadataFlightRequestId !== (int) $payment->flight_request_id, 409, 'La sesion de Checkout no corresponde a esta reserva.');
        }

        $paymentStatus = strtolower((string) ($session->payment_status ?? ''));
        $sessionStatus = strtolower((string) ($session->status ?? ''));

        if (in_array($paymentStatus, ['paid', 'no_payment_required'], true) || $sessionStatus === 'complete') {
            $flightRequest = SolicitudVuelo::query()
                ->with(['reservation.payments' => fn ($query) => $query->latest('id'), 'quotes'])
                ->findOrFail((int) $payment->flight_request_id);
            $reservation = $payment->reservation ?: $this->ensureReservationForFlightRequest($flightRequest, (int) $payment->user_id);

            $this->finalizeSuccessfulPayment(
                flightRequest: $flightRequest,
                reservation: $reservation,
                paymentIntentId: (string) ($session->payment_intent->id ?? $session->payment_intent ?? ''),
                brandOverride: (string) (
                    data_get($session, 'payment_intent.payment_method_details.card.brand')
                    ?? data_get($session, 'payment_intent.charges.data.0.payment_method_details.card.brand')
                    ?? ''
                ),
                paymentMethod: 'stripe_checkout',
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
        });
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
        $session = Session::retrieve($sessionId, [
            'expand' => ['payment_intent'],
        ]);

        abort_if(! $session, 404, 'Stripe no devolvio informacion de la sesion de Checkout.');

        $paymentIntentStatus = strtolower((string) data_get($session, 'payment_intent.status', ''));
        Log::info('Stripe checkout success reconcile.', [
            'session_id' => $sessionId,
            'payment_intent_id' => (string) data_get($session, 'payment_intent.id', $session->payment_intent ?? ''),
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

        if (! $reservation) {
            $reservation = $flightRequest?->reservation;
        }

        if (! $payment) {
            $payment = $this->findStoredReservationStripePayment(
                reservationId: (int) ($reservation?->id ?? 0),
                flightRequestId: (int) ($flightRequest?->id ?? 0),
                sessionId: $sessionId,
            );
        }

        if ($paymentIntentStatus === 'succeeded' && $flightRequest) {
            $reservation = $reservation ?: $this->ensureReservationForFlightRequest($flightRequest, $userId);
            $this->finalizeSuccessfulPayment(
                flightRequest: $flightRequest,
                reservation: $reservation,
                paymentIntentId: (string) data_get($session, 'payment_intent.id', $session->payment_intent ?? ''),
                brandOverride: (string) (
                    data_get($session, 'payment_intent.payment_method_details.card.brand')
                    ?? data_get($session, 'payment_intent.charges.data.0.payment_method_details.card.brand')
                    ?? ''
                ),
                paymentMethod: 'stripe_checkout',
            );
        } elseif ($payment) {
            $this->syncCheckoutSessionPayment($payment, $sessionId);
        }
    }
}
