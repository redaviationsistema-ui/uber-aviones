<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AircraftAvailabilityBlock extends Model
{
    protected $table = 'aircraft_availability_blocks';

    protected $fillable = [
        'aircraft_id',
        'quote_id',
        'flight_request_id',
        'user_id',
        'reservation_id',
        'block_type',
        'start_datetime',
        'end_datetime',
        'hold_expires_at',
        'payment_status',
        'source',
        'status',
        'reason',
        'notes',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'start_datetime' => 'datetime',
            'end_datetime' => 'datetime',
            'hold_expires_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aeronave::class, 'aircraft_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reserva::class, 'reservation_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class, 'quote_id');
    }

    public function flightRequest(): BelongsTo
    {
        return $this->belongsTo(SolicitudVuelo::class, 'flight_request_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }
}
