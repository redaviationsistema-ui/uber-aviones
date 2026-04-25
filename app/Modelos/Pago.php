<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pago extends Model
{
    protected $table = 'payments';

    protected $fillable = [
        'user_id',
        'reservation_id',
        'subscription_id',
        'payment_method_id',
        'payment_type',
        'amount',
        'currency',
        'provider',
        'transaction_reference',
        'status',
        'paid_at',
        'failure_reason',
        'gateway_response',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'gateway_response' => 'array',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reserva::class);
    }
}
