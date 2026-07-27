<?php

namespace Tests\Feature;

use App\Modelos\Aeronave;
use App\Modelos\Aeropuerto;
use App\Servicios\Vuelos\FlightRouteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Tests\TestCase;

class CanonicalAirportResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_persisted_airport_id_before_other_aliases(): void
    {
        $origin = $this->airport('MMMX', 'MEX');
        $destination = $this->airport('MMTO', 'TLC', 19.3, -99.5);

        $route = $this->route([
            'origin_airport_id' => $origin->id,
            'origin_icao' => 'XXXX',
            'destination_airport_id' => $destination->id,
        ]);

        $this->assertSame('MMMX', $route['origin']);
        $this->assertSame('MMTO', $route['destination']);
    }

    public function test_resolves_icao_iata_lowercase_and_whitespace(): void
    {
        $this->airport('MMMX', 'MEX');
        $this->airport('MMTO', 'TLC', 19.3, -99.5);

        $this->assertSame('MMMX', $this->route(['origin_icao' => '  mmmx ', 'destination_icao' => 'mmto'])['origin']);
        $this->assertSame('MMMX', $this->route(['origin_iata' => ' mex ', 'destination_iata' => 'tlc'])['origin']);
    }

    public function test_resolves_an_accented_legacy_city_only_when_catalog_match_is_unique(): void
    {
        $origin = $this->airport('MMQT', 'QRO');
        $origin->forceFill(['city' => 'Queretaro'])->save();
        $this->airport('MMMM', 'MLM', 19.8499, -101.0250);

        $route = $this->route([
            'origin' => ' QUERÉTARO ',
            'destination' => 'MMMM',
        ]);

        $this->assertSame('MMQT>MMMM', $route['route_signature']);
    }

    public function test_resolves_real_legacy_frontend_airport_objects(): void
    {
        $origin = $this->airport('MMMX', 'MEX');
        $destination = $this->airport('MMTO', 'TLC', 19.3, -99.5);

        $route = $this->route([
            'legs' => [[
                'origin' => 'MEX',
                'originAirport' => ['id' => $origin->id, 'code' => 'MMMX', 'iata' => 'MEX'],
                'destination' => 'TLC',
                'destinationAirport' => ['id' => $destination->id, 'code' => 'MMTO', 'iata' => 'TLC'],
            ]],
        ]);

        $this->assertSame('MMMX>MMTO', $route['route_signature']);
    }

    public function test_mmto_to_mmmm_resolves_with_the_real_payload_when_ids_are_null(): void
    {
        $this->airport('MMTO', 'TLC');
        $this->airport('MMMM', 'MLM', 19.8499, -101.0250);
        $leg = [
            'origin_airport_id' => null,
            'origin' => 'MMTO',
            'origin_icao' => 'MMTO',
            'origin_iata' => 'TLC',
            'origin_airport' => ['id' => null, 'icao' => 'MMTO', 'iata' => 'TLC', 'name' => 'Toluca'],
            'destination_airport_id' => null,
            'destination' => 'MMMM',
            'destination_icao' => 'MMMM',
            'destination_iata' => 'MLM',
            'destination_airport' => ['id' => null, 'icao' => 'MMMM', 'iata' => 'MLM', 'name' => 'Morelia'],
        ];

        $route = app(FlightRouteService::class)->buildCanonicalRoute([...$leg, 'legs' => [$leg]]);

        $this->assertSame('MMTO>MMMM', $route['route_signature']);
        $this->assertSame(1, $route['legs'][0]['position']);
    }

    public function test_distinct_catalog_codes_reconcile_a_stale_duplicate_airport_id(): void
    {
        $toluca = $this->airport('MMTO', 'TLC');
        $this->airport('MMMM', 'MLM', 19.8499, -101.0250);
        $route = $this->route([
            'legs' => [[
                'origin' => 'MMTO',
                'origin_airport_id' => $toluca->id,
                'origin_icao' => 'MMTO',
                'destination' => 'MMMM',
                'destination_airport_id' => $toluca->id,
                'destination_icao' => 'MMMM',
            ]],
        ]);

        $this->assertSame('MMTO>MMMM', $route['route_signature']);
        $this->assertNotSame(
            $route['legs'][0]['origin_airport']['id'],
            $route['legs'][0]['destination_airport']['id'],
        );
    }

    public function test_airport_search_catalog_returns_the_persisted_id(): void
    {
        $airport = $this->airport('MMTO', 'TLC');

        $this->getJson('/api/v1/airports/search?q=MMTO')
            ->assertOk()
            ->assertJsonPath('airports.0.id', $airport->id)
            ->assertJsonPath('airports.0.icao', 'MMTO');
    }

    public function test_preview_accepts_the_real_mmto_to_mmmm_payload(): void
    {
        $this->seed();
        $origin = Aeropuerto::query()->where('icao', 'MMTO')->first()
            ?? $this->airport('MMTO', 'TLC');
        $destination = Aeropuerto::query()->where('icao', 'MMMM')->first()
            ?? $this->airport('MMMM', 'MLM', 19.8499, -101.0250);
        $aircraft = Aeronave::query()->where('hourly_rate', '>', 0)->firstOrFail();
        $aircraft->forceFill(['base_airport' => 'TOLUCA', 'base_airport_id' => null])->save();
        Aeronave::query()
            ->whereKeyNot($aircraft->id)
            ->where('hourly_rate', '>', 0)
            ->first()
            ?->forceFill(['base_airport' => 'BASE INEXISTENTE', 'base_airport_id' => null])
            ->save();
        $leg = [
            'origin_airport_id' => null,
            'origin' => 'MMTO',
            'origin_icao' => 'MMTO',
            'origin_iata' => 'TLC',
            'origin_airport' => ['id' => null, 'icao' => 'MMTO', 'iata' => 'TLC', 'name' => $origin->name],
            'destination_airport_id' => null,
            'destination' => 'MMMM',
            'destination_icao' => 'MMMM',
            'destination_iata' => 'MLM',
            'destination_airport' => ['id' => null, 'icao' => 'MMMM', 'iata' => 'MLM', 'name' => $destination->name],
            'departure_datetime' => now()->addDays(5)->toISOString(),
        ];

        $this->postJson('/api/v1/client/quotes/preview', [
            ...$leg,
            'legs' => [$leg],
            'trip_type' => 'one_way',
            'passengers' => 2,
        ])
            ->assertOk()
            ->assertJsonPath('legs.0.origin', 'MMTO')
            ->assertJsonPath('legs.0.destination', 'MMMM');

        $this->assertContains(
            $aircraft->id,
            collect($this->postJson('/api/v1/client/quotes/preview', [
                ...$leg,
                'legs' => [$leg],
                'trip_type' => 'one_way',
                'passengers' => 2,
            ])->assertOk()->json('matches'))->pluck('aircraft_id')->all(),
        );
    }

    public function test_preview_only_returns_aircraft_with_a_canonical_base_at_origin(): void
    {
        $this->seed();
        $origin = Aeropuerto::query()->where('icao', 'MMTO')->first()
            ?? $this->airport('MMTO', 'TLC');
        $destination = Aeropuerto::query()->where('icao', 'MMMM')->first()
            ?? $this->airport('MMMM', 'MLM', 19.8499, -101.0250);
        $aircraft = Aeronave::query()->where('hourly_rate', '>', 0)->get();
        $local = $aircraft->firstOrFail();
        $repositioned = $aircraft->skip(1)->firstOrFail();

        $local->forceFill([
            'base_airport' => 'TOLUCA',
            'base_airport_id' => null,
        ])->save();
        $repositioned->forceFill([
            'base_airport' => $destination->icao,
            'base_airport_id' => $destination->id,
        ])->save();

        $response = $this->postJson('/api/v1/client/quotes/preview', [
            'origin' => $origin->icao,
            'origin_airport_id' => $origin->id,
            'destination' => $destination->icao,
            'destination_airport_id' => $destination->id,
            'departure_datetime' => now()->addDays(5)->toISOString(),
            'trip_type' => 'one_way',
            'passengers' => 1,
            'limit' => 24,
        ])->assertOk();

        $matches = collect($response->json('matches'));
        $localMatch = $matches->firstWhere('aircraft_id', $local->id);
        $repositionedMatch = $matches->firstWhere('aircraft_id', $repositioned->id);

        $this->assertNotNull($localMatch);
        $this->assertTrue($localMatch['based_at_origin']);
        $this->assertFalse($localMatch['requires_repositioning']);
        $this->assertTrue($matches->first()['based_at_origin']);
        $this->assertNull($repositionedMatch);
        $this->assertTrue($matches->every(
            fn (array $match) => $match['based_at_origin'] && ! $match['requires_repositioning'],
        ));

        $local->forceFill(['status' => 'inactive'])->save();

        $inactiveResponse = $this->postJson('/api/v1/client/quotes/preview', [
            'origin' => $origin->icao,
            'origin_airport_id' => $origin->id,
            'destination' => $destination->icao,
            'destination_airport_id' => $destination->id,
            'departure_datetime' => now()->addDays(6)->toISOString(),
            'trip_type' => 'one_way',
            'passengers' => 1,
            'limit' => 24,
        ])->assertOk();

        $this->assertNotContains(
            $local->id,
            collect($inactiveResponse->json('matches'))->pluck('aircraft_id')->all(),
        );
    }

    public function test_preview_reconciles_a_stale_duplicate_destination_id(): void
    {
        $this->seed();
        $toluca = Aeropuerto::query()->where('icao', 'MMTO')->first()
            ?? $this->airport('MMTO', 'TLC');
        Aeropuerto::query()->where('icao', 'MMMM')->first()
            ?? $this->airport('MMMM', 'MLM', 19.8499, -101.0250);
        $leg = [
            'origin' => 'MMTO',
            'origin_airport_id' => $toluca->id,
            'origin_icao' => 'MMTO',
            'destination' => 'MMMM',
            'destination_airport_id' => $toluca->id,
            'destination_icao' => 'MMMM',
            'departure_datetime' => now()->addDays(5)->toISOString(),
        ];

        $this->postJson('/api/v1/client/quotes/preview', [
            ...$leg,
            'legs' => [$leg],
            'trip_type' => 'one_way',
            'passengers' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('legs.0.origin', 'MMTO')
            ->assertJsonPath('legs.0.destination', 'MMMM');
    }

    public function test_preview_returns_safe_airport_not_found_details(): void
    {
        $this->seed();

        $this->postJson('/api/v1/client/quotes/preview', [
            'origin' => 'XXXX',
            'origin_icao' => 'XXXX',
            'origin_iata' => 'XXX',
            'destination' => 'MMMM',
            'destination_icao' => 'MMMM',
            'departure_datetime' => now()->addDays(5)->toISOString(),
            'trip_type' => 'one_way',
            'passengers' => 2,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'AIRPORT_NOT_FOUND')
            ->assertJsonPath('details.leg_index', 0)
            ->assertJsonPath('details.side', 'origin')
            ->assertJsonPath('details.attempted_icao', 'XXXX')
            ->assertJsonPath('details.attempted_iata', 'XXX');
    }

    public function test_unknown_inactive_and_coordinate_less_airports_return_422(): void
    {
        $this->airport('INAC', 'INA', status: 'inactive');
        $this->airport('NOCO', 'NOC', latitude: null, longitude: null);

        foreach (['XXXX', 'INAC', 'NOCO'] as $code) {
            try {
                $this->route(['origin' => $code, 'destination' => 'MMTO']);
                $this->fail("{$code} debió ser rechazado.");
            } catch (HttpResponseException $exception) {
                $this->assertSame(422, $exception->getResponse()->getStatusCode());
                $this->assertSame('AIRPORT_NOT_FOUND', $exception->getResponse()->getData(true)['code']);
            }
        }
    }

    public function test_ambiguous_exact_name_is_not_accepted(): void
    {
        $this->airport('AAAA', 'AAA', name: 'Aeropuerto Duplicado');
        $this->airport('BBBB', 'BBB', 20.0, -100.0, name: 'Aeropuerto Duplicado');
        $this->airport('MMTO', 'TLC', 19.3, -99.5);

        $this->expectException(HttpResponseException::class);
        $this->route([
            'origin' => null,
            'origin_airport' => ['name' => 'Aeropuerto Duplicado'],
            'destination' => 'MMTO',
        ]);
    }

    private function route(array $payload): array
    {
        if (! Aeropuerto::query()->where('icao', 'MMTO')->exists()) {
            $this->airport('MMTO', 'TLC', 19.3, -99.5);
        }

        return app(FlightRouteService::class)->buildCanonicalRoute([
            'origin' => 'MMMX',
            'destination' => 'MMTO',
            ...$payload,
        ]);
    }

    private function airport(
        string $icao,
        string $iata,
        ?float $latitude = 19.4,
        ?float $longitude = -99.0,
        string $status = 'active',
        ?string $name = null,
    ): Aeropuerto {
        return Aeropuerto::query()->create([
            'icao' => $icao,
            'iata' => $iata,
            'name' => $name ?? "Airport {$icao}",
            'city' => 'Test',
            'country' => 'MX',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'status' => $status,
        ]);
    }
}
