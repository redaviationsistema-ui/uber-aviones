<?php

namespace Tests\Feature;

use App\Modelos\Aeronave;
use App\Modelos\Cotizacion;
use App\Modelos\Pago;
use App\Modelos\Proveedor;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use App\Modelos\TokenApi;
use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ClientAuthorizationBoundariesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_client_only_sees_own_reservations_and_payments(): void
    {
        [$client, $token, $reservation, $payment] = $this->createReservationContext('client.auth.one@test.dev');
        $this->createReservationContext('client.auth.two@test.dev');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/cliente/reservas')
            ->assertOk()
            ->assertJsonCount(1, 'reservations.data')
            ->assertJsonPath('reservations.data.0.id', $reservation->id);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/cliente/pagos')
            ->assertOk()
            ->assertJsonCount(1, 'payments.data')
            ->assertJsonPath('payments.data.0.id', $payment->id);
    }

    public function test_client_cannot_open_foreign_reservation_or_checkout(): void
    {
        [, , $reservation, ] = $this->createReservationContext('client.auth.owner@test.dev');
        [$otherClient, $otherToken, , ] = $this->createReservationContext('client.auth.other@test.dev');

        $this->withHeader('Authorization', 'Bearer '.$otherToken)
            ->getJson('/api/v1/cliente/reservas/'.$reservation->id)
            ->assertStatus(403);

        $foreignFlightRequestId = Reserva::query()->findOrFail($reservation->id)->flight_request_id;

        config()->set('services.stripe.secret', 'sk_test_auth_boundary');
        config()->set('services.stripe.publishable', 'pk_test_auth_boundary');
        Mockery::mock('alias:Stripe\\Checkout\\Session')->shouldReceive('create')->never();

        $this->withHeader('Authorization', 'Bearer '.$otherToken)
            ->postJson('/api/v1/cliente/stripe/checkout/create', [
                'flight_request_id' => $foreignFlightRequestId,
                'contact_email' => $otherClient->email,
            ])
            ->assertStatus(403);
    }

    public function test_client_cannot_access_admin_provider_or_crew_routes(): void
    {
        [, $token] = $this->createReservationContext('client.auth.boundary@test.dev');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/proveedores')
            ->assertStatus(403);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/proveedor/dashboard')
            ->assertStatus(403);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sobrecargo/dashboard')
            ->assertStatus(403);
    }

    public function test_guest_receives_401_on_client_endpoints(): void
    {
        $this->getJson('/api/v1/cliente/reservas')->assertStatus(401);
        $this->getJson('/api/v1/cliente/pagos')->assertStatus(401);
    }

    /**
     * @return array{0: Usuario, 1: string, 2: Reserva, 3: Pago}
     */
    private function createReservationContext(string $email): array
    {
        $client = Usuario::factory()->create([
            'role' => Usuario::ROLE_CLIENT,
            'status' => 'active',
            'email' => $email,
        ]);

        $providerUser = Usuario::factory()->create([
            'role' => Usuario::ROLE_PROVIDER,
            'status' => 'active',
            'email' => 'provider.'.md5($email).'@test.dev',
        ]);

        $provider = Proveedor::query()->create([
            'user_id' => $providerUser->id,
            'company_name' => 'Provider Auth '.substr(md5($email), 0, 6),
            'commercial_name' => 'Provider Auth',
            'approval_status' => 'approved',
        ]);

        $aircraft = Aeronave::query()->create([
            'provider_id' => $provider->id,
            'model' => 'Citation Auth',
            'category' => 'Light Jet',
            'registration' => 'XA-'.strtoupper(substr(md5($email), 0, 5)),
            'capacity' => 6,
            'base_airport' => 'MMMX',
            'range_km' => 2400,
            'speed_kmh' => 690,
            'hourly_rate' => 5000,
            'climb_descent_minutes' => 30,
            'status' => 'active',
            'currency' => 'USD',
        ]);

        $flightRequest = SolicitudVuelo::query()->create([
            'client_id' => $client->id,
            'origin' => 'MMMX',
            'destination' => 'MMTO',
            'departure_datetime' => now()->addDays(4)->setTime(10, 0),
            'departure_date' => now()->addDays(4)->toDateString(),
            'departure_time' => '10:00',
            'passengers' => 3,
            'trip_type' => 'one_way',
            'assigned_provider_id' => $provider->id,
            'assigned_aircraft_id' => $aircraft->id,
            'final_price' => 15990,
            'currency' => 'USD',
            'status' => 'reserved',
            'payment_status' => 'pending',
            'pricing_context' => [
                'flight_cost' => 15000,
                'base_amount' => 15000,
                'total_amount' => 15990,
                'selected_card_price' => 15990,
                'total' => 15990,
                'final_price' => 15990,
            ],
        ]);

        $quote = Cotizacion::query()->create([
            'flight_request_id' => $flightRequest->id,
            'provider_id' => $provider->id,
            'aircraft_id' => $aircraft->id,
            'subtotal' => 15000,
            'taxes' => 0,
            'fees' => 990,
            'total' => 15990,
            'currency' => 'USD',
            'status' => 'accepted',
            'expires_at' => now()->addDays(2),
        ]);

        $reservation = Reserva::query()->create([
            'client_id' => $client->id,
            'provider_id' => $provider->id,
            'aircraft_id' => $aircraft->id,
            'flight_request_id' => $flightRequest->id,
            'quote_id' => $quote->id,
            'reservation_code' => 'PV-AUTH-'.strtoupper(substr(md5($email), 0, 6)),
            'status' => 'pending_payment',
            'total_amount' => 15990,
            'currency' => 'USD',
        ]);

        $payment = Pago::query()->create([
            'user_id' => $client->id,
            'reservation_id' => $reservation->id,
            'flight_request_id' => $flightRequest->id,
            'payment_type' => 'reservation',
            'amount' => 15990,
            'currency' => 'USD',
            'provider' => 'manual',
            'transaction_reference' => 'auth-'.substr(md5($email), 0, 8),
            'status' => 'pending',
        ]);

        $token = TokenApi::issue($client, 'auth-boundary-token-'.substr(md5($email), 0, 6));

        return [$client, $token, $reservation, $payment];
    }
}
