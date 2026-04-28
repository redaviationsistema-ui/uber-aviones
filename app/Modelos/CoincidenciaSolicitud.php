<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoincidenciaSolicitud extends Model
{
    protected $table = 'request_matches';

    protected $fillable = [
        'flight_request_id',
        'aircraft_id',
        'provider_id',
        'match_score',
        'estimated_price',
        'status',
        'response_deadline',
        'accepted_at',
        'rejected_at',
        'visibility_payload',
    ];

    protected function casts(): array
    {
        return [
            'match_score' => 'decimal:2',
            'estimated_price' => 'decimal:2',
            'response_deadline' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'visibility_payload' => 'array',
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
