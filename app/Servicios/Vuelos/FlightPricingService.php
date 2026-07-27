<?php

namespace App\Servicios\Vuelos;

use App\Modelos\Aeronave;
use Closure;

final class FlightPricingService
{
    public const FORMULA_VERSION = 'official_backend_pricing_v3';

    public const UNTRUSTED_FIELDS = [
        'duration_minutes',
        'estimated_minutes',
        'quoted_minutes',
        'flight_minutes',
        'leg_minutes',
        'duration_hours',
        'distance',
        'distance_km',
        'distance_nm',
        'billable_hours',
        'billable_minutes',
        'time_display_mode',
        'billing_hours_mode',
        'rounding_mode',
        'flight_base_source',
        'include_repositioning_in_billed_hours',
        'include_return_to_base_in_billed_hours',
        'include_overnight_in_billed_hours',
        'include_iva',
        'airport_expenses',
        'apply_margin',
        'hourly_rate',
        'rate',
        'tariff',
        'base_price',
        'operational_fee',
        'priority_price',
        'subtotal',
        'tax',
        'taxes',
        'iva',
        'iva_amount',
        'total',
        'total_amount',
        'estimated_total',
        'final_price',
        'selected_card_price',
        'pricing_context',
        'aircraft_snapshot',
        'pricing_formula_version',
    ];

    public function calculate(
        Aeronave $aircraft,
        array $canonicalRoute,
        array $clientPayload,
        Closure $trustedFormula,
    ): array {
        $trustedInput = $this->sanitizeClientPayload($clientPayload);
        $trustedInput['origin'] = $canonicalRoute['origin'];
        $trustedInput['destination'] = $canonicalRoute['destination'];
        $trustedInput['trip_type'] = $canonicalRoute['trip_type'];
        $trustedInput['legs'] = $canonicalRoute['legs'];

        $pricing = $trustedFormula(
            $aircraft,
            $canonicalRoute['trip_type'],
            $canonicalRoute['legs'],
            $trustedInput,
        );

        return [
            ...$pricing,
            'quote_strategy' => self::FORMULA_VERSION,
            'pricing_formula_version' => self::FORMULA_VERSION,
            'calculated_at' => now()->toISOString(),
            'route_snapshot' => [
                'signature' => $canonicalRoute['route_signature'],
                'distance_km' => $canonicalRoute['distance_km'],
                'distance_nm' => $canonicalRoute['distance_nm'],
                'max_leg_distance_km' => $canonicalRoute['max_leg_distance_km'],
                'legs' => $canonicalRoute['legs'],
            ],
            'duration_snapshot' => [
                'display_flight_hours' => (float) ($pricing['client_display_flight_hours'] ?? 0),
                'operational_hours' => (float) ($pricing['client_operational_flight_hours'] ?? 0),
                'billable_hours' => (float) ($pricing['billable_hours'] ?? 0),
                'source' => 'backend_distance_and_aircraft_speed',
            ],
            'ignored_client_pricing_fields' => $this->presentFields($clientPayload),
        ];
    }

    public function sanitizeClientPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (in_array((string) $key, self::UNTRUSTED_FIELDS, true)) {
                unset($payload[$key]);

                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->sanitizeClientPayload($value);
            }
        }

        return $payload;
    }

    public function presentFields(array $payload, string $prefix = ''): array
    {
        $found = [];
        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (in_array((string) $key, self::UNTRUSTED_FIELDS, true)) {
                $found[] = $path;
            }
            if (is_array($value)) {
                $found = [...$found, ...$this->presentFields($value, $path)];
            }
        }

        return array_values(array_unique($found));
    }
}
