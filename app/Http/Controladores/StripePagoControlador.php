<?php

namespace App\Http\Controladores;

use App\Modelos\Pago;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use App\Servicios\Pagos\PaymentFeeCalculationServicio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        return $this->finalizeSuccessfulPayment(
            flightRequest: $flightRequest,
            reservation: $reservation,
            paymentIntentId: $data['payment_intent_id'] ?? null,
            brandOverride: $data['brand'] ?? null,
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
            'brand' => ['nullable', 'string', 'max:120'],
        ]);

        $flightRequest = $reservation->flightRequest;
        abort_if(! $flightRequest, 404, 'La reserva no tiene una solicitud de vuelo asociada.');

        return $this->finalizeSuccessfulPayment(
            flightRequest: $flightRequest,
            reservation: $reservation,
            paymentIntentId: $data['payment_intent_id'] ?? null,
            brandOverride: $data['brand'] ?? null,
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
                'payment_method' => 'card',
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

        DB::transaction(function () use ($flightRequest, $reservation, $paymentIntent, $brand, $pricingBreakdown) {
            $flightRequest->update([
                'payment_method' => 'card',
                'payment_status' => 'paid',
                'stripe_payment_intent_id' => $paymentIntent->id,
                'workflow_status' => 'pago confirmado',
                'status' => 'reserved',
                'final_price' => (float) $pricingBreakdown['total_amount'],
                'pricing_context' => $this->mergeFlightRequestPricingContext($flightRequest, $pricingBreakdown),
            ]);

            $reservation->update([
                'status' => 'paid',
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

        return $this->ok([
            'reservation' => $reservation,
            'reservation_id' => $reservation->id,
            'flight_request_id' => $flightRequest->id,
            'payment_order' => [
                'status' => 'paid',
                'brand' => $brand ?: data_get($paymentOrder, 'gateway_response.brand'),
                'payment_intent_id' => $paymentIntent->id,
            ],
            'payment_status' => 'paid',
            'workflow_status' => 'pago confirmado',
        ]);
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
}
