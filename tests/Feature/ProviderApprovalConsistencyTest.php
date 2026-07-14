<?php

namespace Tests\Feature;

use App\Modelos\Aeronave;
use App\Modelos\Plan;
use App\Modelos\Proveedor;
use App\Modelos\TokenApi;
use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderApprovalConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_scope_approved_for_operations_prefers_admin_status_and_falls_back_to_legacy_columns(): void
    {
        $this->seed();

        $approvedByAdmin = $this->createProvider([
            'approval_status' => 'pending',
            'admin_validation_status' => 'approved',
            'status' => 'pending',
        ]);

        $rejectedByAdmin = $this->createProvider([
            'approval_status' => 'approved',
            'admin_validation_status' => 'rejected',
            'status' => 'approved',
        ]);

        $approvedByLegacy = $this->createProvider([
            'approval_status' => 'approved',
            'admin_validation_status' => 'expediente_incompleto',
            'status' => 'pending',
        ]);

        $ids = Proveedor::query()
            ->approvedForOperations()
            ->pluck('id')
            ->all();

        $this->assertContains($approvedByAdmin->id, $ids);
        $this->assertContains($approvedByLegacy->id, $ids);
        $this->assertNotContains($rejectedByAdmin->id, $ids);
    }

    public function test_admin_can_activate_aircraft_when_provider_is_approved_in_admin_validation_status(): void
    {
        $this->seed();

        $adminToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@privateflights.test',
            'password' => 'password',
        ])->assertOk()->json('token');

        $provider = $this->createProvider([
            'approval_status' => 'pending',
            'admin_validation_status' => 'approved',
            'status' => 'pending',
        ]);

        $aircraft = $this->createAircraft($provider, [
            'status' => 'inactive',
            'billing_status' => 'pending_payment',
            'subscription_status' => 'inactive',
        ]);

        $this->withToken($adminToken)
            ->postJson("/api/v1/admin/aeronaves/{$aircraft->id}/activar")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('aircraft.status', 'inactive')
            ->assertJsonPath('aircraft.review_status', 'approved');

        $this->assertDatabaseHas('aircraft', [
            'id' => $aircraft->id,
            'status' => 'inactive',
        ]);

        $this->assertNotNull($aircraft->fresh()->approved_at);
    }

    public function test_admin_approval_activates_aircraft_immediately_when_billing_is_already_active(): void
    {
        $this->seed();

        $adminToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@privateflights.test',
            'password' => 'password',
        ])->assertOk()->json('token');

        $provider = $this->createProvider([
            'approval_status' => 'pending',
            'admin_validation_status' => 'approved',
            'status' => 'pending',
        ]);

        $aircraft = $this->createAircraft($provider, [
            'status' => 'inactive',
            'billing_status' => 'active',
            'subscription_status' => 'active',
        ]);

        $this->withToken($adminToken)
            ->postJson("/api/v1/admin/aeronaves/{$aircraft->id}/activar")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('aircraft.status', 'active')
            ->assertJsonPath('aircraft.review_status', 'approved');

        $this->assertNotNull($aircraft->fresh()->approved_at);
    }

    public function test_admin_aircraft_detail_exposes_approved_state_without_forcing_active_status(): void
    {
        $this->seed();

        $adminToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@privateflights.test',
            'password' => 'password',
        ])->assertOk()->json('token');

        $provider = $this->createProvider([
            'approval_status' => 'pending',
            'admin_validation_status' => 'approved',
            'status' => 'pending',
        ]);

        $aircraft = $this->createAircraft($provider, [
            'status' => 'inactive',
            'approved_at' => now(),
            'billing_status' => 'pending_payment',
            'subscription_status' => 'inactive',
        ]);

        $this->withToken($adminToken)
            ->getJson("/api/v1/admin/aeronaves/{$aircraft->id}")
            ->assertOk()
            ->assertJsonPath('aircraft.status', 'inactive')
            ->assertJsonPath('aircraft.approved', true)
            ->assertJsonPath('aircraft.review_status', 'approved')
            ->assertJsonPath('aircraft.billing_status', 'pending_payment');
    }

    public function test_operator_can_subscribe_aircraft_when_provider_is_approved_in_admin_validation_status(): void
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

        [$user, $provider] = $this->createProviderContext([
            'approval_status' => 'pending',
            'admin_validation_status' => 'approved',
            'status' => 'pending',
        ]);

        $aircraft = $this->createAircraft($provider, [
            'status' => 'active',
        ]);

        $this->withToken(TokenApi::issue($user))
            ->postJson("/api/v1/operator/aircraft/{$aircraft->id}/subscribe")
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('billing_status', 'pending_payment')
            ->assertJsonPath('aircraft.status', 'inactive');
    }

    private function createProvider(array $attributes = []): Proveedor
    {
        $user = Usuario::factory()->create([
            'role' => Usuario::ROLE_PROVIDER,
            'status' => 'active',
            'email' => sprintf('provider.%s@test.dev', uniqid()),
        ]);
        $user->syncRoles([Usuario::ROLE_PROVIDER], Usuario::ROLE_PROVIDER);

        return Proveedor::query()->create([
            'user_id' => $user->id,
            'company_name' => 'Proveedor Test '.uniqid(),
            'commercial_name' => 'Proveedor Test',
            'approval_status' => 'pending',
            ...$attributes,
        ]);
    }

    private function createProviderContext(array $providerAttributes = []): array
    {
        $provider = $this->createProvider($providerAttributes);
        $user = $provider->user;
        $user->forceFill(['provider_id' => $provider->id])->saveQuietly();

        return [$user->fresh(['provider']), $provider->fresh()];
    }

    private function createAircraft(Proveedor $provider, array $attributes = []): Aeronave
    {
        return Aeronave::query()->create([
            'provider_id' => $provider->id,
            'model' => 'Learjet 31A',
            'registration' => 'XA-'.strtoupper(substr(md5((string) microtime(true)), 0, 3)),
            'category' => 'Light Jet',
            'capacity' => 6,
            'base_airport' => 'MMMX',
            'range_km' => 2400,
            'speed_kmh' => 710,
            'hourly_rate' => 5000,
            'currency' => 'USD',
            'status' => 'inactive',
            ...$attributes,
        ]);
    }
}
