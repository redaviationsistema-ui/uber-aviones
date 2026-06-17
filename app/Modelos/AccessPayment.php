<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessPayment extends Model
{
    protected $table = 'access_payments';

    protected $fillable = [
        'user_id',
        'billing_plan_id',
        'amount',
        'currency',
        'billing_period_start',
        'billing_period_end',
        'status',
        'provider',
        'provider_payment_id',
        'provider_invoice_id',
        'provider_subscription_id',
        'provider_customer_id',
        'provider_checkout_id',
        'card_brand',
        'card_last4',
        'failure_reason',
        'retry_count',
        'grace_period_ends_at',
        'paid_at',
        'gateway_response',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'billing_period_start' => 'date',
            'billing_period_end' => 'date',
            'retry_count' => 'integer',
            'grace_period_ends_at' => 'datetime',
            'paid_at' => 'datetime',
            'gateway_response' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }

    public function billingPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'billing_plan_id');
    }
}
