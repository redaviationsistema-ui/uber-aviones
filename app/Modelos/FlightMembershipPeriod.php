<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlightMembershipPeriod extends Model
{
    protected $table = 'flight_membership_periods';

    protected $fillable = [
        'flight_membership_id',
        'membership_period_key',
        'stripe_invoice_id',
        'period_start',
        'period_end',
        'status',
        'granted_flights',
        'granted_hours',
        'granted_credit',
        'used_flights',
        'used_hours',
        'used_credit',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'granted_flights' => 'decimal:2',
            'granted_hours' => 'decimal:2',
            'granted_credit' => 'decimal:2',
            'used_flights' => 'decimal:2',
            'used_hours' => 'decimal:2',
            'used_credit' => 'decimal:2',
        ];
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(FlightMembership::class, 'flight_membership_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(FlightMembershipBenefitLedger::class, 'flight_membership_period_id');
    }
}
