<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AircraftPerformanceProfile extends Model
{
    protected $table = 'aircraft_performance_profiles';

    protected $fillable = [
        'aircraft_id',
        'aircraft_model',
        'aircraft_type',
        'taxi_out_minutes',
        'taxi_in_minutes',
        'takeoff_minutes',
        'landing_minutes',
        'climb_minutes',
        'climb_distance_nm',
        'descent_minutes',
        'descent_distance_nm',
        'fixed_operational_minutes',
        'short_leg_threshold_nm',
        'medium_leg_threshold_nm',
        'short_leg_speed_factor',
        'medium_leg_speed_factor',
        'long_leg_speed_factor',
        'rounding_increment_minutes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'aircraft_id' => 'integer',
            'taxi_out_minutes' => 'decimal:2',
            'taxi_in_minutes' => 'decimal:2',
            'takeoff_minutes' => 'decimal:2',
            'landing_minutes' => 'decimal:2',
            'climb_minutes' => 'decimal:2',
            'climb_distance_nm' => 'decimal:2',
            'descent_minutes' => 'decimal:2',
            'descent_distance_nm' => 'decimal:2',
            'fixed_operational_minutes' => 'decimal:2',
            'short_leg_threshold_nm' => 'decimal:2',
            'medium_leg_threshold_nm' => 'decimal:2',
            'short_leg_speed_factor' => 'decimal:4',
            'medium_leg_speed_factor' => 'decimal:4',
            'long_leg_speed_factor' => 'decimal:4',
            'rounding_increment_minutes' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aeronave::class, 'aircraft_id');
    }
}
