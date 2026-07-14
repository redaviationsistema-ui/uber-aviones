<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuscripcionAeronave extends Model
{
    protected $table = 'aircraft_subscriptions';

    protected $fillable = [
        'aircraft_id',
        'plan_id',
        'user_id',
        'status',
        'payment_provider',
        'payment_reference',
        'provider_checkout_id',
        'provider_subscription_id',
        'provider_customer_id',
        'provider_invoice_id',
        'paid_at',
        'starts_at',
        'ends_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aeronave::class, 'aircraft_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }
}
