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
        'flight_request_id',
        'subscription_id',
        'payment_method_id',
        'payment_type',
        'amount',
        'currency',
        'provider',
        'transaction_reference',
        'stripe_checkout_session_id',
        'stripe_payment_intent_id',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }

    public function flightRequest(): BelongsTo
    {
        return $this->belongsTo(SolicitudVuelo::class, 'flight_request_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Suscripcion::class, 'subscription_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(MetodoPago::class, 'payment_method_id');
    }
}
