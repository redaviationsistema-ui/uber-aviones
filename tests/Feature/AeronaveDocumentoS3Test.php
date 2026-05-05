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

        $user->provider()->create([
            'company_name' => 'Red Aviation Test',
            'commercial_name' => 'Red Aviation',
            'approval_status' => 'approved',
        ]);

        $aircraft = Aeronave::factory()->create([
            'provider_id' => $user->provider->id,
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

    public function test_provider_can_delete_aircraft_document_and_remove_file_from_s3(): void
    {
        Storage::fake('s3');
        config()->set('filesystems.disks.s3.url', 'https://red-aviation-docs.s3.us-east-1.amazonaws.com');

        $this->seed();

        $user = Usuario::factory()->create([
            'role' => 'provider',
            'status' => 'active',
        ]);

        $user->provider()->create([
            'company_name' => 'Red Aviation Test',
            'commercial_name' => 'Red Aviation',
            'approval_status' => 'approved',
        ]);

        $aircraft = Aeronave::factory()->create([
            'provider_id' => $user->provider->id,
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
}
