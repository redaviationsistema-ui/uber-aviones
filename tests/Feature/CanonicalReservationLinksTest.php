<?php

namespace Tests\Feature;

use App\Modelos\Aeronave;
use App\Modelos\ContratoReserva;
use App\Modelos\Proveedor;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use App\Modelos\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanonicalReservationLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_allows_only_one_reservation_per_flight_request_and_one_contract_per_reservation(): void
    {
        [$reservation] = $this->createReservationContext();

        $this->expectException(QueryException::class);
        Reserva::query()->create([
            'client_id' => $reservation->client_id,
            'provider_id' => $reservation->provider_id,
            'aircraft_id' => $reservation->aircraft_id,
            'flight_request_id' => $reservation->flight_request_id,
            'reservation_code' => 'PV-DUPLICATE',
            'status' => 'pending_payment',
            'total_amount' => 12000,
            'currency' => 'USD',
        ]);
    }

    public function test_database_allows_only_one_contract_per_reservation(): void
    {
        [$reservation] = $this->createReservationContext();
        ContratoReserva::query()->create([
            'reservation_id' => $reservation->id,
            'contract_code' => 'CTR-FIRST',
            'status' => 'generated',
        ]);

        $this->expectException(QueryException::class);
        ContratoReserva::query()->create([
            'reservation_id' => $reservation->id,
            'contract_code' => 'CTR-DUPLICATE',
            'status' => 'generated',
        ]);
    }

    public function test_database_allows_only_one_contract_per_docusign_envelope(): void
    {
        [$firstReservation] = $this->createReservationContext('first');
        [$secondReservation] = $this->createReservationContext('second');
        ContratoReserva::query()->create([
            'reservation_id' => $firstReservation->id,
            'contract_code' => 'CTR-ENVELOPE-FIRST',
            'status' => 'sent',
            'docusign_envelope_id' => 'env-canonical-001',
        ]);

        $this->expectException(QueryException::class);
        ContratoReserva::query()->create([
            'reservation_id' => $secondReservation->id,
            'contract_code' => 'CTR-ENVELOPE-DUPLICATE',
            'status' => 'sent',
            'docusign_envelope_id' => 'env-canonical-001',
        ]);
    }

    private function createReservationContext(string $suffix = 'default'): array
    {
        $client = Usuario::factory()->create(['role' => Usuario::ROLE_CLIENT, 'status' => 'active']);
        $providerUser = Usuario::factory()->create(['role' => Usuario::ROLE_PROVIDER, 'status' => 'active']);
        $provider = Proveedor::query()->create([
            'user_id' => $providerUser->id,
            'company_name' => 'Canonical Links',
            'commercial_name' => 'Canonical Links',
            'approval_status' => 'approved',
        ]);
        $aircraft = Aeronave::query()->create([
            'provider_id' => $provider->id,
            'model' => 'Citation Canonical',
            'registration' => 'XA-CANON-'.strtoupper($suffix),
            'capacity' => 6,
            'base_airport' => 'MMMX',
            'range_km' => 2500,
            'speed_kmh' => 700,
            'hourly_rate' => 5000,
            'status' => 'active',
            'currency' => 'USD',
        ]);
        $flightRequest = SolicitudVuelo::query()->create([
            'client_id' => $client->id,
            'origin' => 'MMMX',
            'destination' => 'MMTO',
            'departure_datetime' => now()->addDays(3),
            'passengers' => 2,
            'trip_type' => 'one_way',
            'assigned_provider_id' => $provider->id,
            'assigned_aircraft_id' => $aircraft->id,
            'final_price' => 12000,
            'currency' => 'USD',
            'status' => 'reserved',
        ]);

        return [Reserva::query()->create([
            'client_id' => $client->id,
            'provider_id' => $provider->id,
            'aircraft_id' => $aircraft->id,
            'flight_request_id' => $flightRequest->id,
            'reservation_code' => 'PV-CANONICAL-'.strtoupper($suffix),
            'status' => 'pending_payment',
            'total_amount' => 12000,
            'currency' => 'USD',
        ])];
    }
}