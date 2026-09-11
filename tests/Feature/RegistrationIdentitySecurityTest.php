<?php

namespace Tests\Feature;

use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RegistrationIdentitySecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.identity_disk' => 'private']);
        Storage::fake('private');
        $this->seed();
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'name' => 'Cliente Prueba', 'email' => 'registro@example.test',
            'password' => 'Password123!', 'password_confirmation' => 'Password123!',
            'role' => 'client',
        ], $extra);
    }

    private function adminToken(): string
    {
        $admin = Usuario::factory()->create(['role' => 'admin', 'status' => 'active']);
        $admin->syncRoles(['admin'], 'admin');

        return \App\Modelos\TokenApi::issue($admin, 'identity-review-test');
    }

    private function storeCompleteIdentityEvidence(Usuario $user): void
    {
        $front = "identity/ine/front/{$user->id}.jpg";
        $back = "identity/ine/back/{$user->id}.jpg";
        $selfie = "identity/selfies/{$user->id}.jpg";
        Storage::disk('private')->put($front, 'front');
        Storage::disk('private')->put($back, 'back');
        Storage::disk('private')->put($selfie, 'selfie');
        $user->profile->update(['ine_front_path' => $front, 'ine_back_path' => $back]);
        $user->update([
            'biometric_selfie_path' => $selfie,
            'biometric_selfie_disk' => 'private',
            'biometric_image_saved' => true,
        ]);
    }

    private function crewCandidate(array $extra = []): array
    {
        $response = $this->postJson('/api/v1/crew/register', $this->payload(array_merge([
            'email' => 'crew-'.uniqid().'@example.test',
            'document_type' => 'Licencia de sobrecargo',
            'document_number' => '12345678-12',
            'ine_scan_raw' => 'AFAC LICENCIA FEDERAL',
            'ine_scan_status' => 'scanned',
            'license_file' => UploadedFile::fake()->image('license.jpg'),
        ], $extra)))->assertCreated();

        return [Usuario::findOrFail($response->json('user.id')), $response->json('token')];
    }

    public function test_account_without_selfie_is_created_pending_without_operational_permissions(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload());
        $response->assertCreated()->assertJsonPath('user_created', true)
            ->assertJsonPath('identity.status', 'pending');
        $user = Usuario::findOrFail($response->json('user.id'));
        $this->assertFalse($user->identity_verified);
        $this->assertFalse($user->hasRole('sobrecargo', 'admin', 'operator', 'provider'));
        $this->assertTrue((bool) $user->profile->identity_validation_required);
    }

    public function test_selfie_and_injected_identity_claims_cannot_approve_identity_or_assign_roles(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload([
            'selfie_biometric' => UploadedFile::fake()->image('selfie.jpg'),
            'identity_verified' => true, 'identity_verification_status' => 'approved',
            'identity_validation_required' => false, 'face_match_score' => 1.0,
            'liveness_score' => 100, 'face_confidence' => 100,
            'operational_role' => 'sobrecargo', 'roles' => ['admin', 'provider'],
        ]));
        $response->assertCreated()->assertJsonPath('identity.status', 'pending')
            ->assertJsonPath('user.identity_verified', false);
        $user = Usuario::findOrFail($response->json('user.id'));
        $this->assertSame('client', $user->role);
        $this->assertNull($user->operational_role);
        $this->assertNull($user->face_match_score);
        $this->assertNull($user->liveness_score);
        $this->assertFalse($user->hasRole('sobrecargo', 'admin', 'operator', 'provider'));
        $this->assertTrue((bool) $user->biometric_image_saved);
        Storage::disk('private')->assertExists($user->biometric_selfie_path);
        $this->assertDatabaseHas('identity_verifications', [
            'user_id' => $user->id, 'status' => 'pending', 'identity_verified' => false,
            'face_match_score' => null, 'liveness_score' => null, 'face_confidence' => null,
        ]);
    }

    public function test_client_endpoint_cannot_select_provider_or_admin_role(): void
    {
        foreach (['provider', 'admin', 'operator'] as $role) {
            $this->postJson('/api/v1/auth/register', $this->payload(['role' => $role]))->assertUnprocessable();
        }
        $this->assertDatabaseMissing('users', ['email' => 'registro@example.test']);
    }

    public function test_crew_application_requires_afac_and_only_creates_a_pending_candidate(): void
    {
        $this->postJson('/api/v1/crew/register', $this->payload())->assertUnprocessable();
        $response = $this->postJson('/api/v1/crew/register', $this->payload([
            'document_type' => 'Licencia de sobrecargo',
            'document_number' => '12345678-12',
            'ine_scan_raw' => 'AFAC LICENCIA FEDERAL', 'ine_scan_status' => 'scanned',
            'license_file' => UploadedFile::fake()->image('license.jpg'),
        ]));
        $response->assertCreated()->assertJsonPath('crew_application_status', 'pending');
        $user = Usuario::findOrFail($response->json('user.id'));
        $this->assertSame('client', $user->role);
        $this->assertNull($user->operational_role);
        $this->assertFalse($user->hasRole('sobrecargo', 'admin', 'provider'));
        $this->assertSame('pending', $user->profile->tax_data['crew_application']['status']);
        Storage::disk('private')->assertExists($user->profile->tax_data['crew_application']['license_path']);
    }
    public function test_good_face_detection_is_not_identity_approval(): void
    {
        $client = \Mockery::mock(\Aws\Rekognition\RekognitionClient::class);
        $client->shouldReceive('detectFaces')->once()->andReturn(new \Aws\Result(['FaceDetails' => [[
            'Confidence' => 99.9,
            'Quality' => ['Brightness' => 90, 'Sharpness' => 90],
            'Pose' => ['Yaw' => 0, 'Pitch' => 0, 'Roll' => 0],
            'FaceOccluded' => ['Value' => false],
        ]]]));
        $controller = \Mockery::mock(\App\Http\Controladores\BiometricControlador::class)
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $controller->shouldReceive('rekognition')->once()->andReturn($client);
        $this->app->instance(\App\Http\Controladores\BiometricControlador::class, $controller);
        $this->post('/api/v1/public/biometric/detect-face', [
            'selfie' => UploadedFile::fake()->image('selfie.jpg'),
        ])->assertOk()->assertJsonPath('faceDetected', true)
            ->assertJsonPath('captureAccepted', true)
            ->assertJsonPath('identityVerified', false)
            ->assertJsonPath('identityVerificationStatus', 'pending');
    }

    public function test_public_role_variants_never_grant_privileges(): void
    {
        foreach (['admin', 'operator'] as $operationalRole) {
            $this->postJson('/api/v1/auth/register', $this->payload([
                'operational_role' => $operationalRole,
            ]))->assertUnprocessable();
        }
        $response = $this->postJson('/api/v1/auth/register', $this->payload([
            'role' => 'sobrecargo', 'roles' => ['admin', 'provider', 'sobrecargo'],
            'document_type' => 'Licencia de sobrecargo', 'document_number' => '12345678-12',
            'ine_scan_raw' => 'AFAC LICENCIA FEDERAL', 'ine_scan_status' => 'scanned',
        ]));
        $response->assertCreated();
        $user = Usuario::findOrFail($response->json('user.id'));
        $this->assertFalse($user->hasRole('sobrecargo', 'admin', 'provider', 'operator'));
        $this->assertSame('client', $user->effectiveRole());
    }

    public function test_generic_admin_role_assignment_cannot_bypass_candidate_review(): void
    {
        $response = $this->postJson('/api/v1/crew/register', $this->payload([
            'document_type' => 'Licencia de sobrecargo', 'document_number' => '12345678-12',
            'ine_scan_raw' => 'AFAC LICENCIA FEDERAL', 'ine_scan_status' => 'scanned',
        ]))->assertCreated();
        $id = $response->json('user.id');
        $candidateToken = $response->json('token');
        $this->withToken($candidateToken)->putJson("/api/v1/admin/users/{$id}", [
            'role' => 'sobrecargo',
        ])->assertForbidden();
        $admin = Usuario::factory()->create(['role' => 'admin', 'status' => 'active']);
        $admin->syncRoles(['admin'], 'admin');
        $adminToken = \App\Modelos\TokenApi::issue($admin, 'audit');
        $list = $this->withToken($adminToken)->getJson('/api/v1/admin/sobrecargos')->assertOk();
        $this->assertNotContains($id, collect($list->json('sobrecargos.data'))->pluck('id')->all());
        $this->withToken($adminToken)->putJson("/api/v1/admin/sobrecargos/{$id}", [
            'validation_status' => 'Aprobado', 'status' => 'active',
        ])->assertUnprocessable();
        $this->withToken($adminToken)->putJson("/api/v1/admin/users/{$id}", [
            'role' => 'sobrecargo',
        ])->assertStatus(409);
        $user = Usuario::findOrFail($id);
        $this->assertFalse($user->hasRole('sobrecargo'));
        $this->assertSame('client', $user->effectiveRole());
        // Characterization of the missing review transition, not an approval guarantee.
        $this->assertSame('pending', $user->profile->tax_data['crew_application']['status']);
        $this->assertFalse($user->identity_verified);
    }

    public function test_client_cannot_review_identity_and_admin_can_approve_complete_identity_with_traceability(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();
        $user = Usuario::findOrFail($response->json('user.id'));
        $this->storeCompleteIdentityEvidence($user);

        $this->withToken($response->json('token'))
            ->postJson("/api/v1/admin/users/{$user->id}/identity-review", ['status' => 'approved'])
            ->assertForbidden();

        $this->withToken($this->adminToken())
            ->postJson("/api/v1/admin/users/{$user->id}/identity-review", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        $user->refresh();
        $this->assertSame('approved', $user->identity_verification_status);
        $this->assertTrue($user->identity_verified);
        $this->assertNotEmpty($user->profile->fresh()->tax_data['identity_review']['reviewed_by']);
        $this->assertNotEmpty($user->profile->fresh()->tax_data['identity_review']['reviewed_at']);
        $this->assertDatabaseHas('identity_verifications', [
            'user_id' => $user->id,
            'status' => 'approved',
            'identity_verified' => true,
        ]);
    }

    public function test_admin_cannot_approve_identity_without_each_required_evidence_file(): void
    {
        foreach (['ine_front_path', 'ine_back_path', 'selfie'] as $missingEvidence) {
            $response = $this->postJson('/api/v1/auth/register', $this->payload([
                'email' => "{$missingEvidence}@example.test",
            ]))->assertCreated();
            $user = Usuario::findOrFail($response->json('user.id'));
            $this->storeCompleteIdentityEvidence($user);

            if ($missingEvidence === 'selfie') {
                Storage::disk('private')->delete($user->biometric_selfie_path);
            } else {
                Storage::disk('private')->delete($user->profile->{$missingEvidence});
            }

            $this->withToken($this->adminToken())
                ->postJson("/api/v1/admin/users/{$user->id}/identity-review", ['status' => 'approved'])
                ->assertStatus(409);

            $this->assertSame('pending', $user->fresh()->identity_verification_status);
        }
    }

    public function test_admin_can_reject_identity_and_preserves_reason(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();
        $user = Usuario::findOrFail($response->json('user.id'));

        $this->withToken($this->adminToken())
            ->postJson("/api/v1/admin/users/{$user->id}/identity-review", [
                'status' => 'rejected',
                'reason' => 'La imagen de la identificacion no es legible.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'rejected');

        $this->assertSame('rejected', $user->fresh()->identity_verification_status);
        $this->assertSame(
            'La imagen de la identificacion no es legible.',
            $user->profile->fresh()->tax_data['identity_review']['rejection_reason'],
        );
    }

    public function test_pending_or_rejected_identity_cannot_approve_crew_application(): void
    {
        [$pendingCandidate] = $this->crewCandidate();
        $this->withToken($this->adminToken())
            ->postJson("/api/v1/admin/users/{$pendingCandidate->id}/crew-review", ['status' => 'approved'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'La identidad del usuario debe estar aprobada antes de habilitar el rol operacional.');

        [$rejectedCandidate] = $this->crewCandidate();
        $this->withToken($this->adminToken())
            ->postJson("/api/v1/admin/users/{$rejectedCandidate->id}/identity-review", [
                'status' => 'rejected',
                'reason' => 'No coincide con el documento presentado.',
            ])
            ->assertOk();
        $this->withToken($this->adminToken())
            ->postJson("/api/v1/admin/users/{$rejectedCandidate->id}/crew-review", ['status' => 'approved'])
            ->assertStatus(409);

        $this->assertFalse($pendingCandidate->fresh()->hasRole('sobrecargo'));
        $this->assertFalse($rejectedCandidate->fresh()->hasRole('sobrecargo'));
    }

    public function test_admin_can_approve_kyc_approved_crew_application_once(): void
    {
        [$candidate] = $this->crewCandidate();
        $candidate->update(['identity_verification_status' => 'approved', 'identity_verified' => true]);
        $candidate->identityVerifications()->latest('id')->firstOrFail()->update([
            'status' => 'approved',
            'identity_verified' => true,
        ]);
        $adminToken = $this->adminToken();

        $this->withToken($adminToken)
            ->postJson("/api/v1/admin/users/{$candidate->id}/crew-review", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('status', 'approved');
        $this->withToken($adminToken)
            ->postJson("/api/v1/admin/users/{$candidate->id}/crew-review", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('already_reviewed', true);

        $candidate->refresh();
        $application = $candidate->profile->fresh()->tax_data['crew_application'];
        $this->assertTrue($candidate->hasRole('sobrecargo'));
        $this->assertSame('sobrecargo', $candidate->operational_role);
        $this->assertSame('approved', $application['status']);
        $this->assertNotEmpty($application['reviewed_by']);
        $this->assertNotEmpty($application['reviewed_at']);
        $this->assertSame(1, $candidate->roles()->where('code', 'sobrecargo')->count());
    }

    public function test_client_cannot_approve_crew_and_rejection_does_not_grant_role(): void
    {
        [$candidate, $candidateToken] = $this->crewCandidate();

        $this->withToken($candidateToken)
            ->postJson("/api/v1/admin/users/{$candidate->id}/crew-review", ['status' => 'approved'])
            ->assertForbidden();
        $this->withToken($this->adminToken())
            ->postJson("/api/v1/admin/users/{$candidate->id}/crew-review", [
                'status' => 'rejected',
                'reason' => 'La licencia requiere correccion.',
            ])
            ->assertOk();

        $candidate->refresh();
        $application = $candidate->profile->fresh()->tax_data['crew_application'];
        $this->assertFalse($candidate->hasRole('sobrecargo'));
        $this->assertSame('rejected', $application['status']);
        $this->assertSame('La licencia requiere correccion.', $application['rejection_reason']);
    }

    public function test_invalid_afac_evidence_still_blocks_crew_approval(): void
    {
        [$candidate] = $this->crewCandidate();
        $candidate->update(['identity_verification_status' => 'approved', 'identity_verified' => true]);
        $candidate->profile->update(['ine_scan_raw' => 'LICENCIA SIN EVIDENCIA']);

        $this->withToken($this->adminToken())
            ->postJson("/api/v1/admin/users/{$candidate->id}/crew-review", ['status' => 'approved'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'La licencia debe conservar evidencia AFAC válida.');

        $this->assertFalse($candidate->fresh()->hasRole('sobrecargo'));
    }

}
