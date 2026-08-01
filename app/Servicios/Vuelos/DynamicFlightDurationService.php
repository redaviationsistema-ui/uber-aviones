<?php

namespace App\Servicios\Vuelos;

use App\Modelos\Aeronave;
use App\Modelos\Aeropuerto;
use App\Modelos\AircraftPerformanceProfile;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class DynamicFlightDurationService
{
    private const PROFILE_KEYS = [
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
    ];

    private ?bool $profilesTableExists = null;

    private array $resolvedProfiles = [];

    public function __construct(
        private readonly FlightDurationService $legacyFlightDurationService,
    ) {}

    public function calculateLeg(
        Aeronave $aircraft,
        Aeropuerto $origin,
        Aeropuerto $destination,
        float $distanceKm,
        float $distanceNm,
        bool $applyMinimumHours = true,
        array $context = [],
    ): array {
        $profile = $this->resolveProfile($aircraft);
        $storedSpeedValue = $this->resolveStoredSpeedValue($aircraft);
        $storedSpeedUnit = $this->resolveStoredSpeedUnit($aircraft);
        $speedConversionApplied = $storedSpeedUnit === 'km/h_to_knots';
        $baseSpeedKnots = max($this->resolveBaseSpeedKnots($aircraft), 1.0);
        $baseSpeedKmh = $baseSpeedKnots * 1.852;
        $effectiveSpeedFactor = $this->resolveEffectiveSpeedFactor($distanceNm, $profile);
        $effectiveSpeedKnots = max($baseSpeedKnots * $effectiveSpeedFactor, 1.0);
        $effectiveSpeedKmh = $effectiveSpeedKnots * 1.852;
        $directMinutes = ($distanceNm / $baseSpeedKnots) * 60;
        $cruiseDistanceNm = max(
            0.0,
            $distanceNm - (float) $profile['climb_distance_nm'] - (float) $profile['descent_distance_nm']
        );
        $cruiseMinutes = ($cruiseDistanceNm / $effectiveSpeedKnots) * 60;
        $operationalMinutesRaw =
            (float) $profile['taxi_out_minutes']
            + (float) $profile['takeoff_minutes']
            + (float) $profile['climb_minutes']
            + $cruiseMinutes
            + (float) $profile['descent_minutes']
            + (float) $profile['landing_minutes']
            + (float) $profile['taxi_in_minutes']
            + (float) $profile['fixed_operational_minutes'];
        $roundingIncrementMinutes = max((float) $profile['rounding_increment_minutes'], 1.0);
        $roundedMinutes = $this->roundMinutes($operationalMinutesRaw, $roundingIncrementMinutes);
        $minimumHours = $applyMinimumHours
            ? $this->legacyFlightDurationService->minimumHours($aircraft->category, $distanceKm)
            : 0.0;
        $billableMinutes = max($roundedMinutes, $minimumHours * 60);
        $billableHours = $billableMinutes / 60;
        $hourlyRate = $this->commercialHourlyRate($aircraft->hourly_rate);
        $accumulatedMinutes = max(0.0, (float) ($context['accumulated_minutes_before'] ?? 0.0)) + $billableMinutes;
        $departureDateTime = filled($context['departure_datetime'] ?? null)
            ? Carbon::parse((string) $context['departure_datetime'])
            : null;
        $arrivalDateTime = $departureDateTime?->copy()->addMinutes((int) round($billableMinutes));

        $leg = [
            'origin' => $origin->icao ?: $origin->iata,
            'destination' => $destination->icao ?: $destination->iata,
            'hours_source' => 'dynamic_operational_profile',
            'manual_duration_minutes' => 0.0,
            'manual_duration_hours' => 0.0,
            'manual_duration_field' => null,
            'distance_speed_hours' => $directMinutes / 60,
            'distance_nm' => $distanceNm,
            'distance_km' => $distanceKm,
            'adjusted_distance_nm' => $distanceNm,
            'direct_air_time_hours' => $directMinutes / 60,
            'air_time_hours' => $directMinutes / 60,
            'direct_minutes' => $directMinutes,
            'climb_descent_minutes' => (float) $profile['climb_minutes'] + (float) $profile['descent_minutes'],
            'climb_descent_hours' => ((float) $profile['climb_minutes'] + (float) $profile['descent_minutes']) / 60,
            'reserve_hours' => 0.0,
            'display_flight_hours' => $billableHours,
            'operational_display_hours' => $billableHours,
            'operational_factor' => $effectiveSpeedFactor,
            'fixed_minutes_per_leg' => (float) $profile['fixed_operational_minutes'],
            'minimum_minutes_per_leg' => 0.0,
            'calculated_minutes' => (int) round($operationalMinutesRaw),
            'operational_minutes' => (int) round($operationalMinutesRaw),
            'rounded_minutes' => $roundedMinutes,
            'billable_minutes' => $billableMinutes,
            'operational_flight_hours' => $operationalMinutesRaw / 60,
            'commercial_flight_hours' => $billableHours,
            'real_flight_hours' => $operationalMinutesRaw / 60,
            'minimum_hours' => $minimumHours,
            'buffer_hours' => 0.0,
            'billable_hours' => $billableHours,
            'final_hours' => $billableHours,
            'raw_leg_cost' => $billableHours * $hourlyRate,
            'base_price' => $billableHours * $hourlyRate,
            'minimum_route_price' => 0,
            'leg_cost' => $billableHours * $hourlyRate,
            'international' => strtoupper((string) $origin->country) !== strtoupper((string) $destination->country),
            'taxi_out_minutes' => (float) $profile['taxi_out_minutes'],
            'taxi_in_minutes' => (float) $profile['taxi_in_minutes'],
            'takeoff_minutes' => (float) $profile['takeoff_minutes'],
            'landing_minutes' => (float) $profile['landing_minutes'],
            'climb_minutes' => (float) $profile['climb_minutes'],
            'climb_distance_nm' => (float) $profile['climb_distance_nm'],
            'cruise_minutes' => $cruiseMinutes,
            'cruise_distance_nm' => $cruiseDistanceNm,
            'descent_minutes' => (float) $profile['descent_minutes'],
            'descent_distance_nm' => (float) $profile['descent_distance_nm'],
            'fixed_operational_minutes' => (float) $profile['fixed_operational_minutes'],
            'rounding_increment_minutes' => $roundingIncrementMinutes,
            'stored_speed_value' => $storedSpeedValue,
            'stored_speed_unit' => $storedSpeedUnit,
            'speed_conversion_applied' => $speedConversionApplied,
            'speed_knots_used' => $baseSpeedKnots,
            'cruise_speed_knots' => $baseSpeedKnots,
            'effective_speed_knots' => $effectiveSpeedKnots,
            'effective_speed_kmh' => $effectiveSpeedKmh,
            'speed_source' => $this->resolveSpeedSource($aircraft),
            'profile_source' => $profile['profile_source'],
            'profile_match_level' => $profile['profile_match_level'],
            'profile_snapshot' => $profile,
            'accumulated_minutes' => $accumulatedMinutes,
            'departure_datetime' => $departureDateTime?->toIso8601String(),
            'arrival_datetime' => $arrivalDateTime?->toIso8601String(),
        ];

        if (config('vuelos.pricing_debug')) {
            Log::debug('[FlightDuration][Leg Audit]', [
                'pricing_calculation_id' => $context['calculation_id'] ?? null,
                'aircraft_id' => $aircraft->id,
                'aircraft_name' => $aircraft->model,
                'leg_number' => $context['leg_number'] ?? null,
                'origin' => $leg['origin'],
                'destination' => $leg['destination'],
                'distance_nm' => $distanceNm,
                'cruise_speed_knots' => $baseSpeedKnots,
                'effective_speed_knots' => $effectiveSpeedKnots,
                'taxi_out_minutes' => $leg['taxi_out_minutes'],
                'takeoff_minutes' => $leg['takeoff_minutes'],
                'climb_minutes' => $leg['climb_minutes'],
                'cruise_minutes' => $leg['cruise_minutes'],
                'descent_minutes' => $leg['descent_minutes'],
                'landing_minutes' => $leg['landing_minutes'],
                'taxi_in_minutes' => $leg['taxi_in_minutes'],
                'operational_minutes_raw' => $operationalMinutesRaw,
                'rounded_minutes' => $roundedMinutes,
                'billable_minutes' => $billableMinutes,
                'accumulated_minutes' => $accumulatedMinutes,
                'arrival_time' => $arrivalDateTime?->toIso8601String(),
                'profile_source' => $profile['profile_source'],
            ]);
        }

        return $leg;
    }

    private function resolveProfile(Aeronave $aircraft): array
    {
        $cacheKey = implode(':', [
            (string) ($aircraft->id ?? 'new'),
            mb_strtolower(trim((string) ($aircraft->model ?? ''))),
            mb_strtolower(trim((string) ($aircraft->category ?? ''))),
        ]);

        if (array_key_exists($cacheKey, $this->resolvedProfiles)) {
            return $this->resolvedProfiles[$cacheKey];
        }

        $globalProfile = $this->globalProfile();
        $typeProfile = $this->typeProfile((string) ($aircraft->category ?? ''));
        $resolved = [...$globalProfile, ...$typeProfile];

        $dbProfile = $this->findDatabaseProfile($aircraft);
        if ($dbProfile instanceof AircraftPerformanceProfile) {
            $resolved = [...$resolved, ...$this->profileFromModel($dbProfile)];
            $resolved['profile_source'] = 'database';
            $resolved['profile_match_level'] = $this->profileMatchLevel($dbProfile, $aircraft);
            $resolved['profile_id'] = $dbProfile->id;
        } else {
            $resolved['profile_source'] = $typeProfile !== [] ? 'config.aircraft_type' : 'config.global';
            $resolved['profile_match_level'] = $typeProfile !== [] ? 'aircraft_type' : 'global';
            $resolved['profile_id'] = null;
        }

        return $this->resolvedProfiles[$cacheKey] = $resolved;
    }

    private function findDatabaseProfile(Aeronave $aircraft): ?AircraftPerformanceProfile
    {
        if (! $this->profilesTableExists()) {
            return null;
        }

        $query = AircraftPerformanceProfile::query()
            ->where('is_active', true)
            ->where(function ($query) use ($aircraft) {
                $query->where('aircraft_id', $aircraft->id);

                $model = mb_strtolower(trim((string) ($aircraft->model ?? '')));
                if ($model !== '') {
                    $query->orWhereRaw('LOWER(aircraft_model) = ?', [$model]);
                }

                $type = mb_strtolower(trim((string) ($aircraft->category ?? '')));
                if ($type !== '') {
                    $query->orWhereRaw('LOWER(aircraft_type) = ?', [$type]);
                }
            })
            ->orderByRaw('case when aircraft_id = ? then 0 when LOWER(aircraft_model) = ? then 1 when LOWER(aircraft_type) = ? then 2 else 3 end', [
                $aircraft->id,
                mb_strtolower(trim((string) ($aircraft->model ?? ''))),
                mb_strtolower(trim((string) ($aircraft->category ?? ''))),
            ])
            ->orderByDesc('id');

        return $query->first();
    }

    private function profilesTableExists(): bool
    {
        return $this->profilesTableExists ??= Schema::hasTable('aircraft_performance_profiles');
    }

    private function profileFromModel(AircraftPerformanceProfile $profile): array
    {
        $values = [];
        foreach (self::PROFILE_KEYS as $key) {
            $values[$key] = (float) ($profile->{$key} ?? 0.0);
        }

        return $values;
    }

    private function globalProfile(): array
    {
        return $this->normalizeProfile((array) config('vuelos.dynamic_flight_time.global_profile', []));
    }

    private function typeProfile(string $aircraftType): array
    {
        $profiles = (array) config('vuelos.dynamic_flight_time.profiles_by_aircraft_type', []);

        foreach ($profiles as $type => $profile) {
            if (mb_strtolower(trim((string) $type)) === mb_strtolower(trim($aircraftType))) {
                return $this->normalizeProfile((array) $profile);
            }
        }

        return [];
    }

    private function normalizeProfile(array $profile): array
    {
        $normalized = [];
        foreach (self::PROFILE_KEYS as $key) {
            if (array_key_exists($key, $profile)) {
                $normalized[$key] = (float) $profile[$key];
            }
        }

        return $normalized;
    }

    private function profileMatchLevel(AircraftPerformanceProfile $profile, Aeronave $aircraft): string
    {
        if ((int) ($profile->aircraft_id ?? 0) === (int) ($aircraft->id ?? 0)) {
            return 'aircraft_id';
        }

        if (mb_strtolower(trim((string) ($profile->aircraft_model ?? ''))) === mb_strtolower(trim((string) ($aircraft->model ?? '')))) {
            return 'aircraft_model';
        }

        return 'aircraft_type';
    }

    private function resolveEffectiveSpeedFactor(float $distanceNm, array $profile): float
    {
        $shortThresholdNm = (float) ($profile['short_leg_threshold_nm'] ?? 180.0);
        $mediumThresholdNm = (float) ($profile['medium_leg_threshold_nm'] ?? 500.0);

        return match (true) {
            $distanceNm <= $shortThresholdNm => max((float) ($profile['short_leg_speed_factor'] ?? 0.80), 0.1),
            $distanceNm <= $mediumThresholdNm => max((float) ($profile['medium_leg_speed_factor'] ?? 0.90), 0.1),
            default => max((float) ($profile['long_leg_speed_factor'] ?? 1.0), 0.1),
        };
    }

    private function roundMinutes(float $minutes, float $increment): float
    {
        $strategy = (string) config('vuelos.dynamic_flight_time.rounding_strategy', 'ceil');

        if ($increment <= 0) {
            return round($minutes, 2);
        }

        return match ($strategy) {
            'nearest' => round($minutes / $increment) * $increment,
            default => ceil($minutes / $increment) * $increment,
        };
    }

    private function resolveStoredSpeedValue(Aeronave $aircraft): float
    {
        if ((float) ($aircraft->speed_knots ?? 0) > 0) {
            return (float) $aircraft->speed_knots;
        }

        return (float) ($aircraft->speed_kmh ?? 0);
    }

    private function resolveStoredSpeedUnit(Aeronave $aircraft): string
    {
        if ((float) ($aircraft->speed_knots ?? 0) > 0) {
            return 'knots';
        }

        return 'km/h_to_knots';
    }

    private function resolveSpeedSource(Aeronave $aircraft): string
    {
        if ((float) ($aircraft->speed_knots ?? 0) > 0) {
            return 'aircraft.speed_knots';
        }

        return (float) ($aircraft->speed_kmh ?? 0) > 0
            ? 'aircraft.speed_kmh'
            : 'category_fallback';
    }

    private function resolveBaseSpeedKnots(Aeronave $aircraft): float
    {
        if ((float) ($aircraft->speed_knots ?? 0) > 0) {
            return (float) $aircraft->speed_knots;
        }

        return $this->legacyFlightDurationService->cruiseSpeedKmh($aircraft) / 1.852;
    }

    private function commercialHourlyRate(mixed $value): float
    {
        $rate = (float) $value;

        return $rate > 0 && $rate < 100 ? $rate * 1000 : $rate;
    }
}
