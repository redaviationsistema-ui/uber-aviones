<?php

namespace Tests\Feature;

use App\Modelos\Aeronave;
use App\Modelos\Proveedor;
use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOperatorsSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_operators_includes_embedded_aircraft_metrics(): void
    {
        $this->seed();

        $providerUser = Usuario::factory()->create([
            'role' => 'provider',
            'status' => 'active',
        ]);

        $provider = Proveedor::create([
            'user_id' => $providerUser->id,
            'approval_status' => 'approved',
            'company_name' => 'Proveedor Demo',
            'commercial_name' => 'Proveedor Demo',
        ]);

        $providerUser->profile()->create([
            'company_name' => 'Proveedor Demo',
            'base_airport' => 'MMMX',
        ]);

        Aeronave::create([
            'provider_id' => $provider->id,
            'model' => 'Citation Active',
            'category' => 'Light Jet',
            'registration' => 'XA-ACT',
            'capacity' => 6,
            'base_airport' => 'MMMX',
            'range_km' => 2500,
            'speed_kmh' => 700,
            'hourly_rate' => 4500,
            'currency' => 'USD',
            'status' => 'active',
        ]);
        Aeronave::create([
            'provider_id' => $provider->id,
            'model' => 'Citation Trial',
            'category' => 'Light Jet',
            'registration' => 'XA-TRL',
            'capacity' => 6,
            'base_airport' => 'MMMX',
            'range_km' => 2500,
            'speed_kmh' => 700,
            'hourly_rate' => 4500,
            'currency' => 'USD',
            'status' => 'trial_active',
        ]);
        Aeronave::create([
            'provider_id' => $provider->id,
            'model' => 'Citation Inactive',
            'category' => 'Light Jet',
            'registration' => 'XA-INA',
            'capacity' => 6,
            'base_airport' => 'MMMX',
            'range_km' => 2500,
            'speed_kmh' => 700,
            'hourly_rate' => 4500,
            'currency' => 'USD',
            'status' => 'inactive',
        ]);

        $adminToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@privateflights.test',
            'password' => 'password',
        ])->assertOk()->json('token');

        $response = $this->withToken($adminToken)->getJson('/api/v1/admin/operators');

        $response
            ->assertOk()
            ->assertJsonPath('success', true);

        $payload = collect($response->json('operators.data'))
            ->firstWhere('id', $provider->id);

        $this->assertNotNull($payload);
        $this->assertSame('MMMX', $payload['base_airport']);
        $this->assertSame($providerUser->name, $payload['contact_name']);
        $this->assertSame(3, data_get($payload, 'aircraft_metrics.aircraft'));
        $this->assertSame(1, data_get($payload, 'aircraft_metrics.active'));
        $this->assertSame(1, data_get($payload, 'aircraft_metrics.trial'));
        $this->assertSame(2, data_get($payload, 'aircraft_metrics.pending'));
    }
}
