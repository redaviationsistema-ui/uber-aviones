<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $table = 'plans';

    protected $fillable = [
        'name',
        'code',
        'slug',
        'description',
        'price',
        'price_monthly',
        'price_yearly',
        'billing_cycle',
        'role_target',
        'max_requests',
        'max_aircraft',
        'max_users',
        'has_priority',
        'has_concierge',
        'has_reports',
        'is_enterprise',
        'is_active',
        'features',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'price_monthly' => 'decimal:2',
            'price_yearly' => 'decimal:2',
            'features' => 'array',
            'has_priority' => 'boolean',
            'has_concierge' => 'boolean',
            'has_reports' => 'boolean',
            'is_enterprise' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
