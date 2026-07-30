<?php

namespace Tests\Feature;

use App\Modelos\Aeronave;
use App\Modelos\AircraftAvailabilityBlock;
use App\Modelos\ContratoReserva;
use App\Modelos\Proveedor;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use App\Modelos\TokenApi;
use App\Modelos\Usuario;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractAircraftLockingTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_client_locks_aircraft_on_contract_pending_and_second_client_gets_conflict(): void
    {
        $this->seed();

        $provider = $this->createProvider();
        $aircraft = $this->createAircraft($provider, 'XA-LOCK1');

        [$clientA, $tokenA] = $this->createPremiumClient('client-a@test.local');
        [$clientB, $tokenB] = $this->createPremiumClient('client-b@test.local');

        $flightRequestA = $this->createFlightRequest($clientA, $provider, $aircraft, '2026-07-30 08:00:00');
        $flightRequestB = $this->createFlightRequest($clientB, $provider, $aircraft, '2026-07-30 08:15:00');

        $firstResponse = $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->postJson('/api/v1/cliente/reservas', [
                'flight_request_id' => $flightRequestA->id,
            ]);

        $firstResponse
            ->assertCreated()
            ->assertJsonPath('reservation.flight_request_id', $flightRequestA->id);

        $reservationAId = (int) $firstResponse->json('reservation.id');

        $secondResponse = $this->withHeader('Authorization', 'Bearer '.$tokenB)
            ->postJson('/api/v1/cliente/reservas', [
                'flight_request_id' => $flightRequestB->id,
            ]);

        $secondResponse
            ->assertStatus(409)
            ->assertJsonPath('code', 'AIRCRAFT_ALREADY_RESERVED')
            ->assertJsonPath('conflicting_reservation_id', $reservationAId);

        $this->assertSame(1, AircraftAvailabilityBlock::query()
            ->where('aircraft_id', $aircraft->id)
            ->whereIn('status', ['active', 'held', 'booked'])
            ->count());
        $this->assertSame(1, ContratoReserva::query()
            ->whereHas('reservation', fn ($query) => $query->where('aircraft_id', $aircraft->id))
            ->count());
        $this->assertDatabaseMissing('reservations', [
            'flight_request_id' => $flightRequestB->id,
        ]);
    }

    public function test_competing_contract_generation_only_allows_one_active_assignment(): void
    {
        $this->seed();

        $provider = $this->createProvider();
        $aircraft = $this->createAircraft($provider, 'XA-LOCK2');

        [$clientA, $tokenA] = $this->createPremiumClient('client-c@test.local');
        [$clientB, $tokenB] = $this->createPremiumClient('client-d@test.local');

        $flightRequestA = $this->createFlightRequest($clientA, $provider, $aircraft, '2026-07-30 08:00:00');
        $flightRequestB = $this->createFlightRequest($clientB, $provider, $aircraft, '2026-07-30 08:10:00');

        $reservationA = $this->createReservation($clientA, $provider, $aircraft, $flightRequestA);
        $reservationB = $this->createReservation($clientB, $provider, $aircraft, $flightRequestB);

        ContratoReserva::query()->create([
            'reservation_id' => $reservationA->id,
            'contract_code' => 'CTR-LOCK-A',
            'status' => 'draft',
            'terms_snapshot' => [],
        ]);

        ContratoReserva::query()->create([
            'reservation_id' => $reservationB->id,
            'contract_code' => 'CTR-LOCK-B',
            'status' => 'draft',
            'terms_snapshot' => [],
        ]);

        $firstResponse = $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->postJson('/api/v1/cliente/reservas/'.$reservationA->id.'/contrato/generar');

        $firstResponse
            ->assertOk()
            ->assertJsonPath('contract.status', 'generated');

        $secondResponse = $this->withHeader('Authorization', 'Bearer '.$tokenB)
            ->postJson('/api/v1/cliente/reservas/'.$reservationB->id.'/contrato/generar');

        $secondResponse
            ->assertStatus(409)
            ->assertJsonPath('code', 'AIRCRAFT_ALREADY_RESERVED')
            ->assertJsonPath('conflicting_reservation_id', $reservationA->id);

        $this->assertSame(1, AircraftAvailabilityBlock::query()
            ->where('aircraft_id', $aircraft->id)
            ->whereIn('status', ['active', 'held', 'booked'])
            ->count());
        $this->assertDatabaseHas('reservation_contracts', [
            'reservation_id' => $reservationA->id,
            'status' => 'generated',
        ]);
        $this->assertDatabaseHas('reservation_contracts', [
            'reservation_id' => $reservationB->id,
            'status' => 'draft',
        ]);
    }

    private function createProvider(): Proveedor
    {
        $providerUser = Usuario::factory()->create([
            'role' => Usuario::ROLE_PROVIDER,
            'status' => 'active',
        ]);

        return Proveedor::query()->create([
            'user_id' => $providerUser->id,
            'company_name' => 'Provider Contract Lock',
            'commercial_name' => 'Provider Contract Lock',
            'approval_status' => 'approved',
        ]);
    }

    private function createAircraft(Proveedor $provider, string $registration): Aeronave
    {
        return Aeronave::query()->create([
            'provider_id' => $provider->id,
            'model' => 'LEARJET 31A',
            'category' => 'Light Jet',
            'registration' => $registration,
            'capacity' => 6,
            'base_airport' => 'MMMX',
            'range_km' => 2500,
            'speed_kmh' => 700,
            'hourly_rate' => 5000,
            'climb_descent_minutes' => 30,
            'status' => 'active',
            'currency' => 'USD',
        ]);
    }

    private function createPremiumClient(string $email): array
    {
        $client = Usuario::factory()->create([
            'role' => Usuario::ROLE_CLIENT,
            'status' => 'active',
            'email' => $email,
            'has_paid_access' => true,
            'access_status' => 'active',
            'access_expires_at' => now()->addDays(10),
        ]);

        return [$client, TokenApi::issue($client, 'contract-lock')];
    }

    private function createFlightRequest(
        Usuario $client,
        Proveedor $provider,
        Aeronave $aircraft,
        string $departureDateTime,
    ): SolicitudVuelo {
        $departure = Carbon::parse($departureDateTime);

        return SolicitudVuelo::query()->create([
            'client_id' => $client->id,
            'origin' => 'MMMX',
            'destination' => 'MMUN',
            'departure_datetime' => $departure,
            'return_datetime' => $departure->copy()->addHours(3),
            'departure_date' => $departure->format('Y-m-d'),
            'departure_time' => $departure->format('H:i'),
            'passengers' => 4,
            'trip_type' => 'one_way',
            'assigned_provider_id' => $provider->id,
            'assigned_aircraft_id' => $aircraft->id,
            'assigned_aircraft_model' => $aircraft->model,
            'final_price' => 15000,
            'currency' => 'USD',
            'status' => 'quoted',
            'workflow_status' => 'provider_accepted',
            'pricing_context' => [
                'buffer_hours' => 0.5,
                'repositioning_hours' => 0.5,
                'client_climb_descent_minutes' => 30,
            ],
        ]);
    }

    private function createReservation(
        Usuario $client,
        Proveedor $provider,
        Aeronave $aircraft,
        SolicitudVuelo $flightRequest,
    ): Reserva {
        return Reserva::query()->create([
            'client_id' => $client->id,
            'provider_id' => $provider->id,
            'aircraft_id' => $aircraft->id,
            'flight_request_id' => $flightRequest->id,
            'reservation_code' => 'PV-LOCK-'.strtoupper(substr(md5((string) $flightRequest->id), 0, 6)),
            'status' => 'pending_payment',
            'total_amount' => 15000,
            'currency' => 'USD',
        ]);
    }
}
