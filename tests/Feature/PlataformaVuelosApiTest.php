<?php

namespace Tests\Feature;

use App\Modelos\Aeronave;
use App\Modelos\CatalogoDisponibilidadEstatus;
use App\Modelos\IdentityVerification;
use App\Modelos\Operacion;
use App\Modelos\Proveedor;
use App\Modelos\SolicitudVuelo;
use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
            'password_confirmation' => 'password123',
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

    public function test_client_registration_persists_identity_documents_and_biometric_data(): void
    {
        Storage::fake('public');
        Storage::fake('private');

        $response = $this->post('/api/v1/auth/register', [
            'name' => 'Cliente Identidad',
            'email' => 'identidad@cliente.test',
            'phone' => '+52 555 010 1000',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'client',
            'birth_date' => '1991-04-25',
            'nationality' => 'Mexicana',
            'document_type' => 'INE',
            'document_number' => 'ABC123456789',
            'document_expiration' => '2030-12-31',
            'identity_validation_required' => '1',
            'ine_curp' => 'TEST910425HDFXXX01',
            'ine_cic' => '123456789',
            'ine_ocr' => '987654321',
            'ine_scan_raw' => 'LECTURA INE DE PRUEBA',
            'ine_scan_status' => 'scanned',
            'identity_verification_status' => 'approved',
            'identity_verification_message' => 'Rostro validado correctamente.',
            'identity_verified' => '1',
            'face_detected' => '1',
            'faces_count' => '1',
            'face_confidence' => '99.50',
            'face_match_score' => '99.50',
            'liveness_score' => '98.10',
            'image_storage_score' => '100',
            'biometric_image_saved' => '1',
            'biometric_captured_at' => now()->toISOString(),
            'biometric_provider' => 'aws_rekognition',
            'biometric_template_type' => 'selfie-photo',
            'quality_brightness' => '82.10',
            'quality_sharpness' => '88.40',
            'pose_yaw' => '3.10',
            'pose_pitch' => '2.20',
            'pose_roll' => '1.50',
            'face_occluded' => '0',
            'ine_front' => UploadedFile::fake()->image('ine-front.jpg'),
            'ine_back' => UploadedFile::fake()->image('ine-back.jpg'),
            'selfie_biometric' => UploadedFile::fake()->image('selfie.jpg'),
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user.email', 'identidad@cliente.test')
            ->assertJsonPath('user.identity_verification_status', 'approved')
            ->assertJsonPath('user.identity_verified', true);

        $user = Usuario::query()
            ->where('email', 'identidad@cliente.test')
            ->with('profile', 'identityVerifications')
            ->firstOrFail();

        $this->assertSame('INE', $user->profile?->document_type);
        $this->assertSame('ABC123456789', $user->profile?->document_number);
        $this->assertSame('TEST910425HDFXXX01', $user->profile?->ine_curp);
        $this->assertTrue((bool) $user->profile?->identity_validation_required);
        $this->assertNotNull($user->profile?->ine_front_path);
        $this->assertNotNull($user->profile?->ine_back_path);
        Storage::disk('private')->assertExists($user->profile->ine_front_path);
        Storage::disk('private')->assertExists($user->profile->ine_back_path);

        $verification = IdentityVerification::query()
            ->where('user_id', $user->id)
            ->first();

        $this->assertNotNull($verification);
        $this->assertSame('approved', $verification->status);
        $this->assertTrue($verification->identity_verified);
        $this->assertNotNull($verification->image_path);
        Storage::disk('public')->assertExists($verification->image_path);
    }

    public function test_red_aviation_client_flow_creates_blind_request_and_chat(): void
    {
        $this->seed();

        $register = $this->postJson('/api/v1/auth/register', [
            'name' => 'Cliente Red',
            'email' => 'cliente.red@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
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
            'password_confirmation' => 'password123',
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

    public function test_admin_can_fetch_detailed_user_record(): void
    {
        $this->seed();

        $adminLogin = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@privateflights.test',
            'password' => 'password',
        ])->assertOk();

        $adminToken = $adminLogin->json('token');

        $register = $this->postJson('/api/v1/auth/register', [
            'name' => 'Cliente Detalle',
            'email' => 'cliente.detalle@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'client',
        ])->assertCreated();

        $clientId = $register->json('user.id');

        $this->withToken($adminToken)
            ->getJson("/api/v1/admin/users/{$clientId}")
            ->assertOk()
            ->assertJsonPath('user.id', $clientId)
            ->assertJsonPath('user.email', 'cliente.detalle@test.com')
            ->assertJsonStructure([
                'success',
                'user' => [
                    'id',
                    'name',
                    'email',
                    'roles',
                    'profile',
                    'access',
                    'identity_verifications',
                ],
            ]);
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
            'password_confirmation' => 'password123',
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

    public function test_admin_requests_include_assigned_crew_from_operation(): void
    {
        $this->seed();

        $adminToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@privateflights.test',
            'password' => 'password',
        ])->assertOk()->json('token');

        $sobrecargo = Usuario::query()->where('email', 'sobrecargo@redaviation.test')->firstOrFail();
        $provider = Proveedor::query()->firstOrFail();
        $aircraft = Aeronave::query()->where('provider_id', $provider->id)->firstOrFail();
        $client = Usuario::query()->where('email', 'cliente@privateflights.test')->firstOrFail();

        $flightRequest = SolicitudVuelo::query()->create([
            'client_id' => $client->id,
            'assigned_provider_id' => $provider->id,
            'assigned_aircraft_id' => $aircraft->id,
            'assigned_aircraft_model' => $aircraft->model,
            'origin' => 'MMMX',
            'destination' => 'MMTO',
            'departure_datetime' => now()->addDay(),
            'passengers' => 2,
            'trip_type' => 'one_way',
            'status' => 'operador_asignado',
            'workflow_status' => 'tracking_live',
        ]);

        Operacion::query()->create([
            'flight_request_id' => $flightRequest->id,
            'provider_id' => $provider->id,
            'aircraft_id' => $aircraft->id,
            'sobrecargo_user_id' => $sobrecargo->id,
            'status' => 'operador_asignado',
            'crew_status' => 'pending_crew_response',
        ]);

        $response = $this->withToken($adminToken)
            ->getJson('/api/v1/admin/requests')
            ->assertOk();

        $requestPayload = collect($response->json('requests'))
            ->firstWhere('id', $flightRequest->id);

        $this->assertNotNull($requestPayload);
        $this->assertSame($sobrecargo->id, $requestPayload['crew_id']);
        $this->assertSame($sobrecargo->id, $requestPayload['sobrecargo_id']);
        $this->assertSame($sobrecargo->name, $requestPayload['crew_name']);
        $this->assertSame($sobrecargo->id, data_get($requestPayload, 'operation.sobrecargo_user_id'));
        $this->assertSame($sobrecargo->name, data_get($requestPayload, 'operation.sobrecargo.name'));
        $this->assertSame('pending_crew_response', $requestPayload['crew_status']);
        $this->assertIsArray(data_get($requestPayload, 'operation.timeline'));
    }

    public function test_admin_assign_promotes_flight_confirmed_request_to_tracking_live_when_crew_is_assigned(): void
    {
        $this->seed();

        $adminToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@privateflights.test',
            'password' => 'password',
        ])->assertOk()->json('token');

        $sobrecargo = Usuario::query()->where('email', 'sobrecargo@redaviation.test')->firstOrFail();
        $provider = Proveedor::query()->firstOrFail();
        $aircraft = Aeronave::query()->where('provider_id', $provider->id)->firstOrFail();
        $client = Usuario::query()->where('email', 'cliente@privateflights.test')->firstOrFail();

        $flightRequest = SolicitudVuelo::query()->create([
            'client_id' => $client->id,
            'assigned_provider_id' => $provider->id,
            'assigned_aircraft_id' => $aircraft->id,
            'assigned_aircraft_model' => $aircraft->model,
            'origin' => 'MMTO',
            'destination' => 'MMMY',
            'departure_datetime' => now()->addDay(),
            'passengers' => 1,
            'trip_type' => 'one_way',
            'status' => 'confirmada',
            'workflow_status' => 'flight_confirmed',
            'visibility_payload' => [
                'operational_status' => 'flight_confirmed',
                'operational_ready' => false,
            ],
        ]);

        $this->withToken($adminToken)
            ->postJson("/api/v1/admin/requests/{$flightRequest->id}/assign", [
                'provider_id' => $provider->id,
                'aircraft_id' => $aircraft->id,
                'sobrecargo_user_id' => $sobrecargo->id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('flight_requests', [
            'id' => $flightRequest->id,
            'workflow_status' => 'tracking_live',
        ]);

        $this->assertDatabaseHas('operations', [
            'flight_request_id' => $flightRequest->id,
            'sobrecargo_user_id' => $sobrecargo->id,
            'status' => 'tracking_live',
            'crew_status' => 'pending_crew_response',
        ]);

        $requestPayload = $this->withToken($adminToken)
            ->getJson('/api/v1/admin/requests')
            ->assertOk()
            ->json('requests');

        $matchedRequest = collect($requestPayload)->firstWhere('id', $flightRequest->id);

        $this->assertNotNull($matchedRequest);
        $this->assertSame('tracking_live', $matchedRequest['workflow_status']);
        $this->assertSame('tracking_live', data_get($matchedRequest, 'operation.status'));
        $this->assertFalse((bool) data_get($matchedRequest, 'operational_ready'));
    }

    public function test_admin_assign_with_crew_rejects_requests_before_flight_confirmed(): void
    {
        $this->seed();

        $adminToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@privateflights.test',
            'password' => 'password',
        ])->assertOk()->json('token');

        $sobrecargo = Usuario::query()->where('email', 'sobrecargo@redaviation.test')->firstOrFail();
        $provider = Proveedor::query()->firstOrFail();
        $aircraft = Aeronave::query()->where('provider_id', $provider->id)->firstOrFail();
        $client = Usuario::query()->where('email', 'cliente@privateflights.test')->firstOrFail();

        $flightRequest = SolicitudVuelo::query()->create([
            'client_id' => $client->id,
            'assigned_provider_id' => $provider->id,
            'assigned_aircraft_id' => $aircraft->id,
            'assigned_aircraft_model' => $aircraft->model,
            'origin' => 'MMTO',
            'destination' => 'MMMY',
            'departure_datetime' => now()->addDay(),
            'passengers' => 1,
            'trip_type' => 'one_way',
            'status' => 'confirmada',
            'workflow_status' => 'payment_confirmed',
            'visibility_payload' => [
                'operational_status' => 'payment_confirmed',
                'operational_ready' => false,
            ],
        ]);

        $this->withToken($adminToken)
            ->postJson("/api/v1/admin/requests/{$flightRequest->id}/assign", [
                'provider_id' => $provider->id,
                'aircraft_id' => $aircraft->id,
                'sobrecargo_user_id' => $sobrecargo->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sobrecargo_user_id']);

        $this->assertDatabaseMissing('operations', [
            'flight_request_id' => $flightRequest->id,
            'sobrecargo_user_id' => $sobrecargo->id,
            'status' => 'tracking_live',
        ]);
    }

    public function test_admin_workflow_endpoint_promotes_to_tracking_live_when_operation_already_has_crew(): void
    {
        $this->seed();

        $adminToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@privateflights.test',
            'password' => 'password',
        ])->assertOk()->json('token');

        $sobrecargo = Usuario::query()->where('email', 'sobrecargo@redaviation.test')->firstOrFail();
        $provider = Proveedor::query()->firstOrFail();
        $aircraft = Aeronave::query()->where('provider_id', $provider->id)->firstOrFail();
        $client = Usuario::query()->where('email', 'cliente@privateflights.test')->firstOrFail();

        $flightRequest = SolicitudVuelo::query()->create([
            'client_id' => $client->id,
            'assigned_provider_id' => $provider->id,
            'assigned_aircraft_id' => $aircraft->id,
            'assigned_aircraft_model' => $aircraft->model,
            'origin' => 'MMTO',
            'destination' => 'MMMY',
            'departure_datetime' => now()->addDay(),
            'passengers' => 1,
            'trip_type' => 'one_way',
            'status' => 'matched',
            'workflow_status' => 'flight_confirmed',
            'visibility_payload' => [
                'operational_status' => 'flight_confirmed',
                'operational_ready' => false,
            ],
        ]);

        Operacion::query()->create([
            'flight_request_id' => $flightRequest->id,
            'provider_id' => $provider->id,
            'aircraft_id' => $aircraft->id,
            'sobrecargo_user_id' => $sobrecargo->id,
            'status' => 'operador_asignado',
            'crew_status' => 'pending_crew_response',
        ]);

        $response = $this->withToken($adminToken)
            ->putJson("/api/v1/admin/requests/{$flightRequest->id}/workflow", [
                'workflow_status' => 'flight_confirmed',
                'payment_status' => 'Pagado',
                'contract_status' => 'signed',
                'notes' => 'Presentacion 15:00',
            ])
            ->assertOk();

        $this->assertSame('tracking_live', $response->json('request.workflow_status'));
        $this->assertSame('tracking_live', $response->json('request.operation.status'));

        $this->assertDatabaseHas('flight_requests', [
            'id' => $flightRequest->id,
            'workflow_status' => 'tracking_live',
        ]);

        $this->assertDatabaseHas('operations', [
            'flight_request_id' => $flightRequest->id,
            'sobrecargo_user_id' => $sobrecargo->id,
            'status' => 'tracking_live',
        ]);
    }

    public function test_admin_workflow_endpoint_rejects_tracking_live_without_assigned_crew(): void
    {
        $this->seed();

        $adminToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@privateflights.test',
            'password' => 'password',
        ])->assertOk()->json('token');

        $provider = Proveedor::query()->firstOrFail();
        $aircraft = Aeronave::query()->where('provider_id', $provider->id)->firstOrFail();
        $client = Usuario::query()->where('email', 'cliente@privateflights.test')->firstOrFail();

        $flightRequest = SolicitudVuelo::query()->create([
            'client_id' => $client->id,
            'assigned_provider_id' => $provider->id,
            'assigned_aircraft_id' => $aircraft->id,
            'assigned_aircraft_model' => $aircraft->model,
            'origin' => 'MMTO',
            'destination' => 'MMMY',
            'departure_datetime' => now()->addDay(),
            'passengers' => 1,
            'trip_type' => 'one_way',
            'status' => 'confirmada',
            'workflow_status' => 'flight_confirmed',
            'visibility_payload' => [
                'operational_status' => 'flight_confirmed',
                'operational_ready' => false,
            ],
        ]);

        Operacion::query()->create([
            'flight_request_id' => $flightRequest->id,
            'provider_id' => $provider->id,
            'aircraft_id' => $aircraft->id,
            'status' => 'confirmada',
        ]);

        $this->withToken($adminToken)
            ->putJson("/api/v1/admin/requests/{$flightRequest->id}/workflow", [
                'workflow_status' => 'tracking_live',
                'notes' => 'Intento sin sobrecargo',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['workflow_status']);
    }

    public function test_provider_release_promotes_operational_ready_only_to_flight_confirmed_without_assigned_crew(): void
    {
        $this->seed();

        $providerToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'proveedor@privateflights.test',
            'password' => 'password',
        ])->assertOk()->json('token');

        $providerUser = Usuario::query()->where('email', 'proveedor@privateflights.test')->firstOrFail();
        $provider = $providerUser->provider()->firstOrFail();
        $aircraft = Aeronave::query()->where('provider_id', $provider->id)->firstOrFail();
        $client = Usuario::query()->where('email', 'cliente@privateflights.test')->firstOrFail();

        $flightRequest = SolicitudVuelo::query()->create([
            'client_id' => $client->id,
            'assigned_provider_id' => $provider->id,
            'assigned_aircraft_id' => $aircraft->id,
            'assigned_aircraft_model' => $aircraft->model,
            'origin' => 'MMTO',
            'destination' => 'MMMY',
            'departure_datetime' => now()->addDay(),
            'passengers' => 1,
            'trip_type' => 'one_way',
            'status' => 'confirmada',
            'workflow_status' => 'payment_confirmed',
            'visibility_payload' => [
                'operational_status' => 'payment_confirmed',
                'operational_ready' => false,
            ],
        ]);

        $response = $this->withToken($providerToken)
            ->putJson("/api/v1/proveedor/solicitudes/{$flightRequest->id}/release-provider", [
                'provider_operational_release' => [
                    'status' => 'operational_ready',
                    'aircraft_id' => $aircraft->id,
                    'aircraft_label' => $aircraft->model,
                ],
                'operational_ready' => true,
            ])
            ->assertOk();

        $this->assertSame('flight_confirmed', $response->json('request.workflow_status'));
        $this->assertSame('confirmada', $response->json('operation.status'));

        $this->assertDatabaseHas('flight_requests', [
            'id' => $flightRequest->id,
            'workflow_status' => 'flight_confirmed',
        ]);
    }

    public function test_provider_release_rejects_tracking_live_without_assigned_crew(): void
    {
        $this->seed();

        $providerToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'proveedor@privateflights.test',
            'password' => 'password',
        ])->assertOk()->json('token');

        $providerUser = Usuario::query()->where('email', 'proveedor@privateflights.test')->firstOrFail();
        $provider = $providerUser->provider()->firstOrFail();
        $aircraft = Aeronave::query()->where('provider_id', $provider->id)->firstOrFail();
        $client = Usuario::query()->where('email', 'cliente@privateflights.test')->firstOrFail();

        $flightRequest = SolicitudVuelo::query()->create([
            'client_id' => $client->id,
            'assigned_provider_id' => $provider->id,
            'assigned_aircraft_id' => $aircraft->id,
            'assigned_aircraft_model' => $aircraft->model,
            'origin' => 'MMTO',
            'destination' => 'MMMY',
            'departure_datetime' => now()->addDay(),
            'passengers' => 1,
            'trip_type' => 'one_way',
            'status' => 'confirmada',
            'workflow_status' => 'flight_confirmed',
            'visibility_payload' => [
                'operational_status' => 'flight_confirmed',
                'operational_ready' => false,
            ],
        ]);

        $this->withToken($providerToken)
            ->putJson("/api/v1/proveedor/solicitudes/{$flightRequest->id}/release-provider", [
                'provider_operational_release' => [
                    'status' => 'operational_ready',
                    'aircraft_id' => $aircraft->id,
                    'aircraft_label' => $aircraft->model,
                ],
                'workflow_status' => 'tracking_live',
                'operational_ready' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['workflow_status']);
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
            'password_confirmation' => 'password123',
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

    public function test_sobrecargo_can_store_incident_in_backend_using_frontend_payload_shape(): void
    {
        $this->seed();

        $sobrecargo = Usuario::query()->where('email', 'sobrecargo@redaviation.test')->firstOrFail();
        $provider = Proveedor::query()->firstOrFail();
        $aircraft = Aeronave::query()->where('provider_id', $provider->id)->firstOrFail();
        $client = Usuario::query()->where('email', 'cliente@privateflights.test')->firstOrFail();

        $flightRequest = SolicitudVuelo::query()->create([
            'client_id' => $client->id,
            'assigned_provider_id' => $provider->id,
            'assigned_aircraft_id' => $aircraft->id,
            'assigned_aircraft_model' => $aircraft->model,
            'origin' => 'MMMX',
            'destination' => 'MMTO',
            'departure_datetime' => now()->addDay(),
            'passengers' => 4,
            'trip_type' => 'one_way',
            'status' => 'operador_asignado',
            'workflow_status' => 'operator_assigned',
        ]);

        $operation = Operacion::query()->create([
            'flight_request_id' => $flightRequest->id,
            'provider_id' => $provider->id,
            'aircraft_id' => $aircraft->id,
            'sobrecargo_user_id' => $sobrecargo->id,
            'status' => 'confirmada',
            'crew_status' => 'crew_confirmed',
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'sobrecargo@redaviation.test',
            'password' => 'password',
        ])->assertOk();

        $token = $login->json('token');

        $this->withToken($token)
            ->postJson('/api/v1/sobrecargo/incidents', [
                'operation_id' => $operation->id,
                'type' => 'Cabina',
                'flight' => 'OPS-'.$operation->id,
                'priority' => 'Alta',
                'phase' => 'En vuelo',
                'status' => 'Escalada',
                'comment' => 'Prueba de incidencia desde sobrecargo.',
                'description' => 'Prueba de incidencia desde sobrecargo.',
                'evidence' => 'cabina-test.jpg',
                'action_taken' => 'Escalado',
            ])
            ->assertCreated()
            ->assertJsonPath('incident.operation_id', $operation->id)
            ->assertJsonPath('incident.type', 'Cabina')
            ->assertJsonPath('incident.priority', 'Alta')
            ->assertJsonPath('incident.phase', 'En vuelo')
            ->assertJsonPath('incident.status', 'Escalada')
            ->assertJsonPath('incident.evidence', 'cabina-test.jpg')
            ->assertJsonPath('incident.action_taken', 'Escalado');

        $this->assertDatabaseHas('operation_timeline', [
            'operation_id' => $operation->id,
            'status' => 'incidencia',
            'title' => 'Cabina',
        ]);

        $this->assertDatabaseHas('operations', [
            'id' => $operation->id,
            'status' => 'incidencia',
            'crew_status' => 'crew_incident_reported',
        ]);
    }

    public function test_crew_operation_incident_is_reflected_for_assigned_provider_and_syncs_admin_status(): void
    {
        $this->seed();

        $providerUser = Usuario::query()->where('email', 'proveedor@privateflights.test')->firstOrFail();
        $provider = Proveedor::query()->findOrFail($providerUser->provider_id);
        $aircraft = Aeronave::query()->where('provider_id', $provider->id)->firstOrFail();
        $client = Usuario::query()->where('email', 'cliente@privateflights.test')->firstOrFail();
        $crew = Usuario::query()->where('email', 'sobrecargo@redaviation.test')->firstOrFail();

        $flightRequest = SolicitudVuelo::query()->create([
            'client_id' => $client->id,
            'assigned_provider_id' => $provider->id,
            'assigned_aircraft_id' => $aircraft->id,
            'assigned_aircraft_model' => $aircraft->model,
            'origin' => 'MMMX',
            'destination' => 'MMUN',
            'departure_datetime' => now()->addDays(2),
            'passengers' => 5,
            'trip_type' => 'one_way',
            'status' => 'operador_asignado',
            'workflow_status' => 'operator_assigned',
        ]);

        $operation = Operacion::query()->create([
            'flight_request_id' => $flightRequest->id,
            'provider_id' => $provider->id,
            'aircraft_id' => $aircraft->id,
            'sobrecargo_user_id' => $crew->id,
            'status' => 'confirmada',
            'crew_status' => 'crew_confirmed',
        ]);

        $crewToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'sobrecargo@redaviation.test',
            'password' => 'password',
        ])->assertOk()->json('token');

        $incidentResponse = $this->withToken($crewToken)
            ->postJson('/api/v1/crew-operation-incidents', [
                'crew_operation_id' => $operation->id,
                'crew_id' => $crew->id,
                'category' => 'cabina',
                'priority' => 'alta',
                'phase' => 'En vuelo',
                'description' => 'La cabina requiere coordinacion inmediata con el proveedor.',
            ])
            ->assertCreated()
            ->assertJsonPath('incident.crew_operation_id', $operation->id)
            ->assertJsonPath('incident.provider_id', $provider->id)
            ->assertJsonPath('incident.provider_name', $provider->commercial_name)
            ->assertJsonPath('incident.phase', 'En vuelo');

        $incidentId = $incidentResponse->json('incident.id');
        $providerTimelineId = $incidentResponse->json('incident.provider_timeline_id');

        $this->assertNotNull($providerTimelineId);

        $this->assertDatabaseHas('operation_timeline', [
            'id' => $providerTimelineId,
            'operation_id' => $operation->id,
            'status' => 'incidencia',
            'title' => 'Incidencia de sobrecargo · Cabina',
        ]);

        $providerToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'proveedor@privateflights.test',
            'password' => 'password',
        ])->assertOk()->json('token');

        $this->withToken($providerToken)
            ->getJson('/api/v1/proveedor/incidencias')
            ->assertOk()
            ->assertJsonPath('incidents.data.0.operation_id', $operation->id)
            ->assertJsonPath('incidents.data.0.source', 'Sobrecargo')
            ->assertJsonPath('incidents.data.0.crew_name', $crew->name)
            ->assertJsonPath('incidents.data.0.provider_id', $provider->id);

        $adminToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@privateflights.test',
            'password' => 'password',
        ])->assertOk()->json('token');

        $this->withToken($adminToken)
            ->putJson("/api/v1/crew-operation-incidents/{$incidentId}", [
                'status' => 'resolved',
                'admin_response' => 'Se notifico al proveedor y ya quedo atendido.',
            ])
            ->assertOk()
            ->assertJsonPath('incident.status', 'resolved')
            ->assertJsonPath('incident.admin_response', 'Se notifico al proveedor y ya quedo atendido.');

        $this->assertDatabaseHas('crew_operation_incidents', [
            'id' => $incidentId,
            'status' => 'resolved',
            'admin_response' => 'Se notifico al proveedor y ya quedo atendido.',
        ]);

        $this->assertDatabaseHas('operation_timeline', [
            'id' => $providerTimelineId,
            'status' => 'cerrada',
        ]);

        $timelineDescription = DB::table('operation_timeline')->where('id', $providerTimelineId)->value('description');
        $this->assertStringContainsString('Estado: Resuelta', (string) $timelineDescription);
        $this->assertStringContainsString('Respuesta Admin: Se notifico al proveedor y ya quedo atendido.', (string) $timelineDescription);

        $this->withToken($providerToken)
            ->getJson('/api/v1/proveedor/incidencias')
            ->assertOk()
            ->assertJsonPath('incidents.data.0.status', 'Resuelta');
    }

    public function test_sobrecargo_availability_is_persisted_in_normalized_tables(): void
    {
        $this->seed();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'sobrecargo@redaviation.test',
            'password' => 'password',
        ])->assertOk()->json('token');

        $availableStatus = CatalogoDisponibilidadEstatus::query()
            ->where('clave', 'DISPONIBLE')
            ->firstOrFail();

        $response = $this->withToken($token)
            ->postJson('/api/v1/sobrecargo/availability', [
                'from' => now()->addDays(2)->toDateString(),
                'to' => now()->addDays(3)->toDateString(),
                'estatus_id' => $availableStatus->id,
                'motivo' => 'Guardia activa',
                'comentario' => 'Disponible para asignacion.',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertCount(2, $response->json('availability'));

        $sobrecargo = Usuario::query()->where('email', 'sobrecargo@redaviation.test')->firstOrFail();

        $this->assertDatabaseHas('sobrecargo_disponibilidades', [
            'sobrecargo_id' => $sobrecargo->id,
            'estatus_id' => $availableStatus->id,
            'motivo' => 'Guardia activa',
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/sobrecargo/availability')
            ->assertOk()
            ->assertJsonPath('availability.0.clave', 'DISPONIBLE')
            ->assertJsonPath('availability.0.state', 'Disponible');
    }

    public function test_sobrecargo_can_fetch_status_catalog_and_delete_availability_record(): void
    {
        $this->seed();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'sobrecargo@redaviation.test',
            'password' => 'password',
        ])->assertOk()->json('token');

        $this->withToken($token)
            ->getJson('/api/v1/sobrecargo/availability/statuses')
            ->assertOk()
            ->assertJsonPath('statuses.0.clave', 'DISPONIBLE');

        $status = CatalogoDisponibilidadEstatus::query()
            ->where('clave', 'BLOQUEO_SOLICITADO')
            ->firstOrFail();

        $created = $this->withToken($token)
            ->postJson('/api/v1/sobrecargo/availability', [
                'fecha' => now()->addDays(5)->toDateString(),
                'status_key' => $status->clave,
                'notes' => 'Solicitud personal',
            ])
            ->assertCreated();

        $availabilityId = data_get($created->json('availability'), '0.id');

        $this->withToken($token)
            ->deleteJson("/api/v1/sobrecargo/availability/{$availabilityId}")
            ->assertOk()
            ->assertJsonPath('message', 'Disponibilidad eliminada correctamente.');

        $this->assertDatabaseMissing('sobrecargo_disponibilidades', [
            'id' => $availabilityId,
        ]);
    }

    public function test_sobrecargo_availability_range_fills_missing_days_as_por_confirmar(): void
    {
        $this->seed();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'sobrecargo@redaviation.test',
            'password' => 'password',
        ])->assertOk()->json('token');

        $status = CatalogoDisponibilidadEstatus::query()->where('clave', 'DESCANSO')->firstOrFail();
        $targetDate = now()->addDays(2)->toDateString();

        $this->withToken($token)
            ->postJson('/api/v1/sobrecargo/availability', [
                'fecha' => $targetDate,
                'estatus_id' => $status->id,
            ])
            ->assertCreated();

        $this->withToken($token)
            ->getJson('/api/v1/sobrecargo/availability?from='.$targetDate.'&to='.now()->addDays(4)->toDateString())
            ->assertOk()
            ->assertJsonPath('availability.0.clave', 'DESCANSO')
            ->assertJsonPath('availability.1.clave', 'POR_CONFIRMAR')
            ->assertJsonPath('availability.2.clave', 'POR_CONFIRMAR');
    }

    public function test_sobrecargo_can_store_batch_days_payload(): void
    {
        $this->seed();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'sobrecargo@redaviation.test',
            'password' => 'password',
        ])->assertOk()->json('token');

        $available = CatalogoDisponibilidadEstatus::query()->where('clave', 'DISPONIBLE')->firstOrFail();
        $rest = CatalogoDisponibilidadEstatus::query()->where('clave', 'DESCANSO')->firstOrFail();

        $this->withToken($token)
            ->postJson('/api/v1/sobrecargo/availability', [
                'dias' => [
                    [
                        'fecha' => now()->addDays(6)->toDateString(),
                        'estatus_id' => $available->id,
                        'comentario' => 'Guardia abierta',
                    ],
                    [
                        'fecha' => now()->addDays(7)->toDateString(),
                        'estatus_id' => $rest->id,
                        'comentario' => 'Descanso programado',
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'availability');
    }

    public function test_admin_can_manage_crew_availability_module(): void
    {
        $this->seed();

        $adminToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@privateflights.test',
            'password' => 'password',
        ])->assertOk()->json('token');

        $crew = Usuario::query()->where('email', 'sobrecargo@redaviation.test')->firstOrFail();
        $requestedStatus = CatalogoDisponibilidadEstatus::query()->where('clave', 'BLOQUEO_SOLICITADO')->firstOrFail();

        $createResponse = $this->withToken($adminToken)
            ->postJson('/api/v1/admin/sobrecargos/disponibilidad', [
                'sobrecargo_id' => $crew->id,
                'fecha' => now()->addDays(8)->toDateString(),
                'estatus_id' => $requestedStatus->id,
                'comentario' => 'Bloqueo cargado por admin',
            ])
            ->assertCreated();

        $availabilityId = data_get($createResponse->json('availability'), '0.id');

        $this->withToken($adminToken)
            ->getJson('/api/v1/admin/sobrecargos/disponibilidad?from='.now()->addDays(8)->toDateString().'&to='.now()->addDays(9)->toDateString())
            ->assertOk()
            ->assertJsonPath('crew_members.0.availability.0.clave', 'BLOQUEO_SOLICITADO')
            ->assertJsonPath('crew_members.0.availability.1.clave', 'POR_CONFIRMAR');

        $this->withToken($adminToken)
            ->postJson("/api/v1/admin/sobrecargos/disponibilidad/{$availabilityId}/aprobar-bloqueo", [
                'comentario' => 'Aprobado por operaciones',
            ])
            ->assertOk()
            ->assertJsonPath('availability.clave', 'BLOQUEO_APROBADO');

        $this->withToken($adminToken)
            ->getJson("/api/v1/admin/sobrecargos/disponibilidad/{$availabilityId}/bitacora")
            ->assertOk()
            ->assertJsonCount(2, 'bitacora');
    }
}
