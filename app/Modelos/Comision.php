<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Comision extends Model
{
    protected $table = 'commissions';

    protected $fillable = ['reservation_id', 'provider_id', 'platform_fee', 'provider_amount', 'status'];

    protected function casts(): array
    {
        return [
            'platform_fee' => 'decimal:2',
            'provider_amount' => 'decimal:2',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'provider_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reserva::class, 'reservation_id');
    }
}
