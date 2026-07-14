<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlightMembershipPlan extends Model
{
    protected $table = 'flight_membership_plans';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'currency',
        'billing_interval',
        'included_flights',
        'included_hours',
        'included_credit_amount',
        'discount_percentage',
        'rollover_flights',
        'rollover_hours',
        'rollover_credits',
        'validity_days',
        'auto_renew',
        'is_active',
        'stripe_product_id',
        'stripe_price_id',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'included_flights' => 'decimal:2',
            'included_hours' => 'decimal:2',
            'included_credit_amount' => 'decimal:2',
            'discount_percentage' => 'decimal:2',
            'rollover_flights' => 'boolean',
            'rollover_hours' => 'boolean',
            'rollover_credits' => 'boolean',
            'auto_renew' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(FlightMembership::class, 'plan_id');
    }
}
