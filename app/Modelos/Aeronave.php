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
        'registration',
        'capacity',
        'base_airport',
        'range_km',
        'speed_kmh',
        'hourly_rate',
        'currency',
        'status',
    ];

    protected function casts(): array
    {
        return ['hourly_rate' => 'decimal:2'];
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
}
