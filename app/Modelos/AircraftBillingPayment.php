<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AircraftBillingPayment extends Model
{
    protected $table = 'aircraft_billing_payments';

    protected $fillable = [
        'provider_id',
        'aircraft_id',
        'billing_plan_id',
        'amount',
        'currency',
        'billing_period_start',
        'billing_period_end',
        'status',
        'provider',
        'provider_payment_id',
        'provider_subscription_id',
        'provider_checkout_id',
        'paid_at',
        'gateway_response',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'billing_period_start' => 'date',
            'billing_period_end' => 'date',
            'paid_at' => 'datetime',
            'gateway_response' => 'array',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'provider_id');
    }

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aeronave::class, 'aircraft_id');
    }

    public function billingPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'billing_plan_id');
    }
}
