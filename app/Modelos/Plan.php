<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $table = 'plans';

    protected $fillable = ['name', 'slug', 'price', 'billing_cycle', 'features', 'status'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'features' => 'array',
        ];
    }
}
