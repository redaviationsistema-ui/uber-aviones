<?php

namespace Tests\Feature;

use App\Modelos\Aeronave;
use App\Servicios\Vuelos\FlightPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FlightPricingServiceDynamicTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_display_request_activates_dynamic_model_even_when_server_default_is_direct(): void
    {
        $this->seed();
        config()->set('vuelos.dynamic_flight_time_enabled', false);
        config()->set('vuelos.flight_time_model', 'direct');

        $aircraft = new Aeronave([
            'id' => 901,
            'model' => 'Requested Operational Jet',
            'category' => 'Light Jet',
            'hourly_rate' => 2300,
            'minimum_hours' => 0,
            'minimum_route_price' => 0,
            'airport_expenses_usd' => 0,
            'speed_kmh' => 740,
            'currency' => 'USD',
        ]);

        $pricing = app(FlightPricingService::class)->calculateForAircraft(
            $aircraft,
            $this->canonicalRoute(distanceNm: 228.0, distanceKm: 422.256),
            ['time_display_mode' => 'operational'],
        );

        $this->assertSame('operational', $pricing['time_display_mode']);
        $this->assertSame('dynamic_operational_profile', $pricing['legs'][0]['hours_source']);
        $this->assertGreaterThan($pricing['direct_route_hours'], $pricing['display_route_hours']);
        $this->assertSame('operational', $pricing['client_legs'][0]['time_model']);
    }

    public function test_learjet_31a_uses_aircraft_minimum_hours_once_for_the_whole_trip(): void
    {
        $this->seed();
        config()->set('vuelos.dynamic_flight_time_enabled', false);
        config()->set('vuelos.flight_time_model', 'direct');

        $aircraft = new Aeronave([
            'id' => 31,
            'model' => 'Learjet 31A',
            'category' => 'Light Jet',
            'hourly_rate' => 2300,
            'minimum_hours' => 2,
            'minimum_route_price' => 0,
            'airport_expenses_usd' => 0,
            'climb_descent_minutes' => 30,
            'speed_kmh' => 250,
            'currency' => 'USD',
        ]);

        $pricing = app(FlightPricingService::class)->calculateForAircraft(
            $aircraft,
            $this->canonicalRoute(distanceNm: 134.99, distanceKm: 250.0),
            [
                'include_iva' => false,
                'airport_expenses' => false,
                'apply_margin' => false,
            ],
        );

        $this->assertEqualsWithDelta(1.00000592, (float) $pricing['route_billable_hours'], 0.0000001);
        $this->assertSame(2.0, (float) $pricing['configured_minimum_hours']);
        $this->assertSame(2.0, (float) $pricing['applied_minimum_hours']);
        $this->assertSame(2.0, (float) $pricing['final_billable_hours']);
        $this->assertSame(4600.0, (float) $pricing['flight_cost']);
    }

    public function test_aircraft_without_configured_minimum_hours_uses_category_fallback(): void
    {
        $this->seed();
        config()->set('vuelos.dynamic_flight_time_enabled', false);
        config()->set('vuelos.flight_time_model', 'direct');

        $aircraft = new Aeronave([
            'id' => 45,
            'model' => 'Dynamic Light Jet',
            'category' => 'Light Jet',
            'hourly_rate' => 2300,
            'minimum_hours' => null,
            'minimum_route_price' => 0,
            'airport_expenses_usd' => 0,
            'climb_descent_minutes' => 30,
            'speed_kmh' => 250,
            'currency' => 'USD',
        ]);

        $pricing = app(FlightPricingService::class)->calculateForAircraft(
            $aircraft,
            $this->canonicalRoute(distanceNm: 134.99, distanceKm: 250.0),
            [
                'include_iva' => false,
                'airport_expenses' => false,
                'apply_margin' => false,
            ],
        );

        $this->assertSame(0.0, (float) $pricing['configured_minimum_hours']);
        $this->assertSame(2.0, (float) $pricing['fallback_minimum_hours']);
        $this->assertSame(2.0, (float) $pricing['applied_minimum_hours']);
        $this->assertSame(2.0, (float) $pricing['final_billable_hours']);
    }

    public function test_overnight_fee_is_charged_when_itinerary_spans_nights(): void
    {
        $this->seed();
        config()->set('vuelos.dynamic_flight_time_enabled', false);
        config()->set('vuelos.flight_time_model', 'direct');

        $aircraft = new Aeronave([
            'id' => 77,
            'model' => 'Dynamic Mid Jet',
            'category' => 'Mid Jet',
            'hourly_rate' => 3000,
            'minimum_hours' => 0,
            'minimum_route_price' => 0,
            'airport_expenses_usd' => 0,
            'overnight_fee' => 900,
            'climb_descent_minutes' => 30,
            'speed_kmh' => 250,
            'currency' => 'USD',
        ]);

        $pricing = app(FlightPricingService::class)->calculateForAircraft(
            $aircraft,
            $this->multiLegCanonicalRouteWithOvernight(),
            [
                'include_iva' => false,
                'airport_expenses' => false,
                'apply_margin' => false,
            ],
        );

        $this->assertSame(1, (int) $pricing['overnight_nights']);
        $this->assertSame(900.0, (float) $pricing['overnight_fee']);
        $this->assertSame(900.0, (float) $pricing['overnight_cost']);
        $this->assertEquals(
            round((float) $pricing['subtotal_before_margin'] + (float) $pricing['stripe_fee'] + (float) $pricing['administrative_fee'], 2),
            round((float) $pricing['subtotal'], 2),
        );
    }

    public function test_route_billable_hours_preserves_exact_decimal_hours_without_rounding_per_leg(): void
    {
        $this->seed();
        config()->set('vuelos.dynamic_flight_time_enabled', false);
        config()->set('vuelos.flight_time_model', 'direct');

        $aircraft = new Aeronave([
            'id' => 12,
            'model' => 'HAWKER 800XPI',
            'category' => 'Mid Jet',
            'hourly_rate' => 3200,
            'minimum_hours' => 2,
            'minimum_route_price' => 0,
            'airport_expenses_usd' => 0,
            'climb_descent_minutes' => 35,
            'speed_kmh' => 741,
            'currency' => 'USD',
        ]);

        $pricing = app(FlightPricingService::class)->calculateForAircraft(
            $aircraft,
            $this->roundTripCanonicalRoute(distanceNm: 811.0, distanceKm: 1502.0),
            [
                'include_iva' => false,
                'airport_expenses' => false,
                'apply_margin' => false,
            ],
        );

        $expectedLegHours = 811.0 / (741.0 / 1.852);
        $expectedRouteHours = $expectedLegHours * 2;
        $expectedFlightCost = round($expectedRouteHours * 3200.0, 2);

        $this->assertSame('none', $pricing['rounding_mode']);
        $this->assertCount(2, $pricing['raw_leg_hours']);
        $this->assertEqualsWithDelta($expectedLegHours, (float) $pricing['raw_leg_hours'][0], 0.0000001);
        $this->assertEqualsWithDelta($expectedLegHours, (float) $pricing['raw_leg_hours'][1], 0.0000001);
        $this->assertEqualsWithDelta($expectedRouteHours, (float) $pricing['raw_route_hours'], 0.0000001);
        $this->assertEqualsWithDelta($expectedRouteHours, (float) $pricing['route_billable_hours'], 0.0000001);
        $this->assertEqualsWithDelta($expectedRouteHours, (float) $pricing['final_billable_hours'], 0.0000001);
        $this->assertEqualsWithDelta($expectedRouteHours, (float) $pricing['billable_hours'], 0.0000001);
        $this->assertSame($expectedFlightCost, (float) $pricing['flight_cost']);
    }

    public function test_minimum_mission_hours_still_apply_when_exact_route_hours_are_lower(): void
    {
        $this->seed();
        config()->set('vuelos.dynamic_flight_time_enabled', false);
        config()->set('vuelos.flight_time_model', 'direct');

        $aircraft = new Aeronave([
            'id' => 88,
            'model' => 'Minimum Mission Jet',
            'category' => 'Mid Jet',
            'hourly_rate' => 3200,
            'minimum_hours' => 2,
            'minimum_route_price' => 0,
            'airport_expenses_usd' => 0,
            'climb_descent_minutes' => 35,
            'speed_kmh' => 741,
            'currency' => 'USD',
        ]);

        $targetRouteHours = 1.75;
        $speedKnots = 741.0 / 1.852;
        $distanceNm = $targetRouteHours * $speedKnots;
        $distanceKm = $distanceNm * 1.852;

        $pricing = app(FlightPricingService::class)->calculateForAircraft(
            $aircraft,
            $this->canonicalRoute(distanceNm: $distanceNm, distanceKm: $distanceKm),
            [
                'include_iva' => false,
                'airport_expenses' => false,
                'apply_margin' => false,
            ],
        );

        $this->assertSame('none', $pricing['rounding_mode']);
        $this->assertEqualsWithDelta($targetRouteHours, (float) $pricing['raw_route_hours'], 0.0000001);
        $this->assertEqualsWithDelta($targetRouteHours, (float) $pricing['route_billable_hours'], 0.0000001);
        $this->assertSame(2.0, (float) $pricing['final_billable_hours']);
        $this->assertSame(6400.0, (float) $pricing['flight_cost']);
    }

    public function test_dynamic_operational_model_uses_aircraft_profile_and_rounds_each_leg(): void
    {
        $this->seed();
        config()->set('vuelos.dynamic_flight_time_enabled', true);
        config()->set('vuelos.flight_time_model', 'operational');

        DB::table('aircraft_performance_profiles')->insert([
            'aircraft_id' => null,
            'aircraft_model' => 'HAWKER 800XPI',
            'aircraft_type' => null,
            'taxi_out_minutes' => 4,
            'taxi_in_minutes' => 3,
            'takeoff_minutes' => 1,
            'landing_minutes' => 2,
            'climb_minutes' => 14,
            'climb_distance_nm' => 55,
            'descent_minutes' => 10,
            'descent_distance_nm' => 40,
            'fixed_operational_minutes' => 3,
            'short_leg_threshold_nm' => 180,
            'medium_leg_threshold_nm' => 500,
            'short_leg_speed_factor' => 0.76,
            'medium_leg_speed_factor' => 0.88,
            'long_leg_speed_factor' => 1.0,
            'rounding_increment_minutes' => 5,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $aircraft = new Aeronave([
            'id' => 12,
            'model' => 'HAWKER 800XPI',
            'category' => 'Mid Jet',
            'hourly_rate' => 3200,
            'minimum_hours' => 2,
            'minimum_route_price' => 0,
            'airport_expenses_usd' => 0,
            'climb_descent_minutes' => 35,
            'speed_kmh' => 741,
            'currency' => 'USD',
        ]);

        $pricing = app(FlightPricingService::class)->calculateForAircraft(
            $aircraft,
            $this->roundTripCanonicalRoute(distanceNm: 811.0, distanceKm: 1502.0),
            [
                'include_iva' => false,
                'airport_expenses' => false,
                'apply_margin' => false,
            ],
        );

        $baseSpeedKnots = 741.0 / 1.852;
        $cruiseDistanceNm = 811.0 - 55.0 - 40.0;
        $expectedOperationalMinutes = 4.0 + 1.0 + 14.0 + (($cruiseDistanceNm / $baseSpeedKnots) * 60) + 10.0 + 2.0 + 3.0 + 3.0;
        $expectedRoundedMinutes = ceil($expectedOperationalMinutes / 5.0) * 5.0;
        $expectedRouteHours = ($expectedRoundedMinutes * 2) / 60;

        $this->assertSame('operational', $pricing['time_display_mode']);
        $this->assertSame('operational', $pricing['billing_hours_mode']);
        $this->assertEqualsWithDelta($expectedRouteHours, (float) $pricing['route_billable_hours'], 0.0000001);
        $this->assertEqualsWithDelta($expectedRouteHours, (float) $pricing['display_route_hours'], 0.0000001);
        $this->assertEqualsWithDelta($expectedRouteHours, (float) $pricing['final_billable_hours'], 0.0000001);
        $this->assertEqualsWithDelta(145.0, (float) $pricing['legs'][0]['rounded_minutes'], 0.0000001);
        $this->assertCount(2, $pricing['client_legs']);
        foreach ($pricing['client_legs'] as $clientLeg) {
            $this->assertSame('operational', $clientLeg['time_model']);
            $this->assertGreaterThan((float) $clientLeg['direct_flight_hours'], (float) $clientLeg['display_flight_hours']);
            $this->assertEqualsWithDelta(
                (float) $clientLeg['operational_flight_hours'],
                (float) $clientLeg['display_flight_hours'],
                0.1,
            );
        }
        $this->assertContains((string) $pricing['legs'][0]['profile_match_level'], ['aircraft_id', 'aircraft_model']);
        $this->assertSame('database', (string) $pricing['legs'][0]['profile_source']);
        $this->assertSame('operational', (string) data_get($pricing, 'flight_time_comparison.model'));
        $this->assertSame(15466.67, (float) $pricing['client_flight_cost']);
    }

    public function test_dynamic_operational_model_honors_speed_knots_when_present(): void
    {
        $this->seed();
        config()->set('vuelos.dynamic_flight_time_enabled', true);
        config()->set('vuelos.flight_time_model', 'operational');

        DB::table('aircraft_performance_profiles')->insert([
            'aircraft_id' => null,
            'aircraft_model' => 'Knots Priority Jet',
            'aircraft_type' => null,
            'taxi_out_minutes' => 0,
            'taxi_in_minutes' => 0,
            'takeoff_minutes' => 0,
            'landing_minutes' => 0,
            'climb_minutes' => 0,
            'climb_distance_nm' => 0,
            'descent_minutes' => 0,
            'descent_distance_nm' => 0,
            'fixed_operational_minutes' => 0,
            'short_leg_threshold_nm' => 200,
            'medium_leg_threshold_nm' => 500,
            'short_leg_speed_factor' => 1,
            'medium_leg_speed_factor' => 1,
            'long_leg_speed_factor' => 1,
            'rounding_increment_minutes' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $aircraft = new Aeronave([
            'id' => 200,
            'model' => 'Knots Priority Jet',
            'category' => 'Mid Jet',
            'hourly_rate' => 3000,
            'minimum_hours' => 0,
            'minimum_route_price' => 0,
            'airport_expenses_usd' => 0,
            'speed_kmh' => 0,
            'currency' => 'USD',
        ]);
        $aircraft->speed_knots = 350;

        $pricing = app(FlightPricingService::class)->calculateForAircraft(
            $aircraft,
            $this->canonicalRoute(distanceNm: 350.0, distanceKm: 648.2),
            [
                'include_iva' => false,
                'airport_expenses' => false,
                'apply_margin' => false,
            ],
        );

        $this->assertEqualsWithDelta(1.0, (float) $pricing['client_display_flight_hours'], 0.0000001);
        $this->assertSame('aircraft.speed_knots', (string) $pricing['legs'][0]['speed_source']);
        $this->assertSame(350.0, (float) $pricing['legs'][0]['speed_knots_used']);
    }

    public function test_dynamic_operational_model_still_applies_minimum_hours(): void
    {
        $this->seed();
        config()->set('vuelos.dynamic_flight_time_enabled', true);
        config()->set('vuelos.flight_time_model', 'operational');

        $aircraft = new Aeronave([
            'id' => 201,
            'model' => 'Operational Minimum Jet',
            'category' => 'Mid Jet',
            'hourly_rate' => 3200,
            'minimum_hours' => 2,
            'minimum_route_price' => 0,
            'airport_expenses_usd' => 0,
            'speed_kmh' => 741,
            'currency' => 'USD',
        ]);

        $pricing = app(FlightPricingService::class)->calculateForAircraft(
            $aircraft,
            $this->canonicalRoute(distanceNm: 90.0, distanceKm: 166.68),
            [
                'include_iva' => false,
                'airport_expenses' => false,
                'apply_margin' => false,
            ],
        );

        $this->assertLessThan(2.0, (float) $pricing['route_billable_hours']);
        $this->assertSame(2.0, (float) $pricing['final_billable_hours']);
        $this->assertSame(6400.0, (float) $pricing['client_flight_cost']);
    }

    public function test_dynamic_operational_model_applies_minimum_route_price_once_and_exposes_trace(): void
    {
        $this->seed();
        config()->set('vuelos.dynamic_flight_time_enabled', true);
        config()->set('vuelos.flight_time_model', 'operational');
        config()->set('vuelos.minimum_route_price_v2_enabled', true);

        DB::table('category_pricing_rules')->where('category', 'Mid Jet')->update([
            'minimum_route_price' => 18000,
            'is_active' => true,
            'updated_at' => now(),
        ]);

        DB::table('aircraft_performance_profiles')->insert([
            'aircraft_id' => null,
            'aircraft_model' => 'HAWKER 800XPI',
            'aircraft_type' => null,
            'taxi_out_minutes' => 4,
            'taxi_in_minutes' => 3,
            'takeoff_minutes' => 1,
            'landing_minutes' => 2,
            'climb_minutes' => 14,
            'climb_distance_nm' => 55,
            'descent_minutes' => 10,
            'descent_distance_nm' => 40,
            'fixed_operational_minutes' => 3,
            'short_leg_threshold_nm' => 180,
            'medium_leg_threshold_nm' => 500,
            'short_leg_speed_factor' => 0.76,
            'medium_leg_speed_factor' => 0.88,
            'long_leg_speed_factor' => 1.0,
            'rounding_increment_minutes' => 5,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $aircraft = new Aeronave([
            'id' => 12,
            'model' => 'HAWKER 800XPI',
            'category' => 'Mid Jet',
            'hourly_rate' => 3200,
            'minimum_hours' => 2,
            'minimum_route_price' => 0,
            'airport_expenses_usd' => 0,
            'speed_kmh' => 741,
            'currency' => 'USD',
        ]);

        $pricing = app(FlightPricingService::class)->calculateForAircraft(
            $aircraft,
            $this->roundTripCanonicalRoute(distanceNm: 811.0, distanceKm: 1502.0),
            [
                'include_iva' => false,
                'airport_expenses' => false,
                'apply_margin' => false,
            ],
        );

        $this->assertSame(15466.67, (float) $pricing['raw_client_flight_cost']);
        $this->assertSame(18000.0, (float) $pricing['minimum_route_price']);
        $this->assertTrue((bool) $pricing['minimum_route_price_applied']);
        $this->assertSame('category_minimum_route_price', (string) $pricing['minimum_route_price_reason']);
        $this->assertSame('category_pricing_rules.active', (string) $pricing['minimum_route_price_source']);
        $this->assertSame(18000.0, (float) $pricing['client_flight_cost']);
        $this->assertSame(1000.0, (float) $pricing['airport_expenses']);
        $this->assertSame(19000.0, (float) $pricing['subtotal_before_margin']);
        $this->assertGreaterThan(19000.0, (float) $pricing['subtotal']);
    }

    private function canonicalRoute(float $distanceNm, float $distanceKm): array
    {
        $originAirport = [
            'id' => 1,
            'icao' => 'MMMX',
            'iata' => 'MEX',
            'country' => 'MX',
            'latitude' => 19.4361,
            'longitude' => -99.0719,
            'climb_descent_adjustment_minutes' => 0,
        ];
        $destinationAirport = [
            'id' => 2,
            'icao' => 'MMTO',
            'iata' => 'TLC',
            'country' => 'MX',
            'latitude' => 19.3371,
            'longitude' => -99.5660,
            'climb_descent_adjustment_minutes' => 0,
        ];

        return [
            'trip_type' => 'one_way',
            'origin' => 'MMMX',
            'destination' => 'MMTO',
            'route_signature' => 'MMMX>MMTO',
            'distance_km' => $distanceKm,
            'distance_nm' => $distanceNm,
            'max_leg_distance_km' => $distanceKm,
            'legs' => [[
                'position' => 1,
                'origin' => 'MMMX',
                'destination' => 'MMTO',
                'origin_airport' => $originAirport,
                'destination_airport' => $destinationAirport,
                'distance_km' => $distanceKm,
                'distance_nm' => $distanceNm,
                'international' => false,
                'departure_datetime' => '2026-08-03T10:00:00Z',
            ]],
        ];
    }

    private function multiLegCanonicalRouteWithOvernight(): array
    {
        $originAirport = [
            'id' => 1,
            'icao' => 'MMMX',
            'iata' => 'MEX',
            'country' => 'MX',
            'latitude' => 19.4361,
            'longitude' => -99.0719,
            'climb_descent_adjustment_minutes' => 0,
        ];
        $destinationAirport = [
            'id' => 2,
            'icao' => 'MMTO',
            'iata' => 'TLC',
            'country' => 'MX',
            'latitude' => 19.3371,
            'longitude' => -99.5660,
            'climb_descent_adjustment_minutes' => 0,
        ];

        return [
            'trip_type' => 'multi_leg',
            'origin' => 'MMMX',
            'destination' => 'MMTO',
            'route_signature' => 'MMMX>MMTO>MMMX',
            'distance_km' => 500.0,
            'distance_nm' => 269.98,
            'max_leg_distance_km' => 250.0,
            'legs' => [
                [
                    'position' => 1,
                    'origin' => 'MMMX',
                    'destination' => 'MMTO',
                    'origin_airport' => $originAirport,
                    'destination_airport' => $destinationAirport,
                    'distance_km' => 250.0,
                    'distance_nm' => 134.99,
                    'international' => false,
                    'departure_datetime' => '2026-08-03T10:00:00Z',
                ],
                [
                    'position' => 2,
                    'origin' => 'MMTO',
                    'destination' => 'MMMX',
                    'origin_airport' => $destinationAirport,
                    'destination_airport' => $originAirport,
                    'distance_km' => 250.0,
                    'distance_nm' => 134.99,
                    'international' => false,
                    'departure_datetime' => '2026-08-04T10:00:00Z',
                ],
            ],
        ];
    }

    private function roundTripCanonicalRoute(float $distanceNm, float $distanceKm): array
    {
        $originAirport = [
            'id' => 5828,
            'icao' => 'MMTO',
            'iata' => 'TLC',
            'country' => 'MX',
            'latitude' => 19.3371,
            'longitude' => -99.5660,
            'climb_descent_adjustment_minutes' => 5,
        ];
        $destinationAirport = [
            'id' => 5784,
            'icao' => 'MMGM',
            'iata' => 'GYM',
            'country' => 'MX',
            'latitude' => 27.9690,
            'longitude' => -110.9250,
            'climb_descent_adjustment_minutes' => 2,
        ];

        return [
            'trip_type' => 'round_trip',
            'origin' => 'MMTO',
            'destination' => 'MMGM',
            'route_signature' => 'MMTO>MMGM>MMTO',
            'distance_km' => $distanceKm * 2,
            'distance_nm' => $distanceNm * 2,
            'max_leg_distance_km' => $distanceKm,
            'legs' => [
                [
                    'position' => 1,
                    'origin' => 'MMTO',
                    'destination' => 'MMGM',
                    'origin_airport' => $originAirport,
                    'destination_airport' => $destinationAirport,
                    'distance_km' => $distanceKm,
                    'distance_nm' => $distanceNm,
                    'international' => false,
                    'departure_datetime' => '2026-08-03T10:00:00Z',
                ],
                [
                    'position' => 2,
                    'origin' => 'MMGM',
                    'destination' => 'MMTO',
                    'origin_airport' => $destinationAirport,
                    'destination_airport' => $originAirport,
                    'distance_km' => $distanceKm,
                    'distance_nm' => $distanceNm,
                    'international' => false,
                    'departure_datetime' => '2026-08-03T18:00:00Z',
                ],
            ],
        ];
    }
}
