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

    public function legs(): HasMany
    {
        return $this->hasMany(TramoReserva::class, 'reservation_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Pago::class, 'reservation_id');
    }

    public function contract(): HasOne
    {
        return $this->hasOne(ContratoReserva::class, 'reservation_id');
    }

    public function review(): HasOne
    {
        return $this->hasOne(CalificacionServicio::class, 'reservation_id');
    }
}
