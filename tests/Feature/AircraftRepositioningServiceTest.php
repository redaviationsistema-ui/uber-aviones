<?php

namespace Tests\Feature;

use App\Modelos\Aeronave;
use App\Modelos\Aeropuerto;
use App\Servicios\Aeronaves\AircraftRepositioningService;
use App\Servicios\Vuelos\FlightRouteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class AircraftRepositioningServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_search_radii_are_sanitized_from_backend_config(): void
    {
        config()->set('aviation.repositioning.search_radii_nm', [350, 100, -5, 200, 100, 0]);

        $service = app(AircraftRepositioningService::class);

        $this->assertSame([100, 200, 350], $service->configuredSearchRadiiNm());
    }

    public function test_nearby_context_uses_the_aircraft_legacy_base_label_and_skips_return_to_base_when_not_needed(): void
    {
        $base = $this->airport('MMGL', 'GDL', 20.5218, -103.3108, city: 'Guadalajara');
        $origin = $this->airport('MMMX', 'MEX', 19.4361, -99.0719, city: 'Ciudad de Mexico');
        $this->airport('MMUN', 'CUN', 21.0365, -86.8771, city: 'Cancun');

        $canonicalRoute = app(FlightRouteService::class)->buildCanonicalRoute([
            'origin' => $origin->icao,
            'origin_airport_id' => $origin->id,
            'destination' => $base->icao,
            'destination_airport_id' => $base->id,
            'departure_datetime' => '2026-08-05T10:00:00Z',
            'trip_type' => 'one_way',
        ]);

        $aircraft = new Aeronave([
            'id' => 9001,
            'model' => 'Dynamic Legacy Base Jet',
            'category' => 'Light Jet',
            'base_airport' => 'Guadalajara',
            'base_airport_id' => null,
            'speed_kmh' => 650,
            'hourly_rate' => 4500,
            'minimum_hours' => 0,
            'climb_descent_minutes' => 18,
        ]);

        $contexts = app(AircraftRepositioningService::class)->nearbyCandidateContexts(
            new Collection([$aircraft]),
            $origin,
            $canonicalRoute,
        );

        $this->assertCount(1, $contexts);
        $context = $contexts->first()['operational_context'];

        $this->assertSame($base->id, data_get($context, 'aircraft_base_airport.id'));
        $this->assertTrue((bool) data_get($context, 'requires_repositioning'));
        $this->assertGreaterThan(0, (float) data_get($context, 'repositioning.distance_nm'));
        $this->assertFalse((bool) data_get($context, 'return_to_base.required'));
        $this->assertSame(0.0, (float) data_get($context, 'return_to_base.distance_nm'));
    }

    private function airport(
        string $icao,
        string $iata,
        float $latitude,
        float $longitude,
        string $status = 'active',
        ?string $city = null,
        ?string $name = null,
    ): Aeropuerto {
        return Aeropuerto::query()->create([
            'icao' => $icao,
            'iata' => $iata,
            'name' => $name ?? "Airport {$icao}",
            'city' => $city ?? $icao,
            'country' => 'MX',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'status' => $status,
        ]);
    }
}
