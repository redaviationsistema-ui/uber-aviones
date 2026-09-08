<?php

namespace Tests\Feature;

use App\Modelos\Aeronave;
use App\Modelos\AsignacionSobrecargo;
use App\Modelos\LineaTiempoOperacion;
use App\Modelos\Operacion;
use App\Modelos\Proveedor;
use App\Modelos\SolicitudVuelo;
use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CrewCanonicalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $status = 'pending_confirmation'): Operacion
    {
        $this->seed();
        $crew = Usuario::where('email', 'sobrecargo@redaviation.test')->firstOrFail();
        $provider = Proveedor::firstOrFail();
        $flight = SolicitudVuelo::create([
            'client_id' => Usuario::where('email', 'cliente@privateflights.test')->value('id'),
            'origin' => 'MMMX', 'destination' => 'MMUN', 'departure_datetime' => now()->addDay(),
            'passengers' => 3, 'trip_type' => 'one_way', 'status' => 'confirmada', 'workflow_status' => 'flight_confirmed',
        ]);
        $op = Operacion::create([
            'flight_request_id' => $flight->id, 'provider_id' => $provider->id,
            'aircraft_id' => Aeronave::where('provider_id', $provider->id)->value('id'),
            'sobrecargo_user_id' => $crew->id, 'status' => 'confirmed', 'crew_status' => $status,
        ]);
        AsignacionSobrecargo::create([
            'operation_id' => $op->id, 'sobrecargo_user_id' => $crew->id,
            'status' => $status === 'pending_confirmation' ? $status : 'confirmed',
            'assigned_at' => now(), 'response_deadline' => now()->addHour(),
            'presentation_time' => now()->addHour(),
        ]);
        $token = $this->postJson('/api/v1/auth/login', ['email' => $crew->email, 'password' => 'password'])->assertOk()->json('token');
        $this->withToken($token);

        return $op;
    }

    private function workflow(Operacion $op): array
    {
        return $this->getJson("/api/v1/sobrecargo/operations/{$op->id}/workflow")->assertOk()->json();
    }

    private function complete(Operacion $op, string $type): void
    {
        $group = collect($this->workflow($op)['checklists'])->firstWhere('type', $type);
        foreach ($group['items'] as $item) {
            $this->putJson("/api/v1/sobrecargo/operations/{$op->id}/checklists/$type/items/{$item['id']}", ['status' => 'completed'])->assertOk();
        }
    }

    private function act(Operacion $op, string $type, string $endpoint, array $body = []): void
    {
        $payload = $this->workflow($op);
        $this->assertSame($type, $payload['allowed_actions'][0]['type'] ?? null);
        $this->postJson("/api/v1/sobrecargo/operations/{$op->id}/$endpoint", $body)->assertSuccessful();
    }

    public function test_full_flow_only_announces_executable_actions_and_preserves_evidence(): void
    {
        $op = $this->fixture();
        $this->postJson("/api/v1/sobrecargo/operations/{$op->id}/respond", ['response' => 'confirmed'])->assertOk();
        $this->act($op, 'transition', 'transition', ['status' => 'preparation_pending']);
        $this->complete($op, 'preparation');
        $this->assertSame('ready_for_operation', $op->fresh()->crew_status);
        $this->act($op, 'crew_checkin', 'checkin', ['fit_to_operate' => true]);
        $this->assertNotNull($op->fresh()->crew_checkin_at);
        $this->assertSame(1, $op->timeline()->where('status', 'crew_checkin')->count());
        $arrival = $this->workflow($op)['checkin'];
        $this->assertNotEmpty($arrival['recorded_at']);
        $this->assertNotEmpty($arrival['actor_name']);
        $this->postJson("/api/v1/sobrecargo/operations/{$op->id}/checkin", ['fit_to_operate' => true])->assertStatus(409);
        $this->act($op, 'start_preflight', 'transition', ['status' => 'preflight_in_progress']);
        $this->complete($op, 'preflight');
        $this->assertSame('cabin_ready', $op->fresh()->crew_status);
        $this->assertFalse($op->timeline()->where('status', 'cabina_lista')->exists());
        $this->act($op, 'cabin_ready', 'cabin-ready');
        $this->assertTrue($op->timeline()->where('status', 'cabina_lista')->exists());
        foreach ([['transition', 'boarding'], ['passengers_ready', null], ['departure', 'in_flight'], ['landing', 'landed'], ['disembark', 'postflight_pending']] as [$type,$target]) {
            if ($type === 'departure') {
                $tracking = $this->workflow($op);
                $this->assertSame('tracking', $tracking['current_step']);
                $this->assertSame('departure', $tracking['current_phase']);
                $this->assertSame('departure', $tracking['next_action']['type']);
            }
            $this->act($op, $type, $target ? 'transition' : 'passengers-ready', $target ? ['status' => $target] : []);
            $this->assertNotNull($op->fresh()->crew_checkin_at);
            $this->assertFalse($this->workflow($op)['workflow_inconsistent']);
            if ($type === 'departure') {
                $inFlight = $this->workflow($op);
                $this->assertSame('tracking', $inFlight['current_step']);
                $this->assertSame('landing', $inFlight['current_phase']);
                $this->assertSame('landing', $inFlight['next_action']['type']);
            }
            if ($type === 'landing') {
                $landed = $this->workflow($op);
                $this->assertSame('tracking', $landed['current_step']);
                $this->assertSame('disembark', $landed['current_phase']);
                $this->assertSame('disembark', $landed['next_action']['type']);
            }
        }
        $postflight = $this->workflow($op);
        $this->assertSame('postflight', $postflight['current_step']);
        $this->assertSame('postflight', $postflight['current_phase']);
        $this->assertNull($postflight['next_action']);
        \Illuminate\Support\Facades\Storage::fake('s3');
        config(['filesystems.disks.s3.key' => 'test', 'filesystems.disks.s3.secret' => 'test',
            'filesystems.disks.s3.bucket' => 'test', 'filesystems.disks.s3.region' => 'us-east-1']);
        foreach (['preflight' => ['catering_received', 'baggage_secured'], 'postflight' => ['cabin_condition']] as $type => $codes) {
            $group = collect($this->workflow($op)['checklists'])->firstWhere('type', $type);
            foreach ($codes as $code) {
                $item = collect($group['items'])->firstWhere('code', $code);
                $this->post("/api/v1/sobrecargo/operations/{$op->id}/checklists/$type/items/{$item['id']}/evidence",
                    ['file' => \Illuminate\Http\UploadedFile::fake()->image("$code.jpg")],
                    ['Accept' => 'application/json'])->assertCreated();
            }
        }
        $this->complete($op, 'postflight');
        $closure = $this->workflow($op);
        $this->assertSame('closure', $closure['current_step']);
        $this->assertSame('closure', $closure['current_phase']);
        $this->assertSame('submit_report', $closure['next_action']['type']);
        $this->assertSame($closure['allowed_actions'][0], $closure['next_action']);
        $this->act($op, 'submit_report', 'report', [
            'service_rating' => 5, 'cabin_condition' => 'Correcta', 'catering_condition' => 'Correcto',
            'cleaning_required' => false, 'restocking_required' => false,
        ]);
        $this->assertSame('crew_completed', $op->fresh()->crew_status);
        $this->assertNotNull($op->fresh()->crew_report_submitted_at);
        $completed = $this->workflow($op);
        $this->assertSame('completed', $completed['current_step']);
        $this->assertSame('completed', $completed['current_phase']);
        $this->assertNull($completed['next_action']);
        $this->assertSame([], $this->workflow($op)['allowed_actions']);
        $group = collect($this->workflow($op)['checklists'])->firstWhere('type', 'preparation');
        $this->putJson("/api/v1/sobrecargo/operations/{$op->id}/checklists/preparation/items/{$group['items'][0]['id']}", ['status' => 'pending'])->assertStatus(409);
        $this->assertSame('crew_completed', $op->fresh()->crew_status);
    }

    public function test_preflight_cannot_start_or_complete_without_checkin(): void
    {
        $op = $this->fixture('ready_for_operation');
        $group = collect($this->workflow($op)['checklists'])->firstWhere('type', 'preflight');
        $this->postJson("/api/v1/sobrecargo/operations/{$op->id}/transition", ['status' => 'preflight_in_progress'])->assertStatus(409)->assertJsonPath('message', 'Registra primero tu llegada al aeropuerto.');
        foreach ($group['items'] as $item) {
            $this->putJson("/api/v1/sobrecargo/operations/{$op->id}/checklists/preflight/items/{$item['id']}", ['status' => 'completed'])->assertStatus(409);
        }
        foreach (['cabin_ready', 'boarding', 'boarding_completed', 'in_flight'] as $target) {
            $this->postJson("/api/v1/sobrecargo/operations/{$op->id}/transition", ['status' => $target])->assertClientError();
        }
        $this->assertSame('ready_for_operation', $op->fresh()->crew_status);
        $this->assertNull($op->fresh()->crew_checkin_at);
    }

    public function test_historical_boarding_is_flagged_without_mutating_records(): void
    {
        $op = $this->fixture('boarding');
        LineaTiempoOperacion::create(['operation_id' => $op->id, 'status' => 'cabin_ready', 'title' => 'Preflight completado']);
        $payload = $this->workflow($op);
        $this->assertTrue($payload['workflow_inconsistent']);
        $this->assertSame([], $payload['allowed_actions']);
        $this->assertStringContainsString('regularización', $payload['blocking_reason']);
        $this->postJson("/api/v1/sobrecargo/operations/{$op->id}/passengers-ready")->assertStatus(409);
        $this->assertSame('boarding', $op->fresh()->crew_status);
        $this->assertNull($op->fresh()->crew_checkin_at);
        $this->assertSame(1, $op->timeline()->count());
    }

    public function test_incident_only_blocks_when_critical_and_unresolved(): void
    {
        $op = $this->fixture('confirmed');
        $this->complete($op, 'preparation');
        $response = $this->postJson('/api/v1/crew-operation-incidents', [
            'crew_operation_id' => $op->id, 'crew_id' => $op->sobrecargo_user_id,
            'category' => 'seguridad', 'priority' => 'critica', 'description' => 'Prueba',
        ])->assertCreated();
        $this->assertSame([], $this->workflow($op)['allowed_actions']);
        $this->postJson("/api/v1/sobrecargo/operations/{$op->id}/checkin", ['fit_to_operate' => true])->assertStatus(409);
        DB::table('crew_operation_incidents')->where('crew_operation_id', $op->id)->update(['status' => 'resolved']);
        $this->act($op, 'crew_checkin', 'checkin', ['fit_to_operate' => true]);
        DB::table('crew_operation_incidents')->where('crew_operation_id', $op->id)->update(['status' => 'open', 'priority' => 'baja']);
        $this->act($op, 'start_preflight', 'transition', ['status' => 'preflight_in_progress']);
    }

    public function test_informative_incident_keeps_recoverable_arrival_and_checkin_advances_step(): void
    {
        $op = $this->fixture('preparation_pending');
        $this->complete($op, 'preparation');

        $this->postJson('/api/v1/crew-operation-incidents', [
            'crew_operation_id' => $op->id,
            'crew_id' => $op->sobrecargo_user_id,
            'category' => 'cabina',
            'priority' => 'media',
            'description' => 'Catering pendiente de revisión',
        ])->assertCreated();

        $payload = $this->workflow($op);
        $this->assertSame('airport_arrival', $payload['current_step']);
        $this->assertSame('crew_checkin', $payload['allowed_actions'][0]['type']);
        $this->assertNull($payload['blocking_reason']);
        $this->assertArrayHasKey('incidents', $payload);
        $this->assertArrayHasKey('timeline', $payload);
        $this->assertArrayHasKey('crew_checkin_at', $payload);

        $this->postJson("/api/v1/sobrecargo/operations/{$op->id}/checkin", ['fit_to_operate' => true])
            ->assertSuccessful();

        $after = $this->workflow($op);
        $this->assertSame('preflight', $after['current_step']);
        $this->assertNotNull($after['crew_checkin_at']);
        $this->assertSame('start_preflight', $after['allowed_actions'][0]['type']);
    }

    public function test_nonrecoverable_inconsistency_blocks_with_specific_reason(): void
    {
        $op = $this->fixture('in_flight');
        $payload = $this->workflow($op);

        $this->assertTrue($payload['workflow_inconsistent']);
        $this->assertFalse($payload['recoverable']);
        $this->assertSame([], $payload['allowed_actions']);
        $this->assertStringContainsString('regularización', $payload['blocking_reason']);
    }

    public function test_last_preflight_item_cannot_bypass_checkin_even_with_existing_answers(): void
    {
        $op = $this->fixture('ready_for_operation');
        $group = collect($this->workflow($op)['checklists'])->firstWhere('type', 'preflight');
        $last = array_pop($group['items']);
        DB::table('checklist_items')->whereIn('id', array_column($group['items'], 'id'))
            ->update(['status' => 'completed', 'is_completed' => true]);
        $this->putJson("/api/v1/sobrecargo/operations/{$op->id}/checklists/preflight/items/{$last['id']}", ['status' => 'completed'])
            ->assertStatus(409)->assertJsonPath('message', 'Registra primero tu llegada al aeropuerto.');
        $this->assertSame('ready_for_operation', $op->fresh()->crew_status);
        $this->assertDatabaseHas('checklist_items', ['id' => $last['id'], 'status' => 'pending']);
    }

    public function test_boarding_with_checkin_but_without_cabin_evidence_never_announces_passengers(): void
    {
        $op = $this->fixture('boarding');
        $op->update(['crew_checkin_at' => now()]);
        LineaTiempoOperacion::create(['operation_id' => $op->id, 'status' => 'cabin_ready', 'title' => 'Checklist completado']);
        $this->assertSame([], $this->workflow($op)['allowed_actions']);
        $this->postJson("/api/v1/sobrecargo/operations/{$op->id}/passengers-ready")->assertStatus(409);
        $this->postJson("/api/v1/sobrecargo/operations/{$op->id}/transition", ['status' => 'boarding_completed'])->assertStatus(409);
    }

    public function test_timeline_checkin_is_real_evidence_and_cannot_be_registered_twice(): void
    {
        $op = $this->fixture('checked_in');
        LineaTiempoOperacion::create(['operation_id' => $op->id, 'status' => 'crew_checkin', 'title' => 'Llegada', 'created_by' => $op->sobrecargo_user_id]);
        $this->act($op, 'start_preflight', 'transition', ['status' => 'preflight_in_progress']);
        $this->postJson("/api/v1/sobrecargo/operations/{$op->id}/checkin", ['fit_to_operate' => true])->assertStatus(409);
        $this->assertSame(1, $op->timeline()->where('status', 'crew_checkin')->count());
    }

    public function test_old_checklist_cannot_rewind_an_active_operation(): void
    {
        $op = $this->fixture('confirmed');
        $this->complete($op, 'preparation');
        $this->act($op, 'crew_checkin', 'checkin', ['fit_to_operate' => true]);
        $group = collect($this->workflow($op)['checklists'])->firstWhere('type', 'preparation');
        $this->putJson("/api/v1/sobrecargo/operations/{$op->id}/checklists/preparation/items/{$group['items'][0]['id']}", ['status' => 'completed'])->assertStatus(409);
        $this->postJson("/api/v1/sobrecargo/operations/{$op->id}/transition", ['status' => 'boarding'])->assertStatus(409);
        $this->assertSame('checked_in', $op->fresh()->crew_status);
    }
    public function test_workflow_read_uses_bounded_queries_and_does_not_resynchronize_existing_checklists(): void
    {
        $op = $this->fixture('boarding');
        $this->workflow($op);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request = \Illuminate\Http\Request::create("/api/v1/sobrecargo/operations/{$op->id}/workflow");
        $request->setUserResolver(fn () => Usuario::findOrFail($op->sobrecargo_user_id));
        $response = app(\App\Http\Controladores\RedAviation\SobrecargoControlador::class)->workflow($request, $op->fresh());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame(200, $response->status());
        $this->assertLessThanOrEqual(16, count($queries));
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete)/i', $query['query']);
        }
    }

}
