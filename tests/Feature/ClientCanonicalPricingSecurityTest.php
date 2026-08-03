<?php

namespace Tests\Feature;

use App\Modelos\Aeronave;
use App\Modelos\Aeropuerto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientCanonicalPricingSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_client_duration_aliases_are_ignored_by_preview(): void
    {
        $this->seed();

        $baseline = $this->preview($this->basePayload());
        $aircraftId = (int) data_get($baseline, 'matches.0.aircraft_id');
        $baselineQuote = collect($baseline['matches'])->firstWhere('aircraft_id', $aircraftId);

        foreach ([
            'duration_minutes' => 1,
            'estimated_minutes' => 1,
            'quoted_minutes' => 1,
            'flight_minutes' => 1,
            'leg_minutes' => 1,
            'duration_hours' => 0.01,
        ] as $field => $value) {
            $payload = $this->basePayload();
            $payload['legs'][0][$field] = $value;
            $quote = collect($this->preview($payload)['matches'])->firstWhere('aircraft_id', $aircraftId);

            $this->assertSame((float) $baselineQuote['total'], (float) $quote['total'], $field);
            $this->assertSame(
                (float) data_get($baselineQuote, 'pricing_breakdown.billable_hours'),
                (float) data_get($quote, 'pricing_breakdown.billable_hours'),
                $field,
            );
            $this->assertSame('distance_speed', data_get($quote, 'pricing_breakdown.debug_pricing.hours_source'));
        }
    }

    public function test_nested_duration_and_economic_values_are_ignored(): void
    {
        $this->seed();

        $baseline = $this->preview($this->basePayload());
        $aircraftId = (int) data_get($baseline, 'matches.0.aircraft_id');
        $baselineQuote = collect($baseline['matches'])->firstWhere('aircraft_id', $aircraftId);
        $payload = $this->basePayload();
        $payload['legs'][0] = [
            ...$payload['legs'][0],
            'duration_minutes' => 1,
            'distance_km' => 1,
            'distance_nm' => 1,
            'billable_hours' => 0.01,
            'hourly_rate' => 0.01,
            'subtotal' => 0.01,
            'tax' => 0,
            'iva' => 0,
            'total' => 0.01,
            'final_price' => 0.01,
        ];
        $payload += [
            'subtotal' => 1,
            'taxes' => 0,
            'total' => 1,
            'final_price' => 1,
            'hourly_rate' => 1,
            'billable_hours' => 0.01,
        ];

        $quote = collect($this->preview($payload)['matches'])->firstWhere('aircraft_id', $aircraftId);

        $this->assertSame((float) $baselineQuote['total'], (float) $quote['total']);
        $this->assertSame(
            (float) data_get($baselineQuote, 'pricing_breakdown.billable_hours'),
            (float) data_get($quote, 'pricing_breakdown.billable_hours'),
        );
    }

    public function test_preview_and_persisted_request_use_the_same_backend_price(): void
    {
        $this->seed();

        $aircraft = Aeronave::query()
            ->whereIn('status', ['active', 'trial_active'])
            ->where('capacity', '>=', 2)
            ->where('hourly_rate', '>', 0)
            ->firstOrFail();
        $originAirport = Aeropuerto::query()->where('icao', 'MMMX')->firstOrFail();
        $destinationAirport = Aeropuerto::query()->where('icao', 'MMTO')->firstOrFail();
        $payload = [
            ...$this->basePayload(),
            'origin_airport_id' => $originAirport->id,
            'origin_icao' => 'MMMX',
            'destination_airport_id' => $destinationAirport->id,
            'destination_icao' => 'MMTO',
            'aircraft_id' => $aircraft->id,
            'provider_id' => $aircraft->provider_id,
            'requirements' => [],
        ];
        $previewQuote = collect($this->preview($payload)['matches'])->firstWhere('aircraft_id', $aircraft->id);
        $this->assertNotNull($previewQuote);

        $token = $this->registerTrialClient();
        $stored = $this->withToken($token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/client/flight-requests', [
                'origin' => $payload['origin'],
                'destination' => $payload['destination'],
                'origin_airport_id' => $originAirport->id,
                'origin_icao' => 'MMMX',
                'destination_airport_id' => $destinationAirport->id,
                'destination_icao' => 'MMTO',
                'departure_datetime' => $payload['departure_datetime'],
                'passengers' => $payload['passengers'],
                'trip_type' => $payload['trip_type'],
                'aircraft_id' => $aircraft->id,
                'provider_id' => $aircraft->provider_id,
                'requirements' => [[
                    'origin' => $payload['origin'],
                    'destination' => $payload['destination'],
                    'origin_airport_id' => $originAirport->id,
                    'origin_icao' => 'MMMX',
                    'destination_airport_id' => $destinationAirport->id,
                    'destination_icao' => 'MMTO',
                    'departure_datetime' => $payload['departure_datetime'],
                    'duration_minutes' => 1,
                    'subtotal' => 1,
                    'total' => 1,
                ]],
                'subtotal' => 1,
                'total' => 1,
                'final_price' => 1,
            ])
            ->assertCreated()
            ->json();

        $this->assertSame(
            (float) $previewQuote['total'],
            (float) data_get($stored, 'flight_request.final_price'),
        );
        $this->assertSame(
            'official_backend_pricing_v4',
            data_get($stored, 'flight_request.pricing_context.pricing_formula_version'),
        );
        $this->assertSame(
            'backend_distance_and_aircraft_speed',
            data_get($stored, 'flight_request.pricing_context.duration_snapshot.source'),
        );
    }

    public function test_preview_exposes_explicit_estimated_and_billable_time_fields(): void
    {
        $this->seed();

        $response = $this->preview($this->basePayload());
        $quote = collect($response['matches'])->first();

        $this->assertNotNull($quote);
        $this->assertGreaterThan(0, (float) data_get($quote, 'estimated_flight_minutes'));
        $this->assertGreaterThan(0, (float) data_get($quote, 'billable_flight_minutes'));
        $this->assertNotSame('', (string) data_get($quote, 'estimated_flight_time'));
        $this->assertNotSame('', (string) data_get($quote, 'billable_flight_time'));
        $this->assertSame(
            (float) data_get($quote, 'pricing_breakdown.billable_minutes'),
            (float) data_get($quote, 'billable_flight_minutes'),
        );
        $this->assertSame(
            (string) data_get($quote, 'billed_time'),
            (string) data_get($quote, 'billable_flight_time'),
        );
    }

    public function test_one_way_round_trip_and_multi_leg_use_canonical_backend_legs(): void
    {
        $this->seed();

        $oneWay = $this->preview($this->basePayload());
        $roundTrip = $this->preview([
            ...$this->basePayload(),
            'trip_type' => 'round_trip',
            'return_datetime' => now()->addDays(5)->addHours(4)->toISOString(),
        ]);
        $multiPayload = [
            ...$this->basePayload(),
            'trip_type' => 'multi_leg',
            'return_to_origin' => true,
            'requirements' => [[
                'origin' => 'MMTO',
                'destination' => 'MMSD',
                'departure_datetime' => now()->addDays(5)->addHours(3)->toISOString(),
                'duration_hours' => 0.01,
            ]],
        ];
        unset($multiPayload['legs']);
        $multiLeg = $this->preview($multiPayload);

        $this->assertSame(1, $oneWay['segment_count']);
        $this->assertSame(2, $roundTrip['segment_count']);
        $this->assertSame(3, $multiLeg['segment_count']);
        $this->assertSame('MMMX', data_get($roundTrip, 'legs.1.destination'));
        $this->assertSame('MMMX', data_get($multiLeg, 'legs.2.destination'));
    }

    public function test_preview_excludes_aircraft_that_would_require_repositioning(): void
    {
        $this->seed();

        $aircraft = Aeronave::query()
            ->where('base_airport', 'MMMX')
            ->where('category', 'Light Jet')
            ->where('hourly_rate', '>', 0)
            ->firstOrFail();
        $aircraft->forceFill(['base_airport' => 'MMUN'])->save();
        $response = $this->preview([
            'origin' => 'MMTO',
            'destination' => 'MMSD',
            'departure_datetime' => now()->addDays(6)->toISOString(),
            'passengers' => min(2, (int) $aircraft->capacity),
            'trip_type' => 'one_way',
            'legs' => [[
                'origin' => 'MMTO',
                'destination' => 'MMSD',
                'departure_datetime' => now()->addDays(6)->toISOString(),
                'duration_minutes' => 1,
            ]],
        ]);
        $quote = collect($response['matches'])->firstWhere('aircraft_id', $aircraft->id);

        $this->assertNull($quote);
    }

    private function basePayload(): array
    {
        $departure = now()->addDays(5)->setTime(10, 0);

        return [
            'origin' => 'MMMX',
            'destination' => 'MMTO',
            'departure_datetime' => $departure->toISOString(),
            'passengers' => 2,
            'trip_type' => 'one_way',
            'legs' => [[
                'origin' => 'MMMX',
                'destination' => 'MMTO',
                'departure_datetime' => $departure->toISOString(),
            ]],
        ];
    }

    private function preview(array $payload): array
    {
        return $this->postJson('/api/v1/client/quotes/preview', $payload)
            ->assertOk()
            ->json();
    }

    private function registerTrialClient(): string
    {
        $email = 'pricing.security.'.Str::lower(Str::random(8)).'@test.dev';
        $register = $this->postJson('/api/v1/auth/register', [
            'name' => 'Cliente Pricing Seguro',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'client',
        ])->assertCreated();

        $token = (string) $register->json('token');
        $this->withToken($token)->postJson('/api/v1/subscriptions/start-trial')->assertCreated();

        return $token;
    }
}
