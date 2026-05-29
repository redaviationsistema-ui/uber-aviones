<?php

namespace Tests\Feature;

use App\Modelos\Aeronave;
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

        $sessionToken = $register->json('token');

        $this->withToken($sessionToken)
            ->postJson('/api/v1/cliente/demo/activar')
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->withToken($sessionToken)
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

        $sessionToken = $register->json('token');

        $this->withToken($sessionToken)
            ->postJson('/api/v1/subscriptions/start-trial')
            ->assertCreated()
            ->assertJsonPath('subscription_status', 'demo_activa');

        $response = $this->withToken($sessionToken)
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

    public function test_admin_can_grant_trial_to_client_and_unlock_flight_requests(): void
    {
        $this->seed();

        $adminLogin = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@privateflights.test',
            'password' => 'password',
        ])->assertOk();

        $adminToken = $adminLogin->json('token');

        $register = $this->postJson('/api/v1/auth/register', [
            'name' => 'Cliente Bloqueado',
            'email' => 'cliente.bloqueado@test.com',
            'password' => 'password123',
            'role' => 'client',
        ])->assertCreated();

        $clientToken = $register->json('token');
        $clientId = $register->json('user.id');

        $this->withToken($clientToken)
            ->postJson('/api/v1/client/flight-requests', [
                'origin' => 'MMMX',
                'destination' => 'MMUN',
                'departure_datetime' => now()->addDays(3)->toISOString(),
                'passengers' => 4,
            ])
            ->assertStatus(402)
            ->assertJsonPath('message', 'Necesitas demo activa o suscripcion vigente.');

        $this->withToken($adminToken)
            ->postJson("/api/v1/admin/users/{$clientId}/grant-trial")
            ->assertCreated()
            ->assertJsonPath('message', 'Demo comercial activada correctamente.')
            ->assertJsonPath('access.has_access', true)
            ->assertJsonPath('access.demo.status', 'active');

        $this->withToken($clientToken)
            ->postJson('/api/v1/client/flight-requests', [
                'origin' => 'MMMX',
                'destination' => 'MMUN',
                'departure_datetime' => now()->addDays(3)->toISOString(),
                'passengers' => 4,
            ])
            ->assertCreated()
            ->assertJsonPath('success', true);
    }

    public function test_selected_provider_receives_request_in_provider_queue(): void
    {
        $this->seed();

        $aircraft = Aeronave::where('registration', 'XA-LJ45')->firstOrFail();

        $providerLogin = $this->postJson('/api/v1/auth/login', [
            'email' => 'proveedor@privateflights.test',
            'password' => 'password',
        ])->assertOk();

        $providerToken = $providerLogin->json('token');

        $register = $this->postJson('/api/v1/auth/register', [
            'name' => 'Cliente Cola',
            'email' => 'cliente.cola@test.com',
            'password' => 'password123',
            'role' => 'client',
        ])->assertCreated();

        $clientToken = $register->json('token');

        $this->withToken($clientToken)
            ->postJson('/api/v1/subscriptions/start-trial')
            ->assertCreated();

        $requestResponse = $this->withToken($clientToken)
            ->postJson('/api/v1/client/flight-requests', [
                'origin' => 'MMMX',
                'destination' => 'MMUN',
                'departure_datetime' => now()->addDays(3)->toISOString(),
                'passengers' => 4,
                'aircraft_type' => 'light_jet',
                'provider_id' => $aircraft->provider_id,
                'aircraft_id' => $aircraft->id,
                'final_price' => 15620,
                'total' => 15620,
                'estimated_total' => 15620,
                'selected_card_price' => 15620,
                'requirements' => ['wifi' => true],
            ])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $flightRequestId = $requestResponse->json('flight_request.id');

        $this->assertDatabaseHas('flight_requests', [
            'id' => $flightRequestId,
            'assigned_provider_id' => $aircraft->provider_id,
            'assigned_aircraft_id' => $aircraft->id,
            'final_price' => 15620,
        ]);

        $this->assertDatabaseHas('request_matches', [
            'flight_request_id' => $flightRequestId,
            'provider_id' => $aircraft->provider_id,
            'aircraft_id' => $aircraft->id,
            'estimated_price' => 15620,
            'status' => 'sent_to_provider',
        ]);

        $this->withToken($providerToken)
            ->getJson('/api/v1/proveedor/mis-solicitudes')
            ->assertOk()
            ->assertJsonPath('requests.0.id', $flightRequestId)
            ->assertJsonPath('requests.0.provider_id', $aircraft->provider_id)
            ->assertJsonPath('requests.0.aircraft_id', $aircraft->id)
            ->assertJsonPath('requests.0.workflow_status', 'operador_asignado')
            ->assertJsonPath('requests.0.contract_status', null)
            ->assertJsonPath('requests.0.payment_status', null)
            ->assertJsonPath('requests.0.reservation', null)
            ->assertJsonPath('requests.0.operation', null);
    }

    public function test_admin_can_list_requests_queue(): void
    {
        $this->seed();

        $adminLogin = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@privateflights.test',
            'password' => 'password',
        ])->assertOk();

        $adminToken = $adminLogin->json('token');

        $this->withToken($adminToken)
            ->getJson('/api/v1/admin/requests')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'requests' => [
                    '*' => [
                        'id',
                        'origin',
                        'destination',
                        'status',
                        'workflow_status',
                    ],
                ],
            ]);
    }

    public function test_preview_quote_applies_iva_only_to_taxable_flight_amount(): void
    {
        $this->seed();

        $aircraft = Aeronave::where('registration', 'XA-LJ45')->firstOrFail();
        $aircraft->update([
            'airport_expenses_usd' => 1000,
        ]);

        $response = $this->postJson('/api/v1/client/quotes/preview', [
            'origin' => 'MMMX',
            'destination' => 'MMUN',
            'departure_datetime' => now()->addDays(3)->toISOString(),
            'passengers' => 4,
            'trip_type' => 'one_way',
            'include_iva' => true,
            'airport_expenses' => true,
            'apply_margin' => false,
        ])->assertOk();

        $quote = collect($response->json('matches'))
            ->firstWhere('aircraft_id', $aircraft->id);

        $this->assertNotNull($quote);
        $this->assertSame(1000.0, (float) $quote['airport_expenses']);

        $subtotal = (float) $quote['subtotal'];
        $airportExpenses = (float) $quote['airport_expenses'];
        $taxableSubtotal = (float) $quote['pricing_breakdown']['taxable_subtotal'];
        $taxes = (float) $quote['taxes'];
        $total = (float) $quote['total'];

        $this->assertEquals(round($subtotal - $airportExpenses, 2), round($taxableSubtotal, 2));
        $this->assertEquals(round($taxableSubtotal * 0.16, 2), round($taxes, 2));
        $this->assertEquals(round($subtotal + $taxes, 2), round($total, 2));
    }

    public function test_multi_leg_preview_applies_minimum_hours_once_to_total_itinerary(): void
    {
        $this->seed();

        $aircraft = Aeronave::where('registration', 'XA-LJ45')->firstOrFail();

        $response = $this->postJson('/api/v1/client/quotes/preview', [
            'origin' => 'MMMX',
            'destination' => 'MMTO',
            'departure_datetime' => now()->addDays(3)->toISOString(),
            'passengers' => 4,
            'trip_type' => 'multi_leg',
            'include_iva' => false,
            'airport_expenses' => false,
            'apply_margin' => false,
            'requirements' => [
                [
                    'origin' => 'MMTO',
                    'destination' => 'MMMX',
                    'departure_datetime' => now()->addDays(3)->addHours(3)->toISOString(),
                ],
            ],
        ])->assertOk();

        $quote = collect($response->json('matches'))
            ->firstWhere('aircraft_id', $aircraft->id);

        $this->assertNotNull($quote);
        $this->assertSame('multi_leg', $quote['pricing_breakdown']['trip_type']);
        $this->assertSame(2.0, (float) $quote['pricing_breakdown']['minimum_hours']);
        $this->assertSame(2.0, (float) $quote['pricing_breakdown']['billable_hours']);
        $this->assertSame(10400.0, (float) $quote['pricing_breakdown']['client_flight_cost']);

        foreach ($quote['pricing_breakdown']['client_leg_pricing'] as $legPricing) {
            $this->assertSame(0.0, (float) $legPricing['minimum_hours']);
        }
    }

    public function test_multi_leg_preview_can_close_route_back_to_origin(): void
    {
        $this->seed();

        $response = $this->postJson('/api/v1/client/quotes/preview', [
            'origin' => 'MMMX',
            'destination' => 'MMTO',
            'departure_datetime' => now()->addDays(3)->toISOString(),
            'return_datetime' => now()->addDays(4)->toISOString(),
            'passengers' => 4,
            'trip_type' => 'multi_leg',
            'return_to_origin' => true,
            'requirements' => [
                [
                    'origin' => 'MMTO',
                    'destination' => 'MMSD',
                    'departure_datetime' => now()->addDays(3)->addHours(3)->toISOString(),
                ],
            ],
        ])->assertOk();

        $response->assertJsonPath('segment_count', 3);
        $response->assertJsonPath('legs.2.origin', 'MMSD');
        $response->assertJsonPath('legs.2.destination', 'MMMX');

        $quote = collect($response->json('matches'))->first();

        $this->assertNotNull($quote);
        $this->assertCount(3, $quote['pricing_breakdown']['client_leg_pricing']);
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

        $sessionToken = $register->json('token');

        $this->withToken($sessionToken)
            ->postJson('/api/v1/subscriptions/start-trial')
            ->assertCreated();

        $requestResponse = $this->withToken($sessionToken)
            ->postJson('/api/v1/client/flight-requests', [
                'origin' => 'MMMX',
                'destination' => 'MMUN',
                'departure_datetime' => now()->addDays(5)->toISOString(),
                'passengers' => 3,
            ])
            ->assertCreated();

        $chatId = $requestResponse->json('chat_id');

        $this->withToken($sessionToken)
            ->postJson("/api/v1/chats/{$chatId}/messages", [
                'message' => 'Escribeme por WhatsApp al +52 55 1234 5678 o correo test@example.com',
            ])
            ->assertCreated()
            ->assertJsonPath('message.has_blocked_content', true);

        $this->assertGreaterThanOrEqual(2, \App\Modelos\BanderaAntiBroker::count());
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
            ->assertJsonPath('login_context.dashboard', '/client/dashboard')
            ->assertCookie('red_aviation_session');

        $sobrecargoLogin = $this->postJson('/api/v1/auth/login', [
            'email' => 'sobrecargo@redaviation.test',
            'password' => 'password',
        ])->assertOk();

        $sobrecargoLogin
            ->assertJsonPath('login_context.effective_role', 'sobrecargo')
            ->assertJsonPath('login_context.dashboard', '/sobrecargo/dashboard')
            ->assertCookie('red_aviation_session');

        $adminLogin = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@privateflights.test',
            'password' => 'password',
        ])->assertOk();

        $adminLogin
            ->assertJsonPath('login_context.effective_role', 'admin')
            ->assertJsonPath('login_context.dashboard', '/admin/dashboard')
            ->assertCookie('red_aviation_session');
    }

    public function test_authenticated_user_can_request_redirect_dashboard(): void
    {
        $this->seed();

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'sobrecargo@redaviation.test',
            'password' => 'password',
        ])->assertOk();

        $sessionToken = $login->json('token');

        $this->withToken($sessionToken)
            ->getJson('/api/v1/auth/redirect-dashboard')
            ->assertOk()
            ->assertJsonPath('dashboard', '/sobrecargo/dashboard')
            ->assertJsonPath('login_context.effective_role', 'sobrecargo');
    }
}
