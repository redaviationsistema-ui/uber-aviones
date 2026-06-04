<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Suscripcion::class, 'plan_id');
    }

    public function aircraftSubscriptions(): HasMany
    {
        return $this->hasMany(SuscripcionAeronave::class, 'plan_id');
    }
}
