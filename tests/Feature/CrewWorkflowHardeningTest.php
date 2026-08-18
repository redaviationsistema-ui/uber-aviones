<?php

namespace Tests\Feature;

use App\Dominio\Sobrecargo\CrewAssignmentStatus;
use App\Modelos\Aeronave;
use App\Modelos\AsignacionSobrecargo;
use App\Modelos\Notificacion;
use App\Modelos\Operacion;
use App\Modelos\Proveedor;
use App\Modelos\RegistroAuditoria;
use App\Modelos\SolicitudVuelo;
use App\Modelos\Usuario;
use App\Servicios\Sobrecargo\CrewOperationalNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CrewWorkflowHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_assignment_cannot_be_accepted_and_double_response_conflicts(): void
    {
        [$operation, $crew, $token] = $this->crewOperation();
        AsignacionSobrecargo::create([
            'operation_id' => $operation->id, 'sobrecargo_user_id' => $crew->id,
            'status' => CrewAssignmentStatus::PENDING_CONFIRMATION, 'response_deadline' => now()->subMinute(),
        ]);

        $this->withToken($token)->postJson("/api/v1/sobrecargo/operations/{$operation->id}/respond", ['response' => 'confirmed'])->assertStatus(409);
        AsignacionSobrecargo::where('operation_id', $operation->id)->update(['response_deadline' => now()->addHour()]);
        $this->withToken($token)->postJson("/api/v1/sobrecargo/operations/{$operation->id}/respond", ['response' => 'confirmed'])->assertOk();
        $this->withToken($token)->postJson("/api/v1/sobrecargo/operations/{$operation->id}/respond", ['response' => 'rejected', 'reject_reason' => 'personal'])->assertStatus(409);
    }

    public function test_crew_cannot_access_another_crews_workflow_or_incident(): void
    {
        [$operation, $owner] = $this->crewOperation();
        $other = Usuario::factory()->create(['email' => 'other.crew@test.com', 'role' => Usuario::ROLE_SOBRECARGO, 'operational_role' => Usuario::ROLE_SOBRECARGO]);
        $token = $this->postJson('/api/v1/auth/login', ['email' => $other->email, 'password' => 'password'])->assertOk()->json('token');

        $this->withToken($token)->getJson("/api/v1/sobrecargo/operations/{$operation->id}/workflow")->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/crew-operation-incidents', [
            'crew_operation_id' => $operation->id, 'crew_id' => $owner->id,
            'category' => 'seguridad', 'priority' => 'alta', 'description' => 'Intento ajeno',
        ])->assertForbidden();
    }

    public function test_provider_cannot_use_global_incident_listing_and_executables_are_rejected(): void
    {
        [$operation, $crew, $crewToken] = $this->crewOperation();
        $providerToken = $this->postJson('/api/v1/auth/login', ['email' => 'proveedor@privateflights.test', 'password' => 'password'])->assertOk()->json('token');
        $this->withToken($providerToken)->getJson('/api/v1/crew-operation-incidents')->assertForbidden();

        Storage::fake('s3');
        $this->withToken($crewToken)->post('/api/v1/crew-operation-incidents', [
            'crew_operation_id' => $operation->id, 'crew_id' => $crew->id,
            'category' => 'seguridad', 'priority' => 'alta', 'description' => 'Archivo no permitido',
            'files' => [UploadedFile::fake()->create('payload.php', 10, 'application/x-php')],
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_incident_does_not_replace_operational_state(): void
    {
        [$operation, $crew, $token] = $this->crewOperation(CrewAssignmentStatus::IN_FLIGHT);
        $this->withToken($token)->postJson('/api/v1/crew-operation-incidents', [
            'crew_operation_id' => $operation->id, 'crew_id' => $crew->id,
            'category' => 'cabina', 'priority' => 'media', 'phase' => 'En vuelo', 'description' => 'Prueba de estado',
        ])->assertCreated();
        $this->assertSame(CrewAssignmentStatus::IN_FLIGHT, $operation->fresh()->crew_status);
    }

    public function test_acceptance_creates_one_idempotent_notification_and_complete_audit(): void
    {
        [$operation, $crew, $token] = $this->crewOperation();
        $assignment = AsignacionSobrecargo::create([
            'operation_id' => $operation->id, 'sobrecargo_user_id' => $crew->id,
            'status' => CrewAssignmentStatus::PENDING_CONFIRMATION, 'response_deadline' => now()->addHour(),
        ]);

        $response = $this->withHeaders(['User-Agent' => 'CrewConcurrencyTest/1.0', 'X-Request-Id' => 'crew-accept-1'])
            ->withToken($token)->postJson("/api/v1/sobrecargo/operations/{$operation->id}/respond", ['response' => 'confirmed']);
        $response->assertOk();
        $this->withToken($token)->postJson("/api/v1/sobrecargo/operations/{$operation->id}/respond", ['response' => 'confirmed'])->assertStatus(409);

        $this->assertSame(1, Notificacion::query()->where('type', 'assignment_accepted')->where('payload->operation_id', $operation->id)->count());
        $audit = RegistroAuditoria::query()->where('action', 'assignment_accepted')->where('entity_id', (string) $operation->id)->firstOrFail();
        $this->assertSame(CrewAssignmentStatus::PENDING_CONFIRMATION, data_get($audit->before, 'status'));
        $this->assertSame(CrewAssignmentStatus::CONFIRMED, data_get($audit->after, 'status'));
        $this->assertSame($assignment->id, data_get($audit->metadata, 'assignment_id'));
        $this->assertSame('CrewConcurrencyTest/1.0', $audit->user_agent);
        $this->assertNotNull($audit->ip_address);
    }

    public function test_expired_session_never_mutates_assignment(): void
    {
        [$operation, $crew] = $this->crewOperation();
        $assignment = AsignacionSobrecargo::create([
            'operation_id' => $operation->id, 'sobrecargo_user_id' => $crew->id,
            'status' => CrewAssignmentStatus::PENDING_CONFIRMATION, 'response_deadline' => now()->addHour(),
        ]);

        $this->postJson("/api/v1/sobrecargo/operations/{$operation->id}/respond", ['response' => 'confirmed'])->assertUnauthorized();
        $this->assertSame(CrewAssignmentStatus::PENDING_CONFIRMATION, $assignment->fresh()->status);
        $this->assertNull($assignment->fresh()->accepted_at);
    }

    public function test_notification_endpoints_are_paginated_and_protected_against_idor(): void
    {
        [$operation, $crew, $token] = $this->crewOperation();
        $other = Usuario::factory()->create(['role' => Usuario::ROLE_SOBRECARGO, 'operational_role' => Usuario::ROLE_SOBRECARGO]);
        $own = Notificacion::create(['user_id' => $crew->id, 'type' => 'new_assignment', 'title' => 'Propia', 'message' => 'Mensaje']);
        $foreign = Notificacion::create(['user_id' => $other->id, 'type' => 'new_assignment', 'title' => 'Ajena', 'message' => 'Mensaje']);

        $this->withToken($token)->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('unread_count', 1)->assertJsonCount(1, 'notifications.data');
        $this->withToken($token)->patchJson("/api/v1/notifications/{$foreign->id}/read")->assertForbidden();
        $this->assertNull($foreign->fresh()->read_at);
        $this->withToken($token)->patchJson("/api/v1/notifications/{$own->id}/read")->assertOk();
        $this->assertNotNull($own->fresh()->read_at);
    }

    public function test_metrics_return_real_denominators_and_null_for_an_empty_period(): void
    {
        [$operation, $crew] = $this->crewOperation();
        $admin = Usuario::where('email', 'admin@privateflights.test')->firstOrFail();
        $adminToken = $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password'])->assertOk()->json('token');
        AsignacionSobrecargo::create(['operation_id' => $operation->id, 'sobrecargo_user_id' => $crew->id, 'status' => CrewAssignmentStatus::CONFIRMED, 'assigned_at' => now(), 'accepted_at' => now()]);
        $response = $this->withToken($adminToken)->getJson('/api/v1/admin/crew/metrics?period=month')->assertOk();
        $response->assertJsonPath('metrics.response.acceptance_rate.numerator', 1)
            ->assertJsonPath('metrics.response.acceptance_rate.denominator', 1)
            ->assertJsonPath('metrics.response.acceptance_rate.percentage', 100);

        $empty = $this->withToken($adminToken)->getJson('/api/v1/admin/crew/metrics?period=custom&from=2035-01-01&to=2035-01-02')->assertOk();
        $empty->assertJsonPath('metrics.response.acceptance_rate.denominator', 0)
            ->assertJsonPath('metrics.response.acceptance_rate.percentage', null);
    }

    public function test_crew_dashboard_and_assignments_only_use_real_current_assignment_records(): void
    {
        [$operation, $crew, $token] = $this->crewOperation();
        AsignacionSobrecargo::create([
            'operation_id' => $operation->id,
            'sobrecargo_user_id' => $crew->id,
            'status' => CrewAssignmentStatus::PENDING_CONFIRMATION,
            'assigned_at' => now(),
            'response_deadline' => now()->addHour(),
        ]);

        $orphanFlight = SolicitudVuelo::create([
            'client_id' => Usuario::where('email', 'cliente@privateflights.test')->firstOrFail()->id,
            'origin' => 'MMGL',
            'destination' => 'MMTO',
            'departure_datetime' => now()->addDays(3),
            'return_datetime' => now()->addDays(4),
            'passengers' => 2,
            'trip_type' => 'one_way',
            'status' => 'confirmada',
            'workflow_status' => 'flight_confirmed',
        ]);
        Operacion::create([
            'flight_request_id' => $orphanFlight->id,
            'provider_id' => $operation->provider_id,
            'aircraft_id' => $operation->aircraft_id,
            'sobrecargo_user_id' => $crew->id,
            'status' => 'confirmed',
            'crew_status' => CrewAssignmentStatus::CONFIRMED,
        ]);

        $realAssignmentWithoutSnapshotFlight = SolicitudVuelo::create([
            'client_id' => Usuario::where('email', 'cliente@privateflights.test')->firstOrFail()->id,
            'origin' => 'MMTY',
            'destination' => 'MMMX',
            'departure_datetime' => now()->addDays(5),
            'return_datetime' => now()->addDays(6),
            'passengers' => 4,
            'trip_type' => 'round_trip',
            'status' => 'confirmada',
            'workflow_status' => 'flight_confirmed',
        ]);
        $realAssignmentWithoutSnapshot = Operacion::create([
            'flight_request_id' => $realAssignmentWithoutSnapshotFlight->id,
            'provider_id' => $operation->provider_id,
            'aircraft_id' => $operation->aircraft_id,
            'sobrecargo_user_id' => null,
            'status' => 'confirmed',
            'crew_status' => CrewAssignmentStatus::PENDING_CONFIRMATION,
        ]);
        AsignacionSobrecargo::create([
            'operation_id' => $realAssignmentWithoutSnapshot->id,
            'sobrecargo_user_id' => $crew->id,
            'status' => CrewAssignmentStatus::PENDING_CONFIRMATION,
            'assigned_at' => now(),
            'response_deadline' => now()->addHours(2),
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/sobrecargo/dashboard')
            ->assertOk()
            ->assertJsonPath('metrics.asignaciones', 2)
            ->assertJsonPath('metrics.servicios_activos', 2);

        $assignmentsResponse = $this->withToken($token)
            ->getJson('/api/v1/sobrecargo/assignments')
            ->assertOk();

        $assignmentIds = collect($assignmentsResponse->json('assignments'))->pluck('id')->all();

        $this->assertContains($operation->id, $assignmentIds);
        $this->assertContains($realAssignmentWithoutSnapshot->id, $assignmentIds);
        $this->assertCount(2, $assignmentIds);
    }

    public function test_missing_assignment_cannot_be_answered_and_is_never_autocreated(): void
    {
        [$operation, $crew, $token] = $this->crewOperation();

        $this->assertDatabaseMissing('sobrecargo_assignments', [
            'operation_id' => $operation->id,
            'sobrecargo_user_id' => $crew->id,
        ]);

        $this->withToken($token)
            ->postJson("/api/v1/sobrecargo/operations/{$operation->id}/respond", ['response' => 'confirmed'])
            ->assertStatus(409);

        $this->assertDatabaseMissing('sobrecargo_assignments', [
            'operation_id' => $operation->id,
            'sobrecargo_user_id' => $crew->id,
        ]);
    }

    public function test_workflow_access_uses_latest_real_assignment_even_when_operation_snapshot_is_empty(): void
    {
        [$operation, $crew, $token] = $this->crewOperation(CrewAssignmentStatus::CONFIRMED);
        AsignacionSobrecargo::create([
            'operation_id' => $operation->id,
            'sobrecargo_user_id' => $crew->id,
            'status' => CrewAssignmentStatus::CONFIRMED,
            'assigned_at' => now()->subHour(),
            'accepted_at' => now()->subMinutes(30),
        ]);
        $operation->update(['sobrecargo_user_id' => null]);

        $this->withToken($token)
            ->getJson("/api/v1/sobrecargo/operations/{$operation->id}/workflow")
            ->assertOk()
            ->assertJsonPath('status', CrewAssignmentStatus::CONFIRMED);
    }

    public function test_operational_reminders_are_idempotent(): void
    {
        [$operation, $crew] = $this->crewOperation();
        AsignacionSobrecargo::create(['operation_id' => $operation->id, 'sobrecargo_user_id' => $crew->id, 'status' => CrewAssignmentStatus::PENDING_CONFIRMATION, 'assigned_at' => now(), 'response_deadline' => now()->addHour()]);

        $this->artisan('crew:send-operational-reminders')->assertSuccessful();
        $this->artisan('crew:send-operational-reminders')->assertSuccessful();
        $this->assertSame(1, Notificacion::where('user_id', $crew->id)->where('type', 'assignment_deadline_soon')->count());
    }

    public function test_operational_notifications_remain_idempotent_when_notifications_table_lacks_idempotency_key(): void
    {
        [$operation, $crew] = $this->crewOperation();

        Schema::shouldReceive('hasColumn')
            ->once()
            ->with('notifications', 'idempotency_key')
            ->andReturn(false);

        /** @var CrewOperationalNotificationService $service */
        $service = app(CrewOperationalNotificationService::class);

        $first = $service->send(
            $crew,
            $operation,
            'new_assignment',
            'Nueva asignacion operativa',
            'Tienes una nueva asignacion pendiente de respuesta.',
            'info',
            12,
            ['response_deadline' => now()->addHours(3)->toISOString()],
        );

        $second = $service->send(
            $crew,
            $operation,
            'new_assignment',
            'Nueva asignacion operativa',
            'Tienes una nueva asignacion pendiente de respuesta.',
            'info',
            12,
            ['response_deadline' => data_get($first->payload, 'response_deadline')],
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Notificacion::query()->where('user_id', $crew->id)->where('type', 'new_assignment')->count());
    }

    private function crewOperation(string $status = CrewAssignmentStatus::PENDING_CONFIRMATION): array
    {
        $this->seed();
        $crew = Usuario::where('email', 'sobrecargo@redaviation.test')->firstOrFail();
        $provider = Proveedor::firstOrFail();
        $aircraft = Aeronave::where('provider_id', $provider->id)->firstOrFail();
        $client = Usuario::where('email', 'cliente@privateflights.test')->firstOrFail();
        $flight = SolicitudVuelo::create([
            'client_id' => $client->id, 'origin' => 'MMMX', 'destination' => 'MMUN',
            'departure_datetime' => now()->addDay(), 'return_datetime' => now()->addDays(2),
            'passengers' => 3, 'trip_type' => 'round_trip', 'status' => 'confirmada', 'workflow_status' => 'flight_confirmed',
        ]);
        $operation = Operacion::create([
            'flight_request_id' => $flight->id, 'provider_id' => $provider->id, 'aircraft_id' => $aircraft->id,
            'sobrecargo_user_id' => $crew->id, 'status' => 'confirmed', 'crew_status' => $status,
        ]);
        $token = $this->postJson('/api/v1/auth/login', ['email' => $crew->email, 'password' => 'password'])->assertOk()->json('token');

        return [$operation, $crew, $token];
    }
}
