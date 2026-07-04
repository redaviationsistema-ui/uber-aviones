<?php

namespace Tests\Feature;

use App\Modelos\AircraftAvailabilityBlock;
use App\Modelos\Aeronave;
use App\Modelos\Proveedor;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use App\Modelos\TramoReserva;
use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAircraftCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_aircraft_calendar_returns_enriched_events_and_filters_by_company(): void
    {
        $this->seed();

        $adminToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@privateflights.test',
            'password' => 'password',
        ])->assertOk()->json('token');

        $providerUserA = Usuario::factory()->create([
            'role' => Usuario::ROLE_PROVIDER,
            'status' => 'active',
        ]);
        $providerA = Proveedor::query()->create([
            'user_id' => $providerUserA->id,
            'company_name' => 'Red Aviation',
            'commercial_name' => 'Red Aviation',
            'approval_status' => 'approved',
        ]);

        $providerUserB = Usuario::factory()->create([
            'role' => Usuario::ROLE_PROVIDER,
            'status' => 'active',
        ]);
        $providerB = Proveedor::query()->create([
            'user_id' => $providerUserB->id,
            'company_name' => 'Blue Skies',
            'commercial_name' => 'Blue Skies',
            'approval_status' => 'approved',
        ]);

        $client = Usuario::factory()->create([
            'role' => Usuario::ROLE_CLIENT,
            'status' => 'active',
            'name' => 'Juan Perez',
        ]);

        $aircraftA = Aeronave::query()->create([
            'provider_id' => $providerA->id,
            'model' => 'Learjet 31A',
            'registration' => 'XA-RED1',
            'category' => 'Light Jet',
            'capacity' => 6,
            'base_airport' => 'MMTO',
            'range_km' => 2400,
            'speed_kmh' => 710,
            'hourly_rate' => 5000,
            'currency' => 'USD',
            'status' => 'active',
        ]);

        $aircraftB = Aeronave::query()->create([
            'provider_id' => $providerB->id,
            'model' => 'Citation XLS',
            'registration' => 'XA-BLU1',
            'category' => 'Light Jet',
            'capacity' => 7,
            'base_airport' => 'MMMX',
            'range_km' => 2600,
            'speed_kmh' => 720,
            'hourly_rate' => 5200,
            'currency' => 'USD',
            'status' => 'active',
        ]);

        $flightRequest = SolicitudVuelo::query()->create([
            'client_id' => $client->id,
            'origin' => 'MMTO',
            'destination' => 'MMMX',
            'departure_datetime' => '2026-07-10 09:00:00',
            'return_datetime' => '2026-07-10 13:30:00',
            'departure_date' => '2026-07-10',
            'departure_time' => '09:00',
            'passengers' => 4,
            'trip_type' => 'one_way',
            'assigned_provider_id' => $providerA->id,
            'assigned_aircraft_id' => $aircraftA->id,
            'payment_status' => 'paid',
            'status' => 'reserved',
            'currency' => 'USD',
        ]);

        $reservation = Reserva::query()->create([
            'client_id' => $client->id,
            'provider_id' => $providerA->id,
            'aircraft_id' => $aircraftA->id,
            'flight_request_id' => $flightRequest->id,
            'reservation_code' => 'PV-TEST-154',
            'status' => 'confirmed',
            'total_amount' => 15000,
            'currency' => 'USD',
        ]);

        TramoReserva::query()->create([
            'reservation_id' => $reservation->id,
            'leg_order' => 1,
            'origin' => 'MMTO',
            'destination' => 'MMMX',
            'departure_datetime' => '2026-07-10 09:00:00',
            'arrival_datetime' => '2026-07-10 13:30:00',
            'passengers' => 4,
            'status' => 'scheduled',
        ]);

        AircraftAvailabilityBlock::query()->create([
            'aircraft_id' => $aircraftA->id,
            'reservation_id' => $reservation->id,
            'start_datetime' => '2026-07-10 09:00:00',
            'end_datetime' => '2026-07-10 13:30:00',
            'status' => 'active',
            'reason' => 'Reserva pagada',
        ]);

        AircraftAvailabilityBlock::query()->create([
            'aircraft_id' => $aircraftB->id,
            'reservation_id' => null,
            'start_datetime' => '2026-07-11 08:00:00',
            'end_datetime' => '2026-07-11 18:00:00',
            'status' => 'active',
            'reason' => 'Mantenimiento preventivo',
        ]);

        $response = $this
            ->withToken($adminToken)
            ->getJson('/api/v1/admin/aircraft-calendar?start_date=2026-07-10&end_date=2026-07-12&company_id='.$providerA->id);

        $response
            ->assertOk()
            ->assertJsonPath('summary.total_aircraft', 1)
            ->assertJsonPath('summary.occupied_aircraft', 1)
            ->assertJsonPath('calendar.0.aircraft_id', $aircraftA->id)
            ->assertJsonPath('calendar.0.company_name', 'Red Aviation')
            ->assertJsonPath('calendar.0.client_name', 'Juan Perez')
            ->assertJsonPath('calendar.0.origin', 'MMTO')
            ->assertJsonPath('calendar.0.destination', 'MMMX')
            ->assertJsonPath('calendar.0.status', 'paid')
            ->assertJsonPath('calendar.0.color', '#22c55e');

        $calendar = collect($response->json('calendar'));
        $this->assertCount(1, $calendar);
        $this->assertSame([$providerA->id], $calendar->pluck('company_id')->unique()->values()->all());
    }
}
