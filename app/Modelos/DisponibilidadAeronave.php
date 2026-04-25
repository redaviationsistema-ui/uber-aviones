<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisponibilidadAeronave extends Model
{
    protected $table = 'aircraft_availability';

    protected $fillable = ['aircraft_id', 'start_datetime', 'end_datetime', 'status', 'notes'];

    protected function casts(): array
    {
        return [
            'start_datetime' => 'datetime',
            'end_datetime' => 'datetime',
        ];
    }

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aeronave::class);
    }
}
