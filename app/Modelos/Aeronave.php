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
        'category',
        'model_year',
        'registration',
        'capacity',
        'base_airport',
        'range_km',
        'speed_kmh',
        'coverage',
        'amenities',
        'hourly_rate',
        'airport_expenses_usd',
        'minimum_hours',
        'minimum_route_price',
        'climb_descent_minutes',
        'operational_cost',
        'fuel_burn_gph',
        'engine_reserve_rate',
        'insurance_rate',
        'maintenance_rate',
        'crew_rate',
        'repositioning_fee',
        'overnight_fee',
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
            'capacity' => 'integer',
            'range_km' => 'integer',
            'speed_kmh' => 'integer',
            'hourly_rate' => 'decimal:2',
            'airport_expenses_usd' => 'decimal:2',
            'minimum_hours' => 'decimal:2',
            'minimum_route_price' => 'decimal:2',
            'climb_descent_minutes' => 'integer',
            'operational_cost' => 'decimal:2',
            'fuel_burn_gph' => 'decimal:2',
            'engine_reserve_rate' => 'decimal:2',
            'insurance_rate' => 'decimal:2',
            'maintenance_rate' => 'decimal:2',
            'crew_rate' => 'decimal:2',
            'repositioning_fee' => 'decimal:2',
            'overnight_fee' => 'decimal:2',
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
