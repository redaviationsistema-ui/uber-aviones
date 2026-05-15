<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;

class ReglaPrecioCategoria extends Model
{
    protected $table = 'category_pricing_rules';

    protected $fillable = [
        'category',
        'minimum_route_price',
        'redsky_markup',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'minimum_route_price' => 'decimal:2',
            'redsky_markup' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
