<?php

namespace Tests\Feature;

use App\Modelos\AccessPayment;
use App\Modelos\Plan;
use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminClientsSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_clients_endpoint_returns_lightweight_client_summary(): void
    {
        $this->seed();

        $client = Usuario::factory()->create([
            'name' => 'Cliente Demo',
            'email' => 'cliente.demo@test.dev',
            'role' => Usuario::ROLE_CLIENT,
            'status' => 'active',
            'access_status' => 'trial_active',
            'free_quote_limit' => 2,
            'free_quotes_used' => 1,
        ]);

        $client->profile()->create([
            'company_name' => 'Cliente Demo SA',
            'city' => 'CDMX',
            'base_airport' => 'MMMX',
        ]);

        $provider = Usuario::factory()->create([
            'email' => 'provider.demo@test.dev',
            'role' => Usuario::ROLE_PROVIDER,
            'status' => 'active',
        ]);

        $adminToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@privateflights.test',
            'password' => 'password',
        ])->assertOk()->json('token');

        $response = $this->withToken($adminToken)->getJson('/api/v1/admin/clientes');

        $response
            ->assertOk()
            ->assertJsonPath('success', true);

        $clients = collect($response->json('clients.data'));
        $payload = $clients->firstWhere('id', $client->id);

        $this->assertNotNull($payload);
        $this->assertNull($clients->firstWhere('id', $provider->id));
        $this->assertSame('Cliente Demo SA', data_get($payload, 'profile.company_name'));
        $this->assertSame(1, data_get($payload, 'commercial_access.free_quotes_used'));
        $this->assertArrayNotHasKey('roles', $payload);
        $this->assertArrayNotHasKey('active_suscripcion', $payload);
    }

    public function test_client_access_payments_returns_latest_payment_per_client(): void
    {
        $this->seed();

        $client = Usuario::factory()->create([
            'name' => 'Cliente Pago',
            'email' => 'cliente.pagos@test.dev',
            'role' => Usuario::ROLE_CLIENT,
            'status' => 'active',
            'access_status' => 'payment_pending',
        ]);

        $client->profile()->create([
            'company_name' => 'Cliente Pagos SA',
        ]);

        $secondClient = Usuario::factory()->create([
            'name' => 'Cliente Dos',
            'email' => 'cliente.dos@test.dev',
            'role' => Usuario::ROLE_CLIENT,
            'status' => 'active',
            'access_status' => 'active',
            'has_paid_access' => true,
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

        $olderPayment = AccessPayment::query()->create([
            'user_id' => $client->id,
            'billing_plan_id' => $plan->id,
            'amount' => 115,
            'currency' => 'USD',
            'status' => 'pending',
            'provider' => 'stripe',
            'provider_checkout_id' => 'cs_old_client',
        ]);

        $latestPayment = AccessPayment::query()->create([
            'user_id' => $client->id,
            'billing_plan_id' => $plan->id,
            'amount' => 122.59,
            'currency' => 'USD',
            'status' => 'paid',
            'provider' => 'stripe',
            'provider_checkout_id' => 'cs_latest_client',
            'paid_at' => now(),
            'gateway_response' => [
                'pricing' => [
                    'base_amount' => 115,
                    'stripe_fee' => 7.59,
                    'total_amount' => 122.59,
                ],
            ],
        ]);

        $secondClientPayment = AccessPayment::query()->create([
            'user_id' => $secondClient->id,
            'billing_plan_id' => $plan->id,
            'amount' => 122.59,
            'currency' => 'USD',
            'status' => 'paid',
            'provider' => 'stripe',
            'provider_checkout_id' => 'cs_second_client',
            'paid_at' => now(),
        ]);

        $adminToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@privateflights.test',
            'password' => 'password',
        ])->assertOk()->json('token');

        $response = $this->withToken($adminToken)->getJson('/api/v1/admin/client-access-payments');

        $response
            ->assertOk()
            ->assertJsonPath('success', true);

        $payments = collect($response->json('access_payments.data'));
        $clientPayments = $payments->where('user_id', $client->id)->values();

        $this->assertCount(1, $clientPayments);
        $this->assertSame($latestPayment->id, data_get($clientPayments->first(), 'id'));
        $this->assertNotSame($olderPayment->id, data_get($clientPayments->first(), 'id'));
        $this->assertSame('Cliente Pagos SA', data_get($clientPayments->first(), 'user.company_name'));
        $this->assertTrue($payments->contains(fn (array $payment) => (int) $payment['id'] === $secondClientPayment->id));
    }
}
