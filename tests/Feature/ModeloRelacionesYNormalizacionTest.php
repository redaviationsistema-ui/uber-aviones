<?php

namespace Tests\Feature;

use App\Modelos\Aeronave;
use App\Modelos\Aeropuerto;
use App\Modelos\BanderaAntiBroker;
use App\Modelos\ChatProtegido;
use App\Modelos\Comision;
use App\Modelos\DocumentoAeronave;
use App\Modelos\MensajeChat;
use App\Modelos\MetodoPago;
use App\Modelos\Notificacion;
use App\Modelos\Pago;
use App\Modelos\PagoProveedor;
use App\Modelos\Plan;
use App\Modelos\Proveedor;
use App\Modelos\RegistroAuditoria;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use App\Modelos\Suscripcion;
use App\Modelos\TokenApi;
use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModeloRelacionesYNormalizacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_persists_normalized_profile_airport_and_resolved_provider_context(): void
    {
        $this->seed();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Proveedor Normalizado',
            'email' => 'proveedor.normalizado@test.dev',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'provider',
            'company_name' => 'Proveedor Normalizado SA',
            'base_airport' => 'MEX',
            'city' => 'Ciudad de Mexico',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.email', 'proveedor.normalizado@test.dev');

        $user = Usuario::query()
            ->where('email', 'proveedor.normalizado@test.dev')
            ->with(['profile.baseAirport', 'provider', 'ownedProvider'])
            ->firstOrFail();

        $this->assertNotNull($user->profile);
        $this->assertNotNull($user->profile->base_airport_id);
        $this->assertSame('MMMX', $user->profile->baseAirport?->icao);
        $this->assertSame($user->ownedProvider?->id, $user->resolvedProviderId());

        $token = $response->json('token');

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.profile.base_airport_id', $user->profile->base_airport_id)
            ->assertJsonPath('user.provider_id', $user->resolvedProviderId())
            ->assertJsonPath('user.proveedor_id', $user->resolvedProviderId());
    }

    public function test_client_search_uses_normalized_aircraft_airport_relationships(): void
    {
        $this->seed();

        $airport = Aeropuerto::query()->where('icao', 'MMMX')->firstOrFail();
        [$providerUser, $provider] = $this->createProviderContext();
        $client = Usuario::factory()->create([
            'role' => Usuario::ROLE_CLIENT,
            'status' => 'active',
        ]);

        $aircraft = Aeronave::factory()->create([
            'provider_id' => $provider->id,
            'base_airport' => 'LEGACY-BASE',
            'base_airport_id' => $airport->id,
            'status' => 'active',
            'capacity' => 6,
        ]);

        $response = $this->withToken(TokenApi::issue($client))
            ->postJson('/api/v1/cliente/buscar-vuelo', [
                'origin' => 'MMMX',
                'departure_datetime' => now()->addDays(3)->toISOString(),
                'passengers' => 4,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('aircraft.0.id', $aircraft->id)
            ->assertJsonPath('aircraft.0.base_airport', 'MMMX');

        $this->assertSame($provider->id, $providerUser->resolvedProviderId());
    }

    public function test_support_models_expose_their_normalized_relationships(): void
    {
        $this->seed();

        $originAirport = Aeropuerto::query()->where('icao', 'MMMX')->firstOrFail();
        $destinationAirport = Aeropuerto::query()->where('icao', 'MMUN')->firstOrFail();
        $admin = Usuario::query()->where('email', 'admin@privateflights.test')->firstOrFail();
        $client = Usuario::factory()->create([
            'role' => Usuario::ROLE_CLIENT,
            'status' => 'active',
        ]);
        [$providerUser, $provider] = $this->createProviderContext();

        $client->profile()->create([
            'city' => 'Ciudad de Mexico',
            'base_airport' => 'MEX',
            'base_airport_id' => $originAirport->id,
        ]);

        $aircraft = Aeronave::factory()->create([
            'provider_id' => $provider->id,
            'base_airport' => 'LEGACY-BASE',
            'base_airport_id' => $originAirport->id,
            'status' => 'active',
        ]);

        $plan = Plan::query()->firstOrFail();
        $subscription = Suscripcion::create([
            'user_id' => $client->id,
            'plan_id' => $plan->id,
            'started_at' => now(),
            'expires_at' => now()->addMonth(),
            'status' => 'active',
            'payment_status' => 'paid',
        ]);

        $paymentMethod = MetodoPago::create([
            'user_id' => $client->id,
            'type' => 'card',
            'brand' => 'visa',
            'last_four' => '4242',
            'provider' => 'stripe',
            'is_default' => true,
        ]);

        $flightRequest = SolicitudVuelo::create([
            'client_id' => $client->id,
            'origin' => 'MEX',
            'origin_airport_id' => $originAirport->id,
            'destination' => 'CUN',
            'destination_airport_id' => $destinationAirport->id,
            'departure_datetime' => now()->addDays(5),
            'departure_date' => now()->addDays(5)->toDateString(),
            'departure_time' => '10:30',
            'passengers' => 4,
            'trip_type' => 'one_way',
            'status' => 'pending',
            'workflow_status' => 'draft',
        ]);

        $payment = Pago::create([
            'user_id' => $client->id,
            'flight_request_id' => $flightRequest->id,
            'subscription_id' => $subscription->id,
            'payment_method_id' => $paymentMethod->id,
            'payment_type' => 'subscription',
            'amount' => 299.00,
            'currency' => 'USD',
            'provider' => 'stripe',
            'status' => 'pending',
        ]);

        $reservation = Reserva::create([
            'client_id' => $client->id,
            'provider_id' => $provider->id,
            'aircraft_id' => $aircraft->id,
            'flight_request_id' => $flightRequest->id,
            'reservation_code' => 'RES-1001',
            'status' => 'pending_payment',
            'total_amount' => 5200,
            'currency' => 'USD',
        ]);

        $commission = Comision::create([
            'reservation_id' => $reservation->id,
            'provider_id' => $provider->id,
            'platform_fee' => 500,
            'provider_amount' => 4700,
            'status' => 'held',
        ]);

        $payout = PagoProveedor::create([
            'provider_id' => $provider->id,
            'commission_id' => $commission->id,
            'amount' => 4700,
            'currency' => 'USD',
            'status' => 'pending',
        ]);

        $document = DocumentoAeronave::create([
            'aircraft_id' => $aircraft->id,
            'provider_id' => $provider->id,
            'type' => 'insurance',
            'document_type' => 'insurance',
            'document_name' => 'Seguro anual',
            'document_url' => 'https://example.test/seguro.pdf',
            'file_url' => 'https://example.test/seguro.pdf',
            'status' => 'pending',
        ]);

        $chat = ChatProtegido::create([
            'flight_request_id' => $flightRequest->id,
            'client_id' => $client->id,
            'provider_id' => $provider->id,
            'admin_id' => $admin->id,
            'status' => 'activo',
        ]);

        $message = MensajeChat::create([
            'chat_id' => $chat->id,
            'sender_id' => $client->id,
            'message' => 'Hola, quiero cotizar este vuelo.',
        ]);

        $flag = BanderaAntiBroker::create([
            'user_id' => $client->id,
            'flight_request_id' => $flightRequest->id,
            'message_id' => $message->id,
            'type' => 'contact_intent',
            'detected_value' => 'whatsapp',
            'severity' => 'media',
            'status' => 'abierta',
        ]);

        $notification = Notificacion::create([
            'user_id' => $client->id,
            'type' => 'system',
            'title' => 'Notificacion de prueba',
            'message' => 'La relacion debe resolverse.',
        ]);

        $audit = RegistroAuditoria::create([
            'user_id' => $client->id,
            'action' => 'create',
            'module' => 'relationships',
            'description' => 'Prueba de integridad de relaciones.',
        ]);

        $this->assertSame($client->id, $payment->fresh()->user?->id);
        $this->assertSame($subscription->id, $payment->fresh()->subscription?->id);
        $this->assertSame($paymentMethod->id, $payment->fresh()->paymentMethod?->id);
        $this->assertSame($provider->id, $payout->fresh()->provider?->id);
        $this->assertSame($commission->id, $payout->fresh()->commission?->id);
        $this->assertSame($provider->id, $document->fresh()->provider?->id);
        $this->assertSame($client->id, $chat->fresh()->client?->id);
        $this->assertSame($admin->id, $chat->fresh()->admin?->id);
        $this->assertSame($client->id, $message->fresh()->sender?->id);
        $this->assertSame($message->id, $flag->fresh()->message?->id);
        $this->assertSame($client->id, $notification->fresh()->user?->id);
        $this->assertSame($client->id, $audit->fresh()->user?->id);
        $this->assertTrue($plan->subscriptions()->whereKey($subscription->id)->exists());
        $this->assertSame('MMMX', $aircraft->fresh('baseAirport')->resolvedBaseAirportCode());
        $this->assertSame('MMMX', $flightRequest->fresh(['originAirport', 'destinationAirport'])->resolvedOriginCode());
        $this->assertSame('MMUN', $flightRequest->fresh(['originAirport', 'destinationAirport'])->resolvedDestinationCode());
        $this->assertSame('MMMX', $client->fresh('profile.baseAirport')->profile?->baseAirport?->icao);
        $this->assertSame($provider->id, $providerUser->resolvedProviderId());
    }

    private function createProviderContext(): array
    {
        $providerUser = Usuario::factory()->create([
            'role' => Usuario::ROLE_PROVIDER,
            'status' => 'active',
        ]);

        $provider = Proveedor::create([
            'user_id' => $providerUser->id,
            'company_name' => 'Proveedor Test SA',
            'commercial_name' => 'Proveedor Test',
            'approval_status' => 'approved',
        ]);

        $providerUser->forceFill(['provider_id' => $provider->id])->saveQuietly();

        return [$providerUser->fresh(), $provider->fresh()];
    }
}
