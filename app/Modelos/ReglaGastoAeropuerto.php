<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;

class ReglaGastoAeropuerto extends Model
{
    protected $table = 'airport_expense_rules';

    protected $fillable = [
        'aircraft_id',
        'category',
        'origin_airport_code',
        'destination_airport_code',
        'route_signature',
        'expense_fee',
        'priority',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'aircraft_id' => 'integer',
            'expense_fee' => 'decimal:2',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
