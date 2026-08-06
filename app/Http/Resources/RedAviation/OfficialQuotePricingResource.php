<?php

namespace App\Http\Resources\RedAviation;

use App\Servicios\Vuelos\FlightPricingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfficialQuotePricingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return self::build(is_array($this->resource) ? $this->resource : []);
    }

    public static function build(array $pricing, array $overrides = []): array
    {
        $breakdown = self::buildPricingBreakdown($pricing, $overrides);
        $displayHours = self::normalizeHours($breakdown['display_route_hours'] ?? 0);
        $operationalHours = self::normalizeHours($breakdown['operational_flight_hours'] ?? 0);
        $directHours = self::normalizeHours($breakdown['direct_flight_hours'] ?? 0);
        $finalBillableHours = self::normalizeHours($breakdown['final_billable_hours'] ?? 0);
        $billableHours = self::normalizeHours($breakdown['billable_hours'] ?? 0);
        $cardTime = self::formatDisplayHours($displayHours);
        $billableTime = self::formatDisplayHours($billableHours);
        $estimatedFlightMinutes = round($displayHours * 60, 2);
        $billableFlightMinutes = round((float) ($breakdown['billable_minutes'] ?? ($billableHours * 60)), 2);
        $currency = (string) ($breakdown['currency'] ?? 'USD');
        $totalAmount = round((float) ($breakdown['total_amount'] ?? 0), 2);
        $pricingVersion = (string) ($breakdown['pricing_version'] ?? FlightPricingService::FORMULA_VERSION);

        return [
            'time' => $cardTime,
            'card_time' => $cardTime,
            'display_time' => $cardTime,
            'ui_time' => $cardTime,
            'trip_time' => $cardTime,
            'flight_time' => $cardTime,
            'estimated_flight_time' => $cardTime,
            'billed_time' => $billableTime,
            'billable_flight_time' => $billableTime,
            'repositioning_time' => self::formatHours($breakdown['repositioning_hours'] ?? 0),
            'return_to_base_time' => self::formatHours($breakdown['return_to_base_hours'] ?? 0),
            'estimated_flight_minutes' => $estimatedFlightMinutes,
            'billable_flight_minutes' => $billableFlightMinutes,
            'display_flight_hours' => $displayHours,
            'display_route_hours' => $displayHours,
            'client_display_flight_hours' => $displayHours,
            'card_flight_hours' => $displayHours,
            'ui_flight_hours' => $displayHours,
            'trip_flight_hours' => $displayHours,
            'operational_flight_hours' => $operationalHours,
            'client_operational_flight_hours' => $operationalHours,
            'estimated_hours' => $directHours,
            'direct_route_hours' => $directHours,
            'real_flight_hours' => $operationalHours,
            'pricing_hours' => $finalBillableHours,
            'final_billable_hours' => $finalBillableHours,
            'billable_hours' => $billableHours,
            'billable_minutes' => $billableFlightMinutes,
            'final_hours' => $billableHours,
            'distance_km' => round((float) ($breakdown['distance_km'] ?? 0), 2),
            'distance_nm' => round((float) ($breakdown['distance_nm'] ?? 0), 2),
            'flight_cost' => round((float) ($breakdown['flight_cost'] ?? 0), 2),
            'repositioning_cost' => round((float) ($breakdown['repositioning_cost'] ?? 0), 2),
            'overnight_cost' => round((float) ($breakdown['overnight_cost'] ?? 0), 2),
            'airport_expenses' => round((float) ($breakdown['airport_expenses'] ?? 0), 2),
            'stripe_fee' => round((float) ($breakdown['stripe_fee'] ?? 0), 2),
            'administrative_fee' => round((float) ($breakdown['administrative_fee'] ?? 0), 2),
            'subtotal' => round((float) ($breakdown['subtotal'] ?? 0), 2),
            'taxes' => round((float) ($breakdown['taxes'] ?? 0), 2),
            'total' => $totalAmount,
            'total_amount' => $totalAmount,
            'quoted_total' => $totalAmount,
            'currency' => $currency,
            'pricing_version' => $pricingVersion,
            'pricing_breakdown' => $breakdown,
            'pricing' => [
                'estimated_flight_minutes' => $estimatedFlightMinutes,
                'billable_flight_minutes' => $billableFlightMinutes,
                'estimated_flight_time' => $cardTime,
                'billable_flight_time' => $billableTime,
                'customer_flight_cost' => round((float) ($breakdown['client_flight_cost'] ?? $breakdown['flight_cost'] ?? 0), 2),
                'repositioning_cost' => round((float) ($breakdown['repositioning_cost'] ?? 0), 2),
                'return_to_base_cost' => round((float) ($breakdown['return_to_base_cost'] ?? 0), 2),
                'airport_expenses' => round((float) ($breakdown['airport_expenses'] ?? 0), 2),
                'overnight_cost' => round((float) ($breakdown['overnight_cost'] ?? 0), 2),
                'margin_amount' => round((float) ($breakdown['margin_amount'] ?? 0), 2),
                'payment_fees' => round((float) ($breakdown['stripe_fee'] ?? 0) + (float) ($breakdown['administrative_fee'] ?? 0), 2),
                'taxes' => round((float) ($breakdown['taxes'] ?? 0), 2),
                'total_amount' => $totalAmount,
                'currency' => $currency,
                'pricing_version' => $pricingVersion,
            ],
        ];
    }

    public static function pricingBreakdown(array $pricing, array $overrides = []): array
    {
        return self::buildPricingBreakdown($pricing, $overrides);
    }

    private static function buildPricingBreakdown(array $pricing, array $overrides = []): array
    {
        $distanceKm = self::resolveFloat($overrides, [
            'distance_km',
        ], self::resolveFloat($pricing, [
            'distance_km',
            'route_snapshot.distance_km',
        ], 0));
        $distanceNm = self::resolveFloat($overrides, [
            'distance_nm',
        ], self::resolveFloat($pricing, [
            'distance_nm',
            'route_snapshot.distance_nm',
        ], 0));
        $directHours = self::resolveFloat($pricing, [
            'direct_flight_hours',
            'client_direct_flight_hours',
            'direct_route_hours',
            'route_direct_hours',
        ], 0);
        $operationalHours = self::resolveFloat($pricing, [
            'operational_flight_hours',
            'client_operational_flight_hours',
            'route_operational_hours',
        ], 0);
        $displayHours = self::resolveFloat($pricing, [
            'display_route_hours',
            'client_display_flight_hours',
            'route_operational_display_hours',
        ], $operationalHours);
        $finalBillableHours = self::resolveFloat($pricing, [
            'final_billable_hours',
            'pricing_hours',
            'flight_base_hours',
        ], $operationalHours);
        $billableHours = self::resolveFloat($pricing, [
            'billable_hours',
            'total_billed_hours',
            'duration_snapshot.billable_hours',
        ], $finalBillableHours);
        $currency = (string) self::resolveValue($overrides, ['currency'], self::resolveValue($pricing, ['currency'], 'USD'));
        $pricingVersion = (string) self::resolveValue($pricing, [
            'pricing_version',
            'pricing_formula_version',
            'quote_strategy',
        ], FlightPricingService::FORMULA_VERSION);
        $calculatedAt = self::resolveValue($pricing, ['calculated_at'], null);

        $breakdown = array_merge($pricing, [
            'distance_nm' => round($distanceNm, 2),
            'distance_km' => round($distanceKm, 2),
            'direct_flight_hours' => self::normalizeHours($directHours),
            'operational_flight_hours' => self::normalizeHours($operationalHours),
            'display_route_hours' => self::normalizeHours($displayHours),
            'final_billable_hours' => self::normalizeHours($finalBillableHours),
            'billable_hours' => self::normalizeHours($billableHours),
            'flight_cost' => round((float) self::resolveValue($pricing, ['flight_cost'], 0), 2),
            'repositioning_cost' => round((float) self::resolveValue($pricing, ['repositioning_cost', 'initial_repositioning_cost'], 0), 2),
            'overnight_cost' => round((float) self::resolveValue($pricing, ['overnight_cost'], 0), 2),
            'airport_expenses' => round((float) self::resolveValue($pricing, ['airport_expenses', 'expense_fee'], 0), 2),
            'stripe_fee' => round((float) self::resolveValue($pricing, ['stripe_fee'], 0), 2),
            'administrative_fee' => round((float) self::resolveValue($pricing, ['administrative_fee'], 0), 2),
            'taxes' => round((float) self::resolveValue($pricing, ['taxes', 'tax', 'iva', 'iva_amount'], 0), 2),
            'subtotal' => round((float) self::resolveValue($pricing, ['subtotal'], 0), 2),
            'total_amount' => round((float) self::resolveValue($pricing, ['total_amount', 'total', 'final_price'], 0), 2),
            'currency' => $currency,
            'pricing_version' => $pricingVersion,
            'pricing_formula_version' => $pricingVersion,
            'quote_strategy' => $pricingVersion,
            'calculated_at' => $calculatedAt,
        ]);

        if (! array_key_exists('tax', $breakdown)) {
            $breakdown['tax'] = $breakdown['taxes'];
        }
        if (! array_key_exists('total', $breakdown)) {
            $breakdown['total'] = $breakdown['total_amount'];
        }
        if (! array_key_exists('final_price', $breakdown)) {
            $breakdown['final_price'] = $breakdown['total_amount'];
        }

        return $breakdown;
    }

    private static function resolveFloat(array $source, array $paths, float $default): float
    {
        $value = self::resolveValue($source, $paths, $default);

        return (float) $value;
    }

    private static function resolveValue(array $source, array $paths, mixed $default): mixed
    {
        foreach ($paths as $path) {
            $segments = explode('.', $path);
            $value = $source;

            foreach ($segments as $segment) {
                if (! is_array($value) || ! array_key_exists($segment, $value)) {
                    $value = null;
                    break;
                }

                $value = $value[$segment];
            }

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $default;
    }

    private static function normalizeHours(mixed $value): float
    {
        return (float) $value;
    }

    private static function formatHours(mixed $hours): string
    {
        return self::formatDisplayHours((float) $hours);
    }

    private static function formatDisplayHours(float $hours): string
    {
        if (! is_finite($hours) || $hours <= 0) {
            return '0 min';
        }

        $totalMinutes = max((int) round($hours * 60), 1);
        $hourPart = intdiv($totalMinutes, 60);
        $minutePart = $totalMinutes % 60;

        if ($hourPart === 0) {
            return "{$minutePart} min";
        }

        if ($minutePart === 0) {
            return "{$hourPart} h";
        }

        return "{$hourPart} h ".str_pad((string) $minutePart, 2, '0', STR_PAD_LEFT).' min';
    }
}
