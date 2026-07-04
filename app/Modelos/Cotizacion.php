<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cotizacion extends Model
{
    protected $table = 'quotes';

    use HasFactory;

    protected static function newFactory()
    {
        return \Database\Factories\CotizacionFactory::new();
    }

    protected $fillable = [
        'flight_request_id',
        'aircraft_id',
        'provider_id',
        'subtotal',
        'taxes',
        'fees',
        'total',
        'currency',
        'provider_notes',
        'status',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'taxes' => 'decimal:2',
            'fees' => 'decimal:2',
            'total' => 'decimal:2',
            'expires_at' => 'datetime',
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

    public function items(): HasMany
    {
        return $this->hasMany(ItemCotizacion::class, 'quote_id');
    }
}
