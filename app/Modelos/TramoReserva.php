<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TramoReserva extends Model
{
    protected $table = 'reservation_legs';

    protected $fillable = ['reservation_id', 'leg_order', 'origin', 'origin_airport_id', 'destination', 'destination_airport_id', 'departure_datetime', 'arrival_datetime', 'passengers', 'status'];

    protected function casts(): array
    {
        return [
            'departure_datetime' => 'datetime',
            'arrival_datetime' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reserva::class);
    }

    public function originAirport(): BelongsTo
    {
        return $this->belongsTo(Aeropuerto::class, 'origin_airport_id');
    }

    public function destinationAirport(): BelongsTo
    {
        return $this->belongsTo(Aeropuerto::class, 'destination_airport_id');
    }
}
