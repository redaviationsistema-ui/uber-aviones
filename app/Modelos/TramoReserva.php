<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TramoReserva extends Model
{
    protected $table = 'reservation_legs';

    protected $fillable = ['reservation_id', 'leg_order', 'origin', 'destination', 'departure_datetime', 'arrival_datetime', 'passengers', 'status'];

    protected function casts(): array
    {
        return [
            'departure_datetime' => 'datetime',
            'arrival_datetime' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reserva::class);
    }
}
