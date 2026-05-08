<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Aeronave extends Model
{
    protected $table = 'aircraft';

    use HasFactory;

    protected $fillable = [
        'provider_id',
        'model',
        'manufacturer',
        'model_year',
        'registration',
        'capacity',
        'base_airport',
        'range_km',
        'speed_kmh',
        'coverage',
        'amenities',
        'hourly_rate',
        'minimum_hours',
        'operational_cost',
        'currency',
        'status',
        'security_filter',
        'security_score',
        'airworthiness_status',
        'last_maintenance_at',
        'engine_run_at',
        'captain_training_at',
        'lodging_location',
        'client_fbo',
        'dispatch_center',
        'dispatch_notes',
        'security_notes',
    ];

    protected function casts(): array
    {
        return [
            'model_year' => 'integer',
            'hourly_rate' => 'decimal:2',
            'minimum_hours' => 'decimal:2',
            'operational_cost' => 'decimal:2',
            'security_score' => 'integer',
            'last_maintenance_at' => 'date',
            'engine_run_at' => 'date',
            'captain_training_at' => 'date',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'provider_id');
    }

    public function availability(): HasMany
    {
        return $this->hasMany(DisponibilidadAeronave::class, 'aircraft_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ImagenAeronave::class, 'aircraft_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DocumentoAeronave::class, 'aircraft_id');
    }

    public function suscripcionesAeronave(): HasMany
    {
        return $this->hasMany(SuscripcionAeronave::class, 'aircraft_id');
    }
}
