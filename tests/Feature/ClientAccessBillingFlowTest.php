<?php

namespace Tests\Feature;

use App\Http\Controladores\StripeWebhookControlador;
use App\Modelos\AccessPayment;
use App\Modelos\Plan;
use App\Modelos\TokenApi;
use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use ReflectionMethod;
use Mockery;
use Tests\TestCase;

class ClientAccessBillingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_paid_checkout_session_activates_client_commercial_access(): void
    {
        $this->seed();

        $user = Usuario::query()->create([
            'name' => 'Cliente Pago',
            'email' => 'cliente.pago@test.dev',
            'password' => Hash::make('password123'),
            'role' => Usuario::ROLE_CLIENT,
            'status' => 'active',
            'access_status' => 'payment_pending',
            'has_paid_access' => false,
            'free_quote_limit' => 1,
            'free_quotes_used' => 1,
        ]);

        $plan = Plan::query()->updateOrCreate(
            ['code' => 'client_access_monthly'],
            [
                'name' => 'Acceso comercial cliente',
                'slug' => 'client-access-monthly',
                'description' => 'Acceso comercial mensual.',
                'amount' => 115,
                'price' => 115,
                'price_monthly' => 115,
                'currency' => 'USD',
                'billing_type' => 'client_access',
                'interval_type' => 'monthly',
                'billing_cycle' => 'monthly',
                'role_target' => 'client',
                'user_type' => 'client',
                'status' => 'active',
                'is_active' => true,
            ],
        );

        $payment = AccessPayment::query()->create([
            'user_id' => $user->id,
            'billing_plan_id' => $plan->id,
            'amount' => 122.59,
            'currency' => 'USD',
            'status' => 'pending',
            'provider' => 'stripe',
            'provider_checkout_id' => 'cs_test_access_paid',
            'billing_period_start' => now()->toDateString(),
            'billing_period_end' => now()->addMonthNoOverflow()->toDateString(),
        ]);

        $session = (object) [
            'id' => 'cs_test_access_paid',
            'customer' => 'cus_test_123',
            'subscription' => 'sub_test_123',
            'payment_status' => 'paid',
            'status' => 'complete',
            'invoice' => 'in_test_123',
            'payment_intent' => 'pi_test_123',
            'amount_total' => 12259,
            'currency' => 'usd',
        ];

        $method = new ReflectionMethod(
            StripeWebhookControlador::class,
            'handleClientAccessSubscriptionCheckoutCompleted',
        );
        $method->setAccessible(true);
        $method->invoke(new StripeWebhookControlador(), $session, [
            'billing_context' => 'client_access_subscription',
            'access_payment_id' => (string) $payment->id,
            'user_id' => (string) $user->id,
            'billing_plan_id' => (string) $plan->id,
        ]);

        $this->assertDatabaseHas('access_payments', [
            'id' => $payment->id,
            'status' => 'paid',
            'provider_checkout_id' => 'cs_test_access_paid',
            'provider_subscription_id' => 'sub_test_123',
            'provider_customer_id' => 'cus_test_123',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'access_status' => 'active',
            'has_paid_access' => true,
            'provider_subscription_id' => 'sub_test_123',
            'provider_customer_id' => 'cus_test_123',
            'access_payment_id' => $payment->id,
        ]);
    }

    public function test_create_access_checkout_uses_authenticated_user_email_when_contact_email_is_missing(): void
    {
        $this->seed();
        config()->set('services.stripe.secret', 'sk_test_client_access');
        config()->set('services.stripe.publishable', 'pk_test_client_access');
        config()->set('services.stripe.frontend_url', 'https://frontend.test');

        $user = Usuario::query()->create([
            'name' => 'Cliente Acceso',
            'email' => 'cliente.acceso@gmail.com',
            'password' => Hash::make('password123'),
            'role' => Usuario::ROLE_CLIENT,
            'status' => 'active',
            'access_status' => 'trial_used',
            'has_paid_access' => false,
            'free_quote_limit' => 1,
            'free_quotes_used' => 1,
        ]);

        Plan::query()->updateOrCreate(
            ['code' => 'client_access_monthly'],
            [
                'name' => 'Acceso comercial cliente',
                'slug' => 'client-access-monthly',
                'description' => 'Acceso comercial mensual.',
                'amount' => 115,
                'price' => 115,
                'price_monthly' => 115,
                'currency' => 'USD',
                'billing_type' => 'client_access',
                'interval_type' => 'monthly',
                'billing_cycle' => 'monthly',
                'role_target' => 'client',
                'user_type' => 'client',
                'status' => 'active',
                'is_active' => true,
            ],
        );

        $token = TokenApi::issue($user, 'client-access-checkout-token');

        $sessionAlias = Mockery::mock('alias:Stripe\Checkout\Session');
        $sessionAlias
            ->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function (array $payload) use ($user): bool {
                return ($payload['customer_email'] ?? null) === $user->email;
            }))
            ->andReturn((object) [
                'id' => 'cs_test_access_create',
                'url' => 'https://checkout.stripe.com/pay/cs_test_access_create',
            ]);

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/client/access-payment/create', [
                'success_url' => 'https://frontend.test/success',
                'cancel_url' => 'https://frontend.test/cancel',
                'return_url' => 'https://frontend.test/renta/cliente/pago',
            ])
            ->assertCreated()
            ->assertJsonPath('checkout_session_id', 'cs_test_access_create')
            ->assertJsonPath('checkout_url', 'https://checkout.stripe.com/pay/cs_test_access_create');
    }

    public function test_checkout_intent_does_not_return_billing_portal_for_active_access(): void
    {
        $this->seed();
        config()->set('services.stripe.secret', 'sk_test_client_access');
        config()->set('services.stripe.publishable', 'pk_test_client_access');
        config()->set('services.stripe.frontend_url', 'https://frontend.test');

        $user = Usuario::query()->create([
            'name' => 'Cliente Activo',
            'email' => 'cliente.activo@test.dev',
            'password' => Hash::make('password123'),
            'role' => Usuario::ROLE_CLIENT,
            'status' => 'active',
        ]);
        $user->forceFill([
            'access_status' => 'active',
            'has_paid_access' => true,
            'provider_customer_id' => 'cus_test_active',
            'access_expires_at' => now()->addDays(15),
        ])->save();

        $token = TokenApi::issue($user, 'client-access-active-token');

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/client/access-payment/create', [
                'intent' => 'checkout',
                'success_url' => 'https://frontend.test/success',
                'cancel_url' => 'https://frontend.test/cancel',
                'return_url' => 'https://frontend.test/cliente/perfil',
            ])
            ->assertOk()
            ->assertJsonPath('already_active', true)
            ->assertJsonMissingPath('management_url')
            ->assertJsonMissingPath('checkout_url');
    }

    public function test_me_and_access_status_prioritize_user_access_expiry_as_source_of_truth(): void
    {
        $this->seed();

        $user = Usuario::query()->create([
            'name' => 'Cliente Snapshot',
            'email' => 'cliente.snapshot@test.dev',
            'password' => Hash::make('password123'),
            'role' => Usuario::ROLE_CLIENT,
            'status' => 'active',
        ]);
        $user->forceFill([
            'access_status' => 'active',
            'has_paid_access' => true,
            'access_expires_at' => now()->subDay(),
        ])->save();

        $plan = Plan::query()->updateOrCreate(
            ['code' => 'client_access_monthly'],
            [
                'name' => 'Acceso comercial cliente',
                'slug' => 'client-access-monthly',
                'description' => 'Acceso comercial mensual.',
                'amount' => 115,
                'price' => 115,
                'price_monthly' => 115,
                'currency' => 'USD',
                'billing_type' => 'client_access',
                'interval_type' => 'monthly',
                'billing_cycle' => 'monthly',
                'role_target' => 'client',
                'user_type' => 'client',
                'status' => 'active',
                'is_active' => true,
            ],
        );

        $payment = AccessPayment::query()->create([
            'user_id' => $user->id,
            'billing_plan_id' => $plan->id,
            'amount' => 122.59,
            'currency' => 'USD',
            'status' => 'paid',
            'provider' => 'stripe',
            'provider_checkout_id' => 'cs_test_latest_snapshot',
            'billing_period_start' => now()->toDateString(),
            'billing_period_end' => now()->addMonthNoOverflow()->toDateString(),
            'paid_at' => now(),
        ]);

        $token = TokenApi::issue($user, 'client-access-snapshot-token');

        $meResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $meResponse
            ->assertJsonPath('access.commercial_access.status', 'expired')
            ->assertJsonPath('access.commercial_access.access_is_expired', true)
            ->assertJsonPath('access.commercial_access.available_actions.can_quote', false)
            ->assertJsonPath('access.commercial_access.latest_payment.id', $payment->id);

        $this->assertStringStartsWith(
            now()->addMonthNoOverflow()->toDateString(),
            (string) data_get($meResponse->json(), 'access.commercial_access.billing_period_end')
        );
        $this->assertStringStartsWith(
            now()->subDay()->toDateString(),
            (string) data_get($meResponse->json(), 'access.commercial_access.access_expires_at')
        );

        $statusResponse = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/client/access-status')
            ->assertOk();

        $statusResponse
            ->assertJsonPath('access.status', 'expired')
            ->assertJsonPath('access.access_is_expired', true)
            ->assertJsonPath('access.available_actions.can_quote', false)
            ->assertJsonPath('access.latest_payment.id', $payment->id);

        $this->assertStringStartsWith(
            now()->addMonthNoOverflow()->toDateString(),
            (string) data_get($statusResponse->json(), 'access.billing_period_end')
        );
    }

    public function test_manage_intent_returns_billing_portal_for_manageable_subscription(): void
    {
        $this->seed();
        config()->set('services.stripe.secret', 'sk_test_client_access');
        config()->set('services.stripe.publishable', 'pk_test_client_access');
        config()->set('services.stripe.frontend_url', 'https://frontend.test');

        $user = Usuario::query()->create([
            'name' => 'Cliente Facturacion',
            'email' => 'cliente.facturacion@test.dev',
            'password' => Hash::make('password123'),
            'role' => Usuario::ROLE_CLIENT,
            'status' => 'active',
        ]);
        $user->forceFill([
            'access_status' => 'past_due',
            'has_paid_access' => true,
            'provider_customer_id' => 'cus_test_manage',
            'grace_period_ends_at' => now()->addDays(3),
        ])->save();

        $token = TokenApi::issue($user, 'client-access-manage-token');

        $portalAlias = Mockery::mock('alias:Stripe\BillingPortal\Session');
        $portalAlias
            ->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function (array $payload) use ($user): bool {
                return ($payload['customer'] ?? null) === $user->provider_customer_id
                    && ($payload['return_url'] ?? null) === 'https://frontend.test/cliente/perfil';
            }))
            ->andReturn((object) [
                'id' => 'bps_test_manage',
                'url' => 'https://billing.stripe.com/session/test_manage',
            ]);

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/client/access-payment/create', [
                'intent' => 'manage',
                'return_url' => 'https://frontend.test/cliente/perfil',
            ])
            ->assertOk()
            ->assertJsonPath('management_url', 'https://billing.stripe.com/session/test_manage')
            ->assertJsonMissingPath('checkout_url');
    }
}
