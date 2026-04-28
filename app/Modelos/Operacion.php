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
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
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
