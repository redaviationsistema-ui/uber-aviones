<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlightMembershipBenefitLedger extends Model
{
    protected $table = 'flight_membership_benefit_ledger';

    protected $fillable = [
        'flight_membership_id',
        'flight_membership_period_id',
        'membership_period_key',
        'quote_id',
        'flight_id',
        'reservation_id',
        'entry_type',
        'benefit_type',
        'quantity',
        'amount',
        'status',
        'reference',
        'metadata',
        'occurred_at',
        'reversed_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'amount' => 'decimal:2',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(FlightMembership::class, 'flight_membership_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(FlightMembershipPeriod::class, 'flight_membership_period_id');
    }

    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_entry_id');
    }
}
