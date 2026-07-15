<?php

namespace Tests\Feature;

use App\Modelos\DocumentoEmpresa;
use App\Modelos\Perfil;
use App\Modelos\Proveedor;
use App\Modelos\TokenApi;
use App\Modelos\Usuario;
use App\Servicios\Providers\AdminProviderApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderAdministrativeApprovalFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_approve_a_complete_provider_and_enable_operational_access(): void
    {
        $this->seed();

        $admin = $this->createAdmin();
        $provider = $this->createProviderForReview();
        $this->attachRequiredApprovedDocuments($provider);

        $this->withToken(TokenApi::issue($admin))
            ->postJson('/api/v1/admin/providers/'.$provider->id.'/approve', [
                'admin_notes' => 'Expediente completo y aprobado.',
            ])
            ->assertOk()
            ->assertJsonPath('provider.approval_status', 'approved')
            ->assertJsonPath('provider.admin_validation_status', 'approved')
            ->assertJsonPath('provider.operator_status', 'active')
            ->assertJsonPath('provider.access_enabled', true);

        $this->assertDatabaseHas('providers', [
            'id' => $provider->id,
            'approval_status' => 'approved',
            'admin_validation_status' => 'approved',
            'operator_status' => 'active',
            'access_enabled' => true,
        ]);
    }

    public function test_legal_representative_requirement_uses_captured_name_even_without_representative_phone(): void
    {
        $this->seed();

        $admin = $this->createAdmin();
        $provider = $this->createProviderForReview([
            'representative_name' => 'Laura Campos',
            'representative_phone' => null,
        ]);
        $this->attachRequiredApprovedDocuments($provider);

        $detailResponse = $this->withToken(TokenApi::issue($admin))
            ->getJson('/api/v1/admin/providers/'.$provider->id.'/detail')
            ->assertOk();

        $representativeRequirement = collect($detailResponse->json('provider.validation_requirements', []))
            ->firstWhere('key', 'legal_representative_complete');

        $this->assertNotNull($representativeRequirement);
        $this->assertTrue((bool) ($representativeRequirement['complete'] ?? false));

        $this->withToken(TokenApi::issue($admin))
            ->postJson('/api/v1/admin/providers/'.$provider->id.'/approve', [
                'admin_notes' => 'Aprobado con representante legal capturado.',
            ])
            ->assertOk()
            ->assertJsonPath('provider.approval_status', 'approved')
            ->assertJsonPath('provider.access_enabled', true);
    }

    public function test_admin_cannot_approve_incomplete_provider_and_receives_missing_requirements(): void
    {
        $this->seed();

        $admin = $this->createAdmin();
        $provider = $this->createProviderForReview([
            'rfc' => null,
            'base_airport' => null,
        ]);

        $response = $this->withToken(TokenApi::issue($admin))
            ->postJson('/api/v1/admin/providers/'.$provider->id.'/approve');

        $response->assertStatus(422)
            ->assertJsonPath('errors.validation.0', fn (string $message) => str_contains($message, 'Faltan requisitos obligatorios'));

        $this->assertDatabaseHas('providers', [
            'id' => $provider->id,
            'approval_status' => 'pending',
            'access_enabled' => false,
        ]);
    }

    public function test_provider_can_submit_review_and_persist_submitted_timestamp(): void
    {
        $this->seed();

        [$user, $provider] = $this->createProviderContext([
            'rfc' => 'PRV010101AAA',
            'sat_validation_status' => 'approved',
        ]);
        $this->attachRequiredApprovedDocuments($provider);

        $before = $provider->fresh();
        $this->assertNull($before->admin_review_submitted_at);

        $response = $this->withToken(TokenApi::issue($user))
            ->post('/api/v1/proveedor/empresa/enviar-revision');

        $response->assertOk()
            ->assertJsonPath('company.admin_validation_status', 'pending_review');

        $after = $provider->fresh();
        $this->assertNotNull($after->admin_review_submitted_at);
        $this->assertSame('pending_review', $after->admin_validation_status);
        $this->assertSame('pending_review', $after->operator_status);
    }

    public function test_provider_updates_after_submission_do_not_clear_review_timestamp(): void
    {
        $this->seed();

        [$user, $provider] = $this->createProviderContext([
            'rfc' => 'PRV010101AAA',
            'sat_validation_status' => 'approved',
            'admin_review_submitted_at' => now(),
            'admin_validation_status' => 'pending_review',
            'operator_status' => 'pending_review',
            'status' => 'pending_review',
        ]);

        $submittedAt = $provider->admin_review_submitted_at?->toISOString();

        $this->withToken(TokenApi::issue($user))
            ->putJson('/api/v1/proveedor/empresa', [
                'legal_name' => 'Proveedor Base SA de CV Actualizado',
                'commercial_name' => 'Proveedor Base',
                'company_name' => 'Proveedor Base',
                'rfc' => 'PRV010101AAA',
                'company_phone' => '5550001111',
                'company_email' => 'proveedor-base@test.dev',
                'base_airport' => 'MMMX',
                'address' => 'Toluca Centro 123',
                'representative_name' => 'Base Legal',
            ])
            ->assertOk();

        $this->assertSame($submittedAt, $provider->fresh()->admin_review_submitted_at?->toISOString());
    }

    public function test_admin_can_request_changes_and_keep_operational_access_blocked(): void
    {
        $this->seed();

        $admin = $this->createAdmin();
        $provider = $this->createProviderForReview();

        $this->withToken(TokenApi::issue($admin))
            ->postJson('/api/v1/admin/providers/'.$provider->id.'/request-changes', [
                'notes' => 'Corrige la informacion fiscal.',
            ])
            ->assertOk()
            ->assertJsonPath('provider.approval_status', 'changes_requested')
            ->assertJsonPath('provider.admin_validation_status', 'changes_requested')
            ->assertJsonPath('provider.operator_status', 'incomplete')
            ->assertJsonPath('provider.access_enabled', false);
    }

    public function test_admin_can_reject_provider_and_store_reason(): void
    {
        $this->seed();

        $admin = $this->createAdmin();
        $provider = $this->createProviderForReview();

        $this->withToken(TokenApi::issue($admin))
            ->postJson('/api/v1/admin/providers/'.$provider->id.'/reject', [
                'notes' => 'El expediente no cumple con la documentacion minima.',
            ])
            ->assertOk()
            ->assertJsonPath('provider.approval_status', 'rejected')
            ->assertJsonPath('provider.admin_validation_status', 'rejected')
            ->assertJsonPath('provider.operator_status', 'rejected')
            ->assertJsonPath('provider.access_enabled', false)
            ->assertJsonPath('provider.rejection_reason', 'El expediente no cumple con la documentacion minima.');
    }

    public function test_non_admin_cannot_approve_provider(): void
    {
        $this->seed();

        $providerUser = Usuario::factory()->create([
            'role' => Usuario::ROLE_PROVIDER,
            'operational_role' => Usuario::ROLE_PROVIDER,
            'status' => 'active',
        ]);
        $providerUser->syncRoles([Usuario::ROLE_PROVIDER], Usuario::ROLE_PROVIDER);

        $provider = $this->createProviderForReview();
        $this->attachRequiredApprovedDocuments($provider);

        $this->withToken(TokenApi::issue($providerUser))
            ->postJson('/api/v1/admin/providers/'.$provider->id.'/approve')
            ->assertForbidden();
    }

    public function test_approval_is_idempotent_for_already_approved_provider(): void
    {
        $this->seed();

        $admin = $this->createAdmin();
        $provider = $this->createProviderForReview([
            'approval_status' => 'approved',
            'admin_validation_status' => 'approved',
            'operator_status' => 'active',
            'status' => 'approved',
            'access_enabled' => true,
            'validated_at' => now()->subDay(),
            'admin_validated_at' => now()->subDay(),
        ]);
        $this->attachRequiredApprovedDocuments($provider);

        $validatedAt = $provider->validated_at;

        $this->withToken(TokenApi::issue($admin))
            ->postJson('/api/v1/admin/providers/'.$provider->id.'/approve')
            ->assertOk()
            ->assertJsonPath('provider.approval_status', 'approved')
            ->assertJsonPath('provider.access_enabled', true);

        $this->assertEquals($validatedAt?->toISOString(), $provider->fresh()->validated_at?->toISOString());
    }

    public function test_service_rolls_back_provider_state_when_callback_throws(): void
    {
        $this->seed();

        $admin = $this->createAdmin();
        $provider = $this->createProviderForReview();
        $this->attachRequiredApprovedDocuments($provider);

        $service = new class extends AdminProviderApprovalService {
            protected function afterDecisionPersisted(string $action, Proveedor $provider, ?Usuario $admin, array $context): void
            {
                throw new \RuntimeException('forced rollback');
            }
        };

        try {
            $service->approve($provider, $admin, ['admin_notes' => 'rollback']);
            $this->fail('Expected rollback exception was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('forced rollback', $exception->getMessage());
        }

        $this->assertDatabaseHas('providers', [
            'id' => $provider->id,
            'approval_status' => 'pending',
            'admin_validation_status' => 'pending_review',
            'operator_status' => 'pending_review',
            'access_enabled' => false,
        ]);
    }

    public function test_operational_writes_remain_blocked_until_admin_approval(): void
    {
        $this->seed();

        [$user, $provider] = $this->createProviderContext([
            'approval_status' => 'pending',
            'admin_validation_status' => 'pending_review',
            'operator_status' => 'pending_review',
            'status' => 'pending_review',
            'access_enabled' => false,
        ]);

        $this->withToken(TokenApi::issue($user))
            ->postJson('/api/v1/operator/aircraft', [
                'model' => 'Learjet 60',
                'manufacturer' => 'Bombardier',
                'category' => 'Mid Jet',
                'registration' => 'XA-BLK',
                'capacity' => 8,
                'base_airport' => 'MMMX',
                'range_km' => 3800,
                'speed_kmh' => 780,
                'hourly_rate' => 6000,
                'currency' => 'USD',
            ])
            ->assertForbidden();
    }

    public function test_admin_provider_payloads_include_normalized_status_summary(): void
    {
        $this->seed();

        $admin = $this->createAdmin();
        $provider = $this->createProviderForReview([
            'approval_status' => 'approved',
            'admin_validation_status' => 'approved',
            'operator_status' => 'active',
            'status' => 'approved',
            'access_enabled' => true,
        ]);
        $this->attachRequiredApprovedDocuments($provider);

        $listResponse = $this->withToken(TokenApi::issue($admin))
            ->getJson('/api/v1/admin/providers')
            ->assertOk();

        $listProvider = collect($listResponse->json('providers.data', []))
            ->firstWhere('provider_id', $provider->id);

        $this->assertNotNull($listProvider);
        $this->assertSame('approved', $listProvider['provider_status_summary']['status'] ?? null);
        $this->assertSame(6, $listProvider['provider_status_summary']['document_summary']['approved'] ?? null);
        $this->assertSame('no_aircraft', $listProvider['provider_status_summary']['fleet_summary']['status'] ?? null);

        $this->withToken(TokenApi::issue($admin))
            ->getJson('/api/v1/admin/providers/'.$provider->id.'/detail')
            ->assertOk()
            ->assertJsonPath('provider.provider_status_summary.status', 'approved')
            ->assertJsonPath('provider.provider_status_summary.progress', 100)
            ->assertJsonPath('provider.provider_status_summary.document_summary.total', 6);
    }

    private function createAdmin(): Usuario
    {
        $admin = Usuario::factory()->create([
            'role' => Usuario::ROLE_ADMIN,
            'operational_role' => Usuario::ROLE_ADMIN,
            'status' => 'active',
        ]);
        $admin->syncRoles([Usuario::ROLE_ADMIN], Usuario::ROLE_ADMIN);

        return $admin;
    }

    private function createProviderForReview(array $attributes = []): Proveedor
    {
        [$user, $provider] = $this->createProviderContext($attributes + [
            'company_name' => 'Proveedor Revision',
            'commercial_name' => 'Proveedor Revision',
            'legal_name' => 'Proveedor Revision SA de CV',
            'rfc' => 'PRV010101AAA',
            'company_phone' => '5551112233',
            'company_email' => 'proveedor@test.dev',
            'base_airport' => 'MMMX',
            'representative_name' => 'Laura Campos',
            'representative_phone' => '5553334455',
            'approval_status' => 'pending',
            'admin_validation_status' => 'pending_review',
            'operator_status' => 'pending_review',
            'status' => 'pending_review',
            'access_enabled' => false,
            'admin_review_submitted_at' => now(),
        ]);

        return $provider;
    }

    private function createProviderContext(array $providerAttributes = []): array
    {
        $user = Usuario::factory()->create([
            'role' => Usuario::ROLE_PROVIDER,
            'operational_role' => Usuario::ROLE_PROVIDER,
            'status' => 'active',
            'email' => sprintf('provider.%s@test.dev', uniqid()),
        ]);
        $user->syncRoles([Usuario::ROLE_PROVIDER], Usuario::ROLE_PROVIDER);

        Perfil::query()->create([
            'user_id' => $user->id,
            'address' => 'Toluca Centro 123',
            'base_airport' => 'MMMX',
            'tax_data' => [
                'legal_representative' => 'Base Legal',
            ],
        ]);

        $provider = Proveedor::query()->create([
            'user_id' => $user->id,
            'company_name' => 'Proveedor Base',
            'commercial_name' => 'Proveedor Base',
            'legal_name' => 'Proveedor Base SA de CV',
            'company_phone' => '5550001111',
            'company_email' => 'proveedor-base@test.dev',
            'base_airport' => 'MMMX',
            'representative_name' => 'Base Legal',
            'representative_phone' => '5550002222',
            ...$providerAttributes,
        ]);

        $user->forceFill(['provider_id' => $provider->id])->saveQuietly();

        return [$user->fresh(['provider']), $provider->fresh()];
    }

    private function attachRequiredApprovedDocuments(Proveedor $provider): void
    {
        foreach ([
            'sat_certificate',
            'articles_of_incorporation',
            'legal_representative_power',
            'legal_representative_id',
            'tax_address_proof',
            'operational_permit',
        ] as $slot) {
            DocumentoEmpresa::query()->create([
                'provider_id' => $provider->id,
                'document_slot' => $slot,
                'document_type' => $slot,
                'document_name' => $slot.'.pdf',
                'file_url' => 'https://example.test/'.$slot.'.pdf',
                'document_url' => 'https://example.test/'.$slot.'.pdf',
                'status' => 'approved',
                'metadata' => [
                    'definition_key' => $slot,
                    'definition_label' => $slot,
                    'section_key' => $slot === 'sat_certificate' ? 'tax' : 'legal',
                ],
            ]);
        }
    }
}
