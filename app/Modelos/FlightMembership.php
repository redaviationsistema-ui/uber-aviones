<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FlightMembership extends Model
{
    protected $table = 'flight_memberships';

    protected $fillable = [
        'user_id',
        'plan_id',
        'status',
        'starts_at',
        'ends_at',
        'current_period_start',
        'current_period_end',
        'stripe_customer_id',
        'stripe_subscription_id',
        'stripe_checkout_session_id',
        'last_invoice_id',
        'last_payment_at',
        'cancel_at_period_end',
        'canceled_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'last_payment_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'canceled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(FlightMembershipPlan::class, 'plan_id');
    }

    public function periods(): HasMany
    {
        return $this->hasMany(FlightMembershipPeriod::class, 'flight_membership_id');
    }

    public function currentPeriod(): HasOne
    {
        return $this->hasOne(FlightMembershipPeriod::class, 'flight_membership_id')
            ->latestOfMany('period_end');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(FlightMembershipBenefitLedger::class, 'flight_membership_id');
    }
}
