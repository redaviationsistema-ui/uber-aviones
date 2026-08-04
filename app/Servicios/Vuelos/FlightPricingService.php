<?php

namespace App\Servicios\Vuelos;

use App\Modelos\Aeronave;
use App\Modelos\Aeropuerto;
use App\Modelos\ReglaGastoAeropuerto;
use App\Modelos\ReglaPrecioCategoria;
use App\Servicios\Pagos\PaymentFeeCalculationServicio;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class FlightPricingService
{
    public const FORMULA_VERSION = 'official_backend_pricing_v4';

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

    private const TIME_MODE_DIRECT = 'direct';

    private const TIME_MODE_DIRECT_PLUS_CLIMB = 'direct_plus_climb';

    private const TIME_MODE_OPERATIONAL = 'operational';

    private const ROUNDING_MODE_NONE = 'none';

    private const ROUNDING_MODE_QUARTER_NEAREST = 'quarter_nearest';

    private const ROUNDING_MODE_QUARTER_UP = 'quarter_up';

    private const DEFAULT_IVA_RATE = 0.16;

    private const DEFAULT_AIRPORT_EXPENSE_USD = 1000.0;

    private const COMMERCIAL_OVERNIGHT_HOURS_PER_NIGHT = 0.5;

    private const SHORT_ROUTE_DISTANCE_KM = 300.0;

    private const AIRCRAFT_CATEGORY_MINIMUM_PRICE = [
        'Helicoptero' => ['minimum_route_price' => 2200.0, 'redsky_markup' => 20.0],
        'Turboprop' => ['minimum_route_price' => 2800.0, 'redsky_markup' => 20.0],
        'Light Jet' => ['minimum_route_price' => 3800.0, 'redsky_markup' => 22.0],
        'Mid Jet' => ['minimum_route_price' => 4800.0, 'redsky_markup' => 25.0],
        'Heavy Jet' => ['minimum_route_price' => 7000.0, 'redsky_markup' => 30.0],
    ];

    private ?bool $categoryPricingRulesTableExists = null;

    private array $categoryPricingRuleCache = [];

    private ?bool $airportExpenseRulesTableExists = null;

    private ?Collection $airportExpenseRulesCache = null;

    public function __construct(
        private readonly FlightDurationService $flightDurationService,
        private readonly DynamicFlightDurationService $dynamicFlightDurationService,
        private readonly PaymentFeeCalculationServicio $paymentFeeCalculationServicio,
    ) {}

    public function calculate(
        Aeronave $aircraft,
        array $canonicalRoute,
        array $clientPayload,
        Closure $trustedFormula,
    ): array {
        unset($trustedFormula);

        $trustedInput = $this->sanitizeClientPayload($clientPayload);
        $trustedInput['origin'] = $canonicalRoute['origin'];
        $trustedInput['destination'] = $canonicalRoute['destination'];
        $trustedInput['trip_type'] = $canonicalRoute['trip_type'];
        $trustedInput['legs'] = $canonicalRoute['legs'];

        return $this->calculateForAircraft($aircraft, $canonicalRoute, $trustedInput, $clientPayload);
    }

    public function calculateForAircraft(
        Aeronave $aircraft,
        array $canonicalRoute,
        array $options = [],
        ?array $rawClientPayload = null,
    ): array {
        $trustedInput = $this->sanitizeClientPayload($options);
        $dynamicOperationalEnabled = $this->useOperationalFlightTimeModel($trustedInput);
        $defaultTimeMode = $dynamicOperationalEnabled ? self::TIME_MODE_OPERATIONAL : self::TIME_MODE_DIRECT;
        if (! array_key_exists('time_display_mode', $trustedInput)) {
            $trustedInput['time_display_mode'] = $defaultTimeMode;
        }
        if (! array_key_exists('billing_hours_mode', $trustedInput)) {
            $trustedInput['billing_hours_mode'] = $defaultTimeMode;
        }
        // Client quote flows use exact decimal hours; do not trust or require public rounding input.
        $trustedInput['rounding_mode'] = self::ROUNDING_MODE_NONE;
        $tripType = (string) ($canonicalRoute['trip_type'] ?? 'one_way');
        $legs = array_values($canonicalRoute['legs'] ?? []);
        $hourlyRate = $this->resolveCommercialHourlyRate($aircraft->hourly_rate);
        $pricePerMinute = round($hourlyRate / 60, 2);
        $timeDisplayMode = $this->resolveTimeMode($trustedInput, 'time_display_mode', self::TIME_MODE_DIRECT);
        $billingHoursMode = $this->resolveTimeMode($trustedInput, 'billing_hours_mode', self::TIME_MODE_DIRECT);
        $roundingMode = $this->resolveRoundingMode($trustedInput, 'distance_speed');
        $marginRate = $this->shouldApplyCommercialMargin($trustedInput)
            ? $this->resolveCommercialMarginRate($aircraft, $this->resolveCategoryPricingRule($aircraft))
            : 0.0;
        $pricingCalculationId = (string) Str::uuid();
        $legPricings = [];
        $accumulatedMinutes = 0.0;

        foreach ($legs as $index => $leg) {
            $origin = $this->airportFromPayload($leg['origin_airport'] ?? []);
            $destination = $this->airportFromPayload($leg['destination_airport'] ?? []);
            $distanceKm = (float) ($leg['distance_km'] ?? 0);
            $distanceNm = (float) ($leg['distance_nm'] ?? 0);

            $legPricing = $dynamicOperationalEnabled
                ? $this->dynamicFlightDurationService->calculateLeg(
                    $aircraft,
                    $origin,
                    $destination,
                    $distanceKm,
                    $distanceNm,
                    false,
                    [
                        'calculation_id' => $pricingCalculationId,
                        'leg_number' => $index + 1,
                        'departure_datetime' => $leg['departure_datetime'] ?? null,
                        'accumulated_minutes_before' => $accumulatedMinutes,
                    ],
                )
                : $this->flightDurationService->calculateLeg(
                    $aircraft,
                    $origin,
                    $destination,
                    $distanceKm,
                    $distanceNm,
                    false,
                );

            $accumulatedMinutes += (float) ($legPricing['billable_minutes'] ?? (($legPricing['billable_hours'] ?? 0) * 60));
            $legPricings[] = $legPricing;
        }

        $calculatedClientLegs = collect($legs)
            ->map(function (array $leg, int $index) use ($legPricings, $timeDisplayMode, $billingHoursMode): array {
                $legPricing = $legPricings[$index] ?? [];
                $flightHours = (float) $this->resolveLegHoursByMode($legPricing, $billingHoursMode);
                $displayHours = (float) $this->resolveLegHoursByMode($legPricing, $timeDisplayMode);

                return [
                    ...$leg,
                    'sequence' => $index + 1,
                    'distance_km' => (float) ($legPricing['distance_km'] ?? $leg['distance_km'] ?? 0.0),
                    'distance_nm' => (float) ($legPricing['distance_nm'] ?? $leg['distance_nm'] ?? 0.0),
                    'direct_flight_hours' => (float) ($legPricing['direct_air_time_hours'] ?? 0.0),
                    'display_flight_hours' => $displayHours,
                    'operational_flight_hours' => (float) ($legPricing['real_flight_hours'] ?? 0.0),
                    'time_model' => $timeDisplayMode,
                    'flight_hours' => $flightHours,
                    'flight_minutes' => (int) round($flightHours * 60),
                ];
            })
            ->values()
            ->all();

        $routeDistanceKm = (float) collect($legPricings)->sum('distance_km');
        $routeDirectHours = (float) collect($legPricings)->sum('direct_air_time_hours');
        $routeOperationalHours = (float) collect($legPricings)->sum('real_flight_hours');
        $routeOperationalDisplayHours = (float) collect($legPricings)->sum(
            fn (array $legPricing): float => (float) ($legPricing['operational_display_hours'] ?? ($legPricing['real_flight_hours'] ?? 0.0))
        );
        $routeDisplayHours = (float) collect($legPricings)->sum(
            fn (array $legPricing) => $this->resolveLegHoursByMode($legPricing, $timeDisplayMode)
        );
        $routePricingHours = (float) collect($legPricings)->sum(
            fn (array $legPricing) => $this->resolveLegHoursByMode($legPricing, $billingHoursMode)
        );
        $rawLegHours = collect($legPricings)
            ->map(fn (array $legPricing): float => (float) $this->resolveLegHoursByMode($legPricing, $billingHoursMode))
            ->values()
            ->all();
        $rawRouteHours = (float) array_sum($rawLegHours);
        $routeClimbDescentMinutes = (float) collect($legPricings)->sum('climb_descent_minutes');
        $routeClimbDescentHours = (float) collect($legPricings)->sum('climb_descent_hours');
        $routeBillableHours = $roundingMode === self::ROUNDING_MODE_NONE
            ? $rawRouteHours
            : (float) collect($legPricings)->sum(
                fn (array $legPricing) => $this->applyRoundingMode(
                    (float) $this->resolveLegHoursByMode($legPricing, $billingHoursMode),
                    $roundingMode,
                )
            );

        $operationalContext = is_array($trustedInput['operational_context'] ?? null)
            ? $trustedInput['operational_context']
            : [];
        $applyRepositioningPricing = filter_var(
            $operationalContext['apply_repositioning_pricing'] ?? false,
            FILTER_VALIDATE_BOOL
        );
        $repositioning = $this->normalizeOperationalSegment($operationalContext['repositioning'] ?? null);
        $returnToBase = $this->normalizeOperationalSegment($operationalContext['return_to_base'] ?? null);
        $aircraftBaseAirport = is_array($operationalContext['aircraft_base_airport'] ?? null)
            ? $operationalContext['aircraft_base_airport']
            : null;
        $includeRepositioningInBilledHours = $applyRepositioningPricing
            && $this->shouldIncludeBilledComponent($trustedInput, 'include_repositioning_in_billed_hours', true);
        $includeReturnToBaseInBilledHours = $applyRepositioningPricing
            && $this->shouldIncludeBilledComponent($trustedInput, 'include_return_to_base_in_billed_hours', true);

        $configuredMinimumHours = max((float) ($aircraft->minimum_hours ?? 0), 0.0);
        $fallbackMinimumHours = $configuredMinimumHours > 0
            ? 0.0
            : $this->resolveFallbackMinimumHours($aircraft->category, $routeDistanceKm);
        $appliedMinimumHours = $configuredMinimumHours > 0
            ? $configuredMinimumHours
            : $fallbackMinimumHours;
        $clientBillableHours = max($routeBillableHours, $appliedMinimumHours, 0.0);
        $minimumHoursApplied = max($clientBillableHours - $routeBillableHours, 0.0);

        $rawClientFlightCost = $clientBillableHours * $hourlyRate;
        $directFlightCost = $routeDirectHours * $hourlyRate;
        $operationalFlightCost = $routeOperationalDisplayHours * $hourlyRate;
        $minimumRoutePricing = $this->resolveMinimumRoutePricing(
            $aircraft,
            $canonicalRoute,
            [
                'trip_type' => $tripType,
                'route_distance_km' => $routeDistanceKm,
                'route_distance_nm' => (float) collect($legPricings)->sum('distance_nm'),
                'final_billable_hours' => $clientBillableHours,
                'raw_client_flight_cost' => $rawClientFlightCost,
            ],
        );
        $minimumRoutePrice = max((float) ($minimumRoutePricing['amount'] ?? 0.0), 0.0);
        $minimumRoutePriceApplied = $minimumRoutePrice > 0.0 && $minimumRoutePrice > $rawClientFlightCost;
        $clientFlightCost = $minimumRoutePriceApplied ? $minimumRoutePrice : $rawClientFlightCost;
        $repositioningHours = (float) ($repositioning['billable_hours'] ?? 0.0);
        $returnToBaseHours = (float) ($returnToBase['billable_hours'] ?? 0.0);
        $repositioningCost = $applyRepositioningPricing ? (float) ($repositioning['cost'] ?? 0.0) : 0.0;
        $returnToBaseCost = $applyRepositioningPricing ? (float) ($returnToBase['cost'] ?? 0.0) : 0.0;
        $overnightNights = $this->resolveOvernightNights($legs, $trustedInput);
        $overnightFee = $this->resolveOvernightFee($aircraft, $hourlyRate);
        $overnightCost = $overnightNights > 0 ? $overnightFee * $overnightNights : 0.0;
        $includeOvernightInBilledHours = $this->shouldIncludeBilledComponent(
            $trustedInput,
            'include_overnight_in_billed_hours',
            false
        );
        $overnightHours = $includeOvernightInBilledHours
            ? $overnightNights * self::COMMERCIAL_OVERNIGHT_HOURS_PER_NIGHT
            : 0.0;
        $totalBilledHours = $clientBillableHours
            + ($includeRepositioningInBilledHours ? $repositioningHours : 0.0)
            + ($includeReturnToBaseInBilledHours ? $returnToBaseHours : 0.0)
            + ($includeOvernightInBilledHours ? $overnightHours : 0.0);

        $airportExpenseContext = $this->shouldApplyAirportExpenses($trustedInput)
            ? $this->resolveAirportExpenseContext($aircraft, $legs)
            : ['amount' => 0.0, 'source' => 'disabled_by_request'];
        $airportExpenses = (float) ($airportExpenseContext['amount'] ?? 0.0);

        $subtotalOperative = $clientFlightCost
            + ($includeRepositioningInBilledHours ? $repositioningCost : 0.0)
            + ($includeReturnToBaseInBilledHours ? $returnToBaseCost : 0.0)
            + $airportExpenses
            + $overnightCost;
        $subtotalBeforeMargin = $subtotalOperative;
        $minimumAdjustment = max($clientFlightCost - $rawClientFlightCost, 0.0);
        $marginAmount = $subtotalBeforeMargin * $marginRate;
        $subtotalBeforeFees = $subtotalBeforeMargin + $marginAmount;
        $paymentBreakdown = $this->paymentFeeCalculationServicio->flightBreakdown($subtotalBeforeFees);
        $stripeFee = (float) ($paymentBreakdown['stripe_fee'] ?? 0.0);
        $administrativeFee = (float) ($paymentBreakdown['administrative_fee'] ?? 0.0);
        $paymentFee = $stripeFee + $administrativeFee;
        $subtotal = $subtotalBeforeFees + $stripeFee + $administrativeFee;
        $taxableSubtotal = max($subtotal - $airportExpenses, 0.0);
        $taxRate = $this->shouldIncludeIva($trustedInput) ? self::DEFAULT_IVA_RATE : 0.0;
        $taxes = round($taxableSubtotal * $taxRate, 2);
        $totalAmount = round($subtotal + $taxes, 2);
        $hoursSource = 'distance_speed';
        $routeSignature = (string) ($canonicalRoute['route_signature'] ?? $this->buildRouteSignature($legs));
        $flightTimeComparison = [
            'model' => $dynamicOperationalEnabled ? 'operational' : 'direct',
            'direct_hours' => round($routeDirectHours, 4),
            'operational_hours' => round($routeOperationalHours, 4),
            'display_hours' => round($routeDisplayHours, 4),
            'billable_hours' => round($clientBillableHours, 4),
            'direct_price' => round($directFlightCost, 2),
            'operational_price' => round($operationalFlightCost, 2),
            'selected_price' => round($clientFlightCost, 2),
            'difference_hours_vs_direct' => round($clientBillableHours - $routeDirectHours, 4),
            'difference_price_vs_direct' => round($clientFlightCost - $directFlightCost, 2),
        ];
        $frontendPricingDebug = config('vuelos.frontend_pricing_debug') ? [
            'enabled' => true,
            'group_label' => sprintf(
                '%s %s %s',
                $aircraft->model ?? 'Aircraft',
                $canonicalRoute['route_signature'] ?? $this->buildRouteSignature($legs),
                $dynamicOperationalEnabled ? 'operational' : 'direct'
            ),
            'legs' => collect($legPricings)->map(function (array $legPricing, int $index) use ($hourlyRate): array {
                return [
                    'leg_number' => $index + 1,
                    'origin' => $legPricing['origin'] ?? null,
                    'destination' => $legPricing['destination'] ?? null,
                    'distance_nm' => round((float) ($legPricing['distance_nm'] ?? 0.0), 2),
                    'direct_minutes' => round((float) ($legPricing['direct_minutes'] ?? 0.0), 2),
                    'operational_minutes' => round((float) ($legPricing['operational_minutes'] ?? 0.0), 2),
                    'rounded_minutes' => round((float) ($legPricing['rounded_minutes'] ?? ($legPricing['billable_minutes'] ?? 0.0)), 2),
                    'billable_minutes' => round((float) ($legPricing['billable_minutes'] ?? 0.0), 2),
                    'departure_time' => $legPricing['departure_datetime'] ?? null,
                    'arrival_time' => $legPricing['arrival_datetime'] ?? null,
                    'accumulated_minutes' => round((float) ($legPricing['accumulated_minutes'] ?? 0.0), 2),
                    'accumulated_cost' => round(((float) ($legPricing['accumulated_minutes'] ?? 0.0) / 60) * $hourlyRate, 2),
                ];
            })->values()->all(),
        ] : null;

        $debugPricing = [
            'aircraft_name' => $aircraft->name ?? $aircraft->model ?? $aircraft->registration ?? null,
            'hours_source' => $hoursSource,
            'flight_time_model' => $dynamicOperationalEnabled ? 'operational' : 'direct',
            'route_signature' => $routeSignature,
            'hourly_rate_source' => $this->resolveHourlyRateSource($aircraft),
            'expense_fee_source' => (string) ($airportExpenseContext['source'] ?? 'unknown'),
            'rounding_mode' => $roundingMode,
            'raw_leg_hours' => $rawLegHours,
            'raw_route_hours' => $rawRouteHours,
            'direct_route_hours' => $routeDirectHours,
            'operational_route_hours' => $routeOperationalHours,
            'operational_display_hours' => $routeOperationalDisplayHours,
            'configured_minimum_hours' => $configuredMinimumHours,
            'fallback_minimum_hours' => $fallbackMinimumHours,
            'applied_minimum_hours' => $appliedMinimumHours,
            'minimum_hours_applied' => $minimumHoursApplied,
            'route_billable_hours' => $routeBillableHours,
            'final_billable_hours' => $clientBillableHours,
            'comparison' => $flightTimeComparison,
            'flight_cost' => round($clientFlightCost + $repositioningCost + $returnToBaseCost, 2),
            'raw_client_flight_cost' => round($rawClientFlightCost, 2),
            'minimum_route_price' => round($minimumRoutePrice, 2),
            'minimum_route_price_source' => (string) ($minimumRoutePricing['source'] ?? 'none'),
            'minimum_route_price_rule_id' => $minimumRoutePricing['rule_id'] ?? null,
            'minimum_route_price_applied' => $minimumRoutePriceApplied,
            'minimum_route_price_reason' => (string) ($minimumRoutePricing['reason'] ?? 'no_minimum_route_price'),
            'billable_flight_cost' => round($clientFlightCost, 2),
            'customer_flight_cost' => round($clientFlightCost, 2),
            'repositioning_cost' => round($repositioningCost, 2),
            'return_to_base_cost' => round($returnToBaseCost, 2),
            'repositioning_hours' => round($repositioningHours, 4),
            'return_to_base_hours' => round($returnToBaseHours, 4),
            'airport_expenses' => round($airportExpenses, 2),
            'overnight_fee' => round($overnightFee, 2),
            'overnight_nights' => $overnightNights,
            'overnight_hours' => round($overnightHours, 2),
            'overnight_cost' => round($overnightCost, 2),
            'subtotal_operative' => round($subtotalOperative, 2),
            'margin_amount' => round($marginAmount, 2),
            'payment_fee' => round($paymentFee, 2),
            'stripe_fee' => round($stripeFee, 2),
            'administrative_fee' => round($administrativeFee, 2),
            'taxable_subtotal' => round($taxableSubtotal, 2),
            'taxes' => round($taxes, 2),
            'total_amount' => round($totalAmount, 2),
        ];

        $pricing = [
            'aircraft_id' => $aircraft->id,
            'trip_type' => $tripType,
            'currency' => $aircraft->currency ?: 'USD',
            'hourly_rate' => $hourlyRate,
            'price_per_minute' => $pricePerMinute,
            'raw_leg_hours' => $rawLegHours,
            'raw_route_hours' => $rawRouteHours,
            'configured_minimum_hours' => $configuredMinimumHours,
            'fallback_minimum_hours' => $fallbackMinimumHours,
            'applied_minimum_hours' => $appliedMinimumHours,
            'minimum_hours_applied' => $minimumHoursApplied,
            'minimum_hours' => $appliedMinimumHours,
            'route_direct_hours' => $routeDirectHours,
            'direct_route_hours' => $routeDirectHours,
            'route_operational_hours' => $routeOperationalHours,
            'route_operational_display_hours' => $routeOperationalDisplayHours,
            'display_route_hours' => $routeDisplayHours,
            'operational_billable_hours' => $routePricingHours,
            'route_billable_hours' => $routeBillableHours,
            'final_billable_hours' => $clientBillableHours,
            'billable_hours' => $totalBilledHours,
            'total_billed_hours' => $totalBilledHours,
            'billable_minutes' => round($totalBilledHours * 60, 2),
            'flight_cost' => round($clientFlightCost + $repositioningCost + $returnToBaseCost, 2),
            'raw_client_flight_cost' => round($rawClientFlightCost, 2),
            'customer_flight_cost' => round($clientFlightCost, 2),
            'client_flight_cost' => round($clientFlightCost, 2),
            'base_price' => round($clientFlightCost, 2),
            'minimum_route_price' => round($minimumRoutePrice, 2),
            'minimum_route_price_source' => (string) ($minimumRoutePricing['source'] ?? 'none'),
            'minimum_route_price_rule_id' => $minimumRoutePricing['rule_id'] ?? null,
            'minimum_route_price_applied' => $minimumRoutePriceApplied,
            'minimum_route_price_reason' => (string) ($minimumRoutePricing['reason'] ?? 'no_minimum_route_price'),
            'minimum_adjustment' => round($minimumAdjustment, 2),
            'billable_flight_cost' => round($clientFlightCost, 2),
            'airport_expenses' => round($airportExpenses, 2),
            'airport_fees' => round($airportExpenses, 2),
            'expense_fee' => round($airportExpenses, 2),
            'overnight_fee' => round($overnightFee, 2),
            'overnight_nights' => $overnightNights,
            'overnight_hours' => round($overnightHours, 2),
            'overnight_cost' => round($overnightCost, 2),
            'margin_percentage' => round($marginRate * 100, 2),
            'margin_rate' => $marginRate,
            'margin_amount' => round($marginAmount, 2),
            'payment_fee' => round($paymentFee, 2),
            'stripe_fee' => round($stripeFee, 2),
            'administrative_fee' => round($administrativeFee, 2),
            'subtotal_before_margin' => round($subtotalBeforeMargin, 2),
            'subtotal' => round($subtotal, 2),
            'taxable_subtotal' => round($taxableSubtotal, 2),
            'tax_rate' => $taxRate,
            'tax' => round($taxes, 2),
            'taxes' => round($taxes, 2),
            'iva' => round($taxes, 2),
            'iva_amount' => round($taxes, 2),
            'landing_fee' => null,
            'tuag' => null,
            'total' => round($totalAmount, 2),
            'total_amount' => round($totalAmount, 2),
            'final_price' => round($totalAmount, 2),
            'selected_card_price' => round($totalAmount, 2),
            'client_legs' => $calculatedClientLegs,
            'legs' => $legPricings,
            'client_leg_pricing' => $legPricings,
            'repositioning_hours' => round($repositioningHours, 4),
            'return_to_base_hours' => round($returnToBaseHours, 4),
            'repositioning_cost' => round($repositioningCost, 2),
            'return_to_base_cost' => round($returnToBaseCost, 2),
            'initial_repositioning_cost' => round($repositioningCost, 2),
            'repositioning_distance_km' => round((float) ($repositioning['distance_km'] ?? 0), 2),
            'repositioning_distance_nm' => round((float) ($repositioning['distance_nm'] ?? 0), 2),
            'return_to_base_distance_km' => round((float) ($returnToBase['distance_km'] ?? 0), 2),
            'return_to_base_distance_nm' => round((float) ($returnToBase['distance_nm'] ?? 0), 2),
            'client_display_flight_hours' => $routeDisplayHours,
            'client_operational_flight_hours' => $routeOperationalHours,
            'client_direct_flight_hours' => $routeDirectHours,
            'client_pricing_flight_hours' => $routePricingHours,
            'client_climb_descent_minutes' => round($routeClimbDescentMinutes),
            'client_climb_descent_hours' => $routeClimbDescentHours,
            'flight_time_comparison' => $flightTimeComparison,
            'flight_base_hours' => $clientBillableHours,
            'flight_base_source' => 'final_billable_hours',
            'rounding_mode' => $roundingMode,
            'time_display_mode' => $timeDisplayMode,
            'billing_hours_mode' => $billingHoursMode,
            'quote_strategy' => self::FORMULA_VERSION,
            'pricing_formula_version' => self::FORMULA_VERSION,
            'calculated_at' => now()->toISOString(),
            'requires_repositioning' => $applyRepositioningPricing,
            'aircraft_base_airport' => $aircraftBaseAirport,
            'repositioning' => [
                ...$repositioning,
                'cost' => round($repositioningCost, 2),
            ],
            'return_to_base' => [
                ...$returnToBase,
                'required' => $applyRepositioningPricing && (float) ($returnToBase['distance_nm'] ?? 0) > 0,
                'cost' => round($returnToBaseCost, 2),
            ],
            'route_snapshot' => [
                'signature' => $routeSignature,
                'distance_km' => (float) ($canonicalRoute['distance_km'] ?? $routeDistanceKm),
                'distance_nm' => (float) ($canonicalRoute['distance_nm'] ?? collect($legPricings)->sum('distance_nm')),
                'max_leg_distance_km' => (float) ($canonicalRoute['max_leg_distance_km'] ?? collect($legPricings)->max('distance_km')),
                'operational_distance_km' => round(
                    (float) ($canonicalRoute['distance_km'] ?? $routeDistanceKm)
                        + (float) ($repositioning['distance_km'] ?? 0)
                        + (float) ($returnToBase['distance_km'] ?? 0),
                    2
                ),
                'operational_distance_nm' => round(
                    (float) ($canonicalRoute['distance_nm'] ?? collect($legPricings)->sum('distance_nm'))
                        + (float) ($repositioning['distance_nm'] ?? 0)
                        + (float) ($returnToBase['distance_nm'] ?? 0),
                    2
                ),
                'legs' => $legs,
            ],
            'duration_snapshot' => [
                'display_flight_hours' => (float) $routeDisplayHours,
                'operational_hours' => (float) $routeOperationalHours + (float) ($repositioning['operational_hours'] ?? 0) + (float) ($returnToBase['operational_hours'] ?? 0),
                'billable_hours' => (float) $totalBilledHours,
                'source' => 'backend_distance_and_aircraft_speed',
            ],
            'ignored_client_pricing_fields' => $this->presentFields($rawClientPayload ?? $options),
            'debug_pricing' => $debugPricing,
            'pricing' => [
                'customer_flight_cost' => round($clientFlightCost, 2),
                'raw_client_flight_cost' => round($rawClientFlightCost, 2),
                'repositioning_cost' => round($repositioningCost, 2),
                'return_to_base_cost' => round($returnToBaseCost, 2),
                'airport_expenses' => round($airportExpenses, 2),
                'overnight_cost' => round($overnightCost, 2),
                'margin_amount' => round($marginAmount, 2),
                'payment_fees' => round($paymentFee, 2),
                'payment_fee' => round($paymentFee, 2),
                'taxes' => round($taxes, 2),
                'total_amount' => round($totalAmount, 2),
                'currency' => $aircraft->currency ?: 'USD',
            ],
            'base_price_formula' => [
                'hourly_rate' => round($hourlyRate, 2),
                'configured_minimum_hours' => round($configuredMinimumHours, 2),
                'fallback_minimum_hours' => round($fallbackMinimumHours, 2),
                'applied_minimum_hours' => round($appliedMinimumHours, 2),
                'route_billable_hours' => round($routeBillableHours, 4),
                'final_billable_hours' => round($totalBilledHours, 4),
                'raw_client_flight_cost' => round($rawClientFlightCost, 2),
                'flight_cost' => round($clientFlightCost, 2),
                'minimum_route_price' => round($minimumRoutePrice, 2),
                'minimum_route_price_source' => (string) ($minimumRoutePricing['source'] ?? 'none'),
                'minimum_route_price_rule_id' => $minimumRoutePricing['rule_id'] ?? null,
                'minimum_route_price_applied' => $minimumRoutePriceApplied,
                'minimum_route_price_reason' => (string) ($minimumRoutePricing['reason'] ?? 'no_minimum_route_price'),
                'billable_flight_cost' => round($clientFlightCost, 2),
                'repositioning_cost' => round($repositioningCost, 2),
                'return_to_base_cost' => round($returnToBaseCost, 2),
                'airport_expenses' => round($airportExpenses, 2),
                'overnight_cost' => round($overnightCost, 2),
                'margin_amount' => round($marginAmount, 2),
                'stripe_fee' => round($stripeFee, 2),
                'administrative_fee' => round($administrativeFee, 2),
                'payment_fee' => round($paymentFee, 2),
                'taxes' => round($taxes, 2),
                'expression' => sprintf(
                    'horas %.6f x tarifa %.2f = %.2f; minimo %.2f (%s) => cliente %.2f; repo %.2f + regreso %.2f + airport %.2f + overnight %.2f => %.2f; margin %.2f; stripe %.2f; admin %.2f; taxes %.2f; total %.2f',
                    round($clientBillableHours, 6),
                    round($hourlyRate, 2),
                    round($rawClientFlightCost, 2),
                    round($minimumRoutePrice, 2),
                    $minimumRoutePriceApplied ? 'aplicado' : 'no_aplicado',
                    round($clientFlightCost, 2),
                    round($repositioningCost, 2),
                    round($returnToBaseCost, 2),
                    round($airportExpenses, 2),
                    round($overnightCost, 2),
                    round($subtotalBeforeMargin, 2),
                    round($marginAmount, 2),
                    round($stripeFee, 2),
                    round($administrativeFee, 2),
                    round($taxes, 2),
                    round($totalAmount, 2),
                ),
            ],
            'jet_a_price' => 0.0,
            'operator_subtotal' => round($subtotalBeforeMargin, 2),
            'subtotal_operativo' => round($subtotalOperative, 2),
            'non_taxable_expenses' => round($airportExpenses, 2),
            'utility' => round($marginAmount, 2),
            'markup_amount' => round($marginAmount, 2),
            'redsky_markup_percent' => round($marginRate * 100, 2),
            'redsky_markup_factor' => 1 + $marginRate,
            'include_repositioning_in_billed_hours' => $includeRepositioningInBilledHours,
            'include_return_to_base_in_billed_hours' => $includeReturnToBaseInBilledHours,
            'include_overnight_in_billed_hours' => $includeOvernightInBilledHours,
            'frontend_pricing_debug' => $frontendPricingDebug,
        ];

        if (config('vuelos.pricing_debug')) {
            Log::debug('[FlightDuration][Route Audit]', [
                'pricing_calculation_id' => $pricingCalculationId,
                'aircraft_id' => $aircraft->id,
                'aircraft_name' => $aircraft->model,
                'legs_count' => count($legs),
                'total_distance_nm' => round((float) collect($legPricings)->sum('distance_nm'), 2),
                'total_direct_minutes' => round($routeDirectHours * 60, 2),
                'total_operational_minutes' => round($routeOperationalHours * 60, 2),
                'total_rounded_minutes' => round($routeOperationalDisplayHours * 60, 2),
                'route_billable_hours' => round($routeBillableHours, 4),
                'minimum_hours' => round($appliedMinimumHours, 4),
                'minimum_applied' => round($minimumHoursApplied, 4),
                'final_billable_hours' => round($clientBillableHours, 4),
                'hourly_rate' => round($hourlyRate, 2),
                'raw_client_flight_cost' => round($rawClientFlightCost, 2),
                'client_flight_cost' => round($clientFlightCost, 2),
                'minimum_route_price' => round($minimumRoutePrice, 2),
                'minimum_route_price_applied' => $minimumRoutePriceApplied,
                'comparison' => $flightTimeComparison,
            ]);
        }

        Log::info('Flight pricing calculated', [
            'aircraft_id' => $aircraft->id,
            'aircraft_model' => $aircraft->model,
            'route_signature' => $routeSignature,
            'configured_minimum_hours' => $configuredMinimumHours,
            'fallback_minimum_hours' => $fallbackMinimumHours,
            'applied_minimum_hours' => $appliedMinimumHours,
            'route_billable_hours' => round($routeBillableHours, 4),
            'final_billable_hours' => round($clientBillableHours, 4),
            'flight_cost' => round($clientFlightCost + $repositioningCost + $returnToBaseCost, 2),
            'raw_client_flight_cost' => round($rawClientFlightCost, 2),
            'minimum_route_price' => round($minimumRoutePrice, 2),
            'minimum_route_price_applied' => $minimumRoutePriceApplied,
            'billable_flight_cost' => round($clientFlightCost, 2),
            'airport_expenses' => round($airportExpenses, 2),
            'overnight_nights' => $overnightNights,
            'overnight_fee' => round($overnightFee, 2),
            'overnight_cost' => round($overnightCost, 2),
            'repositioning_cost' => round($repositioningCost, 2),
            'return_to_base_cost' => round($returnToBaseCost, 2),
            'margin_amount' => round($marginAmount, 2),
            'stripe_fee' => round($stripeFee, 2),
            'administrative_fee' => round($administrativeFee, 2),
            'taxes' => round($taxes, 2),
            'total_amount' => round($totalAmount, 2),
        ]);

        return $pricing;
    }

    public function sanitizeClientPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if ((string) $key === 'operational_context') {
                $payload[$key] = is_array($value) ? $value : [];

                continue;
            }

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

    private function airportFromPayload(array $payload): Aeropuerto
    {
        return new Aeropuerto($payload);
    }

    private function normalizeOperationalSegment(mixed $segment): array
    {
        if (! is_array($segment)) {
            return [
                'origin_airport_id' => null,
                'destination_airport_id' => null,
                'origin_iata' => null,
                'origin_icao' => null,
                'destination_iata' => null,
                'destination_icao' => null,
                'distance_km' => 0.0,
                'distance_nm' => 0.0,
                'flight_hours' => 0.0,
                'operational_hours' => 0.0,
                'billable_hours' => 0.0,
                'cost' => 0.0,
            ];
        }

        return [
            'origin_airport_id' => $segment['origin_airport_id'] ?? null,
            'destination_airport_id' => $segment['destination_airport_id'] ?? null,
            'origin_iata' => $segment['origin_iata'] ?? null,
            'origin_icao' => $segment['origin_icao'] ?? null,
            'destination_iata' => $segment['destination_iata'] ?? null,
            'destination_icao' => $segment['destination_icao'] ?? null,
            'distance_km' => (float) ($segment['distance_km'] ?? 0.0),
            'distance_nm' => (float) ($segment['distance_nm'] ?? 0.0),
            'flight_hours' => (float) ($segment['flight_hours'] ?? 0.0),
            'operational_hours' => (float) ($segment['operational_hours'] ?? 0.0),
            'billable_hours' => (float) ($segment['billable_hours'] ?? 0.0),
            'cost' => (float) ($segment['cost'] ?? 0.0),
        ];
    }

    private function resolveTimeMode(array $requestData, string $key, string $default): string
    {
        $mode = mb_strtolower(trim((string) ($requestData[$key] ?? '')));

        return in_array($mode, [
            self::TIME_MODE_DIRECT,
            self::TIME_MODE_DIRECT_PLUS_CLIMB,
            self::TIME_MODE_OPERATIONAL,
        ], true) ? $mode : $default;
    }

    private function resolveLegHoursByMode(array $legPricing, string $mode): float
    {
        return match ($mode) {
            self::TIME_MODE_OPERATIONAL => (float) ($legPricing['operational_display_hours'] ?? $legPricing['real_flight_hours'] ?? 0),
            self::TIME_MODE_DIRECT_PLUS_CLIMB => (float) ($legPricing['direct_air_time_hours'] ?? 0) + (float) ($legPricing['climb_descent_hours'] ?? 0),
            default => (float) ($legPricing['direct_air_time_hours'] ?? 0),
        };
    }

    private function resolveRoundingMode(array $requestData, string $hoursSource): string
    {
        $mode = mb_strtolower(trim((string) ($requestData['rounding_mode'] ?? '')));

        if (in_array($mode, [
            self::ROUNDING_MODE_NONE,
            self::ROUNDING_MODE_QUARTER_NEAREST,
            self::ROUNDING_MODE_QUARTER_UP,
        ], true)) {
            return $mode;
        }

        return $hoursSource === 'distance_speed'
            ? self::ROUNDING_MODE_QUARTER_NEAREST
            : self::ROUNDING_MODE_NONE;
    }

    private function applyRoundingMode(float $hours, string $mode): float
    {
        return match ($mode) {
            self::ROUNDING_MODE_QUARTER_UP => ceil($hours * 4) / 4,
            self::ROUNDING_MODE_NONE => round($hours, 2),
            default => round($hours * 4) / 4,
        };
    }

    private function shouldIncludeIva(array $data): bool
    {
        if (! array_key_exists('include_iva', $data)) {
            return true;
        }

        return filter_var($data['include_iva'], FILTER_VALIDATE_BOOL);
    }

    private function shouldApplyAirportExpenses(array $data): bool
    {
        if (! array_key_exists('airport_expenses', $data)) {
            return true;
        }

        return filter_var($data['airport_expenses'], FILTER_VALIDATE_BOOL);
    }

    private function shouldApplyCommercialMargin(array $data): bool
    {
        if (! array_key_exists('apply_margin', $data)) {
            return false;
        }

        return filter_var($data['apply_margin'], FILTER_VALIDATE_BOOL);
    }

    private function shouldIncludeBilledComponent(array $requestData, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $requestData)) {
            return $default;
        }

        return filter_var($requestData[$key], FILTER_VALIDATE_BOOL);
    }

    private function resolveCommercialHourlyRate(mixed $value): float
    {
        $hourlyRate = (float) $value;

        if ($hourlyRate > 0 && $hourlyRate < 100) {
            $hourlyRate *= 1000;
        }

        return $hourlyRate;
    }

    private function useOperationalFlightTimeModel(array $requestData = []): bool
    {
        $requestedMode = mb_strtolower(trim((string) ($requestData['time_display_mode'] ?? '')));

        return $requestedMode === self::TIME_MODE_OPERATIONAL
            || ((bool) config('vuelos.dynamic_flight_time_enabled', false)
                && (string) config('vuelos.flight_time_model', 'direct') === self::TIME_MODE_OPERATIONAL);
    }

    private function resolveFallbackMinimumHours(mixed $category, float $distanceKm): float
    {
        if ($distanceKm >= self::SHORT_ROUTE_DISTANCE_KM) {
            return 0.0;
        }

        return match ($this->normalizePricingCategory($category)) {
            'helicopter' => 1.0,
            'turboprop' => 1.5,
            'light_jet' => 2.0,
            'mid_jet' => 2.5,
            'heavy_jet' => 3.0,
            'ultra_long' => 4.0,
            default => 0.0,
        };
    }

    private function normalizePricingCategory(mixed $value): string
    {
        return match ($this->normalizeAircraftCategory($value)) {
            'Helicoptero' => 'helicopter',
            'Turboprop' => 'turboprop',
            'Light Jet' => 'light_jet',
            'Mid Jet' => 'mid_jet',
            'Heavy Jet' => 'heavy_jet',
            'Ultra Long Range' => 'ultra_long',
            default => 'default',
        };
    }

    private function normalizeAircraftCategory(mixed $value): ?string
    {
        $normalized = mb_strtolower(trim((string) ($value ?? '')));

        return match ($normalized) {
            'helicoptero', 'helicóptero', 'helicopter' => 'Helicoptero',
            'turboprop', 'turbo prop', 'turbo_prop' => 'Turboprop',
            'light jet', 'light_jet', 'lightjet' => 'Light Jet',
            'mid jet', 'mid_jet', 'midjet', 'midsize jet', 'midsize_jet', 'super mid', 'super_mid' => 'Mid Jet',
            'heavy jet', 'heavy_jet', 'heavyjet', 'long range', 'long_range' => 'Heavy Jet',
            'ultra long', 'ultra_long', 'ultra long range', 'ultra_long_range' => 'Ultra Long Range',
            default => null,
        };
    }

    private function resolveCommercialMarginRate(Aeronave $aircraft, array $categoryPricingRule): float
    {
        $providerMargin = (float) ($aircraft->provider?->margin_percent ?? 0);
        if ($providerMargin > 0) {
            return $providerMargin > 1 ? $providerMargin / 100 : $providerMargin;
        }

        $categoryMarkup = (float) ($categoryPricingRule['redsky_markup'] ?? 0);

        return $categoryMarkup > 0 ? $categoryMarkup / 100 : 0.0;
    }

    private function resolveCategoryPricingRule(Aeronave $aircraft): array
    {
        $category = $this->normalizeAircraftCategory($aircraft->category);
        $defaultRule = self::AIRCRAFT_CATEGORY_MINIMUM_PRICE[$category ?? ''] ?? [
            'minimum_route_price' => 0.0,
            'redsky_markup' => 0.0,
            'rule_id' => null,
            'source' => 'default_category_config',
        ];

        if (! $category) {
            return $defaultRule;
        }

        if ($this->categoryPricingRulesTableExists === null) {
            $this->categoryPricingRulesTableExists = Schema::hasTable('category_pricing_rules');
        }

        if (! $this->categoryPricingRulesTableExists) {
            return $defaultRule;
        }

        if (array_key_exists($category, $this->categoryPricingRuleCache)) {
            return $this->categoryPricingRuleCache[$category] ?: $defaultRule;
        }

        try {
            $rule = ReglaPrecioCategoria::query()
                ->where('category', $category)
                ->where('is_active', true)
                ->first();
        } catch (QueryException) {
            $this->categoryPricingRuleCache[$category] = null;

            return $defaultRule;
        }

        if (! $rule) {
            $this->categoryPricingRuleCache[$category] = null;

            return $defaultRule;
        }

        return $this->categoryPricingRuleCache[$category] = [
            'minimum_route_price' => (float) $rule->minimum_route_price,
            'redsky_markup' => (float) $rule->redsky_markup,
            'rule_id' => $rule->id,
            'source' => 'category_pricing_rules.active',
        ];
    }

    private function resolveCategoryMinimumRoutePrice(Aeronave $aircraft): float
    {
        return max((float) ($this->resolveCategoryPricingRule($aircraft)['minimum_route_price'] ?? 0.0), 0.0);
    }

    private function resolveMinimumRoutePricing(
        Aeronave $aircraft,
        array $route = [],
        array $pricingContext = [],
    ): array {
        $configuredMinimumRoutePrice = max((float) ($aircraft->minimum_route_price ?? 0), 0.0);
        if ($configuredMinimumRoutePrice > 0) {
            return [
                'amount' => $configuredMinimumRoutePrice,
                'source' => 'aircraft.minimum_route_price',
                'rule_id' => null,
                'reason' => 'aircraft_specific_minimum_route_price',
                'route_signature' => (string) ($route['route_signature'] ?? $this->buildRouteSignature($route['legs'] ?? [])),
                'pricing_context' => $pricingContext,
            ];
        }

        $categoryPricingRule = $this->resolveCategoryPricingRule($aircraft);
        $categoryMinimumRoutePrice = max((float) ($categoryPricingRule['minimum_route_price'] ?? 0.0), 0.0);
        if ($categoryMinimumRoutePrice > 0) {
            return [
                'amount' => $categoryMinimumRoutePrice,
                'source' => (string) ($categoryPricingRule['source'] ?? 'category_pricing_rules'),
                'rule_id' => $categoryPricingRule['rule_id'] ?? null,
                'reason' => 'category_minimum_route_price',
                'route_signature' => (string) ($route['route_signature'] ?? $this->buildRouteSignature($route['legs'] ?? [])),
                'pricing_context' => $pricingContext,
            ];
        }

        return [
            'amount' => 0.0,
            'source' => (string) ($categoryPricingRule['source'] ?? 'none'),
            'rule_id' => $categoryPricingRule['rule_id'] ?? null,
            'reason' => 'no_minimum_route_price',
            'route_signature' => (string) ($route['route_signature'] ?? $this->buildRouteSignature($route['legs'] ?? [])),
            'pricing_context' => $pricingContext,
        ];
    }

    private function resolveAirportExpenseContext(Aeronave $aircraft, array $legs): array
    {
        $ruleContext = $this->resolveAirportExpenseRule($aircraft, $legs);
        if ($ruleContext !== null) {
            return $ruleContext;
        }

        $airportExpenses = (float) ($aircraft->airport_expenses_usd ?? 0);
        if ($airportExpenses > 0 && $airportExpenses < 100) {
            $airportExpenses *= 1000;
        }

        if ($airportExpenses > 0) {
            return [
                'amount' => $airportExpenses,
                'source' => 'aircraft.airport_expenses_usd',
            ];
        }

        return [
            'amount' => self::DEFAULT_AIRPORT_EXPENSE_USD,
            'source' => 'DEFAULT_AIRPORT_EXPENSE_USD',
        ];
    }

    private function resolveAirportExpenseRule(Aeronave $aircraft, array $legs): ?array
    {
        if (! $this->airportExpenseRulesTableExists()) {
            return null;
        }

        $routeSignature = $this->buildRouteSignature($legs);
        $firstLeg = $legs[0] ?? [];
        $originCode = strtoupper(trim((string) ($firstLeg['origin'] ?? '')));
        $destinationCode = strtoupper(trim((string) ($firstLeg['destination'] ?? '')));
        $category = $this->normalizeAircraftCategory($aircraft->category);

        $rule = $this->activeAirportExpenseRules()
            ->filter(function (ReglaGastoAeropuerto $rule) use ($aircraft, $category, $routeSignature, $originCode, $destinationCode) {
                return ($rule->aircraft_id === null || (int) $rule->aircraft_id === (int) $aircraft->id)
                    && ($rule->category === null || ($category && $rule->category === $category))
                    && ($rule->route_signature === null || ($routeSignature !== '' && strtoupper((string) $rule->route_signature) === $routeSignature))
                    && ($rule->origin_airport_code === null || ($originCode !== '' && strtoupper((string) $rule->origin_airport_code) === $originCode))
                    && ($rule->destination_airport_code === null || ($destinationCode !== '' && strtoupper((string) $rule->destination_airport_code) === $destinationCode));
            })
            ->sort(function (ReglaGastoAeropuerto $left, ReglaGastoAeropuerto $right) {
                $leftScore = [
                    $left->aircraft_id === null ? 0 : 1,
                    $left->route_signature === null ? 0 : 1,
                    $left->origin_airport_code === null ? 0 : 1,
                    $left->destination_airport_code === null ? 0 : 1,
                    $left->category === null ? 0 : 1,
                    (int) $left->priority,
                ];
                $rightScore = [
                    $right->aircraft_id === null ? 0 : 1,
                    $right->route_signature === null ? 0 : 1,
                    $right->origin_airport_code === null ? 0 : 1,
                    $right->destination_airport_code === null ? 0 : 1,
                    $right->category === null ? 0 : 1,
                    (int) $right->priority,
                ];

                return $rightScore <=> $leftScore;
            })
            ->first();

        if (! $rule || (float) $rule->expense_fee <= 0) {
            return null;
        }

        return [
            'amount' => (float) $rule->expense_fee,
            'source' => sprintf(
                'airport_expense_rules.id=%s route=%s aircraft_id=%s category=%s',
                $rule->id,
                $rule->route_signature ?: 'null',
                $rule->aircraft_id ?: 'null',
                $rule->category ?: 'null'
            ),
        ];
    }

    private function airportExpenseRulesTableExists(): bool
    {
        if ($this->airportExpenseRulesTableExists === null) {
            $this->airportExpenseRulesTableExists = Schema::hasTable('airport_expense_rules');
        }

        return $this->airportExpenseRulesTableExists;
    }

    private function activeAirportExpenseRules(): Collection
    {
        if ($this->airportExpenseRulesCache !== null) {
            return $this->airportExpenseRulesCache;
        }

        try {
            $this->airportExpenseRulesCache = ReglaGastoAeropuerto::query()
                ->select([
                    'id',
                    'aircraft_id',
                    'category',
                    'origin_airport_code',
                    'destination_airport_code',
                    'route_signature',
                    'expense_fee',
                    'priority',
                ])
                ->where('is_active', true)
                ->get();
        } catch (QueryException) {
            $this->airportExpenseRulesCache = collect();
        }

        return $this->airportExpenseRulesCache;
    }

    private function buildRouteSignature(array $legs): string
    {
        $codes = [];

        foreach ($legs as $index => $leg) {
            $origin = strtoupper(trim((string) ($leg['origin'] ?? '')));
            $destination = strtoupper(trim((string) ($leg['destination'] ?? '')));

            if ($index === 0 && $origin !== '') {
                $codes[] = $origin;
            }

            if ($destination !== '') {
                $codes[] = $destination;
            }
        }

        return implode('>', $codes);
    }

    private function resolveHourlyRateSource(Aeronave $aircraft): string
    {
        $rawHourlyRate = (float) ($aircraft->hourly_rate ?? 0);

        if ($rawHourlyRate > 0 && $rawHourlyRate < 100) {
            return sprintf('aircraft.hourly_rate (%s) x 1000', $rawHourlyRate);
        }

        return sprintf('aircraft.hourly_rate (%s)', $rawHourlyRate);
    }

    private function resolveOvernightFee(Aeronave $aircraft, float $hourlyRate): float
    {
        $configured = (float) ($aircraft->overnight_fee ?? 0);

        if ($configured > 0 && $configured < 100) {
            $configured *= 1000;
        }

        if ($configured > 0) {
            return round($configured, 2);
        }

        return round($hourlyRate / 2, 2);
    }

    private function resolveOvernightNights(array $legs, array $requestData = []): int
    {
        $explicitNights = $requestData['overnights'] ?? $requestData['overnight_nights'] ?? null;
        if ($explicitNights !== null && $explicitNights !== '') {
            return max((int) $explicitNights, 0);
        }

        return $this->calculateOvernightNights($legs);
    }

    private function calculateOvernightNights(array $legs): int
    {
        $nights = 0;

        for ($index = 0; $index < count($legs) - 1; $index++) {
            $current = $legs[$index]['departure_datetime'] ?? null;
            $next = $legs[$index + 1]['departure_datetime'] ?? null;

            if (! $current || ! $next) {
                continue;
            }

            $currentDate = Carbon::parse($current)->startOfDay();
            $nextDate = Carbon::parse($next)->startOfDay();

            if ($nextDate->greaterThan($currentDate)) {
                $nights += $currentDate->diffInDays($nextDate);
            }
        }

        return $nights;
    }
}
