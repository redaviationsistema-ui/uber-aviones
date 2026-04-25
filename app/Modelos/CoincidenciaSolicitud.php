<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoincidenciaSolicitud extends Model
{
    protected $table = 'request_matches';

    protected $fillable = ['flight_request_id', 'aircraft_id', 'provider_id', 'match_score', 'estimated_price', 'status'];

    protected function casts(): array
    {
        return [
            'match_score' => 'decimal:2',
            'estimated_price' => 'decimal:2',
        ];
    }

    public function flightRequest(): BelongsTo
    {
        return $this->belongsTo(SolicitudVuelo::class, 'flight_request_id');
    }

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aeronave::class, 'aircraft_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'provider_id');
    }
}
