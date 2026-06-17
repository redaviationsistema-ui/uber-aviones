<?php

namespace App\Http\Controladores;

use App\Modelos\AccessPayment;
use App\Modelos\AircraftBillingPayment;
use App\Modelos\Notificacion;
use App\Modelos\Pago;
use App\Modelos\SolicitudVuelo;
use App\Modelos\Suscripcion;
use App\Modelos\SuscripcionAeronave;
use App\Modelos\Usuario;
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
                'customer.subscription.updated' => $this->handleCustomerSubscriptionUpdated($event->data->object),
                'customer.subscription.deleted' => $this->handleCustomerSubscriptionDeleted($event->data->object),
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

        if ($context === 'client_access_subscription') {
            $this->handleClientAccessSubscriptionCheckoutCompleted($session, $metadata);
            return;
        }

        if ($context === 'client_access') {
            $this->handleClientAccessCheckoutCompleted($session, $metadata);
            return;
        }

        if ($context === 'provider_aircraft_subscription') {
            $this->handleAircraftBillingCheckoutCompleted($session, $metadata);
            return;
        }

        if ($context === 'client_subscription') {
            $this->handleClientSubscriptionCheckoutCompleted($session, $metadata);
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

        if ($context === 'client_access_subscription') {
            $payment = AccessPayment::query()
                ->where('id', (int) ($metadata['access_payment_id'] ?? 0))
                ->orWhere('provider_checkout_id', (string) ($session->id ?? ''))
                ->latest('id')
                ->first();

            if ($payment) {
                $payment->update([
                    'status' => 'cancelled',
                    'gateway_response' => json_decode(json_encode($session), true),
                ]);

                DB::table('users')->where('id', $payment->user_id)->update([
                    'access_status' => DB::raw("
                        case
                            when has_paid_access = true then access_status
                            when coalesce(free_quotes_used, 0) >= greatest(coalesce(free_quote_limit, 1), 1) then 'trial_used'
                            else 'trial_active'
                        end
                    "),
                    'updated_at' => now(),
                ]);
            }

            return;
        }

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

        if ($context === 'client_subscription') {
            Suscripcion::query()
                ->where('id', (int) ($metadata['subscription_record_id'] ?? 0))
                ->orWhere('provider_subscription_id', (string) ($session->subscription ?? ''))
                ->update([
                    'status' => 'cancelled',
                    'payment_status' => 'cancelled',
                    'cancelled_at' => now(),
                ]);
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
        if ($this->isClientAccessSubscriptionBillingContext($metadata)) {
            $this->handleClientAccessSubscriptionInvoicePaid($invoice, $metadata);
            return;
        }

        if (($metadata['billing_context'] ?? null) === 'client_subscription') {
            $subscriptionId = (string) ($invoice->subscription ?? '');
            $subscriptionRecordId = (int) ($metadata['subscription_record_id'] ?? 0);
            $amount = ((int) ($invoice->amount_paid ?? $invoice->amount_due ?? 0)) / 100;
            $currency = strtoupper((string) ($invoice->currency ?? 'USD'));
            $periodStart = $this->extractInvoicePeriodDate($invoice, 'start');
            $periodEnd = $this->extractInvoicePeriodDate($invoice, 'end');

            $this->markClientSubscriptionPaid(
                subscriptionRecordId: $subscriptionRecordId,
                providerSubscriptionId: $subscriptionId,
                providerPaymentId: (string) ($invoice->payment_intent ?? $invoice->id ?? ''),
                amount: $amount,
                currency: $currency,
                gatewayPayload: json_decode(json_encode($invoice), true),
                periodStart: $periodStart,
                periodEnd: $periodEnd,
            );
            return;
        }

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
        if ($this->isClientAccessSubscriptionBillingContext($metadata)) {
            $this->handleClientAccessSubscriptionInvoiceFailed($invoice, $metadata);
            return;
        }

        if (($metadata['billing_context'] ?? null) === 'client_subscription') {
            $subscriptionId = (string) ($invoice->subscription ?? '');
            Suscripcion::query()
                ->when($subscriptionId !== '', fn ($query) => $query->where('provider_subscription_id', $subscriptionId))
                ->when((int) ($metadata['subscription_record_id'] ?? 0) > 0, fn ($query) => $query->orWhere('id', (int) ($metadata['subscription_record_id'] ?? 0)))
                ->latest('id')
                ->first()?->update([
                    'status' => 'past_due',
                    'payment_status' => 'failed',
                ]);
            return;
        }

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

    private function handleClientAccessSubscriptionCheckoutCompleted(object $session, array $metadata): void
    {
        $payment = AccessPayment::query()
            ->where('id', (int) ($metadata['access_payment_id'] ?? 0))
            ->orWhere('provider_checkout_id', (string) ($session->id ?? ''))
            ->latest('id')
            ->first();

        if (! $payment) {
            return;
        }

        $customerId = (string) ($session->customer ?? '');
        $subscriptionId = (string) ($session->subscription ?? '');

        DB::transaction(function () use ($payment, $session, $customerId, $subscriptionId) {
            $payment->update([
                'provider_checkout_id' => (string) ($session->id ?? $payment->provider_checkout_id),
                'provider_customer_id' => $customerId !== '' ? $customerId : $payment->provider_customer_id,
                'provider_subscription_id' => $subscriptionId !== '' ? $subscriptionId : $payment->provider_subscription_id,
                'gateway_response' => json_decode(json_encode($session), true),
            ]);

            DB::table('users')->where('id', $payment->user_id)->update([
                'access_status' => 'payment_pending',
                'provider_subscription_id' => $subscriptionId !== '' ? $subscriptionId : DB::raw('provider_subscription_id'),
                'provider_customer_id' => $customerId !== '' ? $customerId : DB::raw('provider_customer_id'),
                'access_payment_id' => $payment->id,
                'updated_at' => now(),
            ]);
        });
    }

    private function handleClientAccessSubscriptionInvoicePaid(object $invoice, array $metadata): void
    {
        $providerSubscriptionId = (string) ($invoice->subscription ?? '');
        $providerCustomerId = (string) ($invoice->customer ?? '');
        $providerInvoiceId = (string) ($invoice->id ?? '');
        $userId = $this->resolveAccessSubscriptionUserId($metadata, $providerSubscriptionId, $providerCustomerId);
        if ($userId <= 0) {
            return;
        }

        $payment = $this->findAccessPaymentForSubscription($userId, $providerSubscriptionId, $providerInvoiceId);
        if (! $payment) {
            $planId = (int) ($metadata['billing_plan_id'] ?? 0);
            if ($planId <= 0) {
                return;
            }

            $payment = AccessPayment::create([
                'user_id' => $userId,
                'billing_plan_id' => $planId,
                'amount' => ((int) ($invoice->amount_paid ?? $invoice->amount_due ?? 0)) / 100,
                'currency' => strtoupper((string) ($invoice->currency ?? 'USD')),
                'billing_period_start' => $this->extractInvoicePeriodDate($invoice, 'start') ?: now()->toDateString(),
                'billing_period_end' => $this->extractInvoicePeriodDate($invoice, 'end') ?: now()->addMonthNoOverflow()->toDateString(),
                'status' => 'pending',
                'provider' => 'stripe',
            ]);
        }

        $periodStart = $this->extractInvoicePeriodDate($invoice, 'start') ?: ($payment->billing_period_start?->toDateString() ?: now()->toDateString());
        $periodEnd = $this->extractInvoicePeriodDate($invoice, 'end') ?: ($payment->billing_period_end?->toDateString() ?: now()->addMonthNoOverflow()->toDateString());

        $this->syncClientAccessSubscriptionPaidState(
            payment: $payment,
            providerSubscriptionId: $providerSubscriptionId,
            providerCustomerId: $providerCustomerId,
            providerInvoiceId: $providerInvoiceId,
            providerPaymentId: (string) ($invoice->payment_intent ?? $invoice->id ?? ''),
            amount: ((int) ($invoice->amount_paid ?? $invoice->amount_due ?? 0)) / 100,
            currency: strtoupper((string) ($invoice->currency ?? $payment->currency ?? 'USD')),
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            gatewayPayload: json_decode(json_encode($invoice), true),
        );
    }

    private function handleClientAccessSubscriptionInvoiceFailed(object $invoice, array $metadata): void
    {
        $providerSubscriptionId = (string) ($invoice->subscription ?? '');
        $providerCustomerId = (string) ($invoice->customer ?? '');
        $providerInvoiceId = (string) ($invoice->id ?? '');
        $userId = $this->resolveAccessSubscriptionUserId($metadata, $providerSubscriptionId, $providerCustomerId);
        if ($userId <= 0) {
            return;
        }

        $payment = $this->findAccessPaymentForSubscription($userId, $providerSubscriptionId, $providerInvoiceId);
        if (! $payment) {
            return;
        }

        $retryAt = ! empty($invoice->next_payment_attempt)
            ? Carbon::createFromTimestamp((int) $invoice->next_payment_attempt)
            : null;
        $graceEndsAt = now()->addDays(7);
        $reason = (string) (
            Arr::get(json_decode(json_encode($invoice), true), 'last_finalization_error.message')
            ?? Arr::get(json_decode(json_encode($invoice), true), 'last_payment_error.message')
            ?? 'Stripe informo un pago fallido para la renovacion del acceso comercial.'
        );

        DB::transaction(function () use ($payment, $invoice, $providerSubscriptionId, $providerCustomerId, $providerInvoiceId, $retryAt, $graceEndsAt, $reason) {
            $payment->update([
                'status' => 'past_due',
                'provider_subscription_id' => $providerSubscriptionId ?: $payment->provider_subscription_id,
                'provider_customer_id' => $providerCustomerId ?: $payment->provider_customer_id,
                'provider_invoice_id' => $providerInvoiceId ?: $payment->provider_invoice_id,
                'provider_payment_id' => (string) ($invoice->payment_intent ?? $payment->provider_payment_id),
                'failure_reason' => $reason,
                'retry_count' => (int) $payment->retry_count + 1,
                'grace_period_ends_at' => $graceEndsAt,
                'gateway_response' => json_decode(json_encode($invoice), true),
            ]);

            DB::table('users')->where('id', $payment->user_id)->update([
                'access_status' => 'past_due',
                'has_paid_access' => true,
                'grace_period_ends_at' => $graceEndsAt,
                'next_retry_at' => $retryAt,
                'provider_subscription_id' => $providerSubscriptionId ?: DB::raw('provider_subscription_id'),
                'provider_customer_id' => $providerCustomerId ?: DB::raw('provider_customer_id'),
                'access_payment_id' => $payment->id,
                'updated_at' => now(),
            ]);
        });

        $this->createAccessNotification(
            userId: $payment->user_id,
            type: 'access_payment_failed',
            title: 'Pago de suscripcion comercial fallido',
            message: 'No pudimos renovar automaticamente tu suscripcion comercial. Tu cuenta entro en periodo de gracia mientras actualizas tu metodo de pago.',
            data: [
                'provider_subscription_id' => $providerSubscriptionId,
                'provider_customer_id' => $providerCustomerId,
                'provider_invoice_id' => $providerInvoiceId,
                'next_retry_at' => $retryAt?->toIso8601String(),
                'grace_period_ends_at' => $graceEndsAt->toIso8601String(),
                'failure_reason' => $reason,
            ],
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

    private function handleClientSubscriptionCheckoutCompleted(object $session, array $metadata): void
    {
        $subscriptionRecordId = (int) ($metadata['subscription_record_id'] ?? 0);
        $providerSubscriptionId = (string) ($session->subscription ?? '');

        $subscription = $subscriptionRecordId > 0 ? Suscripcion::find($subscriptionRecordId) : null;
        if (! $subscription && $providerSubscriptionId !== '') {
            $subscription = Suscripcion::query()
                ->where('provider_subscription_id', $providerSubscriptionId)
                ->latest('id')
                ->first();
        }

        if (! $subscription) {
            return;
        }

        $startsAt = now()->startOfDay();
        $endsAt = $subscription->plan?->billing_cycle === 'yearly'
            ? now()->addYear()->endOfDay()
            : now()->addMonth()->endOfDay();

        $subscription->update([
            'status' => 'active',
            'payment_status' => 'paid',
            'payment_provider' => 'stripe',
            'provider_subscription_id' => $providerSubscriptionId !== '' ? $providerSubscriptionId : $subscription->provider_subscription_id,
            'started_at' => $subscription->started_at ?: $startsAt,
            'starts_at' => $subscription->starts_at ?: $startsAt,
            'expires_at' => $subscription->expires_at ?: $endsAt,
            'ends_at' => $subscription->ends_at ?: $endsAt,
            'renews_at' => $subscription->renews_at ?: $endsAt,
            'cancelled_at' => null,
        ]);
    }

    private function handleCustomerSubscriptionUpdated(object $subscriptionPayload): void
    {
        $metadata = $this->extractMetadata($subscriptionPayload);
        if ($this->isClientAccessSubscriptionBillingContext($metadata, (string) ($subscriptionPayload->id ?? ''), (string) ($subscriptionPayload->customer ?? ''))) {
            $providerSubscriptionId = (string) ($subscriptionPayload->id ?? '');
            $providerCustomerId = (string) ($subscriptionPayload->customer ?? '');
            $userId = $this->resolveAccessSubscriptionUserId($metadata, $providerSubscriptionId, $providerCustomerId);
            if ($userId <= 0) {
                return;
            }

            $status = (string) ($subscriptionPayload->status ?? 'active');
            $periodEnd = ! empty($subscriptionPayload->current_period_end)
                ? Carbon::createFromTimestamp((int) $subscriptionPayload->current_period_end)->endOfDay()
                : null;
            $cancelledAt = ! empty($subscriptionPayload->canceled_at)
                ? Carbon::createFromTimestamp((int) $subscriptionPayload->canceled_at)
                : null;

            if (in_array($status, ['active', 'trialing'], true)) {
                DB::table('users')->where('id', $userId)->update([
                    'access_status' => 'active',
                    'has_paid_access' => true,
                    'grace_period_ends_at' => null,
                    'next_retry_at' => null,
                    'provider_subscription_id' => $providerSubscriptionId ?: DB::raw('provider_subscription_id'),
                    'provider_customer_id' => $providerCustomerId ?: DB::raw('provider_customer_id'),
                    'access_expires_at' => $periodEnd ?: DB::raw('access_expires_at'),
                    'updated_at' => now(),
                ]);

                return;
            }

            if ($status === 'past_due') {
                DB::table('users')->where('id', $userId)->update([
                    'access_status' => 'past_due',
                    'has_paid_access' => true,
                    'provider_subscription_id' => $providerSubscriptionId ?: DB::raw('provider_subscription_id'),
                    'provider_customer_id' => $providerCustomerId ?: DB::raw('provider_customer_id'),
                    'grace_period_ends_at' => DB::raw("coalesce(grace_period_ends_at, '".now()->addDays(7)->toDateTimeString()."')"),
                    'updated_at' => now(),
                ]);

                return;
            }

            if (in_array($status, ['unpaid', 'paused', 'incomplete_expired', 'canceled'], true)) {
                DB::table('users')->where('id', $userId)->update([
                    'access_status' => in_array($status, ['canceled'], true) ? 'cancelled' : 'suspended',
                    'has_paid_access' => false,
                    'grace_period_ends_at' => null,
                    'next_retry_at' => null,
                    'provider_subscription_id' => $providerSubscriptionId ?: DB::raw('provider_subscription_id'),
                    'provider_customer_id' => $providerCustomerId ?: DB::raw('provider_customer_id'),
                    'access_expires_at' => $periodEnd ?: DB::raw('access_expires_at'),
                    'updated_at' => now(),
                ]);

                $this->createAccessNotification(
                    userId: $userId,
                    type: 'access_subscription_status_changed',
                    title: 'Actualizacion de suscripcion comercial',
                    message: $status === 'canceled'
                        ? 'Stripe notifico que la suscripcion comercial fue cancelada.'
                        : 'Stripe notifico que la suscripcion comercial quedo suspendida por falta de pago.',
                    data: [
                        'provider_subscription_id' => $providerSubscriptionId,
                        'provider_customer_id' => $providerCustomerId,
                        'stripe_status' => $status,
                        'cancelled_at' => $cancelledAt?->toIso8601String(),
                    ],
                );
            }

            return;
        }

        if (($metadata['billing_context'] ?? null) !== 'client_subscription') {
            return;
        }

        $providerSubscriptionId = (string) ($subscriptionPayload->id ?? '');
        $subscriptionRecordId = (int) ($metadata['subscription_record_id'] ?? 0);
        $subscription = $subscriptionRecordId > 0 ? Suscripcion::find($subscriptionRecordId) : null;

        if (! $subscription && $providerSubscriptionId !== '') {
            $subscription = Suscripcion::query()
                ->where('provider_subscription_id', $providerSubscriptionId)
                ->latest('id')
                ->first();
        }

        if (! $subscription) {
            return;
        }

        $status = (string) ($subscriptionPayload->status ?? 'active');
        $currentPeriodStart = ! empty($subscriptionPayload->current_period_start)
            ? Carbon::createFromTimestamp((int) $subscriptionPayload->current_period_start)->startOfDay()
            : $subscription->starts_at;
        $currentPeriodEnd = ! empty($subscriptionPayload->current_period_end)
            ? Carbon::createFromTimestamp((int) $subscriptionPayload->current_period_end)->endOfDay()
            : $subscription->ends_at;
        $cancelAt = ! empty($subscriptionPayload->cancel_at)
            ? Carbon::createFromTimestamp((int) $subscriptionPayload->cancel_at)->endOfDay()
            : null;

        $subscription->update([
            'status' => $status === 'canceled' ? 'cancelled' : ($status === 'active' ? 'active' : $status),
            'payment_status' => in_array($status, ['active', 'trialing'], true) ? 'paid' : ($status === 'past_due' ? 'failed' : $subscription->payment_status),
            'payment_provider' => 'stripe',
            'provider_subscription_id' => $providerSubscriptionId ?: $subscription->provider_subscription_id,
            'started_at' => $subscription->started_at ?: $currentPeriodStart,
            'starts_at' => $currentPeriodStart ?: $subscription->starts_at,
            'expires_at' => $currentPeriodEnd ?: $subscription->expires_at,
            'ends_at' => $currentPeriodEnd ?: $subscription->ends_at,
            'renews_at' => ($subscriptionPayload->cancel_at_period_end ?? false) ? $cancelAt : ($currentPeriodEnd ?: $subscription->renews_at),
            'cancelled_at' => $status === 'canceled'
                ? (! empty($subscriptionPayload->canceled_at)
                    ? Carbon::createFromTimestamp((int) $subscriptionPayload->canceled_at)
                    : now())
                : null,
        ]);
    }

    private function handleCustomerSubscriptionDeleted(object $subscriptionPayload): void
    {
        $metadata = $this->extractMetadata($subscriptionPayload);
        if ($this->isClientAccessSubscriptionBillingContext($metadata, (string) ($subscriptionPayload->id ?? ''), (string) ($subscriptionPayload->customer ?? ''))) {
            $providerSubscriptionId = (string) ($subscriptionPayload->id ?? '');
            $providerCustomerId = (string) ($subscriptionPayload->customer ?? '');
            $userId = $this->resolveAccessSubscriptionUserId($metadata, $providerSubscriptionId, $providerCustomerId);
            if ($userId <= 0) {
                return;
            }

            $endedAt = ! empty($subscriptionPayload->ended_at)
                ? Carbon::createFromTimestamp((int) $subscriptionPayload->ended_at)->endOfDay()
                : now();

            DB::table('users')->where('id', $userId)->update([
                'access_status' => 'cancelled',
                'has_paid_access' => false,
                'grace_period_ends_at' => null,
                'next_retry_at' => null,
                'provider_subscription_id' => $providerSubscriptionId ?: DB::raw('provider_subscription_id'),
                'provider_customer_id' => $providerCustomerId ?: DB::raw('provider_customer_id'),
                'access_expires_at' => $endedAt,
                'updated_at' => now(),
            ]);

            $payment = $this->findAccessPaymentForSubscription($userId, $providerSubscriptionId);
            if ($payment) {
                $payment->update([
                    'status' => 'cancelled',
                    'billing_period_end' => $endedAt->toDateString(),
                    'gateway_response' => [
                        'source' => 'customer_subscription_deleted',
                        'stripe_payload' => json_decode(json_encode($subscriptionPayload), true),
                    ],
                ]);
            }

            $this->createAccessNotification(
                userId: $userId,
                type: 'access_subscription_cancelled',
                title: 'Suscripcion comercial cancelada',
                message: 'Stripe confirmo la cancelacion de la suscripcion comercial.',
                data: [
                    'provider_subscription_id' => $providerSubscriptionId,
                    'provider_customer_id' => $providerCustomerId,
                    'ended_at' => $endedAt->toIso8601String(),
                ],
            );

            return;
        }

        if (($metadata['billing_context'] ?? null) !== 'client_subscription') {
            return;
        }

        $providerSubscriptionId = (string) ($subscriptionPayload->id ?? '');
        $subscriptionRecordId = (int) ($metadata['subscription_record_id'] ?? 0);
        $subscription = $subscriptionRecordId > 0 ? Suscripcion::find($subscriptionRecordId) : null;

        if (! $subscription && $providerSubscriptionId !== '') {
            $subscription = Suscripcion::query()
                ->where('provider_subscription_id', $providerSubscriptionId)
                ->latest('id')
                ->first();
        }

        if (! $subscription) {
            return;
        }

        $endedAt = ! empty($subscriptionPayload->ended_at)
            ? Carbon::createFromTimestamp((int) $subscriptionPayload->ended_at)->endOfDay()
            : now();

        $subscription->update([
            'status' => 'cancelled',
            'payment_status' => 'cancelled',
            'ends_at' => $endedAt,
            'expires_at' => $endedAt,
            'renews_at' => null,
            'cancelled_at' => ! empty($subscriptionPayload->canceled_at)
                ? Carbon::createFromTimestamp((int) $subscriptionPayload->canceled_at)
                : now(),
        ]);

        Pago::create([
            'user_id' => $subscription->user_id,
            'subscription_id' => $subscription->id,
            'payment_type' => 'subscription',
            'amount' => 0,
            'currency' => 'USD',
            'provider' => 'stripe',
            'transaction_reference' => $providerSubscriptionId ?: $subscription->provider_subscription_id,
            'status' => 'cancelled',
            'failure_reason' => 'Stripe notifico la cancelacion de la suscripcion.',
            'gateway_response' => [
                'source' => 'customer_subscription_deleted',
                'stripe_payload' => json_decode(json_encode($subscriptionPayload), true),
            ],
        ]);
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
            $periodStart = $payment->billing_period_start ?: now()->toDateString();
            $periodEnd = $payment->billing_period_end ?: now()->addMonthNoOverflow()->toDateString();
            $cardBrand = (string) (
                data_get($gatewayPayload, 'payment_method_details.card.brand')
                ?? data_get($gatewayPayload, 'charges.data.0.payment_method_details.card.brand')
                ?? ''
            );
            $cardLast4 = (string) (
                data_get($gatewayPayload, 'payment_method_details.card.last4')
                ?? data_get($gatewayPayload, 'charges.data.0.payment_method_details.card.last4')
                ?? ''
            );

            $payment->update([
                'status' => 'paid',
                'provider_payment_id' => $providerPaymentId ?: $payment->provider_payment_id,
                'provider_checkout_id' => $checkoutSessionId ?: $payment->provider_checkout_id,
                'billing_period_start' => $periodStart,
                'billing_period_end' => $periodEnd,
                'card_brand' => $cardBrand !== '' ? $cardBrand : $payment->card_brand,
                'card_last4' => $cardLast4 !== '' ? $cardLast4 : $payment->card_last4,
                'paid_at' => now(),
                'gateway_response' => $gatewayPayload,
            ]);

            DB::table('users')->where('id', $payment->user_id)->update([
                'access_status' => 'active',
                'has_paid_access' => true,
                'paid_access_at' => now(),
                'access_expires_at' => Carbon::parse($periodEnd)->endOfDay(),
                'access_payment_id' => $payment->id,
                'updated_at' => now(),
            ]);
        });
    }

    private function markClientSubscriptionPaid(
        int $subscriptionRecordId,
        string $providerSubscriptionId,
        string $providerPaymentId,
        float $amount,
        string $currency,
        array $gatewayPayload,
        ?string $periodStart,
        ?string $periodEnd,
    ): void {
        $subscription = $subscriptionRecordId > 0 ? Suscripcion::find($subscriptionRecordId) : null;
        if (! $subscription && $providerSubscriptionId !== '') {
            $subscription = Suscripcion::query()
                ->where('provider_subscription_id', $providerSubscriptionId)
                ->latest('id')
                ->first();
        }

        if (! $subscription) {
            return;
        }

        DB::transaction(function () use ($subscription, $providerSubscriptionId, $providerPaymentId, $amount, $currency, $gatewayPayload, $periodStart, $periodEnd) {
            $startsAt = $periodStart ? Carbon::parse($periodStart)->startOfDay() : now()->startOfDay();
            $endsAt = $periodEnd
                ? Carbon::parse($periodEnd)->endOfDay()
                : (($subscription->plan?->billing_cycle === 'yearly') ? now()->addYear()->endOfDay() : now()->addMonth()->endOfDay());

            $subscription->update([
                'status' => 'active',
                'payment_status' => 'paid',
                'payment_provider' => 'stripe',
                'provider_subscription_id' => $providerSubscriptionId ?: $subscription->provider_subscription_id,
                'started_at' => $subscription->started_at ?: $startsAt,
                'starts_at' => $startsAt,
                'expires_at' => $endsAt,
                'ends_at' => $endsAt,
                'renews_at' => $endsAt,
                'cancelled_at' => null,
            ]);

            $pendingPayment = Pago::query()
                ->where('subscription_id', $subscription->id)
                ->where('provider', 'stripe')
                ->where('payment_type', 'subscription')
                ->where('status', 'pending')
                ->latest('id')
                ->first();

            if ($pendingPayment) {
                $pendingPayment->update([
                    'amount' => $amount > 0 ? $amount : $pendingPayment->amount,
                    'currency' => $currency ?: $pendingPayment->currency,
                    'transaction_reference' => $providerSubscriptionId ?: $providerPaymentId ?: $pendingPayment->transaction_reference,
                    'stripe_payment_intent_id' => $providerPaymentId ?: $pendingPayment->stripe_payment_intent_id,
                    'status' => 'paid',
                    'paid_at' => now(),
                    'failure_reason' => null,
                    'gateway_response' => $gatewayPayload,
                ]);
            } else {
                Pago::create([
                    'user_id' => $subscription->user_id,
                    'subscription_id' => $subscription->id,
                    'payment_type' => 'subscription',
                    'amount' => $amount > 0 ? $amount : (float) ($subscription->plan?->amount ?: $subscription->plan?->price_monthly ?: $subscription->plan?->price ?: 0),
                    'currency' => $currency ?: strtoupper((string) ($subscription->plan?->currency ?: 'USD')),
                    'provider' => 'stripe',
                    'transaction_reference' => $providerSubscriptionId ?: $providerPaymentId,
                    'stripe_payment_intent_id' => $providerPaymentId ?: null,
                    'status' => 'paid',
                    'paid_at' => now(),
                    'gateway_response' => $gatewayPayload,
                ]);
            }
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
            'access_status' => 'payment_failed',
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

    private function isClientAccessSubscriptionBillingContext(array $metadata, string $providerSubscriptionId = '', string $providerCustomerId = ''): bool
    {
        if (($metadata['billing_context'] ?? null) === 'client_access_subscription') {
            return true;
        }

        if ($providerSubscriptionId === '' && $providerCustomerId === '') {
            return false;
        }

        return Usuario::query()
            ->when($providerSubscriptionId !== '', fn ($query) => $query->where('provider_subscription_id', $providerSubscriptionId))
            ->when($providerCustomerId !== '', fn ($query) => $query->orWhere('provider_customer_id', $providerCustomerId))
            ->exists();
    }

    private function findAccessPaymentForSubscription(int $userId, string $providerSubscriptionId = '', string $providerInvoiceId = ''): ?AccessPayment
    {
        return AccessPayment::query()
            ->where('user_id', $userId)
            ->when($providerInvoiceId !== '', fn ($query) => $query->where('provider_invoice_id', $providerInvoiceId))
            ->when($providerInvoiceId === '' && $providerSubscriptionId !== '', fn ($query) => $query->where('provider_subscription_id', $providerSubscriptionId))
            ->latest('id')
            ->first()
            ?: AccessPayment::query()
                ->where('user_id', $userId)
                ->latest('id')
                ->first();
    }

    private function resolveAccessSubscriptionUserId(array $metadata, string $providerSubscriptionId = '', string $providerCustomerId = ''): int
    {
        $userId = (int) ($metadata['user_id'] ?? 0);
        if ($userId > 0) {
            return $userId;
        }

        if ($providerSubscriptionId === '' && $providerCustomerId === '') {
            return 0;
        }

        return (int) Usuario::query()
            ->when($providerSubscriptionId !== '', fn ($query) => $query->where('provider_subscription_id', $providerSubscriptionId))
            ->when($providerCustomerId !== '', fn ($query) => $query->orWhere('provider_customer_id', $providerCustomerId))
            ->value('id');
    }

    private function syncClientAccessSubscriptionPaidState(
        AccessPayment $payment,
        string $providerSubscriptionId,
        string $providerCustomerId,
        string $providerInvoiceId,
        string $providerPaymentId,
        float $amount,
        string $currency,
        string $periodStart,
        string $periodEnd,
        array $gatewayPayload,
    ): void {
        DB::transaction(function () use ($payment, $providerSubscriptionId, $providerCustomerId, $providerInvoiceId, $providerPaymentId, $amount, $currency, $periodStart, $periodEnd, $gatewayPayload) {
            $payment->update([
                'status' => 'paid',
                'provider_subscription_id' => $providerSubscriptionId ?: $payment->provider_subscription_id,
                'provider_customer_id' => $providerCustomerId ?: $payment->provider_customer_id,
                'provider_invoice_id' => $providerInvoiceId ?: $payment->provider_invoice_id,
                'provider_payment_id' => $providerPaymentId ?: $payment->provider_payment_id,
                'amount' => $amount > 0 ? $amount : $payment->amount,
                'currency' => $currency ?: $payment->currency,
                'billing_period_start' => $periodStart,
                'billing_period_end' => $periodEnd,
                'failure_reason' => null,
                'grace_period_ends_at' => null,
                'paid_at' => now(),
                'gateway_response' => $gatewayPayload,
            ]);

            DB::table('users')->where('id', $payment->user_id)->update([
                'access_status' => 'active',
                'has_paid_access' => true,
                'paid_access_at' => now(),
                'access_expires_at' => Carbon::parse($periodEnd)->endOfDay(),
                'grace_period_ends_at' => null,
                'next_retry_at' => null,
                'provider_subscription_id' => $providerSubscriptionId ?: DB::raw('provider_subscription_id'),
                'provider_customer_id' => $providerCustomerId ?: DB::raw('provider_customer_id'),
                'access_payment_id' => $payment->id,
                'updated_at' => now(),
            ]);
        });
    }

    private function createAccessNotification(int $userId, string $type, string $title, string $message, array $data = []): void
    {
        if ($userId <= 0) {
            return;
        }

        Notificacion::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
        ]);
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
