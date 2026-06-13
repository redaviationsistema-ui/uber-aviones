<?php

namespace App\Http\Controladores;

use App\Modelos\AccessPayment;
use App\Modelos\AircraftBillingPayment;
use App\Modelos\Pago;
use App\Modelos\SolicitudVuelo;
use App\Modelos\SuscripcionAeronave;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Stripe\Webhook;
use Throwable;

class StripeWebhookControlador extends ControladorBase
{
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $signature = (string) $request->header('Stripe-Signature');
        $secret = (string) config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $signature, $secret);
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook invalido',
            ], 400);
        }

        $existing = DB::table('webhook_events')
            ->where('provider', 'stripe')
            ->where('event_id', $event->id)
            ->first();

        if ($existing && $existing->status === 'processed') {
            return response()->json(['success' => true, 'received' => true]);
        }

        $stripeCreatedAtUtc = Carbon::createFromTimestamp((int) $event->created)->utc();
        $stripeCreatedAtLocal = $stripeCreatedAtUtc->copy()->setTimezone(config('app.timezone', 'UTC'));

        if (! $existing) {
            DB::table('webhook_events')->insert([
                'provider' => 'stripe',
                'event_id' => $event->id,
                'event_type' => $event->type,
                'payload' => json_decode($payload, true),
                'stripe_created_at_utc' => $stripeCreatedAtUtc,
                'stripe_created_at_local' => $stripeCreatedAtLocal,
                'status' => 'received',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('webhook_events')
                ->where('provider', 'stripe')
                ->where('event_id', $event->id)
                ->update([
                    'stripe_created_at_utc' => $stripeCreatedAtUtc,
                    'stripe_created_at_local' => $stripeCreatedAtLocal,
                    'updated_at' => now(),
                ]);
        }

        try {
            match ($event->type) {
                'checkout.session.completed' => $this->handleCheckoutCompleted($event->data->object),
                'checkout.session.expired' => $this->handleCheckoutExpired($event->data->object),
                'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event->data->object),
                'payment_intent.payment_failed' => $this->handlePaymentFailed($event->data->object),
                'payment_intent.canceled' => $this->handlePaymentCanceled($event->data->object),
                'invoice.payment_succeeded', 'invoice.paid' => $this->handleInvoicePaid($event->data->object),
                'invoice.payment_failed' => $this->handleInvoicePaymentFailed($event->data->object),
                default => null,
            };

            DB::table('webhook_events')
                ->where('provider', 'stripe')
                ->where('event_id', $event->id)
                ->update([
                    'status' => 'processed',
                    'processed_at' => now(),
                    'updated_at' => now(),
                    'error_message' => null,
                ]);
        } catch (Throwable $exception) {
            DB::table('webhook_events')
                ->where('provider', 'stripe')
                ->where('event_id', $event->id)
                ->update([
                    'status' => 'failed',
                    'error_message' => $exception->getMessage(),
                    'updated_at' => now(),
                ]);

            throw $exception;
        }

        return response()->json(['success' => true, 'received' => true]);
    }

    private function handleCheckoutCompleted(object $session): void
    {
        $metadata = $this->extractMetadata($session);
        $context = $metadata['billing_context'] ?? null;

        if ($context === 'client_access') {
            $this->handleClientAccessCheckoutCompleted($session, $metadata);
            return;
        }

        if ($context === 'provider_aircraft_subscription') {
            $this->handleAircraftBillingCheckoutCompleted($session, $metadata);
            return;
        }

        $flightRequestId = (int) ($session->metadata->flight_request_id ?? 0);
        if (! $flightRequestId) {
            return;
        }

        $flightRequest = SolicitudVuelo::find($flightRequestId);
        if (! $flightRequest) {
            return;
        }

        DB::transaction(function () use ($flightRequest, $session) {
            $flightRequest->update([
                'payment_method' => 'card',
                'payment_status' => 'paid',
                'stripe_checkout_session_id' => $session->id,
                'stripe_payment_intent_id' => $session->payment_intent ?? null,
                'workflow_status' => 'pago confirmado',
                'status' => 'reserved',
            ]);

            Pago::updateOrCreate(
                [
                    'flight_request_id' => $flightRequest->id,
                    'provider' => 'stripe',
                    'payment_type' => 'reservation',
                ],
                [
                    'user_id' => $flightRequest->client_id,
                    'amount' => ((int) ($session->amount_total ?? 0)) / 100,
                    'currency' => strtoupper((string) ($session->currency ?? $flightRequest->currency ?? 'USD')),
                    'transaction_reference' => $session->id,
                    'stripe_checkout_session_id' => $session->id,
                    'stripe_payment_intent_id' => $session->payment_intent ?? null,
                    'status' => 'paid',
                    'paid_at' => now(),
                    'failure_reason' => null,
                    'gateway_response' => json_decode(json_encode($session), true),
                ],
            );
        });
    }

    private function handleCheckoutExpired(object $session): void
    {
        $metadata = $this->extractMetadata($session);
        $context = $metadata['billing_context'] ?? null;

        if ($context === 'client_access') {
            AccessPayment::query()
                ->where('id', (int) ($metadata['access_payment_id'] ?? 0))
                ->orWhere('provider_checkout_id', (string) ($session->id ?? ''))
                ->update(['status' => 'cancelled']);
            return;
        }

        if ($context === 'provider_aircraft_subscription') {
            AircraftBillingPayment::query()
                ->where('id', (int) ($metadata['aircraft_billing_payment_id'] ?? 0))
                ->orWhere('provider_checkout_id', (string) ($session->id ?? ''))
                ->update(['status' => 'cancelled']);
            return;
        }

        $this->markFlightRequestPaymentFailure(
            checkoutSessionId: (string) ($session->id ?? ''),
            paymentIntentId: null,
            flightRequestId: (int) ($session->metadata->flight_request_id ?? 0),
            status: 'cancelled',
            paymentStatus: 'cancelled',
            reason: 'Checkout expirado.',
        );
    }

    private function handlePaymentIntentSucceeded(object $paymentIntent): void
    {
        $metadata = $this->extractMetadata($paymentIntent);
        $context = $metadata['billing_context'] ?? null;

        if ($context === 'client_access') {
            $this->markAccessPaymentPaid(
                accessPaymentId: (int) ($metadata['access_payment_id'] ?? 0),
                providerPaymentId: (string) ($paymentIntent->id ?? ''),
                gatewayPayload: json_decode(json_encode($paymentIntent), true),
            );
            return;
        }

        $flightRequestId = (int) ($paymentIntent->metadata->flight_request_id ?? 0);
        if (! $flightRequestId) {
            return;
        }

        $flightRequest = SolicitudVuelo::find($flightRequestId);
        if (! $flightRequest) {
            return;
        }

        DB::transaction(function () use ($flightRequest, $paymentIntent) {
            $flightRequest->update([
                'payment_method' => 'card',
                'payment_status' => 'paid',
                'stripe_payment_intent_id' => $paymentIntent->id,
                'workflow_status' => 'pago confirmado',
                'status' => 'reserved',
            ]);

            Pago::updateOrCreate(
                [
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
                    'gateway_response' => json_decode(json_encode($paymentIntent), true),
                ],
            );
        });
    }

    private function handlePaymentFailed(object $paymentIntent): void
    {
        $metadata = $this->extractMetadata($paymentIntent);
        $context = $metadata['billing_context'] ?? null;
        $message = $paymentIntent->last_payment_error->message ?? 'Pago rechazado por Stripe.';

        if ($context === 'client_access') {
            $this->markAccessPaymentFailed((int) ($metadata['access_payment_id'] ?? 0), (string) $message);
            return;
        }

        $flightRequestId = (int) ($paymentIntent->metadata->flight_request_id ?? 0);
        $this->markFlightRequestPaymentFailure(
            checkoutSessionId: '',
            paymentIntentId: (string) ($paymentIntent->id ?? ''),
            flightRequestId: $flightRequestId,
            status: 'failed',
            paymentStatus: 'failed',
            reason: (string) $message,
        );
    }

    private function handlePaymentCanceled(object $paymentIntent): void
    {
        $metadata = $this->extractMetadata($paymentIntent);
        if (($metadata['billing_context'] ?? null) === 'client_access') {
            $this->markAccessPaymentFailed((int) ($metadata['access_payment_id'] ?? 0), 'PaymentIntent cancelado.');
            return;
        }

        $this->markFlightRequestPaymentFailure(
            checkoutSessionId: '',
            paymentIntentId: (string) ($paymentIntent->id ?? ''),
            flightRequestId: (int) ($paymentIntent->metadata->flight_request_id ?? 0),
            status: 'cancelled',
            paymentStatus: 'cancelled',
            reason: 'PaymentIntent cancelado.',
        );
    }

    private function handleInvoicePaid(object $invoice): void
    {
        $metadata = $this->extractMetadata($invoice);
        if (($metadata['billing_context'] ?? null) !== 'provider_aircraft_subscription') {
            return;
        }

        $subscriptionId = (string) ($invoice->subscription ?? $metadata['provider_subscription_id'] ?? '');
        $paymentId = (int) ($metadata['aircraft_billing_payment_id'] ?? 0);
        $aircraftId = (int) ($metadata['aircraft_id'] ?? 0);
        $providerId = (int) ($metadata['provider_id'] ?? 0);
        $planId = (int) ($metadata['billing_plan_id'] ?? 0);
        $amount = ((int) ($invoice->amount_paid ?? $invoice->amount_due ?? 0)) / 100;
        $currency = strtoupper((string) ($invoice->currency ?? 'USD'));
        $periodStart = $this->extractInvoicePeriodDate($invoice, 'start');
        $periodEnd = $this->extractInvoicePeriodDate($invoice, 'end');

        $payment = $paymentId > 0 ? AircraftBillingPayment::find($paymentId) : null;
        if (! $payment && $subscriptionId !== '') {
            $payment = AircraftBillingPayment::query()
                ->where('provider_subscription_id', $subscriptionId)
                ->latest('id')
                ->first();
        }

        if ($payment && ($aircraftId === 0 || $providerId === 0 || $planId === 0)) {
            $aircraftId = $aircraftId ?: (int) $payment->aircraft_id;
            $providerId = $providerId ?: (int) $payment->provider_id;
            $planId = $planId ?: (int) $payment->billing_plan_id;
        }

        if (! $payment && $aircraftId > 0 && $providerId > 0 && $planId > 0) {
            $payment = AircraftBillingPayment::create([
                'provider_id' => $providerId,
                'aircraft_id' => $aircraftId,
                'billing_plan_id' => $planId,
                'amount' => $amount,
                'currency' => $currency,
                'billing_period_start' => $periodStart,
                'billing_period_end' => $periodEnd,
                'status' => 'pending',
                'provider' => 'stripe',
                'provider_subscription_id' => $subscriptionId ?: null,
            ]);
        }

        if (! $payment) {
            return;
        }

        $this->markAircraftBillingPaid(
            payment: $payment,
            providerPaymentId: (string) ($invoice->payment_intent ?? $invoice->id ?? ''),
            subscriptionId: $subscriptionId,
            amount: $amount,
            currency: $currency,
            gatewayPayload: json_decode(json_encode($invoice), true),
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            userId: (int) ($metadata['user_id'] ?? 0),
        );
    }

    private function handleInvoicePaymentFailed(object $invoice): void
    {
        $metadata = $this->extractMetadata($invoice);
        if (($metadata['billing_context'] ?? null) !== 'provider_aircraft_subscription') {
            return;
        }

        $subscriptionId = (string) ($invoice->subscription ?? '');
        AircraftBillingPayment::query()
            ->when($subscriptionId !== '', fn ($query) => $query->where('provider_subscription_id', $subscriptionId))
            ->latest('id')
            ->first()?->update([
                'status' => 'past_due',
                'gateway_response' => json_decode(json_encode($invoice), true),
            ]);

        if (! empty($metadata['aircraft_id'])) {
            DB::table('aircraft')->where('id', (int) $metadata['aircraft_id'])->update([
                'billing_status' => 'past_due',
                'subscription_status' => 'past_due',
                'updated_at' => now(),
            ]);
        }
    }

    private function handleClientAccessCheckoutCompleted(object $session, array $metadata): void
    {
        $this->markAccessPaymentPaid(
            accessPaymentId: (int) ($metadata['access_payment_id'] ?? 0),
            providerPaymentId: (string) ($session->payment_intent ?? ''),
            gatewayPayload: json_decode(json_encode($session), true),
            checkoutSessionId: (string) ($session->id ?? ''),
        );
    }

    private function handleAircraftBillingCheckoutCompleted(object $session, array $metadata): void
    {
        $payment = AircraftBillingPayment::query()
            ->where('id', (int) ($metadata['aircraft_billing_payment_id'] ?? 0))
            ->orWhere('provider_checkout_id', (string) ($session->id ?? ''))
            ->latest('id')
            ->first();

        if (! $payment) {
            return;
        }

        $this->markAircraftBillingPaid(
            payment: $payment,
            providerPaymentId: (string) ($session->payment_intent ?? ''),
            subscriptionId: (string) ($session->subscription ?? ''),
            amount: ((int) ($session->amount_total ?? 0)) / 100,
            currency: strtoupper((string) ($session->currency ?? $payment->currency ?? 'USD')),
            gatewayPayload: json_decode(json_encode($session), true),
            periodStart: $payment->billing_period_start?->toDateString() ?: now()->startOfMonth()->toDateString(),
            periodEnd: $payment->billing_period_end?->toDateString() ?: now()->endOfMonth()->toDateString(),
            userId: (int) ($metadata['user_id'] ?? 0),
        );
    }

    private function markAccessPaymentPaid(int $accessPaymentId, string $providerPaymentId, array $gatewayPayload, string $checkoutSessionId = ''): void
    {
        if ($accessPaymentId <= 0) {
            return;
        }

        $payment = AccessPayment::find($accessPaymentId);
        if (! $payment) {
            return;
        }

        DB::transaction(function () use ($payment, $providerPaymentId, $gatewayPayload, $checkoutSessionId) {
            $payment->update([
                'status' => 'paid',
                'provider_payment_id' => $providerPaymentId ?: $payment->provider_payment_id,
                'provider_checkout_id' => $checkoutSessionId ?: $payment->provider_checkout_id,
                'paid_at' => now(),
                'gateway_response' => $gatewayPayload,
            ]);

            DB::table('users')->where('id', $payment->user_id)->update([
                'access_status' => 'active',
                'has_paid_access' => true,
                'paid_access_at' => now(),
                'access_payment_id' => $payment->id,
                'updated_at' => now(),
            ]);
        });
    }

    private function markAccessPaymentFailed(int $accessPaymentId, string $reason): void
    {
        if ($accessPaymentId <= 0) {
            return;
        }

        $payment = AccessPayment::find($accessPaymentId);
        if (! $payment) {
            return;
        }

        $payment->update([
            'status' => 'failed',
            'gateway_response' => ['reason' => $reason],
        ]);

        DB::table('users')->where('id', $payment->user_id)->update([
            'access_status' => 'payment_pending',
            'updated_at' => now(),
        ]);
    }

    private function markAircraftBillingPaid(
        AircraftBillingPayment $payment,
        string $providerPaymentId,
        string $subscriptionId,
        float $amount,
        string $currency,
        array $gatewayPayload,
        ?string $periodStart,
        ?string $periodEnd,
        int $userId,
    ): void {
        DB::transaction(function () use ($payment, $providerPaymentId, $subscriptionId, $amount, $currency, $gatewayPayload, $periodStart, $periodEnd, $userId) {
            $payment->update([
                'status' => 'paid',
                'provider_payment_id' => $providerPaymentId ?: $payment->provider_payment_id,
                'provider_subscription_id' => $subscriptionId ?: $payment->provider_subscription_id,
                'amount' => $amount > 0 ? $amount : $payment->amount,
                'currency' => $currency ?: $payment->currency,
                'billing_period_start' => $periodStart ?: $payment->billing_period_start,
                'billing_period_end' => $periodEnd ?: $payment->billing_period_end,
                'paid_at' => now(),
                'gateway_response' => $gatewayPayload,
            ]);

            DB::table('aircraft')->where('id', $payment->aircraft_id)->update([
                'billing_status' => 'active',
                'billing_plan_id' => $payment->billing_plan_id,
                'subscription_status' => 'active',
                'subscription_started_at' => $periodStart ? Carbon::parse($periodStart)->startOfDay() : now(),
                'subscription_ends_at' => $periodEnd ? Carbon::parse($periodEnd)->endOfDay() : now()->addMonth(),
                'last_payment_at' => now(),
                'status' => 'active',
                'updated_at' => now(),
            ]);

            SuscripcionAeronave::updateOrCreate(
                ['aircraft_id' => $payment->aircraft_id, 'plan_id' => $payment->billing_plan_id],
                [
                    'user_id' => $userId > 0 ? $userId : DB::table('providers')->where('id', $payment->provider_id)->value('user_id'),
                    'status' => 'active',
                    'payment_provider' => 'stripe',
                    'payment_reference' => $subscriptionId ?: $payment->provider_subscription_id,
                    'starts_at' => $periodStart ? Carbon::parse($periodStart)->startOfDay() : now(),
                    'ends_at' => $periodEnd ? Carbon::parse($periodEnd)->endOfDay() : now()->addMonth(),
                    'updated_at' => now(),
                ]
            );
        });
    }

    private function extractMetadata(object $payload): array
    {
        $candidates = [
            json_decode(json_encode($payload->metadata ?? []), true) ?: [],
            Arr::get(json_decode(json_encode($payload), true), 'subscription_details.metadata', []),
            Arr::get(json_decode(json_encode($payload), true), 'parent.subscription_details.metadata', []),
            Arr::get(json_decode(json_encode($payload), true), 'lines.data.0.metadata', []),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return [];
    }

    private function extractInvoicePeriodDate(object $invoice, string $edge): ?string
    {
        $timestamp = Arr::get(json_decode(json_encode($invoice), true), "lines.data.0.period.$edge");
        if (! $timestamp) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $timestamp)->toDateString();
    }

    private function markFlightRequestPaymentFailure(
        string $checkoutSessionId,
        ?string $paymentIntentId,
        int $flightRequestId,
        string $status,
        string $paymentStatus,
        string $reason,
    ): void {
        $paymentQuery = Pago::query()->where('provider', 'stripe');

        if ($flightRequestId > 0) {
            $paymentQuery->where('flight_request_id', $flightRequestId);
        } elseif ($checkoutSessionId !== '') {
            $paymentQuery->where('stripe_checkout_session_id', $checkoutSessionId);
        } elseif ($paymentIntentId) {
            $paymentQuery->where('stripe_payment_intent_id', $paymentIntentId);
        } else {
            return;
        }

        $payment = $paymentQuery->latest('id')->first();
        $flightRequest = $flightRequestId > 0
            ? SolicitudVuelo::find($flightRequestId)
            : ($payment?->flightRequest);

        if ($payment) {
            $payment->update([
                'status' => $status,
                'failure_reason' => $reason,
                'stripe_payment_intent_id' => $paymentIntentId ?: $payment->stripe_payment_intent_id,
                'paid_at' => null,
            ]);
        }

        if ($flightRequest) {
            $flightRequest->update([
                'payment_status' => $paymentStatus,
                'stripe_payment_intent_id' => $paymentIntentId ?: $flightRequest->stripe_payment_intent_id,
                'workflow_status' => 'pago pendiente',
                'status' => 'reserved',
            ]);
        }
    }
}
