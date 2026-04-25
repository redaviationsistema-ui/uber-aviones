<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlataformaVuelosApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_register_activate_demo_and_create_flight_request(): void
    {
        $this->seed();

        $register = $this->postJson('/api/v1/auth/register', [
            'name' => 'Nuevo Cliente',
            'email' => 'nuevo@cliente.test',
            'password' => 'password123',
            'role' => 'client',
        ])->assertCreated();

        $token = $register->json('token');

        $this->withToken($token)
            ->postJson('/api/v1/cliente/demo/activar')
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->withToken($token)
            ->postJson('/api/v1/cliente/solicitudes', [
                'origin' => 'MMMX',
                'destination' => 'MMUN',
                'departure_date' => now()->addDays(2)->format('Y-m-d'),
                'departure_time' => '10:30',
                'passengers' => 4,
                'trip_type' => 'one_way',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true);
    }
}
