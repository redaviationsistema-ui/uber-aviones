<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Operacion extends Model
{
    protected $table = 'operations';

    protected $fillable = [
        'flight_request_id',
        'provider_id',
        'aircraft_id',
        'sobrecargo_user_id',
        'status',
        'crew_status',
        'crew_confirmed_at',
        'crew_decline_reason',
        'crew_notes',
        'crew_checkin_at',
        'crew_service_started_at',
        'crew_service_completed_at',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'crew_confirmed_at' => 'datetime',
            'crew_checkin_at' => 'datetime',
            'crew_service_started_at' => 'datetime',
            'crew_service_completed_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function solicitudVuelo(): BelongsTo
    {
        return $this->belongsTo(SolicitudVuelo::class, 'flight_request_id');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'provider_id');
    }

    public function aeronave(): BelongsTo
    {
        return $this->belongsTo(Aeronave::class, 'aircraft_id');
    }

    public function sobrecargo(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'sobrecargo_user_id');
    }

    public function timeline(): HasMany
    {
        return $this->hasMany(LineaTiempoOperacion::class, 'operation_id');
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(ChecklistOperacion::class, 'operation_id');
    }
}
