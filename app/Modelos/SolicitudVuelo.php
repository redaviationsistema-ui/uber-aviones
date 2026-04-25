<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SolicitudVuelo extends Model
{
    protected $table = 'flight_requests';

    use HasFactory;

    protected $fillable = [
        'client_id',
        'origin',
        'destination',
        'departure_datetime',
        'return_datetime',
        'departure_date',
        'departure_time',
        'return_date',
        'return_time',
        'estimated_distance_km',
        'passengers',
        'trip_type',
        'notes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'departure_date' => 'date',
            'return_date' => 'date',
            'departure_datetime' => 'datetime',
            'return_datetime' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'client_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(CoincidenciaSolicitud::class, 'flight_request_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Cotizacion::class, 'flight_request_id');
    }

    public function legs(): HasMany
    {
        return $this->hasMany(TramoSolicitudVuelo::class, 'flight_request_id');
    }
}
