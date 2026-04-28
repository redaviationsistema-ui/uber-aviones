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

    public function test_red_aviation_client_flow_creates_blind_request_and_chat(): void
    {
        $this->seed();

        $register = $this->postJson('/api/v1/auth/register', [
            'name' => 'Cliente Red',
            'email' => 'cliente.red@test.com',
            'password' => 'password123',
            'role' => 'client',
        ])->assertCreated();

        $token = $register->json('token');

        $this->withToken($token)
            ->postJson('/api/v1/subscriptions/start-trial')
            ->assertCreated()
            ->assertJsonPath('subscription_status', 'demo_activa');

        $response = $this->withToken($token)
            ->postJson('/api/v1/client/flight-requests', [
                'origin' => 'MMMX',
                'destination' => 'MMUN',
                'departure_datetime' => now()->addDays(3)->toISOString(),
                'passengers' => 4,
                'aircraft_type' => 'light_jet',
                'requirements' => ['wifi' => true],
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('flight_request.status', 'operador_asignado');

        $this->assertNotNull($response->json('chat_id'));
    }

    public function test_red_aviation_chat_blocks_contact_leaks(): void
    {
        $this->seed();

        $register = $this->postJson('/api/v1/auth/register', [
            'name' => 'Cliente Protegido',
            'email' => 'cliente.protegido@test.com',
            'password' => 'password123',
            'role' => 'client',
        ])->assertCreated();

        $token = $register->json('token');

        $this->withToken($token)
            ->postJson('/api/v1/subscriptions/start-trial')
            ->assertCreated();

        $requestResponse = $this->withToken($token)
            ->postJson('/api/v1/client/flight-requests', [
                'origin' => 'MMMX',
                'destination' => 'MMUN',
                'departure_datetime' => now()->addDays(5)->toISOString(),
                'passengers' => 3,
            ])
            ->assertCreated();

        $chatId = $requestResponse->json('chat_id');

        $this->withToken($token)
            ->postJson("/api/v1/chats/{$chatId}/messages", [
                'message' => 'Escribeme por WhatsApp al +52 55 1234 5678 o correo test@example.com',
            ])
            ->assertCreated()
            ->assertJsonPath('message.has_blocked_content', true);

        $this->assertDatabaseCount('anti_broker_flags', 2);
    }

    public function test_login_returns_dashboard_and_effective_role_context(): void
    {
        $this->seed();

        $clientLogin = $this->postJson('/api/v1/auth/login', [
            'email' => 'cliente@privateflights.test',
            'password' => 'password',
        ])->assertOk();

        $clientLogin
            ->assertJsonPath('login_context.effective_role', 'client')
            ->assertJsonPath('login_context.dashboard', '/client/dashboard');

        $sobrecargoLogin = $this->postJson('/api/v1/auth/login', [
            'email' => 'sobrecargo@redaviation.test',
            'password' => 'password',
        ])->assertOk();

        $sobrecargoLogin
            ->assertJsonPath('login_context.effective_role', 'sobrecargo')
            ->assertJsonPath('login_context.dashboard', '/sobrecargo/dashboard');

        $adminLogin = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@privateflights.test',
            'password' => 'password',
        ])->assertOk();

        $adminLogin
            ->assertJsonPath('login_context.effective_role', 'admin')
            ->assertJsonPath('login_context.dashboard', '/admin/dashboard');
    }

    public function test_authenticated_user_can_request_redirect_dashboard(): void
    {
        $this->seed();

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'sobrecargo@redaviation.test',
            'password' => 'password',
        ])->assertOk();

        $token = $login->json('token');

        $this->withToken($token)
            ->getJson('/api/v1/auth/redirect-dashboard')
            ->assertOk()
            ->assertJsonPath('dashboard', '/sobrecargo/dashboard')
            ->assertJsonPath('login_context.effective_role', 'sobrecargo');
    }
}
