<?php

namespace App\Servicios\Vuelos;

use App\Modelos\Aeronave;
use App\Modelos\Aeropuerto;

final class FlightDurationService
{
    private const MACH_1_KMH = 1062.0;

    private const SHORT_ROUTE_DISTANCE_KM = 300.0;

    public function calculateLeg(
        Aeronave $aircraft,
        Aeropuerto $origin,
        Aeropuerto $destination,
        float $distanceKm,
        float $distanceNm,
        bool $applyMinimumHours = true,
    ): array {
        $speedKnots = (float) ($aircraft->speed_knots ?? 0);
        if ($speedKnots <= 0) {
            $speedKnots = max($this->cruiseSpeedKmh($aircraft) / 1.852, 1);
        }

        $directHours = $distanceNm / $speedKnots;
        $climbMinutes = $this->climbDescentMinutes($aircraft, $origin, $destination);
        $operationalMinutes = max(
            ($directHours * 60 * $this->operationalFactor($aircraft)) + $this->fixedMinutes($aircraft),
            $this->minimumMinutes($aircraft),
        );
        $operationalHours = round($operationalMinutes / 60, 4);
        $minimumHours = $applyMinimumHours ? $this->minimumHours($aircraft->category, $distanceKm) : 0.0;
        $billableHours = max(round($directHours * 4) / 4, $minimumHours);
        $hourlyRate = $this->commercialHourlyRate($aircraft->hourly_rate);

        return [
            'origin' => $origin->icao ?: $origin->iata,
            'destination' => $destination->icao ?: $destination->iata,
            'hours_source' => 'distance_speed',
            'manual_duration_minutes' => 0.0,
            'manual_duration_hours' => 0.0,
            'manual_duration_field' => null,
            'distance_speed_hours' => $directHours,
            'distance_nm' => $distanceNm,
            'distance_km' => $distanceKm,
            'adjusted_distance_nm' => $distanceNm,
            'direct_air_time_hours' => $directHours,
            'air_time_hours' => $directHours,
            'direct_minutes' => $directHours * 60,
            'climb_descent_minutes' => $climbMinutes,
            'climb_descent_hours' => $climbMinutes / 60,
            'reserve_hours' => $this->operationalBufferHours($distanceNm),
            'display_flight_hours' => $directHours,
            'operational_factor' => $this->operationalFactor($aircraft),
            'fixed_minutes_per_leg' => $this->fixedMinutes($aircraft),
            'minimum_minutes_per_leg' => $this->minimumMinutes($aircraft),
            'calculated_minutes' => (int) round($operationalMinutes),
            'operational_minutes' => (int) round($operationalMinutes),
            'operational_flight_hours' => $operationalHours,
            'commercial_flight_hours' => $operationalHours,
            'real_flight_hours' => $operationalHours,
            'minimum_hours' => $minimumHours,
            'buffer_hours' => $this->operationalBufferHours($distanceNm),
            'billable_hours' => $billableHours,
            'final_hours' => $billableHours,
            'raw_leg_cost' => $billableHours * $hourlyRate,
            'base_price' => $billableHours * $hourlyRate,
            'minimum_route_price' => 0,
            'leg_cost' => $billableHours * $hourlyRate,
            'international' => strtoupper((string) $origin->country) !== strtoupper((string) $destination->country),
        ];
    }

    public function cruiseSpeedKmh(Aeronave $aircraft): float
    {
        if ((float) $aircraft->speed_kmh > 0) {
            return (float) $aircraft->speed_kmh;
        }

        $mach = match ($this->category($aircraft->category)) {
            'Helicoptero' => 0.35,
            'Light Jet' => 0.75,
            'Mid Jet' => 0.81,
            'Heavy Jet' => 0.87,
            'Ultra Long Range' => 0.92,
            default => null,
        };

        return $mach ? $mach * self::MACH_1_KMH : 740.0;
    }

    public function minimumHours(mixed $category, ?float $distanceKm = null): float
    {
        if ($distanceKm !== null && $distanceKm >= self::SHORT_ROUTE_DISTANCE_KM) {
            return 0.0;
        }

        return match ($this->pricingCategory($category)) {
            'helicopter' => 1.0,
            'turboprop' => 1.5,
            'light_jet' => 2.0,
            'mid_jet' => 2.5,
            'heavy_jet' => 3.0,
            'ultra_long' => 4.0,
            default => 2.0,
        };
    }

    private function climbDescentMinutes(Aeronave $aircraft, Aeropuerto $origin, Aeropuerto $destination): int
    {
        $base = (int) ($aircraft->climb_descent_minutes ?? 0);
        if ($base <= 0) {
            $base = match ($this->category($aircraft->category)) {
                'Helicoptero' => 15,
                'Turboprop' => 25,
                'Light Jet' => 30,
                'Mid Jet' => 35,
                'Heavy Jet', 'Ultra Long Range' => 45,
                default => 30,
            };
        }

        return max(15, $base
            + (int) ($origin->climb_descent_adjustment_minutes ?? 0)
            + (int) ($destination->climb_descent_adjustment_minutes ?? 0));
    }

    private function operationalFactor(Aeronave $aircraft): float
    {
        if ((float) ($aircraft->operational_factor ?? 0) > 0) {
            return (float) $aircraft->operational_factor;
        }

        return match ($this->category($aircraft->category)) {
            'Helicoptero' => 1.10,
            'Turboprop' => 1.20,
            'Light Jet' => 1.32,
            'Mid Jet' => 1.25,
            'Heavy Jet', 'Ultra Long Range' => 1.18,
            default => 1.25,
        };
    }

    private function fixedMinutes(Aeronave $aircraft): int
    {
        if ((int) ($aircraft->fixed_minutes_per_leg ?? 0) > 0) {
            return (int) $aircraft->fixed_minutes_per_leg;
        }

        return match ($this->category($aircraft->category)) {
            'Helicoptero' => 10,
            'Turboprop' => 15,
            'Light Jet' => 18,
            'Mid Jet' => 20,
            'Heavy Jet', 'Ultra Long Range' => 25,
            default => 20,
        };
    }

    private function minimumMinutes(Aeronave $aircraft): int
    {
        if ((int) ($aircraft->minimum_minutes_per_leg ?? 0) > 0) {
            return (int) $aircraft->minimum_minutes_per_leg;
        }

        return match ($this->category($aircraft->category)) {
            'Helicoptero' => 20,
            'Turboprop' => 30,
            'Light Jet' => 35,
            'Mid Jet' => 40,
            'Heavy Jet', 'Ultra Long Range' => 45,
            default => 35,
        };
    }

    private function operationalBufferHours(float $distanceNm): float
    {
        return match (true) {
            $distanceNm < 300 => 0.25,
            $distanceNm < 600 => 0.35,
            $distanceNm < 1000 => 0.45,
            default => 0.50,
        };
    }

    private function commercialHourlyRate(mixed $value): float
    {
        $rate = (float) $value;

        return $rate > 0 && $rate < 100 ? $rate * 1000 : $rate;
    }

    private function category(mixed $value): ?string
    {
        return match (mb_strtolower(trim((string) ($value ?? '')))) {
            'helicoptero', 'helicóptero', 'helicopter' => 'Helicoptero',
            'turboprop', 'turbo prop', 'turbo_prop' => 'Turboprop',
            'light jet', 'light_jet', 'lightjet' => 'Light Jet',
            'mid jet', 'mid_jet', 'midjet', 'midsize jet', 'midsize_jet', 'super mid', 'super_mid' => 'Mid Jet',
            'heavy jet', 'heavy_jet', 'heavyjet', 'long range', 'long_range' => 'Heavy Jet',
            'ultra long', 'ultra_long', 'ultra long range', 'ultra_long_range' => 'Ultra Long Range',
            default => null,
        };
    }

    private function pricingCategory(mixed $value): string
    {
        return match ($this->category($value)) {
            'Helicoptero' => 'helicopter',
            'Turboprop' => 'turboprop',
            'Light Jet' => 'light_jet',
            'Mid Jet' => 'mid_jet',
            'Heavy Jet' => 'heavy_jet',
            'Ultra Long Range' => 'ultra_long',
            default => 'default',
        };
    }
}
