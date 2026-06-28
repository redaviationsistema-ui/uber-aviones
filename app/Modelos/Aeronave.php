<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $provider_id
 * @property string|null $model
 * @property string|null $manufacturer
 * @property string|null $category
 * @property int|null $capacity
 * @property string|null $base_airport
 * @property int|null $base_airport_id
 * @property int|null $range_km
 * @property int|null $speed_kmh
 * @property string|null $currency
 * @property string|null $status
 * @property mixed $hourly_rate
 * @property mixed $airport_expenses_usd
 * @property mixed $minimum_hours
 * @property mixed $minimum_route_price
 * @property mixed $climb_descent_minutes
 * @property mixed $operational_cost
 * @property mixed $repositioning_fee
 * @property mixed $overnight_fee
 * @property-read \App\Modelos\Proveedor|null $provider
 * @property-read \App\Modelos\Aeropuerto|null $baseAirport
 */
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
        'base_airport_id',
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
        'billing_status',
        'billing_plan_id',
        'subscription_status',
        'subscription_started_at',
        'subscription_ends_at',
        'last_payment_at',
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
            'billing_plan_id' => 'integer',
            'security_score' => 'integer',
            'subscription_started_at' => 'datetime',
            'subscription_ends_at' => 'datetime',
            'last_payment_at' => 'datetime',
            'last_maintenance_at' => 'date',
            'engine_run_at' => 'date',
            'captain_training_at' => 'date',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'provider_id');
    }

    public function baseAirport(): BelongsTo
    {
        return $this->belongsTo(Aeropuerto::class, 'base_airport_id');
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

    public function resolvedBaseAirportCode(): ?string
    {
        if ($this->relationLoaded('baseAirport')) {
            return $this->baseAirport?->icao ?: $this->baseAirport?->iata ?: $this->base_airport;
        }

        if ($this->base_airport_id) {
            $airport = $this->baseAirport()->first(['icao', 'iata']);

            return $airport?->icao ?: $airport?->iata ?: $this->base_airport;
        }

        return $this->base_airport;
    }
}
