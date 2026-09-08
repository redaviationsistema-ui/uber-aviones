<?php

namespace Tests\Feature;

use App\Modelos\{Aeronave, AsignacionSobrecargo, ChecklistItem, LineaTiempoOperacion, Operacion, Proveedor, SolicitudVuelo, Usuario};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};
use Tests\TestCase;

class CrewEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $status = 'postflight_pending'): Operacion
    {
        Storage::fake('s3');
        config(['filesystems.disks.s3.key' => 'audit', 'filesystems.disks.s3.secret' => 'audit',
            'filesystems.disks.s3.bucket' => 'audit', 'filesystems.disks.s3.region' => 'us-east-1']);
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
            'crew_checkin_at' => now(),
        ]);
        AsignacionSobrecargo::create(['operation_id' => $op->id, 'sobrecargo_user_id' => $crew->id,
            'status' => 'confirmed', 'assigned_at' => now(), 'accepted_at' => now()]);
        foreach (['cabina_lista', 'pasajeros_recibidos', 'in_flight', 'landed', 'postflight_pending'] as $state) {
            LineaTiempoOperacion::create(['operation_id' => $op->id, 'status' => $state, 'title' => 'Test']);
        }
        $token = $this->postJson('/api/v1/auth/login', ['email' => $crew->email, 'password' => 'password'])->assertOk()->json('token');
        $this->withToken($token);
        $this->getJson($this->base($op).'/workflow')->assertOk();
        foreach ($op->checklists()->whereIn('type', ['preparation', 'preflight'])->get() as $group) {
            $group->items()->update(['status' => 'completed', 'is_completed' => true]);
        }
        return $op;
    }

    private function base(Operacion $op): string { return "/api/v1/sobrecargo/operations/{$op->id}"; }

    private function item(Operacion $op, string $type, string $code): ChecklistItem
    {
        return $op->checklists()->where('type', $type)->firstOrFail()->items()->where('code', $code)->firstOrFail();
    }

    private function upload(Operacion $op, string $type, string $code, string $name = 'photo.jpg')
    {
        $item = $this->item($op, $type, $code);
        return $this->post($this->base($op)."/checklists/$type/items/{$item->id}/evidence",
            ['file' => UploadedFile::fake()->image($name)], ['Accept' => 'application/json']);
    }

    private function twoPhotos(Operacion $op): void
    {
        $this->upload($op, 'preflight', 'catering_received')->assertCreated();
        $this->upload($op, 'preflight', 'baggage_secured')->assertCreated();
    }

    public function test_upload_roundtrip_persists_file_and_workflow_and_blocks_without_three_photos(): void
    {
        $op = $this->fixture();
        $this->twoPhotos($op);
        $postflight = $op->checklists()->where('type', 'postflight')->firstOrFail();
        $last = $this->item($op, 'postflight', 'cabin_condition');
        $postflight->items()->where('id', '!=', $last->id)->update(['status' => 'completed', 'is_completed' => true]);
        $url = $this->base($op)."/checklists/postflight/items/{$last->id}";
        $this->putJson($url, ['status' => 'completed'])->assertStatus(409)->assertJsonPath('code', 'CONFLICT');
        $this->assertSame('postflight_pending', $op->fresh()->crew_status);
        $this->assertSame('pending', $last->fresh()->status);
        $response = $this->upload($op, 'postflight', 'cabin_condition')->assertCreated();
        $path = $response->json('item.evidence_files.0.file_path');
        Storage::disk('s3')->assertExists($path);
        $this->assertSame($path, $last->fresh()->evidence_files[0]['file_path']);
        for ($i = 0; $i < 2; $i++) {
            $workflow = $this->getJson($this->base($op).'/workflow')->assertOk()->json();
            $item = collect(collect($workflow['checklists'])->firstWhere('type', 'postflight')['items'])->firstWhere('id', $last->id);
            $this->assertSame($path, $item['evidence_files'][0]['file_path']);
            $this->assertSame([], $workflow['missing_required_evidence']);
        }
        $this->putJson($url, ['status' => 'completed'])->assertOk();
        $this->assertSame('report_pending', $op->fresh()->crew_status);
    }

    public function test_replacement_deletes_old_file_only_after_metadata_update(): void
    {
        $op = $this->fixture();
        $old = $this->upload($op, 'postflight', 'cabin_condition', 'A.jpg')->assertCreated()->json('item.evidence_files.0.file_path');
        ChecklistItem::updating(function ($item) use ($old) {
            if ($item->isDirty('evidence_files')) Storage::disk('s3')->assertExists($old);
        });
        try {
            $new = $this->upload($op, 'postflight', 'cabin_condition', 'B.jpg')->assertCreated()->json('item.evidence_files.0.file_path');
        } finally {
            ChecklistItem::getEventDispatcher()->forget('eloquent.updating: '.ChecklistItem::class);
        }
        Storage::disk('s3')->assertMissing($old);
        Storage::disk('s3')->assertExists($new);
        $this->assertSame($new, $this->item($op, 'postflight', 'cabin_condition')->evidence_files[0]['file_path']);
    }

    public function test_failed_metadata_update_preserves_old_file_and_cleans_new_file(): void
    {
        $op = $this->fixture();
        $old = $this->upload($op, 'postflight', 'cabin_condition', 'A.jpg')->assertCreated()->json('item.evidence_files.0.file_path');
        ChecklistItem::updating(function ($item) {
            if ($item->isDirty('evidence_files')) throw new \RuntimeException('Simulated metadata failure');
        });
        try {
            $this->upload($op, 'postflight', 'cabin_condition', 'B.jpg')->assertStatus(500);
        } finally {
            ChecklistItem::getEventDispatcher()->forget('eloquent.updating: '.ChecklistItem::class);
        }
        Storage::disk('s3')->assertExists($old);
        $this->assertSame([$old], Storage::disk('s3')->allFiles());
        $this->assertSame($old, $this->item($op, 'postflight', 'cabin_condition')->evidence_files[0]['file_path']);
    }

    public function test_legacy_report_pending_can_add_missing_photos_but_not_close_without_them(): void
    {
        $op = $this->fixture('report_pending');
        $op->checklists()->where('type', 'postflight')->firstOrFail()->items()->update(['status' => 'completed', 'is_completed' => true]);
        $report = ['service_rating' => 5, 'cabin_condition' => 'Correcta', 'catering_condition' => 'Correcto',
            'cleaning_required' => false, 'restocking_required' => false];
        $this->postJson($this->base($op).'/report', $report)->assertStatus(409);
        $this->twoPhotos($op);
        $this->postJson($this->base($op).'/report', $report)->assertStatus(409);
        $this->upload($op, 'postflight', 'cabin_condition')->assertCreated();
        $this->postJson($this->base($op).'/report', $report)->assertCreated();
        $this->assertSame('crew_completed', $op->fresh()->crew_status);
        $this->upload($op, 'postflight', 'cabin_condition')->assertStatus(409);
    }

    public function test_late_evidence_permission_does_not_reopen_preflight_answers(): void
    {
        $op = $this->fixture();
        $item = $this->item($op, 'preflight', 'catering_received');
        $this->putJson($this->base($op)."/checklists/preflight/items/{$item->id}", ['status' => 'pending'])->assertStatus(409);
        $this->upload($op, 'preflight', 'cabin_cleaning')->assertStatus(409);
    }
}
