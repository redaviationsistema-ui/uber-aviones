<?php

namespace Tests\Feature;

use App\Modelos\Aeronave;
use App\Modelos\Proveedor;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use App\Modelos\TokenApi;
use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanonicalIdRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservation_route_resolves_reservation_id(): void
    {
        $context = $this->createContext();

        $this->withHeader('Authorization', 'Bearer '.$context['token'])
            ->getJson('/api/v1/cliente/reservas/'.$context['reservation']->id)
            ->assertOk()
            ->assertJsonPath('reservation.id', $context['reservation']->id);
    }

    public function test_reservation_route_returns_404_for_a_flight_request_id(): void
    {
        $context = $this->createContext();

        $orphanFlightRequest = SolicitudVuelo::query()->create([
            'client_id' => $context['user']->id,
            'origin' => 'MMMX',
            'destination' => 'MMUN',
            'departure_datetime' => now()->addDays(6),
            'passengers' => 2,
            'trip_type' => 'one_way',
            'status' => 'pending',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$context['token'])
            ->getJson('/api/v1/cliente/reservas/'.$orphanFlightRequest->id)
            ->assertNotFound();
    }

    public function test_flight_request_route_resolves_flight_request_id(): void
    {
        $context = $this->createContext();

        $this->withHeader('Authorization', 'Bearer '.$context['token'])
            ->getJson('/api/v1/client/flight-requests/'.$context['flightRequest']->id)
            ->assertOk()
            ->assertJsonPath('flight_request.id', $context['flightRequest']->id);
    }

    public function test_flight_request_route_does_not_accept_a_reservation_id_that_is_not_also_a_flight_request(): void
    {
        $context = $this->createContext();

        $orphanReservationId = $context['reservation']->id;
        $this->assertNotSame($orphanReservationId, $context['flightRequest']->id);

        $this->withHeader('Authorization', 'Bearer '.$context['token'])
            ->getJson('/api/v1/client/flight-requests/'.$orphanReservationId)
            ->assertNotFound();
    }

    public static function legacyContractAliasProvider(): array
    {
        return [
            'cliente solicitudes payment availability' => ['GET', '/api/v1/cliente/solicitudes/{id}/payment-availability'],
            'client flight-requests contract show' => ['GET', '/api/v1/client/flight-requests/{id}/contract'],
            'client flight-requests contract pdf' => ['GET', '/api/v1/client/flight-requests/{id}/contract/pdf'],
            'client flight-requests contract generate' => ['POST', '/api/v1/client/flight-requests/{id}/contract/generate'],
            'client flight-requests contract docusign' => ['POST', '/api/v1/client/flight-requests/{id}/contract/docusign'],
            'client flight-requests contract sign' => ['POST', '/api/v1/client/flight-requests/{id}/contract/sign'],
            'cliente solicitudes contrato show' => ['GET', '/api/v1/cliente/solicitudes/{id}/contrato'],
            'cliente solicitudes contrato pdf' => ['GET', '/api/v1/cliente/solicitudes/{id}/contrato/pdf'],
            'cliente solicitudes contrato generar' => ['POST', '/api/v1/cliente/solicitudes/{id}/contrato/generar'],
            'cliente solicitudes contrato docusign' => ['POST', '/api/v1/cliente/solicitudes/{id}/contrato/docusign'],
            'cliente solicitudes contrato firmar' => ['POST', '/api/v1/cliente/solicitudes/{id}/contrato/firmar'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('legacyContractAliasProvider')]
    public function test_legacy_contract_alias_is_retired(string $method, string $pathTemplate): void
    {
        $context = $this->createContext();
        $path = str_replace('{id}', (string) $context['reservation']->id, $pathTemplate);

        $response = $method === 'GET'
            ? $this->withHeader('Authorization', 'Bearer '.$context['token'])->getJson($path)
            : $this->withHeader('Authorization', 'Bearer '.$context['token'])->postJson($path);

        $response
            ->assertStatus(410)
            ->assertJsonPath('code', 'LEGACY_CONTRACT_ALIAS_RETIRED');
    }

    /**
     * @return array{user: Usuario, token: string, reservation: Reserva, flightRequest: SolicitudVuelo}
     */
    private function createContext(): array
    {
        $client = Usuario::factory()->create(['role' => Usuario::ROLE_CLIENT, 'status' => 'active']);
        $providerUser = Usuario::factory()->create(['role' => Usuario::ROLE_PROVIDER, 'status' => 'active']);
        $provider = Proveedor::query()->create([
            'user_id' => $providerUser->id,
            'company_name' => 'Canonical Routing Provider',
            'commercial_name' => 'Canonical Routing Provider',
            'approval_status' => 'approved',
        ]);
        $aircraft = Aeronave::query()->create([
            'provider_id' => $provider->id,
            'model' => 'Citation Routing',
            'registration' => 'XA-'.strtoupper(substr(uniqid(), -7)),
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
            'departure_datetime' => now()->addDays(5),
            'passengers' => 2,
            'trip_type' => 'one_way',
            'assigned_provider_id' => $provider->id,
            'assigned_aircraft_id' => $aircraft->id,
            'final_price' => 15000,
            'currency' => 'USD',
            'status' => 'reserved',
        ]);
        $reservation = Reserva::query()->forceCreate([
            'id' => 900001,
            'client_id' => $client->id,
            'provider_id' => $provider->id,
            'aircraft_id' => $aircraft->id,
            'flight_request_id' => $flightRequest->id,
            'reservation_code' => 'PV-ROUTING-'.$flightRequest->id,
            'status' => 'pending_payment',
            'total_amount' => 15000,
            'currency' => 'USD',
        ]);

        $token = TokenApi::issue($client, 'test-canonical-routing');

        return [
            'user' => $client,
            'token' => $token,
            'reservation' => $reservation,
            'flightRequest' => $flightRequest,
        ];
    }
}
