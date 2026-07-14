<?php

namespace Tests\Feature;

use App\Modelos\Aeronave;
use App\Modelos\Cotizacion;
use App\Modelos\Pago;
use App\Modelos\Proveedor;
use App\Modelos\RegistroAuditoria;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class StripeWebhookClientAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_payment_intent_succeeded_webhook_is_idempotent_and_audited(): void
    {
        [$flightRequest, $reservation, $payment] = $this->createPendingReservationContext(
            paymentIntentId: 'pi_webhook_success_001',
            checkoutSessionId: 'cs_webhook_success_001',
        );
        config()->set('services.stripe.webhook_secret', 'whsec_test');

        $event = (object) [
            'id' => 'evt_webhook_success_001',
            'type' => 'payment_intent.succeeded',
            'created' => now()->timestamp,
            'data' => (object) [
                'object' => (object) [
                    'id' => 'pi_webhook_success_001',
                    'amount' => 1599000,
                    'currency' => 'usd',
                    'metadata' => (object) [
                        'flight_request_id' => (string) $flightRequest->id,
                        'checkout_session_id' => 'cs_webhook_success_001',
                    ],
                    'charges' => (object) [
                        'data' => [(object) [
                            'payment_method_details' => (object) [
                                'card' => (object) ['brand' => 'visa'],
                            ],
                        ]],
                    ],
                ],
            ],
        ];

        $webhookAlias = Mockery::mock('alias:Stripe\Webhook');
        $webhookAlias
            ->shouldReceive('constructEvent')
            ->twice()
            ->andReturn($event, $event);

        $this->postJson('/api/v1/stripe/webhook', [], ['Stripe-Signature' => 't=1,v1=fake'])->assertOk();
        $this->postJson('/api/v1/stripe/webhook', [], ['Stripe-Signature' => 't=1,v1=fake'])->assertOk();

        $flightRequest->refresh();
        $reservation->refresh();
        $payment->refresh();

        $this->assertSame('paid', $payment->status);
        $this->assertSame('paid', $flightRequest->payment_status);
        $this->assertSame('confirmed', $reservation->status);
        $this->assertSame(1, Pago::query()->where('flight_request_id', $flightRequest->id)->count());
        $this->assertDatabaseHas('webhook_events', [
            'provider' => 'stripe',
            'event_id' => 'evt_webhook_success_001',
            'status' => 'processed',
        ]);
        $this->assertSame(1, RegistroAuditoria::query()->where('action', 'stripe_webhook_payment_confirmed')->count());
        $this->assertSame(1, RegistroAuditoria::query()->where('action', 'stripe_webhook_duplicate_ignored')->count());
    }

    public function test_out_of_order_payment_failed_webhook_does_not_revert_paid_reservation(): void
    {
        [$flightRequest, $reservation, $payment] = $this->createPaidReservationContext();
        config()->set('services.stripe.webhook_secret', 'whsec_test');

        $event = (object) [
            'id' => 'evt_webhook_out_of_order_001',
            'type' => 'payment_intent.payment_failed',
            'created' => now()->timestamp,
            'data' => (object) [
                'object' => (object) [
                    'id' => 'pi_paid_client_001',
                    'metadata' => (object) [
                        'flight_request_id' => (string) $flightRequest->id,
                    ],
                    'last_payment_error' => (object) [
                        'message' => 'Card declined after success.',
                    ],
                ],
            ],
        ];

        $webhookAlias = Mockery::mock('alias:Stripe\Webhook');
        $webhookAlias->shouldReceive('constructEvent')->once()->andReturn($event);

        $this->postJson('/api/v1/stripe/webhook', [], ['Stripe-Signature' => 't=1,v1=fake'])->assertOk();

        $flightRequest->refresh();
        $reservation->refresh();
        $payment->refresh();

        $this->assertSame('paid', $payment->status);
        $this->assertSame('paid', $flightRequest->payment_status);
        $this->assertSame('confirmed', $reservation->status);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $payment->user_id,
            'action' => 'stripe_webhook_out_of_order_ignored',
            'module' => 'reservation_payments',
        ]);
    }

    public function test_invalid_metadata_webhook_is_ignored_and_audited(): void
    {
        [$flightRequest, , $payment] = $this->createPendingReservationContext(
            paymentIntentId: 'pi_invalid_metadata_001',
            checkoutSessionId: 'cs_invalid_metadata_001',
        );
        config()->set('services.stripe.webhook_secret', 'whsec_test');

        $event = (object) [
            'id' => 'evt_invalid_metadata_001',
            'type' => 'payment_intent.succeeded',
            'created' => now()->timestamp,
            'data' => (object) [
                'object' => (object) [
                    'id' => 'pi_invalid_metadata_001',
                    'amount' => 1599000,
                    'currency' => 'usd',
                    'metadata' => (object) [],
                ],
            ],
        ];

        $webhookAlias = Mockery::mock('alias:Stripe\Webhook');
        $webhookAlias->shouldReceive('constructEvent')->once()->andReturn($event);

        $this->postJson('/api/v1/stripe/webhook', [], ['Stripe-Signature' => 't=1,v1=fake'])->assertOk();

        $flightRequest->refresh();
        $payment->refresh();

        $this->assertSame('pending', $payment->status);
        $this->assertSame('pending', $flightRequest->payment_status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'stripe_webhook_invalid_metadata',
            'module' => 'reservation_payments',
        ]);
    }

    public function test_invalid_signature_webhook_is_rejected_and_audited(): void
    {
        config()->set('services.stripe.webhook_secret', 'whsec_test');

        $webhookAlias = Mockery::mock('alias:Stripe\Webhook');
        $webhookAlias
            ->shouldReceive('constructEvent')
            ->once()
            ->andThrow(new \UnexpectedValueException('bad signature'));

        $this->postJson('/api/v1/stripe/webhook', [], ['Stripe-Signature' => 't=1,v1=bad'])
            ->assertStatus(400);

        $this->assertSame(0, Pago::query()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'stripe_webhook_invalid_signature',
            'module' => 'reservation_payments',
        ]);
    }

    /**
     * @return array{0: SolicitudVuelo, 1: Reserva, 2: Pago}
     */
    private function createPendingReservationContext(string $paymentIntentId, string $checkoutSessionId): array
    {
        $client = Usuario::factory()->create([
            'role' => Usuario::ROLE_CLIENT,
            'status' => 'active',
            'email' => 'client.webhook.pending@test.dev',
        ]);

        $providerUser = Usuario::factory()->create([
            'role' => Usuario::ROLE_PROVIDER,
            'status' => 'active',
            'email' => 'provider.webhook.pending@test.dev',
        ]);

        $provider = Proveedor::query()->create([
            'user_id' => $providerUser->id,
            'company_name' => 'Provider Webhook Pending',
            'commercial_name' => 'Provider Webhook Pending',
            'approval_status' => 'approved',
        ]);

        $aircraft = Aeronave::query()->create([
            'provider_id' => $provider->id,
            'model' => 'Citation Webhook',
            'category' => 'Light Jet',
            'registration' => 'XA-WHP1',
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
            'stripe_checkout_session_id' => $checkoutSessionId,
            'stripe_payment_intent_id' => $paymentIntentId,
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
            'reservation_code' => 'PV-WEBHOOK-PENDING',
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
            'provider' => 'stripe',
            'transaction_reference' => $paymentIntentId,
            'stripe_checkout_session_id' => $checkoutSessionId,
            'stripe_payment_intent_id' => $paymentIntentId,
            'status' => 'pending',
        ]);

        return [$flightRequest, $reservation, $payment];
    }

    /**
     * @return array{0: SolicitudVuelo, 1: Reserva, 2: Pago}
     */
    private function createPaidReservationContext(): array
    {
        $client = Usuario::factory()->create([
            'role' => Usuario::ROLE_CLIENT,
            'status' => 'active',
            'email' => 'client.webhook.paid@test.dev',
        ]);

        $providerUser = Usuario::factory()->create([
            'role' => Usuario::ROLE_PROVIDER,
            'status' => 'active',
            'email' => 'provider.webhook.paid@test.dev',
        ]);

        $provider = Proveedor::query()->create([
            'user_id' => $providerUser->id,
            'company_name' => 'Provider Webhook Paid',
            'commercial_name' => 'Provider Webhook Paid',
            'approval_status' => 'approved',
        ]);

        $aircraft = Aeronave::query()->create([
            'provider_id' => $provider->id,
            'model' => 'Citation Paid',
            'category' => 'Light Jet',
            'registration' => 'XA-WHP2',
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
            'workflow_status' => 'vuelo confirmado',
            'payment_status' => 'paid',
            'pricing_context' => [
                'flight_cost' => 15000,
                'base_amount' => 15000,
                'total_amount' => 15990,
                'selected_card_price' => 15990,
                'total' => 15990,
                'final_price' => 15990,
            ],
            'stripe_checkout_session_id' => 'cs_paid_client_001',
            'stripe_payment_intent_id' => 'pi_paid_client_001',
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
            'reservation_code' => 'PV-WEBHOOK-PAID',
            'status' => 'confirmed',
            'total_amount' => 15990,
            'currency' => 'USD',
            'confirmed_at' => now(),
        ]);

        $payment = Pago::query()->create([
            'user_id' => $client->id,
            'reservation_id' => $reservation->id,
            'flight_request_id' => $flightRequest->id,
            'payment_type' => 'reservation',
            'amount' => 15990,
            'currency' => 'USD',
            'provider' => 'stripe',
            'transaction_reference' => 'pi_paid_client_001',
            'stripe_checkout_session_id' => 'cs_paid_client_001',
            'stripe_payment_intent_id' => 'pi_paid_client_001',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        return [$flightRequest, $reservation, $payment];
    }
}
