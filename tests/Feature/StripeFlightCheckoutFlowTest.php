<?php

namespace Tests\Feature;

use App\Modelos\Aeronave;
use App\Modelos\AircraftAvailabilityBlock;
use App\Modelos\ContratoReserva;
use App\Modelos\Cotizacion;
use App\Modelos\Pago;
use App\Modelos\Proveedor;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use App\Modelos\TokenApi;
use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Tests\TestCase;

class StripeFlightCheckoutFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_create_checkout_returns_checkout_url_and_updates_pending_state(): void
    {
        $this->seed();
        $this->configureStripe();

        $context = $this->createFlightPaymentContext();

        $sessionAlias = Mockery::mock('alias:Stripe\Checkout\Session');
        $sessionAlias
            ->shouldReceive('create')
            ->once()
            ->andReturn((object) [
                'id' => 'cs_test_reservation_create',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_reservation_create',
            ]);

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$context['token'])
            ->postJson('/api/v1/cliente/stripe/checkout/create', [
                'flight_request_id' => $context['flightRequest']->id,
                'contact_email' => $context['user']->email,
                'success_url' => 'https://example.com/success',
                'cancel_url' => 'https://example.com/cancel',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('checkout_session_id', 'cs_test_reservation_create')
            ->assertJsonPath(
                'checkout_url',
                'https://checkout.stripe.com/c/pay/cs_test_reservation_create',
            )
            ->assertJsonPath('reservation_id', $context['reservation']->id)
            ->assertJsonPath('payment_status', 'pending');

        $this->assertDatabaseHas('flight_requests', [
            'id' => $context['flightRequest']->id,
            'payment_method' => 'stripe_checkout',
            'payment_status' => 'pending',
            'stripe_checkout_session_id' => 'cs_test_reservation_create',
        ]);

        $this->assertDatabaseHas('payments', [
            'reservation_id' => $context['reservation']->id,
            'flight_request_id' => $context['flightRequest']->id,
            'provider' => 'stripe',
            'payment_type' => 'reservation',
            'status' => 'pending',
            'stripe_checkout_session_id' => 'cs_test_reservation_create',
        ]);
    }

    public function test_create_checkout_reuses_existing_pending_session_instead_of_creating_another_one(): void
    {
        $this->seed();
        $this->configureStripe();

        $context = $this->createFlightPaymentContext(
            checkoutSessionId: 'cs_test_reservation_reused',
        );

        Pago::query()->create([
            'user_id' => $context['user']->id,
            'reservation_id' => $context['reservation']->id,
            'flight_request_id' => $context['flightRequest']->id,
            'payment_type' => 'reservation',
            'amount' => 15990,
            'currency' => 'USD',
            'provider' => 'stripe',
            'transaction_reference' => 'cs_test_reservation_reused',
            'stripe_checkout_session_id' => 'cs_test_reservation_reused',
            'status' => 'pending',
            'gateway_response' => [
                'checkout_url' => 'https://checkout.stripe.com/c/pay/cs_test_reservation_reused',
            ],
        ]);

        $sessionAlias = Mockery::mock('alias:Stripe\Checkout\Session');
        $sessionAlias->shouldReceive('create')->never();

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$context['token'])
            ->postJson('/api/v1/cliente/stripe/checkout/create', [
                'flight_request_id' => $context['flightRequest']->id,
                'contact_email' => $context['user']->email,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('reused_checkout', true)
            ->assertJsonPath('checkout_session_id', 'cs_test_reservation_reused')
            ->assertJsonPath(
                'checkout_url',
                'https://checkout.stripe.com/c/pay/cs_test_reservation_reused',
            );

        $this->assertSame(1, Pago::query()->where('flight_request_id', $context['flightRequest']->id)->count());
    }

    public function test_create_checkout_allows_clients_to_pay_when_conflict_is_only_their_own_hold(): void
    {
        $this->seed();
        $this->configureStripe();

        $context = $this->createFlightPaymentContext();

        AircraftAvailabilityBlock::query()->create([
            'aircraft_id' => $context['aircraft']->id,
            'quote_id' => $context['quote']->id,
            'flight_request_id' => $context['flightRequest']->id,
            'reservation_id' => $context['reservation']->id,
            'user_id' => $context['user']->id,
            'start_datetime' => $context['flightRequest']->departure_datetime,
            'end_datetime' => $context['flightRequest']->departure_datetime->copy()->addHours(3),
            'hold_expires_at' => now()->addMinutes(15),
            'payment_status' => 'pending',
            'source' => 'quote_checkout',
            'status' => 'held',
            'reason' => 'Retencion propia activa para checkout.',
        ]);

        $sessionAlias = Mockery::mock('alias:Stripe\Checkout\Session');
        $sessionAlias
            ->shouldReceive('create')
            ->once()
            ->andReturn((object) [
                'id' => 'cs_test_reservation_own_hold',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_reservation_own_hold',
            ]);

        $this
            ->withHeader('Authorization', 'Bearer '.$context['token'])
            ->postJson('/api/v1/cliente/stripe/checkout/create', [
                'flight_request_id' => $context['flightRequest']->id,
                'contact_email' => $context['user']->email,
                'success_url' => 'https://example.com/success',
                'cancel_url' => 'https://example.com/cancel',
            ])
            ->assertOk()
            ->assertJsonPath('checkout_session_id', 'cs_test_reservation_own_hold');
    }

    public function test_success_returns_checkout_url_for_pending_checkout_recovery(): void
    {
        $this->seed();
        $this->configureStripe();

        $context = $this->createFlightPaymentContext(
            checkoutSessionId: 'cs_test_reservation_pending',
        );

        Pago::query()->create([
            'user_id' => $context['user']->id,
            'reservation_id' => $context['reservation']->id,
            'flight_request_id' => $context['flightRequest']->id,
            'payment_type' => 'reservation',
            'amount' => 15990,
            'currency' => 'USD',
            'provider' => 'stripe',
            'transaction_reference' => 'cs_test_reservation_pending',
            'stripe_checkout_session_id' => 'cs_test_reservation_pending',
            'status' => 'pending',
            'gateway_response' => [
                'checkout_url' => 'https://checkout.stripe.com/c/pay/cs_test_reservation_pending',
            ],
        ]);

        $sessionAlias = Mockery::mock('alias:Stripe\Checkout\Session');
        $sessionAlias->shouldReceive('retrieve')->never();

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$context['token'])
            ->getJson('/api/v1/cliente/stripe/checkout/success?session_id=cs_test_reservation_pending&reservation_id='.$context['reservation']->id.'&flight_request_id='.$context['flightRequest']->id);

        $response
            ->assertOk()
            ->assertJsonPath('payment_status', 'pending')
            ->assertJsonPath('reservation_id', $context['reservation']->id)
            ->assertJsonPath('flight_request_id', $context['flightRequest']->id)
            ->assertJsonPath(
                'checkout_url',
                'https://checkout.stripe.com/c/pay/cs_test_reservation_pending',
            )
            ->assertJsonPath('stripe_checkout_session_id', 'cs_test_reservation_pending');
    }

    private function configureStripe(): void
    {
        config()->set('services.stripe.secret', 'sk_test_backend_checkout');
        config()->set('services.stripe.publishable', 'pk_test_backend_checkout');
    }

    private function makeOpenCheckoutSession(string $sessionId, string $flightRequestId): StripeCheckoutSession
    {
        $session = new StripeCheckoutSession($sessionId);
        $session->payment_status = 'unpaid';
        $session->status = 'open';
        $session->url = 'https://checkout.stripe.com/c/pay/'.$sessionId;
        $session->payment_intent = null;
        $session->metadata = (object) [
            'flight_request_id' => $flightRequestId,
        ];

        return $session;
    }

    /**
     * @return array{user: Usuario, token: string, provider: Proveedor, aircraft: Aeronave, flightRequest: SolicitudVuelo, quote: Cotizacion, reservation: Reserva}
     */
    private function createFlightPaymentContext(string $checkoutSessionId = ''): array
    {
        $user = Usuario::factory()->create([
            'role' => Usuario::ROLE_CLIENT,
            'status' => 'active',
            'email' => 'cliente.stripe@test.dev',
        ]);

        $providerUser = Usuario::factory()->create([
            'role' => Usuario::ROLE_PROVIDER,
            'status' => 'active',
            'email' => 'provider.stripe@test.dev',
        ]);

        $provider = Proveedor::query()->create([
            'user_id' => $providerUser->id,
            'company_name' => 'Provider Stripe Test',
            'commercial_name' => 'Provider Stripe',
            'approval_status' => 'approved',
        ]);

        $aircraft = Aeronave::query()->create([
            'provider_id' => $provider->id,
            'model' => 'Citation Stripe',
            'category' => 'Light Jet',
            'registration' => 'XA-STRP1',
            'capacity' => 6,
            'base_airport' => 'MMMX',
            'range_km' => 2500,
            'speed_kmh' => 700,
            'hourly_rate' => 5000,
            'climb_descent_minutes' => 30,
            'status' => 'active',
            'currency' => 'USD',
        ]);

        $flightRequest = SolicitudVuelo::query()->create([
            'client_id' => $user->id,
            'origin' => 'MMMX',
            'destination' => 'MMUN',
            'departure_datetime' => now()->addDays(3)->setTime(10, 0),
            'departure_date' => now()->addDays(3)->format('Y-m-d'),
            'departure_time' => '10:00',
            'passengers' => 4,
            'trip_type' => 'one_way',
            'assigned_provider_id' => $provider->id,
            'assigned_aircraft_id' => $aircraft->id,
            'final_price' => 15000,
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
            'stripe_checkout_session_id' => $checkoutSessionId !== '' ? $checkoutSessionId : null,
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
            'client_id' => $user->id,
            'provider_id' => $provider->id,
            'aircraft_id' => $aircraft->id,
            'flight_request_id' => $flightRequest->id,
            'quote_id' => $quote->id,
            'reservation_code' => 'PV-TEST-STRIPE',
            'status' => 'pending_payment',
            'total_amount' => 15990,
            'currency' => 'USD',
        ]);

        ContratoReserva::query()->create([
            'reservation_id' => $reservation->id,
            'contract_code' => 'CTR-TEST-STRIPE',
            'status' => 'completed',
            'docusign_status' => 'completed',
            'signed_at' => now(),
            'completed_at' => now(),
        ]);

        $token = TokenApi::issue($user, 'test-token');

        return [
            'user' => $user,
            'token' => $token,
            'provider' => $provider,
            'aircraft' => $aircraft,
            'flightRequest' => $flightRequest,
            'quote' => $quote,
            'reservation' => $reservation,
        ];
    }
}
