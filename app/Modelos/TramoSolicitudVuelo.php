<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TramoSolicitudVuelo extends Model
{
    protected $table = 'flight_request_legs';

    protected $fillable = ['flight_request_id', 'leg_order', 'origin', 'origin_airport_id', 'destination', 'destination_airport_id', 'departure_datetime', 'arrival_datetime', 'distance_km', 'passengers'];

    protected function casts(): array
    {
        return [
            'departure_datetime' => 'datetime',
            'arrival_datetime' => 'datetime',
        ];
    }

    public function flightRequest(): BelongsTo
    {
        return $this->belongsTo(SolicitudVuelo::class);
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
