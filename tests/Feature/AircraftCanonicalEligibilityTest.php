<?php

namespace Tests\Feature;

use App\Modelos\Aeronave;
use App\Modelos\AircraftAvailabilityBlock;
use App\Modelos\CoincidenciaSolicitud;
use App\Modelos\Cotizacion;
use App\Modelos\DocumentoAeronave;
use App\Modelos\Proveedor;
use App\Modelos\SolicitudVuelo;
use App\Modelos\Usuario;
use App\Servicios\Aeronaves\AircraftEligibilityService;
use App\Servicios\Vuelos\FlightRouteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AircraftCanonicalEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_aircraft_and_unapproved_provider_are_rejected_consistently(): void
    {
        $this->seed();
        $provider = $this->provider('approved');
        $inactive = $this->aircraft($provider, ['status' => 'inactive']);
        $pendingProvider = $this->provider('pending');
        $pendingAircraft = $this->aircraft($pendingProvider);

        $this->assertDecision($inactive, false, 'AIRCRAFT_NOT_ACTIVE');
        $this->assertDecision($pendingAircraft, false, 'PROVIDER_NOT_APPROVED');

        $previewIds = collect($this->preview()['matches'])->pluck('aircraft_id');
        $this->assertNotContains($inactive->id, $previewIds);
        $this->assertNotContains($pendingAircraft->id, $previewIds);
    }

    public function test_capacity_and_range_use_backend_aircraft_and_canonical_route(): void
    {
        $this->seed();
        $provider = $this->provider('approved');
        $small = $this->aircraft($provider, ['capacity' => 1]);
        $shortRange = $this->aircraft($provider, ['range_km' => 10]);

        $this->assertDecision($small, false, 'INSUFFICIENT_CAPACITY');
        $this->assertDecision($shortRange, false, 'INSUFFICIENT_RANGE');
    }

    public function test_insufficient_range_on_any_multi_leg_rejects_entire_aircraft(): void
    {
        $this->seed();
        $aircraft = $this->aircraft($this->provider('approved'), ['range_km' => 100]);
        $route = app(FlightRouteService::class)->buildCanonicalRoute([
            ...$this->payload(),
            'trip_type' => 'multi_leg',
            'requirements' => [[
                'origin' => 'MMTO',
                'destination' => 'MMUN',
                'departure_datetime' => now()->addDays(5)->addHours(3)->toISOString(),
            ]],
        ]);
        $decision = app(AircraftEligibilityService::class)->evaluate($aircraft, $this->context($route));

        $this->assertFalse($decision['eligible']);
        $this->assertContains('INSUFFICIENT_RANGE', $decision['reason_codes']);
    }

    public function test_expired_and_unapproved_required_documents_reject_aircraft(): void
    {
        $this->seed();
        $provider = $this->provider('approved');
        $expired = $this->aircraft($provider);
        $this->documents($expired, 'approved', true);
        $pending = $this->aircraft($provider);
        $this->documents($pending, 'pending');

        $this->assertDecision($expired->fresh(), false, 'DOCUMENT_EXPIRED');
        $this->assertDecision($pending->fresh(), false, 'DOCUMENT_NOT_APPROVED');
    }

    public function test_maintenance_administrative_block_and_temporal_block_are_rejected(): void
    {
        $this->seed();
        $provider = $this->provider('approved');
        $maintenance = $this->aircraft($provider, ['status' => 'maintenance']);
        $blocked = $this->aircraft($provider, ['status' => 'blocked']);
        $occupied = $this->aircraft($provider);
        [$start, $end] = $this->window();
        AircraftAvailabilityBlock::query()->create([
            'aircraft_id' => $occupied->id,
            'start_datetime' => $start,
            'end_datetime' => $end,
            'status' => 'booked',
            'reason' => 'Canonical eligibility test',
        ]);

        $this->assertDecision($maintenance, false, 'AIRCRAFT_IN_MAINTENANCE');
        $this->assertDecision($blocked, false, 'AIRCRAFT_NOT_ACTIVE');
        $this->assertDecision($occupied, false, 'AIRCRAFT_NOT_AVAILABLE');
    }

    public function test_optional_preference_warns_but_required_category_rejects(): void
    {
        $this->seed();
        $aircraft = $this->aircraft($this->provider('approved'), ['category' => 'Light Jet']);
        $service = app(AircraftEligibilityService::class);
        $route = app(FlightRouteService::class)->buildCanonicalRoute($this->payload());

        $optional = $service->evaluate($aircraft, [
            ...$this->context($route),
            'preference' => 'Heavy Jet',
        ]);
        $required = $service->evaluate($aircraft, [
            ...$this->context($route),
            'requested_category' => 'Heavy Jet',
            'category_required' => true,
        ]);

        $this->assertTrue($optional['eligible']);
        $this->assertContains('CATEGORY_PREFERENCE_MISMATCH', $optional['warnings']);
        $this->assertFalse($required['eligible']);
        $this->assertContains('CATEGORY_MISMATCH', $required['reason_codes']);
    }

    public function test_invalid_direct_selection_rolls_back_request_match_quote_and_assignment(): void
    {
        $this->seed();
        $aircraft = $this->aircraft($this->provider('pending'));
        $token = $this->trialClient();
        $before = [
            SolicitudVuelo::count(),
            CoincidenciaSolicitud::count(),
            Cotizacion::count(),
        ];

        $response = $this->withToken($token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/client/flight-requests', [
                ...$this->payload(),
                'aircraft_id' => $aircraft->id,
                'provider_id' => $aircraft->provider_id,
            ])
            ->assertStatus(409)
            ->assertJsonPath('reason_code', 'PROVIDER_NOT_APPROVED');

        $this->assertFalse($response->json('success'));
        $this->assertSame($before[0], SolicitudVuelo::count());
        $this->assertSame($before[1], CoincidenciaSolicitud::count());
        $this->assertSame($before[2], Cotizacion::count());
    }

    private function assertDecision(Aeronave $aircraft, bool $eligible, string $reason): void
    {
        $route = app(FlightRouteService::class)->buildCanonicalRoute($this->payload());
        $decision = app(AircraftEligibilityService::class)->evaluate($aircraft, $this->context($route));
        $this->assertSame($eligible, $decision['eligible']);
        $this->assertContains($reason, $decision['reason_codes']);
        $this->assertSame('aircraft_eligibility_v1', $decision['rule_version']);
    }

    private function context(array $route): array
    {
        [$start, $end] = $this->window();

        return [
            'route' => $route,
            'passengers' => 2,
            'trip_type' => $route['trip_type'],
            'requested_start' => $start,
            'requested_end' => $end,
        ];
    }

    private function payload(): array
    {
        [$start] = $this->window();

        return [
            'origin' => 'MMMX',
            'destination' => 'MMTO',
            'departure_datetime' => $start->toISOString(),
            'passengers' => 2,
            'trip_type' => 'one_way',
        ];
    }

    private function preview(): array
    {
        return $this->postJson('/api/v1/client/quotes/preview', $this->payload())->assertOk()->json();
    }

    private function window(): array
    {
        $start = now()->addDays(5)->setTime(10, 0);

        return [$start, $start->copy()->addHours(4)];
    }

    private function provider(string $status): Proveedor
    {
        return Proveedor::factory()->create([
            'approval_status' => $status,
            'admin_validation_status' => $status,
            'status' => $status === 'approved' ? 'active' : $status,
        ]);
    }

    private function aircraft(Proveedor $provider, array $overrides = []): Aeronave
    {
        return Aeronave::factory()->create([
            'provider_id' => $provider->id,
            'status' => 'active',
            'capacity' => 6,
            'range_km' => 3000,
            ...$overrides,
        ]);
    }

    private function documents(Aeronave $aircraft, string $status, bool $expireFirst = false): void
    {
        foreach (['airworthiness', 'registration', 'insurance', 'maintenance'] as $index => $type) {
            DocumentoAeronave::query()->create([
                'aircraft_id' => $aircraft->id,
                'provider_id' => $aircraft->provider_id,
                'type' => $type,
                'document_type' => $type,
                'document_name' => $type,
                'document_url' => "documents/{$type}.pdf",
                'expires_at' => $expireFirst && $index === 0 ? now()->subDay() : now()->addYear(),
                'status' => $status,
                'verified_by_admin' => $status === 'approved',
            ]);
        }
    }

    private function trialClient(): string
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'name' => 'Eligibility Client',
            'email' => 'eligibility.'.Str::lower(Str::random(8)).'@test.dev',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => Usuario::ROLE_CLIENT,
        ])->assertCreated();
        $token = (string) $register->json('token');
        $this->withToken($token)->postJson('/api/v1/subscriptions/start-trial')->assertCreated();

        return $token;
    }
}
