<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TramoSolicitudVuelo extends Model
{
    protected $table = 'flight_request_legs';

    protected $fillable = ['flight_request_id', 'leg_order', 'origin', 'destination', 'departure_datetime', 'arrival_datetime', 'distance_km', 'passengers'];

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
}
