<?php

namespace Tests\Feature;

use App\Http\Controladores\StripeWebhookControlador;
use App\Modelos\Aeronave;
use App\Modelos\AircraftBillingPayment;
use App\Modelos\Plan;
use App\Modelos\Proveedor;
use App\Modelos\RegistroAuditoria;
use App\Modelos\SuscripcionAeronave;
use App\Modelos\TokenApi;
use App\Modelos\Usuario;
use Carbon\Carbon;
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

    public function test_provider_cannot_force_aircraft_activation_fields_through_provider_or_operator_crud(): void
    {
        $this->seed();

        [$user, $provider] = $this->createProviderUserContext('approved');
        $token = TokenApi::issue($user);

        $this->withToken($token)
            ->postJson('/api/v1/proveedor/aeronaves', $this->aircraftPayload() + [
                'status' => 'active',
                'Status' => 'active',
                'STATUS' => 'active',
                'billingStatus' => 'active',
                'billing_status' => 'active',
                'Billing-Status' => 'active',
                'subscriptionStatus' => 'active',
                'operationalStatus' => 'active',
                'reviewStatus' => 'approved',
                'validation_status' => 'approved',
                'stripeCustomerId' => 'cus_forbidden',
                'stripe_customer_id' => 'cus_forbidden_2',
                'stripeAnything' => 'blocked',
                'stripe_anything' => 'blocked',
                'STRIPE-INVOICE-ID' => 'in_forbidden',
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('aircraft', [
            'provider_id' => $provider->id,
            'registration' => 'XA-ABC',
        ]);

        $created = $this->withToken($token)
            ->postJson('/api/v1/proveedor/aeronaves', $this->aircraftPayload())
            ->assertCreated();

        $aircraftId = (int) $created->json('aircraft.id');

        $this->withToken($token)
            ->putJson("/api/v1/proveedor/aeronaves/{$aircraftId}", [
                'Status' => 'active',
                'billing_status' => 'active',
                'subscription_status' => 'active',
                'operational_status' => 'active',
                'stripePaymentIntentId' => 'pi_forbidden',
                'stripe_checkout_session_id' => 'cs_forbidden',
            ])
            ->assertStatus(422);

        $this->withToken($token)
            ->postJson('/api/v1/operator/aircraft', $this->aircraftPayload() + [
                'registration' => 'XA-OPFBD',
                'status' => 'active',
                'estado' => 'active',
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('aircraft', [
            'id' => $aircraftId,
            'provider_id' => $provider->id,
            'status' => 'inactive',
            'billing_status' => 'pending_payment',
            'subscription_status' => 'inactive',
        ]);
        $this->assertDatabaseMissing('aircraft', [
            'provider_id' => $provider->id,
            'registration' => 'XA-OPFBD',
        ]);
        $this->assertDatabaseCount('aircraft_billing_payments', 0);
        $this->assertDatabaseCount('aircraft_subscriptions', 0);
    }

    public function test_provider_aircraft_creation_uses_owned_provider_when_direct_provider_id_is_missing(): void
    {
        $this->seed();

        [$user, $provider] = $this->createProviderUserContext('approved');
        $user->forceFill(['provider_id' => null])->saveQuietly();

        $response = $this->withToken(TokenApi::issue($user->fresh()))
            ->postJson('/api/v1/proveedor/aeronaves', [
                ...$this->aircraftPayload(),
                'registration' => 'XA-OWNED1',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('aircraft.provider_id', $provider->id);

        $this->assertDatabaseHas('aircraft', [
            'provider_id' => $provider->id,
            'registration' => 'XA-OWNED1',
        ]);
    }

    public function test_provider_without_owned_provider_is_rejected_when_registering_aircraft(): void
    {
        $this->seed();

        $user = $this->createProviderUserWithoutProvider();

        $this->withToken(TokenApi::issue($user))
            ->postJson('/api/v1/proveedor/aeronaves', $this->aircraftPayload())
            ->assertStatus(422)
            ->assertSeeText('El usuario proveedor no tiene un proveedor autorizado asignado.');
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
            ->withArgs(function (array $payload, array $options) {
                $metadata = $payload['metadata'] ?? [];
                $subscriptionMetadata = $payload['subscription_data']['metadata'] ?? [];
                $lineItem = $payload['line_items'][0]['price_data']['product_data'] ?? [];

                return ($metadata['billing_context'] ?? null) === 'provider_aircraft_subscription'
                    && ($metadata['action'] ?? null) === 'activate_aircraft'
                    && ($metadata['billing_type'] ?? null) === 'monthly_aircraft_subscription'
                    && ($metadata['aircraft_name'] ?? null) === 'LEAR JET 31'
                    && ($metadata['company_name'] ?? null) === 'Proveedor Test'
                    && ($metadata['provider_aircraft_id'] ?? null) === (string)  $metadata['aircraft_id']
                    && filled($metadata['aircraft_billing_payment_id'] ?? null)
                    && ($subscriptionMetadata['aircraft_billing_payment_id'] ?? null) === ($metadata['aircraft_billing_payment_id'] ?? null)
                    && ($subscriptionMetadata['company_name'] ?? null) === 'Proveedor Test'
                    && ($payload['subscription_data']['description'] ?? null) === 'Mensualidad LEAR JET 31 - Proveedor Test'
                    && ($lineItem['name'] ?? null) === 'Mensualidad LEAR JET 31 - Proveedor Test'
                    && str_starts_with((string) ($options['idempotency_key'] ?? ''), 'provider-aircraft-billing:');
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

    public function test_repeated_aircraft_billing_checkout_reuses_same_session_and_single_payment_row(): void
    {
        $this->seed();
        config()->set('services.stripe.secret', 'sk_test_aircraft_billing_repeat');
        config()->set('services.stripe.publishable', 'pk_test_aircraft_billing_repeat');
        config()->set('services.stripe.frontend_url', 'https://frontend.test');

        $this->createAircraftBillingPlan();
        [$user, $provider] = $this->createProviderUserContext('approved');
        $aircraft = $this->createAircraftForBilling($provider, [
            'registration' => 'XB-REPEAT',
        ]);

        $sessionAlias = Mockery::mock('alias:Stripe\Checkout\Session');
        $sessionAlias
            ->shouldReceive('create')
            ->once()
            ->withArgs(function (array $payload, array $options) {
                return ($payload['metadata']['provider_aircraft_id'] ?? null) !== null
                    && str_starts_with((string) ($options['idempotency_key'] ?? ''), 'provider-aircraft-billing:');
            })
            ->andReturn((object) [
                'id' => 'cs_test_repeat_checkout',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_repeat_checkout',
            ]);

        $first = $this->withToken(TokenApi::issue($user))
            ->postJson("/api/v1/provider/aircraft/{$aircraft->id}/billing", [])
            ->assertCreated()
            ->assertJsonPath('checkout_session_id', 'cs_test_repeat_checkout')
            ->assertJsonPath('reused_checkout', false);

        $second = $this->withToken(TokenApi::issue($user))
            ->postJson("/api/v1/provider/aircraft/{$aircraft->id}/billing", [])
            ->assertOk()
            ->assertJsonPath('checkout_session_id', 'cs_test_repeat_checkout')
            ->assertJsonPath('checkout_url', 'https://checkout.stripe.com/c/pay/cs_test_repeat_checkout')
            ->assertJsonPath('reused_checkout', true);

        $this->assertSame($first->json('payment.id'), $second->json('payment.id'));
        $this->assertSame(1, AircraftBillingPayment::query()
            ->where('provider_id', $provider->id)
            ->where('aircraft_id', $aircraft->id)
            ->count());
    }

    public function test_duplicate_provider_aircraft_invoice_webhook_is_ignored_after_first_processing(): void
    {
        $this->seed();
        config()->set('services.stripe.webhook_secret', 'whsec_provider_aircraft');

        [$user, $provider] = $this->createProviderUserContext('approved');
        $plan = $this->createAircraftBillingPlan();
        $aircraft = $this->createAircraftForBilling($provider, [
            'approved_at' => now(),
            'status' => 'inactive',
            'billing_status' => 'pending_payment',
            'subscription_status' => 'pending',
        ]);

        $payment = $this->createAircraftBillingPayment($provider, $aircraft, $plan, [
            'status' => 'pending',
            'provider_checkout_id' => 'cs_test_dup_aircraft',
            'provider_subscription_id' => 'sub_test_dup_aircraft',
        ]);

        $event = (object) [
            'id' => 'evt_provider_aircraft_invoice_dup_001',
            'type' => 'invoice.paid',
            'created' => now()->timestamp,
            'data' => (object) [
                'object' => (object) [
                    'id' => 'in_test_dup_aircraft',
                    'subscription' => 'sub_test_dup_aircraft',
                    'customer' => 'cus_test_dup_aircraft',
                    'payment_intent' => 'pi_test_dup_aircraft',
                    'amount_paid' => 10000,
                    'currency' => 'usd',
                    'metadata' => (object) [
                        'billing_context' => 'provider_aircraft_subscription',
                        'aircraft_billing_payment_id' => (string) $payment->id,
                        'provider_id' => (string) $provider->id,
                        'aircraft_id' => (string) $aircraft->id,
                        'billing_plan_id' => (string) $plan->id,
                        'user_id' => (string) $user->id,
                        'provider_checkout_id' => 'cs_test_dup_aircraft',
                    ],
                    'lines' => (object) [
                        'data' => [[
                            'period' => [
                                'start' => now()->startOfMonth()->timestamp,
                                'end' => now()->endOfMonth()->timestamp,
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

        $this->assertDatabaseHas('aircraft', [
            'id' => $aircraft->id,
            'status' => 'active',
            'billing_status' => 'active',
            'subscription_status' => 'active',
        ]);
        $this->assertDatabaseHas('aircraft_billing_payments', [
            'id' => $payment->id,
            'status' => 'paid',
            'provider_invoice_id' => 'in_test_dup_aircraft',
        ]);
        $this->assertSame(1, SuscripcionAeronave::query()->where('aircraft_id', $aircraft->id)->where('plan_id', $plan->id)->count());
        $this->assertSame(1, RegistroAuditoria::query()->where('action', 'stripe_webhook_duplicate_ignored')->count());
        $this->assertDatabaseHas('webhook_events', [
            'provider' => 'stripe',
            'event_id' => 'evt_provider_aircraft_invoice_dup_001',
            'status' => 'processed',
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
            'status' => 'inactive',
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

    public function test_invoice_payment_failed_deactivates_aircraft(): void
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
            'subscription_ends_at' => now()->addDays(10),
        ]);

        $payment = $this->createAircraftBillingPayment($provider, $aircraft, $plan, [
            'status' => 'paid',
            'provider_subscription_id' => 'sub_test_failed',
            'provider_checkout_id' => 'cs_test_failed',
            'provider_customer_id' => 'cus_test_failed',
            'provider_invoice_id' => 'in_test_prev',
        ]);

        $this->createAircraftSubscription($user, $aircraft, $plan, [
            'status' => 'active',
            'provider_subscription_id' => 'sub_test_failed',
            'provider_customer_id' => 'cus_test_failed',
            'ends_at' => now()->addDays(10),
        ]);

        $invoice = (object) [
            'id' => 'in_test_failed',
            'subscription' => 'sub_test_failed',
            'customer' => 'cus_test_failed',
            'metadata' => (object) [],
        ];

        $this->invokePrivateWebhookMethod('handleInvoicePaymentFailed', $invoice);

        $this->assertDatabaseHas('aircraft', [
            'id' => $aircraft->id,
            'billing_status' => 'past_due',
            'subscription_status' => 'past_due',
            'status' => 'inactive',
        ]);

        $this->assertDatabaseHas('aircraft_billing_payments', [
            'id' => $payment->id,
            'status' => 'past_due',
            'provider_invoice_id' => 'in_test_failed',
        ]);
    }

    public function test_customer_subscription_updated_past_due_deactivates_aircraft(): void
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
            'subscription_ends_at' => now()->addDays(5),
        ]);

        $payment = $this->createAircraftBillingPayment($provider, $aircraft, $plan, [
            'status' => 'paid',
            'provider_subscription_id' => 'sub_test_past_due',
            'provider_customer_id' => 'cus_test_past_due',
        ]);

        $this->createAircraftSubscription($user, $aircraft, $plan, [
            'status' => 'active',
            'provider_subscription_id' => 'sub_test_past_due',
            'provider_customer_id' => 'cus_test_past_due',
            'ends_at' => now()->addDays(5),
        ]);

        $payload = (object) [
            'id' => 'sub_test_past_due',
            'customer' => 'cus_test_past_due',
            'status' => 'past_due',
            'current_period_start' => now()->startOfMonth()->timestamp,
            'current_period_end' => now()->addDays(5)->timestamp,
            'cancel_at_period_end' => false,
        ];

        $this->invokePrivateWebhookMethod('handleCustomerSubscriptionUpdated', $payload);

        $this->assertDatabaseHas('aircraft', [
            'id' => $aircraft->id,
            'billing_status' => 'past_due',
            'subscription_status' => 'past_due',
            'status' => 'inactive',
        ]);

        $this->assertDatabaseHas('aircraft_billing_payments', [
            'id' => $payment->id,
            'status' => 'past_due',
        ]);
    }

    public function test_customer_subscription_updated_active_and_current_reactivates_aircraft(): void
    {
        $this->seed();
        config()->set('services.stripe.secret', null);

        [$user, $provider] = $this->createProviderUserContext('approved');
        $plan = $this->createAircraftBillingPlan();
        $aircraft = $this->createAircraftForBilling($provider, [
            'approved_at' => now(),
            'status' => 'inactive',
            'billing_status' => 'past_due',
            'subscription_status' => 'past_due',
            'subscription_ends_at' => now()->subDay(),
        ]);

        $payment = $this->createAircraftBillingPayment($provider, $aircraft, $plan, [
            'status' => 'past_due',
            'provider_subscription_id' => 'sub_test_reactivate',
            'provider_customer_id' => 'cus_test_reactivate',
        ]);

        $this->createAircraftSubscription($user, $aircraft, $plan, [
            'status' => 'past_due',
            'provider_subscription_id' => 'sub_test_reactivate',
            'provider_customer_id' => 'cus_test_reactivate',
            'ends_at' => now()->subDay(),
        ]);

        $payload = (object) [
            'id' => 'sub_test_reactivate',
            'customer' => 'cus_test_reactivate',
            'status' => 'active',
            'current_period_start' => now()->startOfDay()->timestamp,
            'current_period_end' => now()->addMonth()->endOfDay()->timestamp,
            'cancel_at_period_end' => false,
        ];

        $this->invokePrivateWebhookMethod('handleCustomerSubscriptionUpdated', $payload);

        $this->assertDatabaseHas('aircraft', [
            'id' => $aircraft->id,
            'billing_status' => 'active',
            'subscription_status' => 'active',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('aircraft_billing_payments', [
            'id' => $payment->id,
            'status' => 'paid',
        ]);
    }

    public function test_expired_period_command_deactivates_aircraft(): void
    {
        $this->seed();

        [$user, $provider] = $this->createProviderUserContext('approved');
        $plan = $this->createAircraftBillingPlan();
        $aircraft = $this->createAircraftForBilling($provider, [
            'approved_at' => now(),
            'status' => 'active',
            'billing_status' => 'active',
            'subscription_status' => 'active',
            'subscription_ends_at' => now()->subHour(),
        ]);

        $this->createAircraftBillingPayment($provider, $aircraft, $plan, [
            'status' => 'paid',
            'provider_subscription_id' => 'sub_test_expired',
            'billing_period_end' => now()->subDay()->toDateString(),
        ]);

        $this->createAircraftSubscription($user, $aircraft, $plan, [
            'status' => 'active',
            'provider_subscription_id' => 'sub_test_expired',
            'ends_at' => now()->subHour(),
        ]);

        $this->artisan('skygroup:expire-aircraft-subscriptions')
            ->assertExitCode(0);

        $this->assertDatabaseHas('aircraft', [
            'id' => $aircraft->id,
            'billing_status' => 'expired',
            'subscription_status' => 'expired',
            'status' => 'inactive',
        ]);

        $this->assertDatabaseHas('aircraft_subscriptions', [
            'aircraft_id' => $aircraft->id,
            'status' => 'expired',
        ]);
    }

    public function test_repeated_expiration_command_is_idempotent(): void
    {
        $this->seed();

        [$user, $provider] = $this->createProviderUserContext('approved');
        $plan = $this->createAircraftBillingPlan();
        $aircraft = $this->createAircraftForBilling($provider, [
            'approved_at' => now(),
            'status' => 'active',
            'billing_status' => 'active',
            'subscription_status' => 'active',
            'subscription_ends_at' => now()->subMinute(),
        ]);

        $this->createAircraftSubscription($user, $aircraft, $plan, [
            'status' => 'active',
            'provider_subscription_id' => 'sub_test_repeat',
            'ends_at' => now()->subMinute(),
        ]);

        $this->artisan('skygroup:expire-aircraft-subscriptions')->assertExitCode(0);
        $this->artisan('skygroup:expire-aircraft-subscriptions')->assertExitCode(0);

        $this->assertSame(1, SuscripcionAeronave::query()->where('aircraft_id', $aircraft->id)->count());
        $this->assertDatabaseHas('aircraft', [
            'id' => $aircraft->id,
            'status' => 'inactive',
            'subscription_status' => 'expired',
        ]);
    }

    public function test_new_invoice_paid_after_expiration_reactivates_aircraft(): void
    {
        $this->seed();
        config()->set('services.stripe.secret', null);

        [$user, $provider] = $this->createProviderUserContext('approved');
        $plan = $this->createAircraftBillingPlan();
        $aircraft = $this->createAircraftForBilling($provider, [
            'approved_at' => now(),
            'status' => 'inactive',
            'billing_status' => 'expired',
            'subscription_status' => 'expired',
            'subscription_ends_at' => now()->subDay(),
        ]);

        $payment = $this->createAircraftBillingPayment($provider, $aircraft, $plan, [
            'status' => 'expired',
            'provider_checkout_id' => 'cs_test_repaid',
            'provider_subscription_id' => 'sub_test_repaid',
        ]);

        $this->createAircraftSubscription($user, $aircraft, $plan, [
            'status' => 'expired',
            'provider_subscription_id' => 'sub_test_repaid',
            'ends_at' => now()->subDay(),
        ]);

        $invoice = (object) [
            'id' => 'in_test_repaid',
            'subscription' => 'sub_test_repaid',
            'customer' => 'cus_test_repaid',
            'payment_intent' => 'pi_test_repaid',
            'amount_paid' => 10000,
            'currency' => 'usd',
            'lines' => (object) [
                'data' => [[
                    'period' => [
                        'start' => now()->startOfDay()->timestamp,
                        'end' => now()->addMonth()->endOfDay()->timestamp,
                    ],
                ]],
            ],
        ];

        $this->invokePrivateWebhookMethod('handleInvoicePaid', $invoice);

        $this->assertDatabaseHas('aircraft', [
            'id' => $aircraft->id,
            'billing_status' => 'active',
            'subscription_status' => 'active',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('aircraft_billing_payments', [
            'id' => $payment->id,
            'status' => 'paid',
            'provider_invoice_id' => 'in_test_repaid',
        ]);
    }

    public function test_webhook_does_not_modify_other_aircraft_subscription(): void
    {
        $this->seed();
        config()->set('services.stripe.secret', null);

        [$user, $provider] = $this->createProviderUserContext('approved');
        $plan = $this->createAircraftBillingPlan();

        $aircraftA = $this->createAircraftForBilling($provider, [
            'approved_at' => now(),
            'status' => 'active',
            'billing_status' => 'active',
            'subscription_status' => 'active',
        ]);
        $aircraftB = $this->createAircraftForBilling($provider, [
            'registration' => 'XB-OTHR',
            'approved_at' => now(),
            'status' => 'active',
            'billing_status' => 'active',
            'subscription_status' => 'active',
        ]);

        $paymentA = $this->createAircraftBillingPayment($provider, $aircraftA, $plan, [
            'status' => 'paid',
            'provider_subscription_id' => 'sub_test_target',
        ]);
        $this->createAircraftBillingPayment($provider, $aircraftB, $plan, [
            'status' => 'paid',
            'provider_subscription_id' => 'sub_test_other',
        ]);

        $this->createAircraftSubscription($user, $aircraftA, $plan, [
            'status' => 'active',
            'provider_subscription_id' => 'sub_test_target',
            'ends_at' => now()->addDays(2),
        ]);
        $this->createAircraftSubscription($user, $aircraftB, $plan, [
            'status' => 'active',
            'provider_subscription_id' => 'sub_test_other',
            'ends_at' => now()->addDays(2),
        ]);

        $invoice = (object) [
            'id' => 'in_test_target_fail',
            'subscription' => 'sub_test_target',
            'customer' => 'cus_test_target',
            'metadata' => (object) [],
        ];

        $this->invokePrivateWebhookMethod('handleInvoicePaymentFailed', $invoice);

        $this->assertDatabaseHas('aircraft', [
            'id' => $aircraftA->id,
            'status' => 'inactive',
            'billing_status' => 'past_due',
        ]);
        $this->assertDatabaseHas('aircraft', [
            'id' => $aircraftB->id,
            'status' => 'active',
            'billing_status' => 'active',
            'subscription_status' => 'active',
        ]);
        $this->assertDatabaseHas('aircraft_billing_payments', [
            'id' => $paymentA->id,
            'status' => 'past_due',
        ]);
    }

    public function test_provider_aircraft_listing_returns_inactive_when_subscription_is_expired(): void
    {
        $this->seed();

        [$user, $provider] = $this->createProviderUserContext('approved');
        $plan = $this->createAircraftBillingPlan();
        $token = TokenApi::issue($user);

        $expiredAircraft = $this->createAircraftForBilling($provider, [
            'approved_at' => now(),
            'status' => 'active',
            'billing_status' => 'active',
            'subscription_status' => 'active',
            'subscription_started_at' => now()->subMonth(),
            'subscription_ends_at' => now()->subMinute(),
        ]);
        $activeAircraft = $this->createAircraftForBilling($provider, [
            'registration' => 'XB-ACTV',
            'approved_at' => now(),
            'status' => 'active',
            'billing_status' => 'active',
            'subscription_status' => 'active',
            'subscription_started_at' => now()->startOfMonth(),
            'subscription_ends_at' => now()->addDays(10),
        ]);

        $this->createAircraftSubscription($user, $expiredAircraft, $plan, [
            'status' => 'active',
            'provider_subscription_id' => 'sub_test_expired_list',
            'ends_at' => now()->subMinute(),
        ]);
        $this->createAircraftSubscription($user, $activeAircraft, $plan, [
            'status' => 'active',
            'provider_subscription_id' => 'sub_test_active_list',
            'ends_at' => now()->addDays(10),
        ]);

        $response = $this->withToken($token)->getJson('/api/v1/proveedor/mis-aeronaves');

        $response->assertOk();

        $aircraftRows = collect($response->json('aircraft.data'));
        $expiredRow = $aircraftRows->firstWhere('id', $expiredAircraft->id);
        $activeRow = $aircraftRows->firstWhere('id', $activeAircraft->id);

        $this->assertNotNull($expiredRow);
        $this->assertNotNull($activeRow);
        $this->assertSame('inactive', $expiredRow['status']);
        $this->assertSame('expired', $expiredRow['subscription_status']);
        $this->assertSame('active', $activeRow['status']);
        $this->assertSame(1, $aircraftRows->where('status', 'active')->count());
        $this->assertDatabaseHas('aircraft', [
            'id' => $expiredAircraft->id,
            'status' => 'inactive',
            'billing_status' => 'expired',
            'subscription_status' => 'expired',
        ]);
    }

    public function test_subscription_updated_incomplete_deactivates_aircraft(): void
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
            'subscription_ends_at' => now()->addDays(3),
        ]);

        $this->createAircraftBillingPayment($provider, $aircraft, $plan, [
            'status' => 'paid',
            'provider_subscription_id' => 'sub_test_incomplete',
            'provider_customer_id' => 'cus_test_incomplete',
        ]);

        $this->createAircraftSubscription($user, $aircraft, $plan, [
            'status' => 'active',
            'provider_subscription_id' => 'sub_test_incomplete',
            'provider_customer_id' => 'cus_test_incomplete',
            'ends_at' => now()->addDays(3),
        ]);

        $payload = (object) [
            'id' => 'sub_test_incomplete',
            'customer' => 'cus_test_incomplete',
            'status' => 'incomplete',
            'current_period_start' => now()->startOfDay()->timestamp,
            'current_period_end' => now()->addDays(3)->timestamp,
            'cancel_at_period_end' => false,
        ];

        $this->invokePrivateWebhookMethod('handleCustomerSubscriptionUpdated', $payload);

        $this->assertDatabaseHas('aircraft', [
            'id' => $aircraft->id,
            'status' => 'inactive',
            'billing_status' => 'pending_payment',
            'subscription_status' => 'incomplete',
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

    private function createProviderUserWithoutProvider(): Usuario
    {
        $user = Usuario::factory()->create([
            'role' => Usuario::ROLE_PROVIDER,
            'status' => 'active',
            'email' => sprintf('provider.orphan.%s@test.dev', uniqid()),
            'provider_id' => null,
        ]);
        $user->syncRoles([Usuario::ROLE_PROVIDER], Usuario::ROLE_PROVIDER);

        return $user->fresh();
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

    private function createAircraftBillingPayment(Proveedor $provider, Aeronave $aircraft, Plan $plan, array $overrides = []): AircraftBillingPayment
    {
        return AircraftBillingPayment::query()->create([
            'provider_id' => $provider->id,
            'aircraft_id' => $aircraft->id,
            'billing_plan_id' => $plan->id,
            'amount' => 100.00,
            'currency' => 'USD',
            'billing_period_start' => now()->startOfMonth()->toDateString(),
            'billing_period_end' => now()->endOfMonth()->toDateString(),
            'status' => 'pending',
            'provider' => 'stripe',
            ...$overrides,
        ]);
    }

    private function createAircraftSubscription(Usuario $user, Aeronave $aircraft, Plan $plan, array $overrides = []): SuscripcionAeronave
    {
        return SuscripcionAeronave::query()->create([
            'aircraft_id' => $aircraft->id,
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'status' => 'active',
            'payment_provider' => 'stripe',
            'payment_reference' => $overrides['provider_subscription_id'] ?? 'sub_test_default',
            'provider_checkout_id' => $overrides['provider_checkout_id'] ?? null,
            'provider_subscription_id' => $overrides['provider_subscription_id'] ?? null,
            'provider_customer_id' => $overrides['provider_customer_id'] ?? null,
            'provider_invoice_id' => $overrides['provider_invoice_id'] ?? null,
            'paid_at' => $overrides['paid_at'] ?? now(),
            'starts_at' => $overrides['starts_at'] ?? now()->startOfMonth(),
            'ends_at' => $overrides['ends_at'] ?? now()->endOfMonth(),
            'cancelled_at' => $overrides['cancelled_at'] ?? null,
        ]);
    }

    private function invokePrivateWebhookMethod(string $methodName, object $payload): mixed
    {
        $method = new ReflectionMethod(StripeWebhookControlador::class, $methodName);
        $method->setAccessible(true);

        return $method->invoke(new StripeWebhookControlador(), $payload);
    }
}
