<?php

namespace App\Servicios\Billing;

use App\Modelos\Aeronave;
use App\Modelos\AircraftBillingPayment;
use App\Modelos\DocumentoAeronave;
use App\Modelos\SuscripcionAeronave;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProviderAircraftSubscriptionService
{
    public const ACTIVE_STRIPE_STATUSES = ['active', 'trialing'];
    public const INACTIVE_STRIPE_STATUSES = ['incomplete', 'incomplete_expired', 'past_due', 'unpaid', 'canceled', 'cancelled', 'paused', 'expired'];
    private const APPROVED_DOCUMENT_STATUSES = ['approved', 'aprobado', 'aprobada', 'validated', 'validado', 'validada', 'vigente'];
    private const REJECTED_DOCUMENT_STATUSES = ['rejected', 'rechazado', 'rechazada', 'cancelled', 'canceled', 'cancelado', 'cancelada'];
    private const AIRCRAFT_REQUIRED_DOCUMENTS = [
        [
            'key' => 'airworthiness_certificate',
            'label' => 'certificado de aeronavegabilidad',
            'aliases' => ['airworthiness_certificate', 'airworthiness', 'certificate_of_airworthiness', 'proof_of_airworthiness'],
        ],
        [
            'key' => 'registration',
            'label' => 'matricula vigente',
            'aliases' => ['registration', 'registration_certificate', 'aircraft_registration'],
        ],
        [
            'key' => 'insurance',
            'label' => 'poliza de seguro',
            'aliases' => ['insurance', 'insurance_policy', 'aircraft_insurance'],
        ],
        [
            'key' => 'maintenance',
            'label' => 'programa o respaldo de mantenimiento',
            'aliases' => ['maintenance', 'maintenance_program', 'maintenance_log', 'maintenance_records'],
        ],
    ];

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
            $activation = $this->getAircraftActivationEvaluation((int) $payment->aircraft_id, 'active', $endsAt);

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
                status: $activation['status'],
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

            Log::info('Pago de aeronave sincronizado.', [
                'aircraft_id' => $payment->aircraft_id,
                'payment_id' => $payment->id,
                'provider_subscription_id' => $subscriptionId ?: $payment->provider_subscription_id,
                'activation_code' => $activation['code'],
                'missing_requirements' => $activation['missing_requirements'],
            ]);
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
        $activation = ($endsAt && in_array($normalizedStatus, self::ACTIVE_STRIPE_STATUSES, true))
            ? $this->getAircraftActivationEvaluation((int) $payment->aircraft_id, $normalizedStatus, $endsAt)
            : null;
        $aircraftStatus = $activation['status'] ?? 'inactive';

        DB::transaction(function () use ($payment, $normalizedStatus, $customerId, $startsAt, $endsAt, $cancelAtPeriodEnd, $cancelledAt, $payload, $userId, $aircraftStatus, $activation) {
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
                billingStatus: $this->canAircraftBeActive($normalizedStatus, $endsAt) ? 'active' : $this->resolveBillingStatusFromSubscriptionStatus($normalizedStatus),
                subscriptionStatus: $normalizedStatus === 'canceled' ? 'cancelled' : $normalizedStatus,
                status: $aircraftStatus,
                startsAt: $startsAt,
                endsAt: $endsAt,
                lastPaymentAt: $this->canAircraftBeActive($normalizedStatus, $endsAt) ? now() : null,
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
                paidAt: $this->canAircraftBeActive($normalizedStatus, $endsAt) ? ($payment->paid_at ?: now()) : null,
                startsAt: $startsAt,
                endsAt: $endsAt,
                cancelledAt: $cancelledAt,
                metadata: [
                    'cancel_at_period_end' => $cancelAtPeriodEnd,
                ],
            );

            if ($activation) {
                Log::info('Suscripcion de aeronave reevaluada.', [
                    'aircraft_id' => $payment->aircraft_id,
                    'payment_id' => $payment->id,
                    'activation_code' => $activation['code'],
                    'missing_requirements' => $activation['missing_requirements'],
                ]);
            }
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

    public function syncAircraftActivationRequirements(Aeronave $aircraft): bool
    {
        if (! $this->canAircraftBeActive((string) $aircraft->subscription_status, $aircraft->subscription_ends_at)) {
            return false;
        }

        $evaluation = $this->getAircraftActivationEvaluation($aircraft, (string) $aircraft->subscription_status, $aircraft->subscription_ends_at);
        $normalizedSubscriptionStatus = $this->normalizeStripeStatus((string) $aircraft->subscription_status, 'active');

        $needsSync =
            (string) $aircraft->status !== $evaluation['status']
            || (string) $aircraft->billing_status !== 'active'
            || $normalizedSubscriptionStatus !== (string) $aircraft->subscription_status;

        if (! $needsSync) {
            return false;
        }

        $this->updateAircraftState(
            aircraftId: (int) $aircraft->id,
            billingPlanId: (int) $aircraft->billing_plan_id,
            billingStatus: 'active',
            subscriptionStatus: $normalizedSubscriptionStatus,
            status: $evaluation['status'],
            startsAt: $aircraft->subscription_started_at,
            endsAt: $aircraft->subscription_ends_at,
            lastPaymentAt: $aircraft->last_payment_at,
        );

        Log::info('Estado comercial de aeronave reevaluado.', [
            'aircraft_id' => $aircraft->id,
            'activation_code' => $evaluation['code'],
            'missing_requirements' => $evaluation['missing_requirements'],
        ]);

        return true;
    }

    public function buildAircraftBillingSnapshots(iterable $aircraftCollection): array
    {
        $aircraftItems = collect($aircraftCollection)
            ->filter(fn ($aircraft) => $aircraft instanceof Aeronave)
            ->values();

        if ($aircraftItems->isEmpty()) {
            return [];
        }

        $aircraftIds = $aircraftItems
            ->map(fn (Aeronave $aircraft) => (int) $aircraft->id)
            ->filter(fn (int $aircraftId) => $aircraftId > 0)
            ->values();

        $latestPayments = AircraftBillingPayment::query()
            ->whereIn('aircraft_id', $aircraftIds)
            ->orderByDesc('id')
            ->get()
            ->groupBy('aircraft_id')
            ->map(fn ($payments) => $payments->first());

        $latestSubscriptions = SuscripcionAeronave::query()
            ->whereIn('aircraft_id', $aircraftIds)
            ->orderByDesc('id')
            ->get()
            ->groupBy('aircraft_id')
            ->map(fn ($subscriptions) => $subscriptions->first());

        return $aircraftItems
            ->mapWithKeys(function (Aeronave $aircraft) use ($latestPayments, $latestSubscriptions) {
                $aircraftId = (int) $aircraft->id;

                return [$aircraftId => $this->buildAircraftBillingSnapshot(
                    aircraft: $aircraft,
                    latestPayment: $latestPayments->get($aircraftId),
                    latestSubscription: $latestSubscriptions->get($aircraftId),
                )];
            })
            ->all();
    }

    public function buildAircraftBillingSnapshot(
        Aeronave $aircraft,
        ?AircraftBillingPayment $latestPayment = null,
        ?SuscripcionAeronave $latestSubscription = null,
    ): array {
        $paymentStatus = $this->normalizeValue($latestPayment?->status);
        $subscriptionStatus = $this->normalizeStripeStatus(
            (string) ($latestSubscription?->status ?: $aircraft->subscription_status ?: 'inactive'),
            'inactive',
        );
        $checkoutSessionId = (string) ($latestPayment?->provider_checkout_id ?? '');
        $stripeSubscriptionId = (string) ($latestPayment?->provider_subscription_id ?: $latestSubscription?->provider_subscription_id ?: '');
        $subscriptionEndsAt = $latestSubscription?->ends_at ?: $aircraft->subscription_ends_at;
        $lastPaymentAt = $latestPayment?->paid_at ?: $latestSubscription?->paid_at ?: $aircraft->last_payment_at;
        $hasPaymentRecord = $latestPayment instanceof AircraftBillingPayment || $latestSubscription instanceof SuscripcionAeronave;
        $hasActiveSubscription = $this->canAircraftBeActive($subscriptionStatus, $subscriptionEndsAt);
        $hasPendingCheckout = $checkoutSessionId !== ''
            && in_array($paymentStatus, ['pending', 'open', 'requires_action', 'pending_payment', 'incomplete'], true);
        $hasExpiredSubscription = in_array($subscriptionStatus, ['past_due', 'unpaid', 'expired', 'incomplete_expired'], true);
        $hasCancelledSubscription = in_array($subscriptionStatus, ['cancelled', 'paused'], true);
        $hasFailedPayment = in_array($paymentStatus, ['failed', 'rejected'], true);
        $needsVerification = ! $hasActiveSubscription
            && ! $hasPendingCheckout
            && (
                $paymentStatus === 'paid'
                || ($checkoutSessionId !== '' && $stripeSubscriptionId !== '')
                || ($checkoutSessionId !== '' && in_array($this->normalizeValue($aircraft->billing_status), ['pending_payment', 'pending', 'processing', 'active'], true))
            );
        $activationEvaluation = $hasActiveSubscription
            ? $this->getAircraftActivationEvaluation($aircraft, $subscriptionStatus, $subscriptionEndsAt)
            : null;
        $canOperate = $hasActiveSubscription
            && (($activationEvaluation['can_activate'] ?? false) === true)
            && $this->normalizeValue($aircraft->status) === 'active';

        $primaryAction = 'activate';
        $billingStatus = 'unpaid';

        if ($canOperate) {
            $primaryAction = 'none';
            $billingStatus = 'active';
        } elseif ($hasActiveSubscription) {
            $primaryAction = 'verify_payment';
            $billingStatus = 'processing';
        } elseif ($hasPendingCheckout) {
            $primaryAction = 'continue_payment';
            $billingStatus = 'pending';
        } elseif ($needsVerification) {
            $primaryAction = 'verify_payment';
            $billingStatus = 'processing';
        } elseif ($hasExpiredSubscription) {
            $primaryAction = 'regularize_payment';
            $billingStatus = $subscriptionStatus === 'expired' ? 'expired' : 'past_due';
        } elseif ($hasCancelledSubscription || $hasFailedPayment) {
            $primaryAction = 'regularize_payment';
            $billingStatus = 'cancelled';
        } elseif ($hasPaymentRecord) {
            $primaryAction = 'regularize_payment';
            $billingStatus = $this->resolveSnapshotBillingStatus($paymentStatus, $subscriptionStatus);
        }

        if (! $hasPaymentRecord) {
            $subscriptionStatus = 'inactive';
        }

        return [
            'aircraft_id' => (int) $aircraft->id,
            'aircraft_name' => trim((string) ($aircraft->model ?: $aircraft->registration ?: 'Aeronave '.$aircraft->id)),
            'billing_status' => $billingStatus,
            'subscription_status' => $subscriptionStatus,
            'payment_status' => $paymentStatus ?: ($hasActiveSubscription ? 'paid' : 'unpaid'),
            'checkout_session_id' => $checkoutSessionId !== '' ? $checkoutSessionId : null,
            'checkout_url' => $latestPayment?->gateway_response['checkout_url'] ?? null,
            'stripe_subscription_id' => $stripeSubscriptionId !== '' ? $stripeSubscriptionId : null,
            'last_payment_at' => $lastPaymentAt,
            'subscription_ends_at' => $subscriptionEndsAt,
            'has_pending_checkout' => $hasPendingCheckout,
            'can_start_checkout' => $primaryAction === 'activate' || $primaryAction === 'regularize_payment',
            'can_continue_checkout' => $primaryAction === 'continue_payment',
            'can_verify_payment' => $primaryAction === 'verify_payment',
            'can_operate' => $canOperate,
            'primary_action' => $primaryAction,
            'latest_payment_id' => $latestPayment?->id,
            'provider_checkout_id' => $checkoutSessionId !== '' ? $checkoutSessionId : null,
            'provider_subscription_id' => $stripeSubscriptionId !== '' ? $stripeSubscriptionId : null,
            'activation_state' => $activationEvaluation,
        ];
    }

    public function getAircraftActivationEvaluation(int|Aeronave $aircraft, string $subscriptionStatus, mixed $endsAt): array
    {
        $resolvedAircraft = $aircraft instanceof Aeronave
            ? $aircraft->loadMissing('provider', 'documents')
            : Aeronave::query()->with('provider', 'documents')->find($aircraft);

        if (! $resolvedAircraft) {
            return [
                'can_activate' => false,
                'status' => 'inactive',
                'code' => 'aircraft_not_found',
                'message' => 'No encontramos la aeronave para completar la activacion.',
                'missing_requirements' => ['aeronave no encontrada'],
            ];
        }

        if (! $this->canAircraftBeActive($subscriptionStatus, $endsAt)) {
            return [
                'can_activate' => false,
                'status' => 'inactive',
                'code' => 'payment_inactive',
                'message' => 'El pago aun no confirma una suscripcion activa o la vigencia ya expiro.',
                'missing_requirements' => [],
            ];
        }

        $missingRequirements = [];

        if (! $resolvedAircraft->provider?->isApprovedForOperations()) {
            $missingRequirements[] = 'proveedor pendiente de aprobacion administrativa';
        }

        if (! $resolvedAircraft->approved_at) {
            $missingRequirements[] = 'aeronave pendiente de aprobacion administrativa';
        }

        $documentEvaluation = $this->evaluateAircraftDocuments($resolvedAircraft);
        if (! $documentEvaluation['approved']) {
            $missingRequirements = [...$missingRequirements, ...$documentEvaluation['missing_requirements']];
        }

        $missingRequirements = array_values(array_unique(array_filter($missingRequirements)));

        if ($missingRequirements !== []) {
            return [
                'can_activate' => false,
                'status' => 'inactive',
                'code' => 'pending_requirements',
                'message' => 'Pago confirmado, pero la aeronave aun no cumple todos los requisitos de activacion.',
                'missing_requirements' => $missingRequirements,
            ];
        }

        return [
            'can_activate' => true,
            'status' => 'active',
            'code' => 'ready',
            'message' => 'La aeronave ya puede operar comercialmente.',
            'missing_requirements' => [],
        ];
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

    private function evaluateAircraftDocuments(Aeronave $aircraft): array
    {
        $documents = $aircraft->documents ?? collect();
        $missingRequirements = [];

        foreach (self::AIRCRAFT_REQUIRED_DOCUMENTS as $requirement) {
            $matchedDocuments = collect($documents)
                ->filter(fn ($document) => $document instanceof DocumentoAeronave)
                ->filter(fn (DocumentoAeronave $document) => in_array($this->resolveAircraftDocumentKey($document), $requirement['aliases'], true))
                ->values();

            if ($matchedDocuments->isEmpty()) {
                $missingRequirements[] = sprintf('falta %s', $requirement['label']);
                continue;
            }

            $hasApprovedCurrent = $matchedDocuments->contains(fn (DocumentoAeronave $document) => $this->isAircraftDocumentApproved($document) && ! $this->isAircraftDocumentExpired($document));
            if ($hasApprovedCurrent) {
                continue;
            }

            $hasExpired = $matchedDocuments->contains(fn (DocumentoAeronave $document) => $this->isAircraftDocumentExpired($document));
            if ($hasExpired) {
                $missingRequirements[] = sprintf('%s vencido', $requirement['label']);
                continue;
            }

            $hasRejected = $matchedDocuments->contains(fn (DocumentoAeronave $document) => in_array($this->normalizeValue($document->status), self::REJECTED_DOCUMENT_STATUSES, true));
            if ($hasRejected) {
                $missingRequirements[] = sprintf('%s rechazado', $requirement['label']);
                continue;
            }

            $missingRequirements[] = sprintf('%s pendiente de revision', $requirement['label']);
        }

        return [
            'approved' => $missingRequirements === [],
            'missing_requirements' => $missingRequirements,
        ];
    }

    private function isAircraftDocumentApproved(DocumentoAeronave $document): bool
    {
        if ($document->verified_by_admin === true) {
            return true;
        }

        return in_array($this->normalizeValue($document->status), self::APPROVED_DOCUMENT_STATUSES, true);
    }

    private function isAircraftDocumentExpired(DocumentoAeronave $document): bool
    {
        if ($this->normalizeValue($document->status) === 'expired') {
            return true;
        }

        return $document->expires_at instanceof Carbon && $document->expires_at->isPast();
    }

    private function resolveAircraftDocumentKey(DocumentoAeronave $document): string
    {
        $candidates = [
            data_get($document->metadata, 'definition_key'),
            data_get($document->metadata, 'document_definition.id'),
            data_get($document->metadata, 'document_type'),
            data_get($document->metadata, 'document_category'),
            $document->document_type,
            $document->type,
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeValue($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    private function normalizeValue(mixed $value): string
    {
        return Str::of((string) ($value ?? ''))
            ->trim()
            ->lower()
            ->ascii()
            ->replaceMatches('/[\s-]+/', '_')
            ->replaceMatches('/_+/', '_')
            ->value();
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

    private function resolveSnapshotBillingStatus(string $paymentStatus, string $subscriptionStatus): string
    {
        if (in_array($subscriptionStatus, ['past_due', 'unpaid'], true) || in_array($paymentStatus, ['past_due', 'unpaid'], true)) {
            return 'past_due';
        }

        if ($subscriptionStatus === 'expired' || $paymentStatus === 'expired') {
            return 'expired';
        }

        if (in_array($subscriptionStatus, ['cancelled', 'paused'], true) || $paymentStatus === 'cancelled') {
            return 'cancelled';
        }

        if (in_array($paymentStatus, ['failed', 'rejected'], true)) {
            return 'cancelled';
        }

        if (in_array($paymentStatus, ['pending', 'open', 'requires_action', 'pending_payment', 'incomplete'], true)) {
            return 'pending';
        }

        if ($paymentStatus === 'paid' || in_array($subscriptionStatus, self::ACTIVE_STRIPE_STATUSES, true)) {
            return 'processing';
        }

        return 'unpaid';
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

        SuscripcionAeronave::updateOrCreate($attributes, array_filter($values, function ($value, $key) {
            return $value !== null || in_array($key, ['paid_at', 'starts_at', 'ends_at', 'cancelled_at'], true);
        }, ARRAY_FILTER_USE_BOTH));

        if (array_key_exists('cancel_at_period_end', $metadata)) {
            $response = is_array($payment->gateway_response) ? $payment->gateway_response : [];
            $response['cancel_at_period_end'] = (bool) $metadata['cancel_at_period_end'];
            $payment->update(['gateway_response' => $response]);
        }
    }
}
