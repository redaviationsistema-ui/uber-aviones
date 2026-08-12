<?php

namespace Tests\Feature;

use App\Modelos\Aeronave;
use App\Modelos\DocumentoAeronave;
use App\Modelos\TokenApi;
use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AeronaveDocumentoS3Test extends TestCase
{
    use RefreshDatabase;

    public function test_provider_can_upload_aircraft_document_to_s3_and_persist_public_url(): void
    {
        Storage::fake('s3');
        config()->set('filesystems.disks.s3.url', 'https://red-aviation-docs.s3.us-east-1.amazonaws.com');

        $this->seed();

        $user = Usuario::factory()->create([
            'role' => 'provider',
            'status' => 'active',
        ]);

        $provider = $user->ownedProvider()->create([
            'company_name' => 'Red Aviation Test',
            'commercial_name' => 'Red Aviation',
            'approval_status' => 'approved',
        ]);

        $user->forceFill(['provider_id' => $provider->id])->saveQuietly();

        $aircraft = Aeronave::factory()->create([
            'provider_id' => $provider->id,
        ]);

        $token = TokenApi::issue($user);

        $response = $this->withToken($token)
            ->post('/api/v1/proveedor/aeronaves/'.$aircraft->id.'/documentos', [
                'file' => UploadedFile::fake()->create('seguro.pdf', 120, 'application/pdf'),
                'type' => 'insurance',
                'document_name' => 'Seguro anual',
                'expires_at' => '2026-05-31',
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('document.aircraft_id', $aircraft->id)
            ->assertJsonPath('document.document_type', 'insurance')
            ->assertJsonPath('document.document_name', 'Seguro anual');

        $url = $response->json('url');
        $path = str_replace('https://red-aviation-docs.s3.us-east-1.amazonaws.com/', '', $url);

        Storage::disk('s3')->assertExists($path);

        $this->assertDatabaseHas('aircraft_documents', [
            'aircraft_id' => $aircraft->id,
            'document_type' => 'insurance',
            'document_name' => 'Seguro anual',
            'document_url' => $url,
            'file_url' => $url,
        ]);
    }

    public function test_pending_provider_can_upload_aircraft_document_while_aircraft_remains_blocked(): void
    {
        Storage::fake('s3');
        config()->set('filesystems.disks.s3.url', 'https://red-aviation-docs.s3.us-east-1.amazonaws.com');

        $this->seed();

        $user = Usuario::factory()->create([
            'role' => 'provider',
            'status' => 'active',
        ]);

        $provider = $user->ownedProvider()->create([
            'company_name' => 'Red Aviation Pending',
            'commercial_name' => 'Red Aviation Pending',
            'approval_status' => 'pending',
        ]);

        $user->forceFill(['provider_id' => $provider->id])->saveQuietly();

        $aircraft = Aeronave::factory()->create([
            'provider_id' => $provider->id,
            'status' => 'blocked',
        ]);

        $token = TokenApi::issue($user);

        $response = $this->withToken($token)
            ->post('/api/v1/operator/aircraft/'.$aircraft->id.'/documents', [
                'file' => UploadedFile::fake()->create('sticker.pdf', 120, 'application/pdf'),
                'type' => 'maintenance_sticker',
                'document_name' => 'Sticker de mantenimiento',
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('document.aircraft_id', $aircraft->id)
            ->assertJsonPath('document.document_type', 'maintenance_sticker')
            ->assertJsonPath('document.document_name', 'Sticker de mantenimiento');

        $this->assertDatabaseHas('aircraft_documents', [
            'aircraft_id' => $aircraft->id,
            'document_type' => 'maintenance_sticker',
            'document_name' => 'Sticker de mantenimiento',
        ]);

        $this->assertDatabaseHas('aircraft', [
            'id' => $aircraft->id,
            'status' => 'blocked',
        ]);
    }

    public function test_provider_can_delete_aircraft_document_and_remove_file_from_s3(): void
    {
        Storage::fake('s3');
        config()->set('filesystems.disks.s3.url', 'https://red-aviation-docs.s3.us-east-1.amazonaws.com');

        $this->seed();

        $user = Usuario::factory()->create([
            'role' => 'provider',
            'status' => 'active',
        ]);

        $provider = $user->ownedProvider()->create([
            'company_name' => 'Red Aviation Test',
            'commercial_name' => 'Red Aviation',
            'approval_status' => 'approved',
        ]);

        $user->forceFill(['provider_id' => $provider->id])->saveQuietly();

        $aircraft = Aeronave::factory()->create([
            'provider_id' => $provider->id,
        ]);

        $token = TokenApi::issue($user);

        $path = 'aircraft-documents/demo.pdf';
        Storage::disk('s3')->put($path, 'fake-document-content');

        $document = DocumentoAeronave::create([
            'aircraft_id' => $aircraft->id,
            'type' => 'insurance',
            'file_url' => 'https://red-aviation-docs.s3.us-east-1.amazonaws.com/'.$path,
            'document_type' => 'insurance',
            'document_name' => 'Seguro',
            'document_url' => 'https://red-aviation-docs.s3.us-east-1.amazonaws.com/'.$path,
        ]);

        $this->withToken($token)
            ->deleteJson('/api/v1/proveedor/aeronaves/'.$aircraft->id.'/documentos/'.$document->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        Storage::disk('s3')->assertMissing($path);
        $this->assertDatabaseMissing('aircraft_documents', ['id' => $document->id]);
    }

    public function test_replacing_an_approved_aircraft_document_resets_it_to_pending_on_the_same_record(): void
    {
        Storage::fake('s3');
        config()->set('filesystems.disks.s3.url', 'https://red-aviation-docs.s3.us-east-1.amazonaws.com');

        $this->seed();

        $user = Usuario::factory()->create([
            'role' => 'provider',
            'status' => 'active',
        ]);

        $provider = $user->ownedProvider()->create([
            'company_name' => 'Red Aviation Replace',
            'commercial_name' => 'Red Aviation Replace',
            'approval_status' => 'approved',
        ]);

        $user->forceFill(['provider_id' => $provider->id])->saveQuietly();

        $aircraft = Aeronave::factory()->create([
            'provider_id' => $provider->id,
        ]);

        $document = DocumentoAeronave::query()->create([
            'aircraft_id' => $aircraft->id,
            'provider_id' => $provider->id,
            'type' => 'insurance_policy',
            'document_type' => 'insurance_policy',
            'document_name' => 'seguro_original.pdf',
            'file_type' => 'application/pdf',
            'file_url' => 'https://red-aviation-docs.s3.us-east-1.amazonaws.com/provider/demo/seguro_original.pdf',
            'document_url' => 'https://red-aviation-docs.s3.us-east-1.amazonaws.com/provider/demo/seguro_original.pdf',
            'status' => 'approved',
            'verified_by_admin' => true,
            'metadata' => [
                'admin_review' => [
                    'status' => 'approved',
                    'reason' => null,
                    'reviewed_by' => 999,
                    'reviewed_at' => now()->subDay()->toIso8601String(),
                ],
            ],
        ]);

        $token = TokenApi::issue($user);

        $response = $this->withToken($token)
            ->post('/api/v1/proveedor/aeronaves/'.$aircraft->id.'/documentos', [
                'file' => UploadedFile::fake()->create('seguro_actualizado.pdf', 120, 'application/pdf'),
                'type' => 'insurance_policy',
                'document_name' => 'Seguro actualizado',
            ]);

        $response->assertCreated()
            ->assertJsonPath('document.id', $document->id)
            ->assertJsonPath('document.status', 'pending')
            ->assertJsonPath('aircraft.documents.0.status', 'pending');

        $document->refresh();

        $this->assertSame('pending', $document->status);
        $this->assertFalse((bool) $document->verified_by_admin);
        $this->assertNull(data_get($document->metadata, 'admin_review'));
        $this->assertSame('Seguro actualizado', $document->document_name);
    }
}
