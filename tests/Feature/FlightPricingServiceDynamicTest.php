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
        $this->assertEqualsWithDelta((float) $pricing['direct_route_hours'], (float) $pricing['display_route_hours'], 0.0000001);
        $this->assertSame('operational', $pricing['client_legs'][0]['time_model']);
    }

    public function test_learjet_31a_keeps_minimum_hours_informational(): void
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
        $this->assertFalse((bool) $pricing['minimum_applied']);
        $this->assertEqualsWithDelta((float) $pricing['route_operational_hours'], (float) $pricing['pricing_hours'], 0.0000001);
        $this->assertEqualsWithDelta((float) $pricing['route_operational_hours'], (float) $pricing['final_billable_hours'], 0.0000001);
        $this->assertSame('route_operational_hours', (string) $pricing['pricing_hours_source']);
        $this->assertSame(
            round((float) $pricing['route_operational_hours'] * 2300.0, 2),
            (float) $pricing['flight_cost']
        );
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
        $this->assertFalse((bool) $pricing['minimum_applied']);
        $this->assertEqualsWithDelta((float) $pricing['route_operational_hours'], (float) $pricing['pricing_hours'], 0.0000001);
        $this->assertEqualsWithDelta((float) $pricing['route_operational_hours'], (float) $pricing['final_billable_hours'], 0.0000001);
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
        $this->assertEqualsWithDelta((float) $pricing['route_operational_hours'], (float) $pricing['pricing_hours'], 0.0000001);
        $this->assertEqualsWithDelta((float) $pricing['route_operational_hours'], (float) $pricing['final_billable_hours'], 0.0000001);
        $this->assertEqualsWithDelta((float) $pricing['route_operational_hours'], (float) $pricing['billable_hours'], 0.0000001);
        $this->assertSame(round((float) $pricing['route_operational_hours'] * 3200.0, 2), (float) $pricing['flight_cost']);
    }

    public function test_minimum_mission_hours_do_not_raise_billable_hours(): void
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
        $expectedOperationalHours = $targetRouteHours;
        $this->assertEqualsWithDelta($expectedOperationalHours, (float) $pricing['raw_route_hours'], 0.0000001);
        $this->assertEqualsWithDelta($expectedOperationalHours, (float) $pricing['route_billable_hours'], 0.0000001);
        $this->assertFalse((bool) $pricing['minimum_applied']);
        $this->assertEqualsWithDelta($expectedOperationalHours, (float) $pricing['pricing_hours'], 0.0000001);
        $this->assertEqualsWithDelta($expectedOperationalHours, (float) $pricing['final_billable_hours'], 0.0000001);
        $this->assertSame(round($expectedOperationalHours * 3200.0, 2), (float) $pricing['flight_cost']);
    }

    public function test_dynamic_operational_model_exposes_unrounded_visible_time(): void
    {
        $this->seed();
        config()->set('vuelos.dynamic_flight_time_enabled', true);
        config()->set('vuelos.flight_time_model', 'operational');

        DB::table('aircraft_performance_profiles')->insert([
            'aircraft_id' => null,
            'aircraft_model' => 'Route Rounded Jet',
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
            'short_leg_threshold_nm' => 180,
            'medium_leg_threshold_nm' => 500,
            'short_leg_speed_factor' => 1.0,
            'medium_leg_speed_factor' => 1.0,
            'long_leg_speed_factor' => 1.0,
            'rounding_increment_minutes' => 5,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $aircraft = new Aeronave([
            'id' => 12,
            'model' => 'Route Rounded Jet',
            'category' => 'Mid Jet',
            'hourly_rate' => 3200,
            'minimum_hours' => 2,
            'minimum_route_price' => 0,
            'airport_expenses_usd' => 0,
            'speed_kmh' => 555,
            'currency' => 'USD',
        ]);

        $pricing = app(FlightPricingService::class)->calculateForAircraft(
            $aircraft,
            $this->multiLegCanonicalRoute([303.0, 303.0]),
            [
                'include_iva' => false,
                'airport_expenses' => false,
                'apply_margin' => false,
            ],
        );

        $baseSpeedKnots = 555.0 / 1.852;
        $expectedOperationalMinutesPerLeg = (303.0 / $baseSpeedKnots) * 60;
        $expectedRouteOperationalHours = ($expectedOperationalMinutesPerLeg * 2) / 60;
        $legacyPerLegRoundedHours = ((ceil($expectedOperationalMinutesPerLeg / 5.0) * 5.0) * 2) / 60;

        $this->assertSame('operational', $pricing['time_display_mode']);
        $this->assertSame('operational', $pricing['billing_hours_mode']);
        $this->assertEqualsWithDelta($expectedRouteOperationalHours, (float) $pricing['route_billable_hours'], 0.0000001);
        $this->assertEqualsWithDelta($expectedRouteOperationalHours, (float) $pricing['display_route_hours'], 0.0000001);
        $this->assertEqualsWithDelta($expectedRouteOperationalHours, (float) $pricing['route_operational_display_hours'], 0.0000001);
        $this->assertNotEqualsWithDelta($legacyPerLegRoundedHours, (float) $pricing['display_route_hours'], 0.0000001);
        $this->assertEqualsWithDelta((float) $pricing['route_operational_hours'], (float) $pricing['pricing_hours'], 0.0000001);
        $this->assertEqualsWithDelta((float) $pricing['route_operational_hours'], (float) $pricing['final_billable_hours'], 0.0000001);
        $this->assertCount(2, $pricing['client_legs']);
        foreach ($pricing['client_legs'] as $clientLeg) {
            $this->assertSame('operational', $clientLeg['time_model']);
            $this->assertEqualsWithDelta(
                (float) $clientLeg['operational_flight_hours'],
                (float) $clientLeg['display_flight_hours'],
                0.0000001,
            );
        }
        $this->assertContains((string) $pricing['legs'][0]['profile_match_level'], ['aircraft_id', 'aircraft_model']);
        $this->assertSame('database', (string) $pricing['legs'][0]['profile_source']);
        $this->assertSame('operational', (string) data_get($pricing, 'flight_time_comparison.model'));
        $this->assertSame(round((float) $pricing['route_operational_hours'] * 3200.0, 2), (float) $pricing['raw_client_flight_cost']);
        $this->assertSame((float) $pricing['raw_client_flight_cost'], (float) $pricing['client_flight_cost']);
    }

    public function test_dynamic_operational_model_generates_profile_from_new_aircraft_data_without_model_rules(): void
    {
        $this->seed();
        config()->set('vuelos.dynamic_flight_time_enabled', true);
        config()->set('vuelos.flight_time_model', 'operational');

        $aircraft = new Aeronave([
            'id' => 912,
            'model' => 'Future Platform 9000',
            'category' => 'Heavy Jet',
            'hourly_rate' => 7200,
            'minimum_hours' => 0,
            'minimum_route_price' => 0,
            'airport_expenses_usd' => 0,
            'speed_kmh' => 870,
            'climb_descent_minutes' => 52,
            'climb_descent_source' => Aeronave::CLIMB_DESCENT_SOURCE_MANUAL,
            'currency' => 'USD',
        ]);
        $aircraft->fixed_minutes_per_leg = 9;

        $pricing = app(FlightPricingService::class)->calculateForAircraft(
            $aircraft,
            $this->canonicalRoute(distanceNm: 640.0, distanceKm: 1185.28),
            [
                'include_iva' => false,
                'airport_expenses' => false,
                'apply_margin' => false,
            ],
        );

        $baseSpeedKnots = 870.0 / 1.852;
        $climbShare = 16.0 / (16.0 + 12.0);
        $expectedClimbMinutes = round(52.0 * $climbShare, 2);
        $expectedDescentMinutes = round(52.0 - $expectedClimbMinutes, 2);
        $expectedCruiseMinutes = (640.0 / $baseSpeedKnots) * 60;
        $expectedOperationalHours = $expectedCruiseMinutes / 60;

        $this->assertSame('aircraft_generated', (string) $pricing['legs'][0]['profile_source']);
        $this->assertSame('aircraft_dynamic', (string) $pricing['legs'][0]['profile_match_level']);
        $this->assertSame('aircraft.speed_kmh', (string) $pricing['legs'][0]['speed_source']);
        $this->assertEqualsWithDelta($expectedClimbMinutes, (float) $pricing['legs'][0]['climb_minutes'], 0.0000001);
        $this->assertEqualsWithDelta($expectedDescentMinutes, (float) $pricing['legs'][0]['descent_minutes'], 0.0000001);
        $this->assertSame(0.0, (float) $pricing['legs'][0]['fixed_operational_minutes']);
        $this->assertEqualsWithDelta($expectedOperationalHours, (float) $pricing['route_operational_hours'], 0.0000001);
        $this->assertEqualsWithDelta((float) $pricing['route_operational_hours'], (float) $pricing['pricing_hours'], 0.0000001);
    }

    public function test_persisted_category_default_minutes_override_type_profile_climb_descent_total(): void
    {
        $this->seed();
        config()->set('vuelos.dynamic_flight_time_enabled', true);
        config()->set('vuelos.flight_time_model', 'operational');

        $aircraft = new Aeronave([
            'id' => 913,
            'model' => 'Category Default Light Jet',
            'category' => 'Light Jet',
            'hourly_rate' => 3200,
            'minimum_hours' => 0,
            'minimum_route_price' => 0,
            'airport_expenses_usd' => 0,
            'speed_kmh' => 780,
            'climb_descent_minutes' => 30,
            'climb_descent_source' => Aeronave::CLIMB_DESCENT_SOURCE_CATEGORY_DEFAULT,
            'currency' => 'USD',
        ]);

        $pricing = app(FlightPricingService::class)->calculateForAircraft(
            $aircraft,
            $this->canonicalRoute(distanceNm: 640.0, distanceKm: 1185.28),
            [
                'include_iva' => false,
                'airport_expenses' => false,
                'apply_margin' => false,
            ],
        );

        $this->assertEqualsWithDelta(16.36, (float) $pricing['legs'][0]['climb_minutes'], 0.01);
        $this->assertEqualsWithDelta(13.64, (float) $pricing['legs'][0]['descent_minutes'], 0.01);
        $this->assertSame(30.0, (float) $pricing['legs'][0]['climb_descent_minutes_effective']);
        $this->assertSame('category_default', (string) $pricing['legs'][0]['climb_descent_source_recorded']);
        $this->assertSame('category_fallback', (string) $pricing['legs'][0]['climb_descent_source_effective']);
    }

    public function test_manual_climb_descent_source_overrides_dynamic_profile(): void
    {
        $this->seed();
        config()->set('vuelos.dynamic_flight_time_enabled', true);
        config()->set('vuelos.flight_time_model', 'operational');

        $aircraft = new Aeronave([
            'id' => 914,
            'model' => 'Manual Climb Jet',
            'category' => 'Light Jet',
            'hourly_rate' => 3200,
            'minimum_hours' => 0,
            'minimum_route_price' => 0,
            'airport_expenses_usd' => 0,
            'speed_kmh' => 780,
            'climb_descent_minutes' => 52,
            'climb_descent_source' => Aeronave::CLIMB_DESCENT_SOURCE_MANUAL,
            'currency' => 'USD',
        ]);

        $pricing = app(FlightPricingService::class)->calculateForAircraft(
            $aircraft,
            $this->canonicalRoute(distanceNm: 640.0, distanceKm: 1185.28),
            [
                'include_iva' => false,
                'airport_expenses' => false,
                'apply_margin' => false,
            ],
        );

        $this->assertEqualsWithDelta(28.36, (float) $pricing['legs'][0]['climb_minutes'], 0.01);
        $this->assertEqualsWithDelta(23.64, (float) $pricing['legs'][0]['descent_minutes'], 0.01);
        $this->assertSame(52.0, (float) $pricing['legs'][0]['climb_descent_minutes_effective']);
        $this->assertSame('manual', (string) $pricing['legs'][0]['climb_descent_source_recorded']);
        $this->assertSame('manual', (string) $pricing['legs'][0]['climb_descent_source_effective']);
    }

    public function test_database_type_profile_is_used_when_aircraft_has_no_persisted_minutes(): void
    {
        $this->seed();
        config()->set('vuelos.dynamic_flight_time_enabled', true);
        config()->set('vuelos.flight_time_model', 'operational');

        DB::table('aircraft_performance_profiles')->insert([
            'aircraft_id' => null,
            'aircraft_model' => null,
            'aircraft_type' => 'Mid Jet',
            'taxi_out_minutes' => 4,
            'taxi_in_minutes' => 3,
            'takeoff_minutes' => 1,
            'landing_minutes' => 2,
            'climb_minutes' => 18,
            'climb_distance_nm' => 58,
            'descent_minutes' => 11,
            'descent_distance_nm' => 42,
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
            'id' => 915,
            'model' => 'Mid Jet Type Override',
            'category' => 'Mid Jet',
            'hourly_rate' => 3200,
            'minimum_hours' => 0,
            'minimum_route_price' => 0,
            'airport_expenses_usd' => 0,
            'speed_kmh' => 741,
            'climb_descent_minutes' => 0,
            'climb_descent_source' => Aeronave::CLIMB_DESCENT_SOURCE_CATEGORY_DEFAULT,
            'currency' => 'USD',
        ]);

        $pricing = app(FlightPricingService::class)->calculateForAircraft(
            $aircraft,
            $this->canonicalRoute(distanceNm: 640.0, distanceKm: 1185.28),
            [
                'include_iva' => false,
                'airport_expenses' => false,
                'apply_margin' => false,
            ],
        );

        $this->assertSame(18.0, (float) $pricing['legs'][0]['climb_minutes']);
        $this->assertSame(11.0, (float) $pricing['legs'][0]['descent_minutes']);
        $this->assertSame(29.0, (float) $pricing['legs'][0]['climb_descent_minutes_effective']);
        $this->assertSame('database', (string) $pricing['legs'][0]['profile_source']);
        $this->assertContains((string) $pricing['legs'][0]['profile_match_level'], ['aircraft_type', 'aircraft_model', 'aircraft_id']);
        $this->assertSame('profile_db', (string) $pricing['legs'][0]['climb_descent_source_effective']);
    }

    public function test_database_model_profile_takes_precedence_over_persisted_aircraft_minutes(): void
    {
        $this->seed();
        config()->set('vuelos.dynamic_flight_time_enabled', true);
        config()->set('vuelos.flight_time_model', 'operational');

        DB::table('aircraft_performance_profiles')->insert([
            'aircraft_id' => null,
            'aircraft_model' => 'Model Priority Jet',
            'aircraft_type' => null,
            'taxi_out_minutes' => 4,
            'taxi_in_minutes' => 3,
            'takeoff_minutes' => 1,
            'landing_minutes' => 2,
            'climb_minutes' => 21,
            'climb_distance_nm' => 58,
            'descent_minutes' => 9,
            'descent_distance_nm' => 42,
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
            'id' => 916,
            'model' => 'Model Priority Jet',
            'category' => 'Mid Jet',
            'hourly_rate' => 3200,
            'minimum_hours' => 0,
            'minimum_route_price' => 0,
            'airport_expenses_usd' => 0,
            'speed_kmh' => 741,
            'climb_descent_minutes' => 35,
            'climb_descent_source' => Aeronave::CLIMB_DESCENT_SOURCE_CATEGORY_DEFAULT,
            'currency' => 'USD',
        ]);

        $pricing = app(FlightPricingService::class)->calculateForAircraft(
            $aircraft,
            $this->canonicalRoute(distanceNm: 640.0, distanceKm: 1185.28),
            [
                'include_iva' => false,
                'airport_expenses' => false,
                'apply_margin' => false,
            ],
        );

        $this->assertSame(21.0, (float) $pricing['legs'][0]['climb_minutes']);
        $this->assertSame(9.0, (float) $pricing['legs'][0]['descent_minutes']);
        $this->assertSame(30.0, (float) $pricing['legs'][0]['climb_descent_minutes_effective']);
        $this->assertSame('profile_db', (string) $pricing['legs'][0]['climb_descent_source_effective']);
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

    public function test_dynamic_operational_model_charges_visible_hours_not_minimum_hours(): void
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
        $this->assertFalse((bool) $pricing['minimum_applied']);
        $this->assertEqualsWithDelta((float) $pricing['route_operational_hours'], (float) $pricing['pricing_hours'], 0.0000001);
        $this->assertEqualsWithDelta((float) $pricing['route_operational_hours'], (float) $pricing['final_billable_hours'], 0.0000001);
        $this->assertSame(round((float) $pricing['route_operational_hours'] * 3200.0, 2), (float) $pricing['raw_client_flight_cost']);
        $this->assertSame((float) $pricing['raw_client_flight_cost'], (float) $pricing['client_flight_cost']);
    }

    public function test_flight_cost_adds_repositioning_without_double_counting_client_hours(): void
    {
        $this->seed();
        config()->set('vuelos.dynamic_flight_time_enabled', false);
        config()->set('vuelos.flight_time_model', 'direct');

        $aircraft = new Aeronave([
            'id' => 501,
            'model' => 'Repositioning Audit Jet',
            'category' => 'Mid Jet',
            'hourly_rate' => 6500,
            'minimum_hours' => 3,
            'minimum_route_price' => 0,
            'airport_expenses_usd' => 0,
            'climb_descent_minutes' => 35,
            'speed_kmh' => 741,
            'currency' => 'USD',
        ]);

        $pricing = app(FlightPricingService::class)->calculateForAircraft(
            $aircraft,
            $this->canonicalRoute(distanceNm: 120.0, distanceKm: 222.24),
            [
                'include_iva' => false,
                'airport_expenses' => false,
                'apply_margin' => false,
                'operational_context' => [
                    'apply_repositioning_pricing' => true,
                    'repositioning' => [
                        'billable_hours' => 0.5,
                        'operational_hours' => 0.5,
                        'cost' => 3250.0,
                    ],
                    'return_to_base' => [
                        'billable_hours' => 0.0,
                        'operational_hours' => 0.0,
                        'cost' => 0.0,
                    ],
                ],
            ],
        );

        $this->assertFalse((bool) $pricing['minimum_applied']);
        $this->assertEqualsWithDelta((float) $pricing['route_operational_hours'], (float) $pricing['pricing_hours'], 0.0000001);
        $this->assertSame(
            round((float) $pricing['pricing_hours'] * 6500.0, 2),
            (float) $pricing['client_flight_cost']
        );
        $this->assertSame(3250.0, (float) $pricing['repositioning_cost']);
        $this->assertSame(
            round((float) $pricing['client_flight_cost'] + (float) $pricing['repositioning_cost'] + (float) $pricing['return_to_base_cost'], 2),
            (float) $pricing['flight_cost']
        );
        $this->assertSame(
            round((float) $pricing['pricing_hours'] + 0.5, 2),
            round((float) $pricing['total_billed_hours'], 2)
        );
    }

    public function test_dynamic_operational_model_exposes_minimum_route_price_without_applying_it(): void
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

        $this->assertSame(round((float) $pricing['route_operational_hours'] * 3200.0, 2), (float) $pricing['raw_client_flight_cost']);
        $this->assertSame(18000.0, (float) $pricing['minimum_route_price']);
        $this->assertFalse((bool) $pricing['minimum_route_price_applied']);
        $this->assertSame('category_minimum_route_price', (string) $pricing['minimum_route_price_reason']);
        $this->assertSame('category_pricing_rules.active', (string) $pricing['minimum_route_price_source']);
        $this->assertSame((float) $pricing['raw_client_flight_cost'], (float) $pricing['client_flight_cost']);
        $this->assertSame(1000.0, (float) $pricing['airport_expenses']);
        $this->assertSame(round((float) $pricing['client_flight_cost'] + 1000.0, 2), (float) $pricing['subtotal_before_margin']);
        $this->assertGreaterThan((float) $pricing['subtotal_before_margin'], (float) $pricing['subtotal']);
    }

    public function test_dynamic_formula_sums_each_component_once_across_aircraft_and_routes(): void
    {
        $this->seed();
        config()->set('vuelos.dynamic_flight_time_enabled', true);
        config()->set('vuelos.flight_time_model', 'operational');

        $cases = [
            ['speed_kmh' => 448.0, 'climb_descent_minutes' => 35.0, 'minimum_hours' => 2.0, 'distances_nm' => [223.0126, 223.0126]],
            ['speed_kmh' => 860.0, 'climb_descent_minutes' => 30.0, 'minimum_hours' => 1.5, 'distances_nm' => [90.0, 420.0, 175.0]],
        ];

        foreach ($cases as $caseIndex => $case) {
            $aircraft = new Aeronave([
                'id' => 9900 + $caseIndex,
                'model' => 'Dynamic invariant aircraft '.$caseIndex,
                'category' => 'Dynamic Test Category',
                'hourly_rate' => 4600,
                'minimum_hours' => $case['minimum_hours'],
                'minimum_route_price' => 0,
                'airport_expenses_usd' => 0,
                'speed_kmh' => $case['speed_kmh'],
                'climb_descent_minutes' => $case['climb_descent_minutes'],
                'currency' => 'USD',
            ]);
            $route = $this->multiLegCanonicalRoute($case['distances_nm']);
            foreach ($route['legs'] as $index => &$leg) {
                $leg['origin_airport']['climb_descent_adjustment_minutes'] = $index + 1;
                $leg['destination_airport']['climb_descent_adjustment_minutes'] = $index + 2;
            }
            unset($leg);

            $pricing = app(FlightPricingService::class)->calculateForAircraft($aircraft, $route, [
                'include_iva' => false,
                'airport_expenses' => false,
                'apply_margin' => false,
            ]);

            $speedKnots = $case['speed_kmh'] / 1.852;
            $expectedRouteMinutes = 0.0;
            foreach ($pricing['legs'] as $index => $legPricing) {
                $expectedCruiseMinutes = ($case['distances_nm'][$index] / $speedKnots) * 60;
                $expectedAdjustment = ($index + 1) + ($index + 2);
                $expectedLegMinutes = $expectedCruiseMinutes;
                $expectedRouteMinutes += $expectedLegMinutes;

                $this->assertEqualsWithDelta($case['climb_descent_minutes'], (float) $legPricing['climb_minutes'] + (float) $legPricing['descent_minutes'], 0.0000001);
                $this->assertEqualsWithDelta($expectedAdjustment, (float) $legPricing['airport_adjustment_minutes'], 0.0000001);
                $this->assertSame(0.0, (float) $legPricing['applied_climb_minutes']);
                $this->assertSame(0.0, (float) $legPricing['applied_descent_minutes']);
                $this->assertSame(0.0, (float) $legPricing['applied_airport_adjustment_minutes']);
                $this->assertEqualsWithDelta($expectedLegMinutes, (float) $legPricing['real_flight_hours'] * 60, 0.0000001);
            }

            $expectedOperationalHours = $expectedRouteMinutes / 60;
            $expectedFinalHours = $expectedOperationalHours;
            $this->assertEqualsWithDelta($expectedRouteMinutes, (float) $pricing['route_operational_hours'] * 60, 0.0000001);
            $this->assertEqualsWithDelta($expectedOperationalHours, (float) $pricing['display_route_hours'], 0.0000001);
            $this->assertEqualsWithDelta($expectedFinalHours, (float) $pricing['final_billable_hours'], 0.0000001);
            $this->assertEqualsWithDelta((float) $pricing['route_operational_hours'], (float) $pricing['final_billable_hours'], 0.0000001);
            $this->assertFalse((bool) $pricing['minimum_applied']);
        }
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

    private function multiLegCanonicalRoute(array $distancesNm): array
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

        $legs = [];
        $totalDistanceNm = 0.0;
        $totalDistanceKm = 0.0;

        foreach (array_values($distancesNm) as $index => $distanceNm) {
            $distanceKm = (float) $distanceNm * 1.852;
            $isEvenLeg = $index % 2 === 0;
            $legs[] = [
                'position' => $index + 1,
                'origin' => $isEvenLeg ? 'MMMX' : 'MMTO',
                'destination' => $isEvenLeg ? 'MMTO' : 'MMMX',
                'origin_airport' => $isEvenLeg ? $originAirport : $destinationAirport,
                'destination_airport' => $isEvenLeg ? $destinationAirport : $originAirport,
                'distance_km' => $distanceKm,
                'distance_nm' => (float) $distanceNm,
                'international' => false,
                'departure_datetime' => sprintf('2026-08-%02dT10:00:00Z', $index + 3),
            ];
            $totalDistanceNm += (float) $distanceNm;
            $totalDistanceKm += $distanceKm;
        }

        return [
            'trip_type' => 'multi_leg',
            'origin' => 'MMMX',
            'destination' => 'MMTO',
            'route_signature' => 'MMMX>MMTO>MMMX',
            'distance_km' => $totalDistanceKm,
            'distance_nm' => $totalDistanceNm,
            'max_leg_distance_km' => max(array_map(fn ($leg) => (float) $leg['distance_km'], $legs)),
            'legs' => $legs,
        ];
    }
}
