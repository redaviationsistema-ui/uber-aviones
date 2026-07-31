<?php

namespace Tests\Feature;

use App\Modelos\Aeronave;
use App\Modelos\Aeropuerto;
use App\Modelos\AircraftAvailabilityBlock;
use App\Modelos\Proveedor;
use App\Servicios\Aeronaves\AircraftAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuotePreviewAvailabilityBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_conflicts_are_compared_against_each_aircraft_operational_window(): void
    {
        $origin = $this->airport('MMMX', 'MEX', 19.4361, -99.0719);
        $destination = $this->airport('MMUN', 'CUN', 21.0365, -86.8771);
        $provider = $this->provider();
        $firstAircraft = $this->aircraft($provider, $origin, ['speed_kmh' => 700]);
        $secondAircraft = $this->aircraft($provider, $origin, ['speed_kmh' => 700]);

        $start = now()->addDays(5)->setTime(10, 0);
        $end = $start->copy()->addHours(3);

        AircraftAvailabilityBlock::query()->create([
            'aircraft_id' => $firstAircraft->id,
            'start_datetime' => $start->copy()->subMinutes(15),
            'end_datetime' => $start->copy()->addMinutes(20),
            'status' => AircraftAvailabilityService::STATUS_BOOKED,
            'reason' => 'Performance overlap test',
        ]);

        $context = app(AircraftAvailabilityService::class)->buildBatchConflictContext(collect([
            [
                'aircraft_id' => $firstAircraft->id,
                'operational_window_start' => $start,
                'operational_window_end' => $end,
            ],
            [
                'aircraft_id' => $secondAircraft->id,
                'operational_window_start' => $end->copy()->addHours(2),
                'operational_window_end' => $end->copy()->addHours(5),
            ],
        ]));

        $this->assertTrue(app(AircraftAvailabilityService::class)->batchContextHasConflict(
            $context,
            $firstAircraft->id,
            $start,
            $end,
        ));
        $this->assertFalse(app(AircraftAvailabilityService::class)->batchContextHasConflict(
            $context,
            $secondAircraft->id,
            $end->copy()->addHours(2),
            $end->copy()->addHours(5),
        ));
    }

    public function test_preview_keeps_availability_queries_stable_for_one_ten_and_fifty_candidates(): void
    {
        $origin = $this->airport('MMMX', 'MEX', 19.4361, -99.0719);
        $destination = $this->airport('MMTO', 'TLC', 19.3371, -99.5660);
        $queryCounts = [];

        foreach ([1, 10, 50] as $candidateCount) {
            Aeronave::query()->delete();
            Proveedor::query()->delete();
            AircraftAvailabilityBlock::query()->delete();

            $provider = $this->provider();

            for ($index = 0; $index < $candidateCount; $index++) {
                $aircraft = $this->aircraft($provider, $origin, [
                    'model' => "Batch Candidate {$candidateCount}-{$index}",
                    'hourly_rate' => 4500 + $index,
                ]);

                if ($index % 7 === 0) {
                    AircraftAvailabilityBlock::query()->create([
                        'aircraft_id' => $aircraft->id,
                        'start_datetime' => now()->addDays(6)->setTime(9, 30),
                        'end_datetime' => now()->addDays(6)->setTime(13, 30),
                        'status' => AircraftAvailabilityService::STATUS_BOOKED,
                        'reason' => 'Batch preview conflict',
                    ]);
                }
            }

            DB::connection()->flushQueryLog();
            DB::connection()->enableQueryLog();

            $this->postJson('/api/v1/client/quotes/preview', [
                'origin' => $origin->icao,
                'origin_airport_id' => $origin->id,
                'destination' => $destination->icao,
                'destination_airport_id' => $destination->id,
                'departure_datetime' => now()->addDays(6)->setTime(10, 0)->toISOString(),
                'trip_type' => 'one_way',
                'passengers' => 2,
                'limit' => 12,
            ])->assertOk();

            $queries = DB::connection()->getQueryLog();
            DB::connection()->disableQueryLog();
            DB::connection()->flushQueryLog();

            $availabilityQueries = collect($queries)
                ->pluck('query')
                ->filter(fn (string $query) => str_contains($query, 'aircraft_availability_blocks'))
                ->values();

            $queryCounts[$candidateCount] = $availabilityQueries->count();

            $this->assertLessThanOrEqual(
                3,
                $availabilityQueries->count(),
                "El preview ejecutó demasiadas consultas de disponibilidad con {$candidateCount} candidatos.",
            );
        }

        $this->assertSame($queryCounts[1], $queryCounts[10]);
        $this->assertSame($queryCounts[10], $queryCounts[50]);
    }

    private function provider(): Proveedor
    {
        return Proveedor::factory()->create([
            'approval_status' => 'approved',
            'admin_validation_status' => 'approved',
            'status' => 'active',
            'operator_status' => 'active',
            'access_enabled' => true,
        ]);
    }

    private function aircraft(Proveedor $provider, Aeropuerto $baseAirport, array $overrides = []): Aeronave
    {
        return Aeronave::factory()->create([
            'provider_id' => $provider->id,
            'status' => 'active',
            'base_airport_id' => $baseAirport->id,
            'base_airport' => $baseAirport->icao,
            'capacity' => 6,
            'range_km' => 3500,
            'speed_kmh' => 700,
            'hourly_rate' => 4500,
            ...$overrides,
        ]);
    }

    private function airport(string $icao, string $iata, float $latitude, float $longitude): Aeropuerto
    {
        return Aeropuerto::query()->create([
            'icao' => $icao,
            'iata' => $iata,
            'name' => "Airport {$icao}",
            'city' => $icao,
            'country' => 'MX',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'status' => 'active',
        ]);
    }
}
