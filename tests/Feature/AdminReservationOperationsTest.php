<?php

namespace Tests\Feature;

use App\Modelos\AircraftAvailabilityBlock;
use App\Modelos\Aeronave;
use App\Modelos\AsignacionSobrecargo;
use App\Modelos\Cotizacion;
use App\Modelos\Operacion;
use App\Modelos\Proveedor;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use App\Modelos\TramoReserva;
use App\Modelos\Usuario;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminReservationOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_reschedule_paid_reservation_without_creating_duplicate_blocks(): void
    {
        [$token, $provider, $client] = $this->bootstrapAdminScenario();
        $originalSchedule = $this->makeSchedule(startDays: 4, startHour: 9, durationHours: 4.5);
        $rescheduled = $this->makeSchedule(startDays: 5, startHour: 10, durationHours: 4);

        $aircraftA = $this->createAircraft($provider, 'XA-RED1', 'Learjet 31A');
        $aircraftB = $this->createAircraft($provider, 'XA-RED2', 'Citation XLS');

        [$flightRequest, $reservation] = $this->createPaidReservation($client, $provider, $aircraftA, [
            'origin' => 'MMTO',
            'destination' => 'MMMX',
            'departure_datetime' => $originalSchedule['departure_datetime'],
            'return_datetime' => $originalSchedule['return_datetime'],
        ]);

        $response = $this
            ->withToken($token)
            ->putJson("/api/v1/admin/reservations/{$reservation->id}/reschedule", [
                'provider_id' => $provider->id,
                'aircraft_id' => $aircraftB->id,
                'origin' => 'MMMX',
                'destination' => 'MMTO',
                'departure_datetime' => $rescheduled['departure_datetime'],
                'return_datetime' => $rescheduled['return_datetime'],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('reservation.aircraft_id', $aircraftB->id)
            ->assertJsonPath('reservation.flight_request.origin', 'MMMX');

        $reservation->refresh();
        $flightRequest->refresh();
        $activeBlocks = AircraftAvailabilityBlock::query()
            ->where('reservation_id', $reservation->id)
            ->whereIn('status', ['active', 'booked'])
            ->get();
        $block = $activeBlocks->sole();
        $releasedBlocks = AircraftAvailabilityBlock::query()
            ->where('reservation_id', $reservation->id)
            ->where('status', 'released')
            ->get();

        $this->assertSame($aircraftB->id, $reservation->aircraft_id);
        $this->assertCount(1, $activeBlocks);
        $this->assertSame($aircraftB->id, $block->aircraft_id);
        $this->assertSame('booked', $block->status);
        $this->assertNull($block->released_at);
        $this->assertSame($rescheduled['departure_datetime'], $block->start_datetime->format('Y-m-d H:i:s'));
        $this->assertSame($rescheduled['return_datetime'], $block->end_datetime->format('Y-m-d H:i:s'));
        $this->assertSame('MMMX', $flightRequest->origin);
        $this->assertSame('MMTO', $flightRequest->destination);
        $this->assertTrue($releasedBlocks->contains(fn ($releasedBlock) => $releasedBlock->aircraft_id === $aircraftA->id));
    }

    public function test_admin_cancel_reservation_releases_block_and_crew_assignment(): void
    {
        [$token, $provider, $client] = $this->bootstrapAdminScenario();
        $schedule = $this->makeSchedule(startDays: 6, startHour: 8, durationHours: 3.5);

        $aircraft = $this->createAircraft($provider, 'XA-RED3', 'Hawker 800XP');
        [$flightRequest, $reservation] = $this->createPaidReservation($client, $provider, $aircraft, [
            'origin' => 'MMMX',
            'destination' => 'MMTO',
            'departure_datetime' => $schedule['departure_datetime'],
            'return_datetime' => $schedule['return_datetime'],
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
        $schedule = $this->makeSchedule(startDays: 9, startHour: 7, durationHours: 12);
        $aircraft = $this->createAircraft($provider, 'XA-RED4', 'Phenom 300');

        $createResponse = $this
            ->withToken($token)
            ->postJson('/api/v1/admin/operations/aircraft-blocks', [
                'aircraft_id' => $aircraft->id,
                'block_type' => 'maintenance',
                'start_datetime' => $schedule['departure_datetime'],
                'end_datetime' => $schedule['return_datetime'],
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

    public function test_admin_workflow_normalizes_paid_status_and_creates_booked_block(): void
    {
        [$token, $provider, $client] = $this->bootstrapAdminScenario();
        $holdWindow = $this->makeSchedule(startDays: 14, startHour: 15, durationHours: 99);

        $aircraft = $this->createAircraft($provider, 'XA-RED5', 'Learjet 45');
        [$flightRequest, $reservation] = $this->createPaidReservation($client, $provider, $aircraft, [
            'origin' => 'MMTO',
            'destination' => 'MMMM',
            'departure_datetime' => $holdWindow['departure_datetime'],
            'return_datetime' => $holdWindow['return_datetime'],
        ]);

        $flightRequest->update([
            'payment_status' => 'pending',
            'status' => 'reserved',
            'workflow_status' => 'pago pendiente',
        ]);

        $reservation->update([
            'status' => 'pending_payment',
            'confirmed_at' => null,
        ]);

        $quote = Cotizacion::query()->create([
            'flight_request_id' => $flightRequest->id,
            'provider_id' => $provider->id,
            'aircraft_id' => $aircraft->id,
            'subtotal' => 12000,
            'taxes' => 1900,
            'fees' => 400,
            'total' => 14300,
            'currency' => 'USD',
            'status' => 'accepted',
            'expires_at' => now()->addDay(),
        ]);

        $reservation->update([
            'quote_id' => $quote->id,
        ]);

        $reservation->payments()->latest('id')->first()?->update([
            'status' => 'pending',
            'paid_at' => null,
        ]);

        AircraftAvailabilityBlock::query()
            ->where('aircraft_id', $aircraft->id)
            ->delete();

        AircraftAvailabilityBlock::query()->create([
            'aircraft_id' => $aircraft->id,
            'reservation_id' => null,
            'quote_id' => $reservation->quote_id,
            'flight_request_id' => $reservation->flight_request_id,
            'user_id' => $reservation->client_id,
            'block_type' => 'payment_hold',
            'start_datetime' => $holdWindow['departure_datetime'],
            'end_datetime' => $holdWindow['return_datetime'],
            'status' => 'held',
            'payment_status' => 'pending',
            'source' => 'quote_checkout',
            'reason' => 'Retencion temporal para completar el pago del vuelo.',
            'hold_expires_at' => now()->addMinutes(15),
        ]);

        $this
            ->withToken($token)
            ->putJson("/api/v1/admin/requests/{$flightRequest->id}/workflow", [
                'workflow_status' => 'pago confirmado',
                'payment_status' => 'Pagado',
                'contract_status' => 'signed',
            ])
            ->assertOk()
            ->assertJsonPath('request.payment_status', 'paid');

        $flightRequest->refresh();
        $reservation->refresh();
        $bookedBlock = AircraftAvailabilityBlock::query()
            ->where('reservation_id', $reservation->id)
            ->where('status', 'booked')
            ->sole();
        $releasedHold = AircraftAvailabilityBlock::query()
            ->where('flight_request_id', $flightRequest->id)
            ->whereNull('reservation_id')
            ->where('status', 'released')
            ->latest('id')
            ->first();

        $this->assertSame('paid', $flightRequest->payment_status);
        $this->assertSame('confirmed', $reservation->status);
        $this->assertNotNull($reservation->confirmed_at);
        $this->assertSame($holdWindow['departure_datetime'], $bookedBlock->start_datetime->format('Y-m-d H:i:s'));
        $this->assertSame($holdWindow['return_datetime'], $bookedBlock->end_datetime->format('Y-m-d H:i:s'));
        $this->assertSame('released', $releasedHold?->status);
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

    private function makeSchedule(int $startDays, int $startHour, float $durationHours): array
    {
        $departure = now()->copy()->addDays($startDays)->setTime($startHour, 0, 0);
        $return = $departure->copy()->addMinutes((int) round($durationHours * 60));

        return [
            'departure' => $departure,
            'return' => $return,
            'departure_datetime' => $departure->format('Y-m-d H:i:s'),
            'return_datetime' => $return->format('Y-m-d H:i:s'),
            'departure_date' => $departure->toDateString(),
            'departure_time' => $departure->format('H:i'),
            'return_date' => $return->toDateString(),
            'return_time' => $return->format('H:i'),
        ];
    }
}
