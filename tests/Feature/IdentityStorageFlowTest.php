<?php

namespace Tests\Feature;

use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class IdentityStorageFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_rolls_back_database_and_uploaded_identity_files_when_a_database_step_fails(): void
    {
        Storage::fake('s3');
        config(['filesystems.identity_disk' => 's3']);

        Usuario::creating(static function (): void {
            throw new \RuntimeException('Forced database failure after identity uploads.');
        });

        $this->post('/api/v1/auth/register', [
            'name' => 'Registro Fallido',
            'email' => 'identity.rollback@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'client',
            'document_type' => 'INE',
            'identity_validation_required' => true,
            'curp' => 'TEST900101HDFXXX01',
            'ine_front' => UploadedFile::fake()->image('ine-front.png'),
            'ine_back' => UploadedFile::fake()->image('ine-back.png'),
            'selfie_biometric' => UploadedFile::fake()->image('selfie.png'),
        ])->assertStatus(500);

        $this->assertDatabaseMissing('users', ['email' => 'identity.rollback@test.com']);
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_registration_and_signed_identity_endpoints_use_the_configured_identity_disk(): void
    {
        Storage::fake('s3');
        config(['filesystems.identity_disk' => 's3']);

        $this->post('/api/v1/auth/register', [
            'name' => 'Cliente S3',
            'email' => 'identity.s3@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'client',
            'document_type' => 'INE',
            'identity_validation_required' => true,
            'curp' => 'TEST900101HDFXXX01',
            'ine_front' => UploadedFile::fake()->image('ine-front.png'),
            'ine_back' => UploadedFile::fake()->image('ine-back.png'),
            'selfie_biometric' => UploadedFile::fake()->image('selfie.png'),
        ])->assertCreated();

        $user = Usuario::query()->with('profile')->where('email', 'identity.s3@test.com')->firstOrFail();
        Storage::disk('s3')->assertExists($user->profile->ine_front_path);
        Storage::disk('s3')->assertExists($user->profile->ine_back_path);
        Storage::disk('s3')->assertExists($user->biometric_selfie_path);

        $frontUrl = URL::temporarySignedRoute('public.identity-documents.show', now()->addMinute(), ['user' => $user->id, 'side' => 'front'], absolute: false);
        $backUrl = URL::temporarySignedRoute('public.identity-documents.show', now()->addMinute(), ['user' => $user->id, 'side' => 'back'], absolute: false);
        $selfieUrl = URL::temporarySignedRoute('public.biometric-selfies.show', now()->addMinute(), ['user' => $user->id], absolute: false);
        $frontDownloadUrl = URL::temporarySignedRoute('public.identity-documents.show', now()->addMinute(), ['user' => $user->id, 'side' => 'front', 'download' => 1], absolute: false);

        $this->get($frontUrl)->assertOk()->assertHeader('content-type', 'image/png');
        $this->get($backUrl)->assertOk()->assertHeader('content-type', 'image/png');
        $this->get($selfieUrl)->assertOk()->assertHeader('content-type', 'image/png');
        $this->get($frontDownloadUrl)->assertOk()->assertHeader('content-disposition', 'attachment');
    }

    public function test_signed_identity_endpoint_rejects_invalid_and_missing_documents(): void
    {
        Storage::fake('s3');
        config(['filesystems.identity_disk' => 's3']);

        $user = Usuario::factory()->create();
        $user->profile()->create(['ine_front_path' => 'identity/ine/front/missing.jpg']);

        $this->get("/api/v1/public/identity/ine/{$user->id}/front")->assertForbidden();

        $signedUrl = URL::temporarySignedRoute('public.identity-documents.show', now()->addMinute(), ['user' => $user->id, 'side' => 'front'], absolute: false);
        $this->get($signedUrl)->assertNotFound();
    }
}
