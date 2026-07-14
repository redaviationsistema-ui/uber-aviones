<?php

namespace App\Servicios\Billing;

use App\Modelos\Aeronave;
use App\Modelos\AircraftBillingPayment;
use App\Modelos\SuscripcionAeronave;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProviderAircraftSubscriptionService
{
    public const ACTIVE_STRIPE_STATUSES = ['active', 'trialing'];
    public const INACTIVE_STRIPE_STATUSES = ['incomplete', 'incomplete_expired', 'past_due', 'unpaid', 'canceled', 'cancelled', 'paused', 'expired'];

    public function syncPaidSubscription(
        AircraftBillingPayment $payment,
        string $providerPaymentId,
        string $providerCustomerId,
        string $providerInvoiceId,
        string $checkoutSessionId,
        string $subscriptionId,
        float $amount,
        string $currency,
        array $gatewayPayload,
        ?string $periodStart,
        ?string $periodEnd,
        int $userId,
    ): void {
        DB::transaction(function () use ($payment, $providerPaymentId, $providerCustomerId, $providerInvoiceId, $checkoutSessionId, $subscriptionId, $amount, $currency, $gatewayPayload, $periodStart, $periodEnd, $userId) {
            $startsAt = $periodStart ? Carbon::parse($periodStart)->startOfDay() : now();
            $endsAt = $periodEnd ? Carbon::parse($periodEnd)->endOfDay() : now()->addMonth()->endOfDay();

            $payment->update([
                'status' => 'paid',
                'provider_payment_id' => $providerPaymentId ?: $payment->provider_payment_id,
                'provider_customer_id' => $providerCustomerId ?: $payment->provider_customer_id,
                'provider_invoice_id' => $providerInvoiceId ?: $payment->provider_invoice_id,
                'provider_checkout_id' => $checkoutSessionId ?: $payment->provider_checkout_id,
                'provider_subscription_id' => $subscriptionId ?: $payment->provider_subscription_id,
                'amount' => $amount > 0 ? $amount : $payment->amount,
                'currency' => $currency ?: $payment->currency,
                'billing_period_start' => $periodStart ?: $payment->billing_period_start,
                'billing_period_end' => $periodEnd ?: $payment->billing_period_end,
                'paid_at' => now(),
                'gateway_response' => $gatewayPayload,
            ]);

            $this->updateAircraftState(
                aircraftId: (int) $payment->aircraft_id,
                billingPlanId: (int) $payment->billing_plan_id,
                billingStatus: 'active',
                subscriptionStatus: 'active',
                status: $this->canAircraftBeActive('active', $endsAt) ? 'active' : 'inactive',
                startsAt: $startsAt,
                endsAt: $endsAt,
                lastPaymentAt: now(),
            );

            $this->upsertSubscriptionRecord(
                payment: $payment,
                userId: $userId,
                status: 'active',
                paymentReference: $subscriptionId ?: $payment->provider_subscription_id,
                providerCheckoutId: $checkoutSessionId ?: $payment->provider_checkout_id,
                providerSubscriptionId: $subscriptionId ?: $payment->provider_subscription_id,
                providerCustomerId: $providerCustomerId ?: $payment->provider_customer_id,
                providerInvoiceId: $providerInvoiceId ?: $payment->provider_invoice_id,
                paidAt: now(),
                startsAt: $startsAt,
                endsAt: $endsAt,
                cancelledAt: null,
            );
        });
    }

    public function syncCheckoutState(
        AircraftBillingPayment $payment,
        array $gatewayPayload,
        string $providerCheckoutId,
        string $providerSubscriptionId,
        string $providerCustomerId,
        string $providerInvoiceId,
        string $providerPaymentId,
        string $subscriptionStatus,
        int $userId,
    ): void {
        DB::transaction(function () use ($payment, $gatewayPayload, $providerCheckoutId, $providerSubscriptionId, $providerCustomerId, $providerInvoiceId, $providerPaymentId, $subscriptionStatus, $userId) {
            $normalizedSubscriptionStatus = $this->normalizeStripeStatus($subscriptionStatus, 'pending');
            $paymentStatus = in_array($normalizedSubscriptionStatus, ['past_due', 'unpaid', 'incomplete', 'incomplete_expired', 'canceled', 'cancelled', 'paused'], true)
                ? ($normalizedSubscriptionStatus === 'canceled' ? 'cancelled' : $normalizedSubscriptionStatus)
                : 'pending';

            $payment->update([
                'status' => $paymentStatus,
                'provider_payment_id' => $providerPaymentId ?: $payment->provider_payment_id,
                'provider_customer_id' => $providerCustomerId ?: $payment->provider_customer_id,
                'provider_invoice_id' => $providerInvoiceId ?: $payment->provider_invoice_id,
                'provider_checkout_id' => $providerCheckoutId ?: $payment->provider_checkout_id,
                'provider_subscription_id' => $providerSubscriptionId ?: $payment->provider_subscription_id,
                'gateway_response' => $gatewayPayload,
            ]);

            $this->updateAircraftState(
                aircraftId: (int) $payment->aircraft_id,
                billingPlanId: (int) $payment->billing_plan_id,
                billingStatus: $this->resolveBillingStatusFromSubscriptionStatus($normalizedSubscriptionStatus),
                subscriptionStatus: $normalizedSubscriptionStatus,
                status: 'inactive',
            );

            $this->upsertSubscriptionRecord(
                payment: $payment,
                userId: $userId,
                status: $normalizedSubscriptionStatus,
                paymentReference: $providerSubscriptionId ?: ($providerPaymentId ?: $providerCheckoutId),
                providerCheckoutId: $providerCheckoutId ?: $payment->provider_checkout_id,
                providerSubscriptionId: $providerSubscriptionId ?: $payment->provider_subscription_id,
                providerCustomerId: $providerCustomerId ?: $payment->provider_customer_id,
                providerInvoiceId: $providerInvoiceId ?: $payment->provider_invoice_id,
            );
        });
    }

    public function syncFailedSubscription(
        ?AircraftBillingPayment $payment,
        int $aircraftId,
        string $subscriptionStatus,
        array $gatewayPayload,
        string $providerSubscriptionId = '',
        string $providerCustomerId = '',
        string $providerInvoiceId = '',
    ): void {
        if (! $payment && $aircraftId <= 0) {
            return;
        }

        DB::transaction(function () use ($payment, $aircraftId, $subscriptionStatus, $gatewayPayload, $providerSubscriptionId, $providerCustomerId, $providerInvoiceId) {
            $normalizedSubscriptionStatus = $this->normalizeStripeStatus($subscriptionStatus, 'past_due');

            if ($payment) {
                $payment->update([
                    'status' => $normalizedSubscriptionStatus === 'canceled' ? 'cancelled' : $normalizedSubscriptionStatus,
                    'provider_subscription_id' => $providerSubscriptionId ?: $payment->provider_subscription_id,
                    'provider_customer_id' => $providerCustomerId ?: $payment->provider_customer_id,
                    'provider_invoice_id' => $providerInvoiceId ?: $payment->provider_invoice_id,
                    'gateway_response' => $gatewayPayload,
                ]);
            }

            $resolvedAircraftId = $aircraftId > 0 ? $aircraftId : (int) $payment?->aircraft_id;
            $resolvedPlanId = (int) ($payment?->billing_plan_id ?? DB::table('aircraft')->where('id', $resolvedAircraftId)->value('billing_plan_id'));

            if ($resolvedAircraftId > 0) {
                $this->updateAircraftState(
                    aircraftId: $resolvedAircraftId,
                    billingPlanId: $resolvedPlanId,
                    billingStatus: $this->resolveBillingStatusFromSubscriptionStatus($normalizedSubscriptionStatus),
                    subscriptionStatus: $normalizedSubscriptionStatus === 'canceled' ? 'cancelled' : $normalizedSubscriptionStatus,
                    status: 'inactive',
                );
            }

            if ($payment) {
                $this->upsertSubscriptionRecord(
                    payment: $payment,
                    userId: 0,
                    status: $normalizedSubscriptionStatus === 'canceled' ? 'cancelled' : $normalizedSubscriptionStatus,
                    paymentReference: $providerSubscriptionId ?: ($payment->provider_subscription_id ?: $payment->provider_payment_id),
                    providerCheckoutId: $payment->provider_checkout_id,
                    providerSubscriptionId: $providerSubscriptionId ?: $payment->provider_subscription_id,
                    providerCustomerId: $providerCustomerId ?: $payment->provider_customer_id,
                    providerInvoiceId: $providerInvoiceId ?: $payment->provider_invoice_id,
                    cancelledAt: in_array($normalizedSubscriptionStatus, ['cancelled', 'canceled'], true) ? now() : null,
                );
            }
        });
    }

    public function syncSubscriptionUpdate(
        AircraftBillingPayment $payment,
        string $stripeStatus,
        string $customerId,
        ?string $periodStart,
        ?string $periodEnd,
        bool $cancelAtPeriodEnd = false,
        ?Carbon $cancelledAt = null,
        array $payload = [],
        int $userId = 0,
    ): void {
        $normalizedStatus = $this->normalizeStripeStatus($stripeStatus, 'pending');
        $startsAt = $periodStart ? Carbon::parse($periodStart)->startOfDay() : null;
        $endsAt = $periodEnd ? Carbon::parse($periodEnd)->endOfDay() : null;
        $aircraftStatus = $this->canAircraftBeActive($normalizedStatus, $endsAt) ? 'active' : 'inactive';

        DB::transaction(function () use ($payment, $normalizedStatus, $customerId, $startsAt, $endsAt, $cancelAtPeriodEnd, $cancelledAt, $payload, $userId, $aircraftStatus) {
            if ($endsAt && in_array($normalizedStatus, self::ACTIVE_STRIPE_STATUSES, true) && $this->canAircraftBeActive($normalizedStatus, $endsAt)) {
                $payment->update([
                    'status' => 'paid',
                    'provider_customer_id' => $customerId ?: $payment->provider_customer_id,
                    'billing_period_start' => $startsAt?->toDateString() ?: $payment->billing_period_start,
                    'billing_period_end' => $endsAt?->toDateString() ?: $payment->billing_period_end,
                    'gateway_response' => $payload !== [] ? $payload : $payment->gateway_response,
                    'paid_at' => $payment->paid_at ?: now(),
                ]);
            } else {
                $payment->update([
                    'status' => $normalizedStatus === 'canceled' ? 'cancelled' : $normalizedStatus,
                    'provider_customer_id' => $customerId ?: $payment->provider_customer_id,
                    'billing_period_start' => $startsAt?->toDateString() ?: $payment->billing_period_start,
                    'billing_period_end' => $endsAt?->toDateString() ?: $payment->billing_period_end,
                    'gateway_response' => $payload !== [] ? $payload : $payment->gateway_response,
                ]);
            }

            $this->updateAircraftState(
                aircraftId: (int) $payment->aircraft_id,
                billingPlanId: (int) $payment->billing_plan_id,
                billingStatus: $aircraftStatus === 'active' ? 'active' : $this->resolveBillingStatusFromSubscriptionStatus($normalizedStatus),
                subscriptionStatus: $normalizedStatus === 'canceled' ? 'cancelled' : $normalizedStatus,
                status: $aircraftStatus,
                startsAt: $startsAt,
                endsAt: $endsAt,
                lastPaymentAt: $aircraftStatus === 'active' ? now() : null,
            );

            $this->upsertSubscriptionRecord(
                payment: $payment,
                userId: $userId,
                status: $normalizedStatus === 'canceled' ? 'cancelled' : $normalizedStatus,
                paymentReference: $payment->provider_subscription_id ?: $payment->provider_payment_id,
                providerCheckoutId: $payment->provider_checkout_id,
                providerSubscriptionId: $payment->provider_subscription_id,
                providerCustomerId: $customerId ?: $payment->provider_customer_id,
                providerInvoiceId: $payment->provider_invoice_id,
                paidAt: $aircraftStatus === 'active' ? ($payment->paid_at ?: now()) : null,
                startsAt: $startsAt,
                endsAt: $endsAt,
                cancelledAt: $cancelledAt,
                metadata: [
                    'cancel_at_period_end' => $cancelAtPeriodEnd,
                ],
            );
        });
    }

    public function expireLapsedSubscriptions(?int $providerId = null): int
    {
        $processed = 0;

        $query = SuscripcionAeronave::query()
            ->with('aircraft:id,provider_id,status,billing_status,billing_plan_id,subscription_status,subscription_started_at,subscription_ends_at,last_payment_at')
            ->whereIn('status', ['active', 'trialing'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now())
            ->when($providerId, function ($builder) use ($providerId) {
                $builder->whereHas('aircraft', fn ($query) => $query->where('provider_id', $providerId));
            })
            ->orderBy('id');

        $query->chunkById(100, function ($subscriptions) use (&$processed) {
            foreach ($subscriptions as $subscription) {
                DB::transaction(function () use ($subscription, &$processed) {
                    $locked = SuscripcionAeronave::query()->lockForUpdate()->find($subscription->id);
                    if (! $locked || ! $locked->ends_at || $locked->ends_at->isFuture()) {
                        return;
                    }

                    $aircraft = Aeronave::query()->lockForUpdate()->find($locked->aircraft_id);

                    $locked->update([
                        'status' => 'expired',
                    ]);

                    $latestPayment = AircraftBillingPayment::query()
                        ->where('aircraft_id', $locked->aircraft_id)
                        ->where('billing_plan_id', $locked->plan_id)
                        ->latest('id')
                        ->first();

                    if ($latestPayment && ! in_array((string) $latestPayment->status, ['paid', 'failed', 'cancelled', 'expired'], true)) {
                        $latestPayment->update([
                            'status' => 'expired',
                        ]);
                    }

                    if ($aircraft) {
                        $this->updateAircraftState(
                            aircraftId: (int) $aircraft->id,
                            billingPlanId: (int) $aircraft->billing_plan_id,
                            billingStatus: 'expired',
                            subscriptionStatus: 'expired',
                            status: 'inactive',
                            startsAt: $aircraft->subscription_started_at,
                            endsAt: $locked->ends_at,
                        );
                    }

                    Log::info('Suscripcion por aeronave expirada automaticamente.', [
                        'aircraft_subscription_id' => $locked->id,
                        'aircraft_id' => $locked->aircraft_id,
                        'provider_subscription_id' => $locked->provider_subscription_id,
                        'ended_at' => optional($locked->ends_at)->toIso8601String(),
                    ]);

                    $processed++;
                });
            }
        });

        Aeronave::query()
            ->whereIn('subscription_status', ['active', 'trialing'])
            ->whereNotNull('subscription_ends_at')
            ->where('subscription_ends_at', '<', now())
            ->when($providerId, fn ($query) => $query->where('provider_id', $providerId))
            ->orderBy('id')
            ->chunkById(100, function ($aircraftBatch) use (&$processed) {
                foreach ($aircraftBatch as $aircraft) {
                    DB::transaction(function () use ($aircraft, &$processed) {
                        $lockedAircraft = Aeronave::query()->lockForUpdate()->find($aircraft->id);
                        if (! $lockedAircraft || ! $lockedAircraft->subscription_ends_at || $lockedAircraft->subscription_ends_at->isFuture()) {
                            return;
                        }

                        $this->updateAircraftState(
                            aircraftId: (int) $lockedAircraft->id,
                            billingPlanId: (int) $lockedAircraft->billing_plan_id,
                            billingStatus: 'expired',
                            subscriptionStatus: 'expired',
                            status: 'inactive',
                            startsAt: $lockedAircraft->subscription_started_at,
                            endsAt: $lockedAircraft->subscription_ends_at,
                        );

                        SuscripcionAeronave::query()
                            ->where('aircraft_id', $lockedAircraft->id)
                            ->whereIn('status', ['active', 'trialing'])
                            ->update([
                                'status' => 'expired',
                                'updated_at' => now(),
                            ]);

                        $latestPayment = AircraftBillingPayment::query()
                            ->where('aircraft_id', $lockedAircraft->id)
                            ->latest('id')
                            ->first();

                        if ($latestPayment && ! in_array((string) $latestPayment->status, ['failed', 'cancelled', 'expired'], true)) {
                            $latestPayment->update(['status' => 'expired']);
                        }

                        Log::info('Aeronave desactivada automaticamente por suscripcion vencida.', [
                            'aircraft_id' => $lockedAircraft->id,
                            'subscription_ends_at' => optional($lockedAircraft->subscription_ends_at)->toIso8601String(),
                        ]);

                        $processed++;
                    });
                }
            });

        return $processed;
    }

    public function syncAircraftStateIfExpired(Aeronave $aircraft): bool
    {
        $endsAt = $aircraft->subscription_ends_at;
        $subscriptionStatus = $this->normalizeStripeStatus((string) $aircraft->subscription_status, 'inactive');
        $shouldBeInactive = ! $this->canAircraftBeActive($subscriptionStatus, $endsAt);
        $isCurrentlyMarkedActive = in_array((string) $aircraft->subscription_status, ['active', 'trialing'], true)
            || in_array((string) $aircraft->billing_status, ['active'], true)
            || (string) $aircraft->status === 'active';

        if (! $shouldBeInactive || ! $isCurrentlyMarkedActive) {
            return false;
        }

        DB::transaction(function () use ($aircraft, $subscriptionStatus, $endsAt) {
            $lockedAircraft = Aeronave::query()->lockForUpdate()->find($aircraft->id);
            if (! $lockedAircraft) {
                return;
            }

            if ($this->canAircraftBeActive((string) $lockedAircraft->subscription_status, $lockedAircraft->subscription_ends_at)) {
                return;
            }

            $newSubscriptionStatus = $lockedAircraft->subscription_ends_at && $lockedAircraft->subscription_ends_at->isPast()
                ? 'expired'
                : ($subscriptionStatus === 'inactive' ? 'inactive' : $subscriptionStatus);
            $newBillingStatus = $lockedAircraft->subscription_ends_at && $lockedAircraft->subscription_ends_at->isPast()
                ? 'expired'
                : $this->resolveBillingStatusFromSubscriptionStatus($newSubscriptionStatus);

            $this->updateAircraftState(
                aircraftId: (int) $lockedAircraft->id,
                billingPlanId: (int) $lockedAircraft->billing_plan_id,
                billingStatus: $newBillingStatus,
                subscriptionStatus: $newSubscriptionStatus,
                status: 'inactive',
                startsAt: $lockedAircraft->subscription_started_at,
                endsAt: $endsAt,
            );

            SuscripcionAeronave::query()
                ->where('aircraft_id', $lockedAircraft->id)
                ->whereIn('status', ['active', 'trialing'])
                ->when($lockedAircraft->subscription_ends_at, fn ($query) => $query->where('ends_at', '<=', $lockedAircraft->subscription_ends_at))
                ->update([
                    'status' => $newSubscriptionStatus,
                    'updated_at' => now(),
                ]);
        });

        return true;
    }

    public function canAircraftBeActive(string $stripeStatus, mixed $endsAt): bool
    {
        $normalized = $this->normalizeStripeStatus($stripeStatus, 'inactive');
        if (! in_array($normalized, self::ACTIVE_STRIPE_STATUSES, true)) {
            return false;
        }

        if ($endsAt === null) {
            return false;
        }

        $resolvedEndsAt = $endsAt instanceof Carbon ? $endsAt : Carbon::parse($endsAt);

        return $resolvedEndsAt->greaterThanOrEqualTo(now());
    }

    public function normalizeStripeStatus(string $status, string $default = 'pending'): string
    {
        $normalized = trim(strtolower($status));
        if ($normalized === '') {
            return $default;
        }

        return $normalized === 'canceled' ? 'cancelled' : $normalized;
    }

    private function resolveBillingStatusFromSubscriptionStatus(string $subscriptionStatus): string
    {
        return match ($this->normalizeStripeStatus($subscriptionStatus, 'pending')) {
            'active', 'trialing' => 'active',
            'past_due', 'unpaid' => 'past_due',
            'cancelled' => 'cancelled',
            'expired' => 'expired',
            'incomplete', 'incomplete_expired', 'paused' => 'pending_payment',
            default => 'pending_payment',
        };
    }

    private function updateAircraftState(
        int $aircraftId,
        int $billingPlanId,
        string $billingStatus,
        string $subscriptionStatus,
        string $status,
        mixed $startsAt = null,
        mixed $endsAt = null,
        mixed $lastPaymentAt = null,
    ): void {
        if ($aircraftId <= 0) {
            return;
        }

        DB::table('aircraft')->where('id', $aircraftId)->update([
            'billing_status' => $billingStatus,
            'billing_plan_id' => $billingPlanId > 0 ? $billingPlanId : DB::raw('billing_plan_id'),
            'subscription_status' => $subscriptionStatus,
            'subscription_started_at' => $startsAt ?? DB::raw('subscription_started_at'),
            'subscription_ends_at' => $endsAt ?? DB::raw('subscription_ends_at'),
            'last_payment_at' => $lastPaymentAt ?? DB::raw('last_payment_at'),
            'status' => $status,
            'updated_at' => now(),
        ]);
    }

    private function upsertSubscriptionRecord(
        AircraftBillingPayment $payment,
        int $userId,
        string $status,
        ?string $paymentReference = null,
        ?string $providerCheckoutId = null,
        ?string $providerSubscriptionId = null,
        ?string $providerCustomerId = null,
        ?string $providerInvoiceId = null,
        mixed $paidAt = null,
        mixed $startsAt = null,
        mixed $endsAt = null,
        mixed $cancelledAt = null,
        array $metadata = [],
    ): void {
        $resolvedUserId = $userId > 0 ? $userId : DB::table('providers')->where('id', $payment->provider_id)->value('user_id');

        $attributes = [
            'aircraft_id' => $payment->aircraft_id,
            'plan_id' => $payment->billing_plan_id,
        ];

        $values = [
            'user_id' => $resolvedUserId,
            'status' => $status,
            'payment_provider' => 'stripe',
            'payment_reference' => $paymentReference,
            'provider_checkout_id' => $providerCheckoutId,
            'provider_subscription_id' => $providerSubscriptionId,
            'provider_customer_id' => $providerCustomerId,
            'provider_invoice_id' => $providerInvoiceId,
            'paid_at' => $paidAt,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'cancelled_at' => $cancelledAt,
            'updated_at' => now(),
        ];

        $subscription = SuscripcionAeronave::updateOrCreate($attributes, array_filter($values, function ($value, $key) {
            return $value !== null || in_array($key, ['paid_at', 'starts_at', 'ends_at', 'cancelled_at'], true);
        }, ARRAY_FILTER_USE_BOTH));

        if (array_key_exists('cancel_at_period_end', $metadata)) {
            $response = is_array($payment->gateway_response) ? $payment->gateway_response : [];
            $response['cancel_at_period_end'] = (bool) $metadata['cancel_at_period_end'];
            $payment->update(['gateway_response' => $response]);
        }
    }
}
