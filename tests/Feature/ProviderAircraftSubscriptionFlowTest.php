<?php

namespace Tests\Feature;

use App\Modelos\Plan;
use App\Modelos\Proveedor;
use App\Modelos\TokenApi;
use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderAircraftSubscriptionFlowTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_pending_provider_cannot_register_aircraft_until_admin_approves(): void
    {
        $this->seed();

        [$user] = $this->createProviderUserContext('pending');

        $response = $this->withToken(TokenApi::issue($user))
            ->postJson('/api/v1/proveedor/aeronaves', $this->aircraftPayload());

        $response
            ->assertForbidden()
            ->assertJsonPath('message', 'Proveedor pendiente de validacion por Admin.');
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
            ->assertJsonPath('message', 'Aeronave registrada correctamente. Pendiente de activacion.')
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
}
