<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Reserva extends Model
{
    protected $table = 'reservations';

    protected $fillable = [
        'client_id',
        'provider_id',
        'aircraft_id',
        'flight_request_id',
        'quote_id',
        'reservation_code',
        'status',
        'total_amount',
        'currency',
        'confirmed_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'client_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'provider_id');
    }

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aeronave::class, 'aircraft_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class, 'quote_id');
    }

    public function flightRequest(): BelongsTo
    {
        return $this->belongsTo(SolicitudVuelo::class, 'flight_request_id');
    }

    public function legs(): HasMany
    {
        return $this->hasMany(TramoReserva::class, 'reservation_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Pago::class, 'reservation_id');
    }

    public function latestPayment(): HasOne
    {
        return $this->hasOne(Pago::class, 'reservation_id')
            ->latestOfMany()
            ->select([
                'payments.id',
                'payments.reservation_id',
                'payments.flight_request_id',
                'payments.status',
                'payments.provider',
                'payments.paid_at',
                'payments.stripe_checkout_session_id',
                'payments.stripe_payment_intent_id',
                'payments.created_at',
                'payments.updated_at',
            ]);
    }

    public function contract(): HasOne
    {
        return $this->hasOne(ContratoReserva::class, 'reservation_id');
    }

    public function aircraftAvailabilityBlock(): HasOne
    {
        return $this->hasOne(AircraftAvailabilityBlock::class, 'reservation_id');
    }

    public function review(): HasOne
    {
        return $this->hasOne(CalificacionServicio::class, 'reservation_id');
    }
}
