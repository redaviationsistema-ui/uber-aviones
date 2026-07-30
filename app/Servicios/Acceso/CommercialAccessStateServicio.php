<?php

namespace App\Servicios\Acceso;

use App\Modelos\AccessPayment;
use App\Modelos\Usuario;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CommercialAccessStateServicio
{
    public function resolve(Usuario $user, ?AccessPayment $latestAccessPayment = null): array
    {
        $status = strtolower(trim((string) ($user->access_status ?: 'trial_active')));
        $hasPaidAccess = (bool) $user->has_paid_access;
        $accessExpiresAt = $user->access_expires_at ? Carbon::parse($user->access_expires_at) : null;
        $gracePeriodEndsAt = $user->grace_period_ends_at ? Carbon::parse($user->grace_period_ends_at) : null;
        $nextRetryAt = $user->next_retry_at ? Carbon::parse($user->next_retry_at) : null;
        $trialEndsAt = $user->trial_ends_at ? Carbon::parse($user->trial_ends_at) : null;
        $now = now();

        $freeQuoteLimit = max(1, (int) ($user->free_quote_limit ?? 1));
        $freeQuotesUsed = max(0, (int) ($user->free_quotes_used ?? 0));
        $remainingFreeQuotes = max(0, $freeQuoteLimit - $freeQuotesUsed);

        $paidActiveStatuses = ['active', 'approved', 'paid', 'succeeded', 'complete', 'completed'];
        $graceStatuses = ['past_due', 'payment_failed', 'retry_required', 'retry_pending', 'in_grace'];
        $blockedStatuses = ['suspended', 'unpaid', 'cancelled', 'canceled', 'blocked', 'inactive'];

        $trialStillActive = $trialEndsAt === null || ! $trialEndsAt->isPast();
        $trialAvailable = ! $hasPaidAccess
            && ! in_array($status, $blockedStatuses, true)
            && ! in_array($status, $graceStatuses, true)
            && $remainingFreeQuotes > 0
            && $trialStillActive;

        $paidWindowActive = $hasPaidAccess
            && in_array($status, $paidActiveStatuses, true)
            && $accessExpiresAt !== null
            && $now->lte($accessExpiresAt);

        $graceActive = $hasPaidAccess
            && in_array($status, $graceStatuses, true)
            && $gracePeriodEndsAt !== null
            && $now->lte($gracePeriodEndsAt);

        $effectiveDeadline = $graceActive ? $gracePeriodEndsAt : $accessExpiresAt;
        $accessExpiresToday = $accessExpiresAt?->isSameDay($now) ?? false;
        $accessExpired = ! $graceActive
            && $accessExpiresAt !== null
            && $now->gt($accessExpiresAt);

        if ($status === 'expired' || ($hasPaidAccess && ! $paidWindowActive && ! $graceActive && $accessExpiresAt !== null)) {
            $status = 'expired';
        }

        if ($graceActive) {
            $status = 'past_due';
        }

        if ($paidWindowActive) {
            $status = 'active';
        }

        $hasCommercialAccess = $paidWindowActive || $graceActive || $trialAvailable;
        $canQuote = $hasCommercialAccess;
        $canReserve = $paidWindowActive || $graceActive;
        $canSignContract = $canReserve;
        $canPay = $canReserve;
        $canRenew = $this->shouldAllowRenewal(
            status: $status,
            hasPaidAccess: $hasPaidAccess,
            paidWindowActive: $paidWindowActive,
            graceActive: $graceActive,
            accessExpiresAt: $accessExpiresAt,
            now: $now,
        );
        $shouldManageSubscription = (bool) $user->provider_customer_id
            && in_array($status, ['active', 'past_due', 'payment_failed', 'suspended', 'unpaid'], true);

        $accessMessage = $this->buildAccessMessage(
            status: $status,
            hasPaidAccess: $hasPaidAccess,
            paidWindowActive: $paidWindowActive,
            graceActive: $graceActive,
            trialAvailable: $trialAvailable,
            accessExpiresAt: $accessExpiresAt,
            gracePeriodEndsAt: $gracePeriodEndsAt,
            remainingFreeQuotes: $remainingFreeQuotes,
        );

        $daysRemaining = null;
        $hoursRemaining = null;
        if ($effectiveDeadline) {
            $daysRemaining = (int) $now->copy()->startOfDay()->diffInDays($effectiveDeadline->copy()->startOfDay(), false);
            $hoursRemaining = (int) $now->diffInHours($effectiveDeadline, false);
        }

        $normalized = [
            'status' => $status,
            'access_status' => $status,
            'has_paid_access' => $hasPaidAccess,
            'has_access' => $hasCommercialAccess,
            'access_is_active' => $paidWindowActive || $graceActive,
            'access_is_expired' => $accessExpired,
            'access_expires_today' => $accessExpiresToday,
            'access_is_in_grace_period' => $graceActive,
            'trial_started_at' => $user->trial_started_at,
            'trial_ends_at' => $user->trial_ends_at,
            'trial_days_left' => $trialEndsAt && $trialEndsAt->isFuture() ? $now->diffInDays($trialEndsAt, false) : 0,
            'free_quote_limit' => $freeQuoteLimit,
            'free_quotes_used' => $freeQuotesUsed,
            'remaining_free_quotes' => $remainingFreeQuotes,
            'has_trial_quote_available' => $trialAvailable,
            'paid_access_at' => $user->paid_access_at,
            'access_expires_at' => $accessExpiresAt,
            'access_expires_date' => $accessExpiresAt?->toDateString(),
            'access_expires_formatted' => $this->formatDate($accessExpiresAt),
            'billing_period_end' => $latestAccessPayment?->billing_period_end,
            'grace_period_ends_at' => $graceActive ? $gracePeriodEndsAt : null,
            'grace_period_ends_date' => $graceActive ? $gracePeriodEndsAt?->toDateString() : null,
            'grace_period_ends_formatted' => $graceActive ? $this->formatDate($gracePeriodEndsAt) : null,
            'next_retry_at' => $nextRetryAt,
            'days_remaining' => $daysRemaining,
            'hours_remaining' => $hoursRemaining,
            'access_message' => $accessMessage,
            'available_actions' => [
                'can_quote' => $canQuote,
                'can_reserve' => $canReserve,
                'can_sign_contract' => $canSignContract,
                'can_pay' => $canPay,
                'can_renew' => $canRenew,
                'should_manage_subscription' => $shouldManageSubscription,
                'should_activate_checkout' => ! $shouldManageSubscription && $canRenew,
            ],
            'provider_subscription_id' => $user->provider_subscription_id,
            'provider_customer_id' => $user->provider_customer_id,
            'access_payment_id' => $user->access_payment_id,
            'latest_payment' => $latestAccessPayment ? [
                'id' => $latestAccessPayment->id,
                'status' => $latestAccessPayment->status,
                'billing_period_start' => $latestAccessPayment->billing_period_start,
                'billing_period_end' => $latestAccessPayment->billing_period_end,
                'provider_checkout_id' => $latestAccessPayment->provider_checkout_id,
                'provider_subscription_id' => $latestAccessPayment->provider_subscription_id,
                'provider_customer_id' => $latestAccessPayment->provider_customer_id,
                'grace_period_ends_at' => $latestAccessPayment->grace_period_ends_at,
                'paid_at' => $latestAccessPayment->paid_at,
            ] : null,
        ];

        if (config('app.debug')) {
            Log::info('commercial_access_state_resolved', [
                'user_id' => $user->id,
                'status' => $normalized['status'],
                'access_expires_at' => $normalized['access_expires_at']?->toIso8601String(),
                'grace_period_ends_at' => $normalized['grace_period_ends_at']?->toIso8601String(),
                'can_quote' => $normalized['available_actions']['can_quote'],
                'can_reserve' => $normalized['available_actions']['can_reserve'],
                'source_of_truth' => 'users.access_expires_at',
            ]);
        }

        return $normalized;
    }

    private function shouldAllowRenewal(
        string $status,
        bool $hasPaidAccess,
        bool $paidWindowActive,
        bool $graceActive,
        ?Carbon $accessExpiresAt,
        Carbon $now,
    ): bool {
        if (in_array($status, ['expired', 'payment_failed', 'past_due', 'suspended', 'unpaid', 'trial_used'], true)) {
            return true;
        }

        if (! $hasPaidAccess) {
            return true;
        }

        if ($graceActive) {
            return true;
        }

        if (! $paidWindowActive || ! $accessExpiresAt) {
            return false;
        }

        return $now->copy()->addDays(7)->startOfDay()->gte($accessExpiresAt->copy()->startOfDay());
    }

    private function buildAccessMessage(
        string $status,
        bool $hasPaidAccess,
        bool $paidWindowActive,
        bool $graceActive,
        bool $trialAvailable,
        ?Carbon $accessExpiresAt,
        ?Carbon $gracePeriodEndsAt,
        int $remainingFreeQuotes,
    ): string {
        $expiryLabel = $this->formatDate($accessExpiresAt);
        $graceLabel = $this->formatDate($gracePeriodEndsAt);

        if ($graceActive) {
            return $graceLabel !== ''
                ? "El cobro automático falló. Tu cuenta sigue activa hasta {$graceLabel} mientras actualizas el método de pago."
                : 'El cobro automático falló. Tu cuenta sigue activa temporalmente mientras actualizas el método de pago.';
        }

        if ($status === 'expired') {
            return $expiryLabel !== ''
                ? "Tu acceso ya expiró el {$expiryLabel}. Reactiva el pago para volver a cotizar, reservar, firmar contrato y pagar vuelos."
                : 'Tu acceso ya expiró. Reactiva el pago para volver a cotizar, reservar, firmar contrato y pagar vuelos.';
        }

        if (in_array($status, ['suspended', 'unpaid'], true)) {
            return 'Tu acceso comercial está suspendido. Actualiza el método de pago para reactivar cotizaciones, reservas y pagos.';
        }

        if ($paidWindowActive || ($hasPaidAccess && $status === 'active')) {
            return 'Tu cuenta ya puede cotizar, reservar, firmar contrato y pagar vuelos.';
        }

        if ($status === 'payment_pending') {
            return 'Tu pago de acceso está en validación. En cuanto se confirme podrás continuar.';
        }

        if ($status === 'payment_failed') {
            return 'No pudimos validar el pago anterior. Reactiva el pago para recuperar cotizaciones, reservas y pagos.';
        }

        if ($trialAvailable) {
            return $remainingFreeQuotes === 1
                ? 'Todavía tienes 1 cotización de prueba disponible.'
                : "Todavía tienes {$remainingFreeQuotes} cotizaciones de prueba disponibles.";
        }

        return 'Activa tu acceso comercial para cotizar, reservar, firmar contrato y pagar vuelos.';
    }

    private function formatDate(?Carbon $value): string
    {
        if (! $value) {
            return '';
        }

        return $value->copy()->locale('es')->translatedFormat('Y-m-d');
    }
}
