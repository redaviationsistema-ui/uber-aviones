<?php

namespace Tests\Feature;

use App\Modelos\AircraftAvailabilityBlock;
use App\Modelos\Aeronave;
use App\Modelos\AsignacionSobrecargo;
use App\Modelos\Operacion;
use App\Modelos\Proveedor;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use App\Modelos\TramoReserva;
use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminReservationOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_reschedule_paid_reservation_without_creating_duplicate_blocks(): void
    {
        [$token, $provider, $client] = $this->bootstrapAdminScenario();

        $aircraftA = $this->createAircraft($provider, 'XA-RED1', 'Learjet 31A');
        $aircraftB = $this->createAircraft($provider, 'XA-RED2', 'Citation XLS');

        [$flightRequest, $reservation] = $this->createPaidReservation($client, $provider, $aircraftA, [
            'origin' => 'MMTO',
            'destination' => 'MMMX',
            'departure_datetime' => '2026-07-10 09:00:00',
            'return_datetime' => '2026-07-10 13:30:00',
        ]);

        $response = $this
            ->withToken($token)
            ->putJson("/api/v1/admin/reservations/{$reservation->id}/reschedule", [
                'provider_id' => $provider->id,
                'aircraft_id' => $aircraftB->id,
                'origin' => 'MMMX',
                'destination' => 'MMTO',
                'departure_datetime' => '2026-07-11 10:00:00',
                'return_datetime' => '2026-07-11 14:00:00',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('reservation.aircraft_id', $aircraftB->id)
            ->assertJsonPath('reservation.flight_request.origin', 'MMMX');

        $reservation->refresh();
        $flightRequest->refresh();
        $activeBlocks = AircraftAvailabilityBlock::query()
            ->where('reservation_id', $reservation->id)
            ->where('status', 'active')
            ->get();
        $block = $activeBlocks->sole();
        $releasedBlocks = AircraftAvailabilityBlock::query()
            ->where('reservation_id', $reservation->id)
            ->where('status', 'released')
            ->get();

        $this->assertSame($aircraftB->id, $reservation->aircraft_id);
        $this->assertCount(1, $activeBlocks);
        $this->assertSame($aircraftB->id, $block->aircraft_id);
        $this->assertSame('active', $block->status);
        $this->assertNull($block->released_at);
        $this->assertSame('2026-07-11 10:00:00', $block->start_datetime->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-11 14:00:00', $block->end_datetime->format('Y-m-d H:i:s'));
        $this->assertSame('MMMX', $flightRequest->origin);
        $this->assertSame('MMTO', $flightRequest->destination);
        $this->assertTrue($releasedBlocks->contains(fn ($releasedBlock) => $releasedBlock->aircraft_id === $aircraftA->id));
    }

    public function test_admin_cancel_reservation_releases_block_and_crew_assignment(): void
    {
        [$token, $provider, $client] = $this->bootstrapAdminScenario();

        $aircraft = $this->createAircraft($provider, 'XA-RED3', 'Hawker 800XP');
        [$flightRequest, $reservation] = $this->createPaidReservation($client, $provider, $aircraft, [
            'origin' => 'MMMX',
            'destination' => 'MMTO',
            'departure_datetime' => '2026-07-12 08:00:00',
            'return_datetime' => '2026-07-12 11:30:00',
        ]);

        $crew = Usuario::factory()->create([
            'role' => Usuario::ROLE_SOBRECARGO,
            'status' => 'active',
            'name' => 'Crew Demo',
        ]);

        $operation = Operacion::query()->create([
            'flight_request_id' => $flightRequest->id,
            'provider_id' => $provider->id,
            'aircraft_id' => $aircraft->id,
            'sobrecargo_user_id' => $crew->id,
            'status' => 'tracking_live',
            'crew_status' => 'confirmed',
        ]);

        AsignacionSobrecargo::query()->create([
            'operation_id' => $operation->id,
            'sobrecargo_user_id' => $crew->id,
            'status' => 'assigned',
        ]);

        $this
            ->withToken($token)
            ->postJson("/api/v1/admin/reservations/{$reservation->id}/cancel", [
                'reason' => 'Cliente solicito cancelar.',
            ])
            ->assertOk()
            ->assertJsonPath('reservation.status', 'cancelled')
            ->assertJsonPath('reservation.cancellation_reason', 'Cliente solicito cancelar.');

        $reservation->refresh();
        $flightRequest->refresh();
        $operation->refresh();
        $block = AircraftAvailabilityBlock::query()->where('reservation_id', $reservation->id)->sole();

        $this->assertSame('cancelled', $reservation->status);
        $this->assertSame('Cliente solicito cancelar.', $reservation->cancellation_reason);
        $this->assertSame('cancelled', $flightRequest->status);
        $this->assertSame('cancelled', $block->status);
        $this->assertNotNull($block->released_at);
        $this->assertNull($operation->sobrecargo_user_id);
        $this->assertSame('cancelled', $operation->status);
        $this->assertDatabaseHas('sobrecargo_assignments', [
            'operation_id' => $operation->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_admin_can_create_and_release_manual_aircraft_blocks(): void
    {
        [$token, $provider] = $this->bootstrapAdminScenario();
        $aircraft = $this->createAircraft($provider, 'XA-RED4', 'Phenom 300');

        $createResponse = $this
            ->withToken($token)
            ->postJson('/api/v1/admin/operations/aircraft-blocks', [
                'aircraft_id' => $aircraft->id,
                'block_type' => 'maintenance',
                'start_datetime' => '2026-07-15 07:00:00',
                'end_datetime' => '2026-07-15 19:00:00',
                'reason' => 'Inspeccion mayor',
            ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('block.block_type', 'maintenance')
            ->assertJsonPath('block.status', 'active');

        $blockId = $createResponse->json('block.id');

        $this
            ->withToken($token)
            ->postJson("/api/v1/admin/operations/aircraft-blocks/{$blockId}/release", [
                'reason' => 'Mantenimiento concluido.',
            ])
            ->assertOk()
            ->assertJsonPath('block.status', 'released');

        $this->assertDatabaseHas('aircraft_availability_blocks', [
            'id' => $blockId,
            'status' => 'released',
            'reason' => 'Mantenimiento concluido.',
        ]);
    }

    private function bootstrapAdminScenario(): array
    {
        $this->seed();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@privateflights.test',
            'password' => 'password',
        ])->assertOk()->json('token');

        $providerUser = Usuario::factory()->create([
            'role' => Usuario::ROLE_PROVIDER,
            'status' => 'active',
        ]);

        $provider = Proveedor::query()->create([
            'user_id' => $providerUser->id,
            'company_name' => 'Red Aviation',
            'commercial_name' => 'Red Aviation',
            'approval_status' => 'approved',
        ]);

        $client = Usuario::factory()->create([
            'role' => Usuario::ROLE_CLIENT,
            'status' => 'active',
            'name' => 'Cliente Demo',
        ]);

        return [$token, $provider, $client];
    }

    private function createAircraft(Proveedor $provider, string $registration, string $model): Aeronave
    {
        return Aeronave::query()->create([
            'provider_id' => $provider->id,
            'model' => $model,
            'registration' => $registration,
            'category' => 'Light Jet',
            'capacity' => 6,
            'base_airport' => 'MMMX',
            'range_km' => 2500,
            'speed_kmh' => 700,
            'hourly_rate' => 5000,
            'currency' => 'USD',
            'status' => 'active',
        ]);
    }

    private function createPaidReservation(Usuario $client, Proveedor $provider, Aeronave $aircraft, array $schedule): array
    {
        $flightRequest = SolicitudVuelo::query()->create([
            'client_id' => $client->id,
            'origin' => $schedule['origin'],
            'destination' => $schedule['destination'],
            'departure_datetime' => $schedule['departure_datetime'],
            'return_datetime' => $schedule['return_datetime'],
            'departure_date' => substr($schedule['departure_datetime'], 0, 10),
            'departure_time' => substr($schedule['departure_datetime'], 11, 5),
            'passengers' => 4,
            'trip_type' => 'one_way',
            'assigned_provider_id' => $provider->id,
            'assigned_aircraft_id' => $aircraft->id,
            'payment_status' => 'paid',
            'status' => 'reserved',
            'currency' => 'USD',
        ]);

        $reservation = Reserva::query()->create([
            'client_id' => $client->id,
            'provider_id' => $provider->id,
            'aircraft_id' => $aircraft->id,
            'flight_request_id' => $flightRequest->id,
            'reservation_code' => 'PV-TEST-'.$flightRequest->id,
            'status' => 'confirmed',
            'total_amount' => 15000,
            'currency' => 'USD',
            'confirmed_at' => now(),
        ]);

        TramoReserva::query()->create([
            'reservation_id' => $reservation->id,
            'leg_order' => 1,
            'origin' => $schedule['origin'],
            'destination' => $schedule['destination'],
            'departure_datetime' => $schedule['departure_datetime'],
            'arrival_datetime' => $schedule['return_datetime'],
            'passengers' => 4,
            'status' => 'scheduled',
        ]);

        AircraftAvailabilityBlock::query()->create([
            'aircraft_id' => $aircraft->id,
            'reservation_id' => $reservation->id,
            'block_type' => 'reservation',
            'start_datetime' => $schedule['departure_datetime'],
            'end_datetime' => $schedule['return_datetime'],
            'status' => 'active',
            'reason' => 'Reserva pagada',
        ]);

        return [$flightRequest, $reservation];
    }
}
