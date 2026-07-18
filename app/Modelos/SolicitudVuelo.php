<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int|null $client_id
 * @property int|null $assigned_provider_id
 * @property int|null $assigned_aircraft_id
 * @property string|null $origin
 * @property string|null $destination
 * @property int|null $passengers
 * @property string|null $trip_type
 * @property string|null $aircraft_type
 * @property mixed $base_price
 * @property mixed $operational_fee
 * @property mixed $priority_price
 * @property mixed $final_price
 * @property string|null $currency
 * @property string|null $status
 * @property string|null $workflow_status
 */
class SolicitudVuelo extends Model
{
    protected $table = 'flight_requests';

    use HasFactory;

    protected static function newFactory()
    {
        return \Database\Factories\SolicitudVueloFactory::new();
    }

    protected $fillable = [
        'client_id',
        'idempotency_key',
        'assigned_provider_id',
        'assigned_aircraft_id',
        'assigned_aircraft_model',
        'origin',
        'origin_airport_id',
        'destination',
        'destination_airport_id',
        'departure_datetime',
        'return_datetime',
        'departure_date',
        'departure_time',
        'return_date',
        'return_time',
        'estimated_distance_km',
        'passengers',
        'trip_type',
        'aircraft_type',
        'requirements',
        'package_snapshot',
        'visibility_payload',
        'base_price',
        'operational_fee',
        'priority_price',
        'final_price',
        'currency',
        'pricing_formula_version',
        'pricing_context',
        'notes',
        'payment_method',
        'payment_status',
        'stripe_checkout_session_id',
        'stripe_payment_intent_id',
        'status',
        'workflow_status',
    ];

    protected $hidden = [
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'departure_date' => 'date',
            'return_date' => 'date',
            'departure_datetime' => 'datetime',
            'return_datetime' => 'datetime',
            'requirements' => 'array',
            'package_snapshot' => 'array',
            'visibility_payload' => 'array',
            'base_price' => 'decimal:2',
            'operational_fee' => 'decimal:2',
            'priority_price' => 'decimal:2',
            'final_price' => 'decimal:2',
            'pricing_context' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'client_id');
    }

    public function assignedProvider(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'assigned_provider_id');
    }

    public function assignedAircraft(): BelongsTo
    {
        return $this->belongsTo(Aeronave::class, 'assigned_aircraft_id');
    }

    public function originAirport(): BelongsTo
    {
        return $this->belongsTo(Aeropuerto::class, 'origin_airport_id');
    }

    public function destinationAirport(): BelongsTo
    {
        return $this->belongsTo(Aeropuerto::class, 'destination_airport_id');
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

    public function operaciones(): HasMany
    {
        return $this->hasMany(Operacion::class, 'flight_request_id');
    }

    public function latestOperation(): HasOne
    {
        return $this->hasOne(Operacion::class, 'flight_request_id')
            ->latestOfMany()
            ->select([
                'operations.id',
                'operations.flight_request_id',
                'operations.status',
                'operations.provider_id',
                'operations.aircraft_id',
                'operations.sobrecargo_user_id',
                'operations.crew_status',
                'operations.crew_confirmed_at',
                'operations.crew_decline_reason',
                'operations.crew_notes',
                'operations.crew_checkin_at',
                'operations.crew_service_started_at',
                'operations.crew_service_completed_at',
                'operations.started_at',
                'operations.completed_at',
                'operations.created_at',
                'operations.updated_at',
            ]);
    }

    public function reservation(): HasOne
    {
        return $this->hasOne(Reserva::class, 'flight_request_id');
    }

    public function chatsProtegidos(): HasMany
    {
        return $this->hasMany(ChatProtegido::class, 'flight_request_id');
    }

    public function resolvedOriginCode(): ?string
    {
        if ($this->relationLoaded('originAirport')) {
            return $this->originAirport?->icao ?: $this->originAirport?->iata ?: $this->origin;
        }

        if ($this->origin_airport_id) {
            $airport = $this->originAirport()->first(['icao', 'iata']);

            return $airport?->icao ?: $airport?->iata ?: $this->origin;
        }

        return $this->origin;
    }

    public function resolvedDestinationCode(): ?string
    {
        if ($this->relationLoaded('destinationAirport')) {
            return $this->destinationAirport?->icao ?: $this->destinationAirport?->iata ?: $this->destination;
        }

        if ($this->destination_airport_id) {
            $airport = $this->destinationAirport()->first(['icao', 'iata']);

            return $airport?->icao ?: $airport?->iata ?: $this->destination;
        }

        return $this->destination;
    }
}
