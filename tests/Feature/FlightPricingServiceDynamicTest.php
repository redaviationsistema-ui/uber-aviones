<?php

namespace Tests\Feature;

use App\Modelos\Aeronave;
use App\Servicios\Vuelos\FlightPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlightPricingServiceDynamicTest extends TestCase
{
    use RefreshDatabase;

    public function test_learjet_31a_uses_aircraft_minimum_hours_once_for_the_whole_trip(): void
    {
        $this->seed();

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

        $this->assertSame(1.0, (float) $pricing['route_billable_hours']);
        $this->assertSame(2.0, (float) $pricing['configured_minimum_hours']);
        $this->assertSame(2.0, (float) $pricing['applied_minimum_hours']);
        $this->assertSame(2.0, (float) $pricing['final_billable_hours']);
        $this->assertSame(4600.0, (float) $pricing['flight_cost']);
    }

    public function test_aircraft_without_configured_minimum_hours_uses_category_fallback(): void
    {
        $this->seed();

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
}
