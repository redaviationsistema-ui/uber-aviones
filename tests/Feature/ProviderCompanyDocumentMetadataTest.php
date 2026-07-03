<?php

namespace Tests\Feature;

use App\Modelos\TokenApi;
use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProviderCompanyDocumentMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_document_metadata_is_persisted_and_visible_for_admin_review(): void
    {
        Storage::fake('s3');
        config()->set('filesystems.disks.s3.url', 'https://red-aviation-docs.s3.us-east-1.amazonaws.com');

        $this->seed();

        $providerUser = Usuario::factory()->create([
            'role' => 'provider',
            'status' => 'active',
        ]);

        $provider = $providerUser->ownedProvider()->create([
            'company_name' => 'Sky Group Demo',
            'commercial_name' => 'Sky Group',
            'legal_name' => 'Sky Group SA de CV',
            'approval_status' => 'pending',
        ]);
        $providerUser->forceFill(['provider_id' => $provider->id])->save();

        $providerToken = TokenApi::issue($providerUser);

        $uploadResponse = $this->withToken($providerToken)
            ->post('/api/v1/proveedor/empresa/documentos', [
                'file' => UploadedFile::fake()->create('identificacion.pdf', 120, 'application/pdf'),
                'document_name' => 'Identificacion oficial del representante',
                'document_type' => 'legal_representative_id',
                'document_category' => 'legal_representative_id',
                'document_slot' => 'legal_representative_id',
                'document_section' => 'legal',
            ]);

        $uploadResponse->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('document.document_slot', 'legal_representative_id')
            ->assertJsonPath('document.definition_label', 'Identificacion oficial del representante')
            ->assertJsonPath('document.section_label', 'Carga legal y respaldo');

        $this->assertDatabaseHas('company_documents', [
            'provider_id' => $provider->id,
            'document_slot' => 'legal_representative_id',
            'document_type' => 'legal_representative_id',
            'document_category' => 'legal_representative_id',
            'document_section' => 'legal',
        ]);

        $adminToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@privateflights.test',
            'password' => 'password',
        ])->assertOk()->json('token');

        $detailResponse = $this->withToken($adminToken)
            ->getJson('/api/v1/admin/proveedores/'.$provider->id);

        $detailResponse->assertOk()
            ->assertJsonPath('provider.company_documents.0.document_slot', 'legal_representative_id')
            ->assertJsonPath('provider.company_documents.0.definition_label', 'Identificacion oficial del representante')
            ->assertJsonPath('provider.company_documents.0.section_label', 'Carga legal y respaldo')
            ->assertJsonPath('provider.company_documents.0.field_map.0.column', 'document_slot');

        $activityResponse = $this->withToken($adminToken)
            ->getJson('/api/v1/admin/proveedores/'.$provider->id.'/actividad');

        $activityResponse->assertOk()
            ->assertJsonPath('data.0.metadata.document_slot', 'legal_representative_id')
            ->assertJsonPath('data.0.metadata.document_definition_label', 'Identificacion oficial del representante');
    }
}
