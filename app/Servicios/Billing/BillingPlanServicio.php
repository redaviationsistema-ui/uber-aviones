<?php

namespace App\Servicios\Billing;

use App\Modelos\Plan;
use Illuminate\Database\Eloquent\Builder;

class BillingPlanServicio
{
    public const CLIENT_ACCESS_CODE = 'client_access_one_time';
    public const PROVIDER_AIRCRAFT_MONTHLY_CODE = 'provider_aircraft_monthly';

    public function activeQuery(): Builder
    {
        return Plan::query()->where(function (Builder $query) {
            $query->where('status', 'active')->orWhere('is_active', true);
        });
    }

    public function findActiveByCode(string $code): ?Plan
    {
        return $this->activeQuery()->where('code', $code)->first();
    }

    public function serialize(Plan $plan): array
    {
        $amount = (float) ($plan->amount ?: ($plan->price_monthly ?: $plan->price ?: 0));

        return [
            'id' => $plan->id,
            'code' => $plan->code,
            'name' => $plan->name,
            'description' => $plan->description,
            'user_type' => $plan->user_type ?: $plan->role_target,
            'billing_type' => $plan->billing_type,
            'amount' => round($amount, 2),
            'currency' => $plan->currency ?: 'USD',
            'interval_type' => $plan->interval_type,
            'billing_cycle' => $plan->billing_cycle,
            'is_active' => (bool) ($plan->is_active ?? ($plan->status === 'active')),
            'stripe_product_id' => $plan->stripe_product_id,
            'stripe_price_id' => $plan->stripe_price_id,
        ];
    }
}
