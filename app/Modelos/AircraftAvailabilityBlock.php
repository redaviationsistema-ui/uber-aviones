<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AircraftAvailabilityBlock extends Model
{
    protected $table = 'aircraft_availability_blocks';

    protected $fillable = [
        'aircraft_id',
        'reservation_id',
        'block_type',
        'start_datetime',
        'end_datetime',
        'status',
        'reason',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'start_datetime' => 'datetime',
            'end_datetime' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aeronave::class, 'aircraft_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reserva::class, 'reservation_id');
    }
}
