<?php

namespace Tests\Feature;

use App\Http\Controladores\StripeWebhookControlador;
use App\Modelos\Aeronave;
use App\Modelos\AircraftBillingPayment;
use App\Modelos\Plan;
use App\Modelos\Proveedor;
use App\Modelos\SuscripcionAeronave;
use App\Modelos\TokenApi;
use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class ProviderAircraftSubscriptionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_provider_register_alias_creates_pending_validation_account(): void
    {
        $this->seed();

        $response = $this->postJson('/api/v1/provider/register', [
            'name' => 'Proveedor Billing',
            'email' => 'provider.billing@test.dev',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'provider',
            'company_name' => 'Proveedor Billing SA de CV',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('provider_status', 'pending_validation')
            ->assertJsonPath('approval_status', 'pending')
            ->assertJsonPath('message', 'Proveedor registrado. Pendiente de validacion por Admin.');

        $provider = Proveedor::query()
            ->whereHas('user', fn ($query) => $query->where('email', 'provider.billing@test.dev'))
            ->firstOrFail();

        $this->assertSame('pending', $provider->approval_status);
    }

    public function test_pending_provider_can_register_aircraft_but_it_stays_inactive_pending_review(): void
    {
        $this->seed();

        [$user, $provider] = $this->createProviderUserContext('pending');

        $response = $this->withToken(TokenApi::issue($user))
            ->postJson('/api/v1/proveedor/aeronaves', $this->aircraftPayload());

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Aeronave registrada y enviada a revisión administrativa.')
            ->assertJsonPath('review_status', 'pending_review')
            ->assertJsonPath('status', 'inactive')
            ->assertJsonPath('aircraft.status', 'inactive')
            ->assertJsonPath('aircraft.review_status', 'pending_review');

        $aircraftId = $response->json('aircraft.id');

        $this->assertDatabaseHas('aircraft', [
            'id' => $aircraftId,
            'provider_id' => $provider->id,
            'status' => 'inactive',
            'billing_status' => 'pending_payment',
            'subscription_status' => 'inactive',
        ]);
    }

    public function test_approved_provider_aircraft_starts_pending_payment_and_redirects_to_billing(): void
    {
        $this->seed();

        Plan::query()->updateOrCreate(
            ['code' => 'provider_aircraft_monthly'],
            [
                'name' => 'Mensualidad por aeronave',
                'slug' => 'provider-aircraft-monthly',
                'description' => 'Cobro recurrente mensual por aeronave.',
                'amount' => 100.00,
                'price' => 100.00,
                'price_monthly' => 100.00,
                'currency' => 'USD',
                'billing_type' => 'subscription_per_aircraft',
                'interval_type' => 'monthly',
                'billing_cycle' => 'monthly',
                'role_target' => 'provider',
                'user_type' => 'provider',
                'status' => 'active',
                'is_active' => true,
            ]
        );

        [$user, $provider] = $this->createProviderUserContext('approved');

        $response = $this->withToken(TokenApi::issue($user))
            ->postJson('/api/v1/proveedor/aeronaves', $this->aircraftPayload());

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Aeronave registrada y enviada a revisión administrativa.')
            ->assertJsonPath('review_status', 'pending_review')
            ->assertJsonPath('status', 'inactive')
            ->assertJsonPath('aircraft.review_status', 'pending_review')
            ->assertJsonPath('aircraft.billing_status', 'pending_payment')
            ->assertJsonPath('aircraft.subscription_status', 'inactive')
            ->assertJsonPath('aircraft.status', 'inactive');

        $aircraftId = $response->json('aircraft.id');
        $this->assertSame("/provider/aircraft/{$aircraftId}/billing", $response->json('redirect_to'));

        $statusResponse = $this->withToken(TokenApi::issue($user))
            ->getJson('/api/v1/provider/profile-status');

        $statusResponse
            ->assertOk()
            ->assertJsonPath('provider_status', 'approved')
            ->assertJsonPath('approval_status', 'approved')
            ->assertJsonPath('can_register_aircraft', true);

        $this->assertDatabaseHas('aircraft', [
            'id' => $aircraftId,
            'provider_id' => $provider->id,
            'status' => 'inactive',
            'billing_status' => 'pending_payment',
            'subscription_status' => 'inactive',
        ]);
    }

    public function test_billing_status_keeps_admin_approved_aircraft_pending_payment_until_subscription_is_active(): void
    {
        $this->seed();

        [$user, $provider] = $this->createProviderUserContext('approved');

        $response = $this->withToken(TokenApi::issue($user))
            ->postJson('/api/v1/proveedor/aeronaves', $this->aircraftPayload());

        $aircraftId = (int) $response->json('aircraft.id');

        $provider->aircraft()->whereKey($aircraftId)->update([
            'approved_at' => now(),
            'status' => 'inactive',
            'billing_status' => 'pending_payment',
            'subscription_status' => 'inactive',
        ]);

        $statusResponse = $this->withToken(TokenApi::issue($user))
            ->getJson("/api/v1/proveedor/aeronaves/{$aircraftId}/billing");

        $statusResponse
            ->assertOk()
            ->assertJsonPath('aircraft.id', $aircraftId)
            ->assertJsonPath('aircraft.status', 'inactive')
            ->assertJsonPath('aircraft.approved', true)
            ->assertJsonPath('aircraft.review_status', 'approved')
            ->assertJsonPath('aircraft.billing_status', 'pending_payment')
            ->assertJsonPath('aircraft.subscription_status', 'inactive');
    }

    public function test_checkout_session_uses_backend_metadata_and_readable_description_for_aircraft_billing(): void
    {
        $this->seed();
        config()->set('services.stripe.secret', 'sk_test_aircraft_billing');
        config()->set('services.stripe.publishable', 'pk_test_aircraft_billing');
        config()->set('services.stripe.frontend_url', 'https://frontend.test');

        Plan::query()->updateOrCreate(
            ['code' => 'provider_aircraft_monthly'],
            [
                'name' => 'Mensualidad por aeronave',
                'slug' => 'provider-aircraft-monthly',
                'description' => 'Cobro recurrente mensual por aeronave.',
                'amount' => 100.00,
                'price' => 100.00,
                'price_monthly' => 100.00,
                'currency' => 'USD',
                'billing_type' => 'subscription_per_aircraft',
                'interval_type' => 'monthly',
                'billing_cycle' => 'monthly',
                'role_target' => 'provider',
                'user_type' => 'provider',
                'status' => 'active',
                'is_active' => true,
            ]
        );

        [$user, $provider] = $this->createProviderUserContext('approved');

        $aircraft = Aeronave::query()->create([
            'provider_id' => $provider->id,
            'model' => 'LEAR JET 31',
            'registration' => 'XB-LJ31',
            'category' => 'Light Jet',
            'capacity' => 8,
            'base_airport' => 'MMMX',
            'hourly_rate' => 5500,
            'status' => 'inactive',
            'billing_status' => 'pending_payment',
            'subscription_status' => 'inactive',
        ]);

        $sessionAlias = Mockery::mock('alias:Stripe\Checkout\Session');
        $sessionAlias
            ->shouldReceive('create')
            ->once()
            ->withArgs(function (array $payload) {
                $metadata = $payload['metadata'] ?? [];
                $subscriptionMetadata = $payload['subscription_data']['metadata'] ?? [];
                $lineItem = $payload['line_items'][0]['price_data']['product_data'] ?? [];

                return ($metadata['billing_context'] ?? null) === 'provider_aircraft_subscription'
                    && ($metadata['action'] ?? null) === 'activate_aircraft'
                    && ($metadata['billing_type'] ?? null) === 'monthly_aircraft_subscription'
                    && ($metadata['aircraft_name'] ?? null) === 'LEAR JET 31'
                    && ($metadata['company_name'] ?? null) === 'Proveedor Test'
                    && ($metadata['provider_aircraft_id'] ?? null) === (string)  $metadata['aircraft_id']
                    && ($subscriptionMetadata['company_name'] ?? null) === 'Proveedor Test'
                    && ($payload['subscription_data']['description'] ?? null) === 'Mensualidad LEAR JET 31 - Proveedor Test'
                    && ($lineItem['name'] ?? null) === 'Mensualidad LEAR JET 31 - Proveedor Test';
            })
            ->andReturn((object) [
                'id' => 'cs_test_aircraft_checkout',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_aircraft_checkout',
            ]);

        $response = $this->withToken(TokenApi::issue($user))
            ->postJson("/api/v1/provider/aircraft/{$aircraft->id}/billing", []);

        $response
            ->assertCreated()
            ->assertJsonPath('checkout_session_id', 'cs_test_aircraft_checkout')
            ->assertJsonPath('checkout_url', 'https://checkout.stripe.com/c/pay/cs_test_aircraft_checkout')
            ->assertJsonPath('payment.provider_checkout_id', 'cs_test_aircraft_checkout');

        $this->assertDatabaseHas('aircraft_billing_payments', [
            'aircraft_id' => $aircraft->id,
            'provider_id' => $provider->id,
            'provider_checkout_id' => 'cs_test_aircraft_checkout',
            'status' => 'pending',
        ]);
    }

    public function test_invoice_paid_activates_aircraft_even_when_invoice_metadata_is_missing(): void
    {
        $this->seed();
        config()->set('services.stripe.secret', null);

        [$user, $provider] = $this->createProviderUserContext('approved');
        $plan = $this->createAircraftBillingPlan();
        $aircraft = $this->createAircraftForBilling($provider, [
            'approved_at' => now(),
            'status' => 'inactive',
            'billing_status' => 'pending_payment',
            'subscription_status' => 'pending',
        ]);

        $payment = AircraftBillingPayment::query()->create([
            'provider_id' => $provider->id,
            'aircraft_id' => $aircraft->id,
            'billing_plan_id' => $plan->id,
            'amount' => 100.00,
            'currency' => 'USD',
            'billing_period_start' => now()->startOfMonth()->toDateString(),
            'billing_period_end' => now()->endOfMonth()->toDateString(),
            'status' => 'pending',
            'provider' => 'stripe',
            'provider_checkout_id' => 'cs_test_invoice_paid',
            'provider_subscription_id' => 'sub_test_invoice_paid',
        ]);

        $invoice = (object) [
            'id' => 'in_test_aircraft_paid',
            'subscription' => 'sub_test_invoice_paid',
            'customer' => 'cus_test_aircraft_paid',
            'payment_intent' => 'pi_test_aircraft_paid',
            'amount_paid' => 10000,
            'currency' => 'usd',
            'lines' => (object) [
                'data' => [
                    [
                        'period' => [
                            'start' => now()->startOfMonth()->timestamp,
                            'end' => now()->endOfMonth()->timestamp,
                        ],
                    ],
                ],
            ],
        ];

        $method = new ReflectionMethod(StripeWebhookControlador::class, 'handleInvoicePaid');
        $method->setAccessible(true);
        $method->invoke(new StripeWebhookControlador(), $invoice);

        $this->assertDatabaseHas('aircraft', [
            'id' => $aircraft->id,
            'billing_status' => 'active',
            'subscription_status' => 'active',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('aircraft_billing_payments', [
            'id' => $payment->id,
            'status' => 'paid',
            'provider_subscription_id' => 'sub_test_invoice_paid',
            'provider_customer_id' => 'cus_test_aircraft_paid',
            'provider_invoice_id' => 'in_test_aircraft_paid',
            'provider_payment_id' => 'pi_test_aircraft_paid',
        ]);

        $this->assertDatabaseHas('aircraft_subscriptions', [
            'aircraft_id' => $aircraft->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'provider_subscription_id' => 'sub_test_invoice_paid',
            'provider_customer_id' => 'cus_test_aircraft_paid',
            'provider_invoice_id' => 'in_test_aircraft_paid',
        ]);
    }

    public function test_customer_subscription_deleted_marks_aircraft_billing_as_cancelled_without_losing_history(): void
    {
        $this->seed();
        config()->set('services.stripe.secret', null);

        [$user, $provider] = $this->createProviderUserContext('approved');
        $plan = $this->createAircraftBillingPlan();
        $aircraft = $this->createAircraftForBilling($provider, [
            'approved_at' => now(),
            'status' => 'active',
            'billing_status' => 'active',
            'subscription_status' => 'active',
        ]);

        $payment = AircraftBillingPayment::query()->create([
            'provider_id' => $provider->id,
            'aircraft_id' => $aircraft->id,
            'billing_plan_id' => $plan->id,
            'amount' => 100.00,
            'currency' => 'USD',
            'billing_period_start' => now()->startOfMonth()->toDateString(),
            'billing_period_end' => now()->endOfMonth()->toDateString(),
            'status' => 'paid',
            'provider' => 'stripe',
            'provider_checkout_id' => 'cs_test_cancelled',
            'provider_subscription_id' => 'sub_test_cancelled',
            'provider_customer_id' => 'cus_test_cancelled',
            'provider_invoice_id' => 'in_test_cancelled',
        ]);

        SuscripcionAeronave::query()->create([
            'aircraft_id' => $aircraft->id,
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'status' => 'active',
            'payment_provider' => 'stripe',
            'payment_reference' => 'sub_test_cancelled',
            'provider_checkout_id' => 'cs_test_cancelled',
            'provider_subscription_id' => 'sub_test_cancelled',
            'provider_customer_id' => 'cus_test_cancelled',
            'provider_invoice_id' => 'in_test_cancelled',
            'paid_at' => now(),
            'starts_at' => now()->startOfMonth(),
            'ends_at' => now()->endOfMonth(),
        ]);

        $subscriptionPayload = (object) [
            'id' => 'sub_test_cancelled',
            'customer' => 'cus_test_cancelled',
            'ended_at' => now()->timestamp,
            'canceled_at' => now()->timestamp,
        ];

        $method = new ReflectionMethod(StripeWebhookControlador::class, 'handleCustomerSubscriptionDeleted');
        $method->setAccessible(true);
        $method->invoke(new StripeWebhookControlador(), $subscriptionPayload);

        $this->assertDatabaseHas('aircraft', [
            'id' => $aircraft->id,
            'billing_status' => 'cancelled',
            'subscription_status' => 'cancelled',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('aircraft_billing_payments', [
            'id' => $payment->id,
            'status' => 'cancelled',
            'provider_subscription_id' => 'sub_test_cancelled',
        ]);

        $this->assertDatabaseHas('aircraft_subscriptions', [
            'aircraft_id' => $aircraft->id,
            'plan_id' => $plan->id,
            'status' => 'cancelled',
            'provider_subscription_id' => 'sub_test_cancelled',
        ]);
    }

    private function createProviderUserContext(string $approvalStatus = 'approved'): array
    {
        $user = Usuario::factory()->create([
            'role' => Usuario::ROLE_PROVIDER,
            'status' => 'active',
            'email' => sprintf('provider.%s.%s@test.dev', $approvalStatus, uniqid()),
        ]);
        $user->syncRoles([Usuario::ROLE_PROVIDER], Usuario::ROLE_PROVIDER);

        $provider = Proveedor::query()->create([
            'user_id' => $user->id,
            'company_name' => 'Proveedor Test',
            'commercial_name' => 'Proveedor Test',
            'approval_status' => $approvalStatus,
        ]);

        $user->forceFill(['provider_id' => $provider->id])->saveQuietly();

        return [$user->fresh(['provider']), $provider->fresh()];
    }

    private function aircraftPayload(): array
    {
        return [
            'model' => 'Hawker 800A',
            'registration' => 'XA-ABC',
            'category' => 'Mid Jet',
            'capacity' => 8,
            'base_airport' => 'MMMX',
            'hourly_rate' => 5500,
        ];
    }

    private function createAircraftBillingPlan(): Plan
    {
        return Plan::query()->updateOrCreate(
            ['code' => 'provider_aircraft_monthly'],
            [
                'name' => 'Mensualidad por aeronave',
                'slug' => 'provider-aircraft-monthly',
                'description' => 'Cobro recurrente mensual por aeronave.',
                'amount' => 100.00,
                'price' => 100.00,
                'price_monthly' => 100.00,
                'currency' => 'USD',
                'billing_type' => 'subscription_per_aircraft',
                'interval_type' => 'monthly',
                'billing_cycle' => 'monthly',
                'role_target' => 'provider',
                'user_type' => 'provider',
                'status' => 'active',
                'is_active' => true,
            ]
        );
    }

    private function createAircraftForBilling(Proveedor $provider, array $overrides = []): Aeronave
    {
        return Aeronave::query()->create([
            'provider_id' => $provider->id,
            'model' => 'LEAR JET 31',
            'registration' => 'XB-LJ31',
            'category' => 'Light Jet',
            'capacity' => 8,
            'base_airport' => 'MMMX',
            'hourly_rate' => 5500,
            'status' => 'inactive',
            'billing_status' => 'pending_payment',
            'subscription_status' => 'pending',
            ...$overrides,
        ]);
    }
}
