<?php

namespace App\Http\Controladores;

use App\Modelos\RegistroAuditoria;
use App\Modelos\Usuario;
use App\Servicios\Identidad\IdentityStorageServicio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdminIdentityReviewControlador extends ControladorBase
{
    public function reviewIdentity(Request $request, Usuario $user)
    {
        $data = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'reason' => ['required_if:status,rejected', 'nullable', 'string', 'max:2000'],
        ]);
        return DB::transaction(function () use ($request, $user, $data) {
            $user = Usuario::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $profile = $user->profile()->lockForUpdate()->first();
            $verification = $user->identityVerifications()->latest('id')->lockForUpdate()->first();
            abort_unless($profile && $verification, 409, 'El expediente de identidad no existe.');
            if ($user->identity_verification_status === $data['status']) {
                return $this->ok(['status' => $data['status'], 'already_reviewed' => true]);
            }
            // Do not revoke existing operational access as a side effect of KYC review.
            abort_if($user->hasRole('sobrecargo'), 409, 'El usuario ya tiene rol operacional. Requiere revisión de su acceso antes de cambiar identidad.');
            if ($data['status'] === 'approved') {
                $disk = app(IdentityStorageServicio::class)->diskName();
                foreach ([$profile->ine_front_path, $profile->ine_back_path] as $path) {
                    abort_unless($path && Storage::disk($disk)->exists($path), 409, 'Se requieren INE frente y reverso accesibles.');
                }
                $selfie = $user->biometric_selfie_path;
                abort_unless($selfie && $user->biometric_selfie_disk && Storage::disk($user->biometric_selfie_disk)->exists($selfie), 409, 'Se requiere una selfie accesible.');
            }
            $before = $user->identity_verification_status;
            $review = $this->reviewMetadata($request, $data);
            $taxData = $profile->tax_data ?? [];
            $taxData['identity_review'] = $review;
            $profile->update(['tax_data' => $taxData]);
            $user->update(['identity_verification_status' => $data['status'], 'identity_verified' => $data['status'] === 'approved', 'identity_verification_message' => $data['reason'] ?? null]);
            $verification->update(['status' => $data['status'], 'identity_verified' => $data['status'] === 'approved']);
            $this->audit($request, $user, 'identity_review', $before, $review);
            return $this->ok(['status' => $data['status'], 'review' => $review]);
        });
    }

    public function reviewCrew(Request $request, Usuario $user)
    {
        $data = $request->validate(['status' => ['required', 'in:approved,rejected'], 'reason' => ['required_if:status,rejected', 'nullable', 'string', 'max:2000']]);
        return DB::transaction(function () use ($request, $user, $data) {
            $user = Usuario::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $profile = $user->profile()->lockForUpdate()->first();
            $taxData = $profile?->tax_data ?? [];
            $application = $taxData['crew_application'] ?? [];
            if (($application['status'] ?? null) === $data['status']) {
                return $this->ok(['status' => $data['status'], 'already_reviewed' => true]);
            }
            abort_unless(($application['status'] ?? null) === 'pending', 409, 'La candidatura debe estar pendiente.');
            abort_if($user->hasRole('sobrecargo'), 409, 'El rol operacional ya está otorgado.');
            if ($data['status'] === 'approved') {
                abort_unless($user->identity_verification_status === 'approved' && $user->identity_verified, 409, 'La identidad del usuario debe estar aprobada antes de habilitar el rol operacional.');
                $type = strtoupper((string) $profile->document_type);
                $raw = strtoupper((string) $profile->ine_scan_raw);
                $markers = ['AFAC', 'LICENCIA FEDERAL', 'PERSONAL TECNICO AERONAUTICO'];
                $hasMarker = collect($markers)->contains(fn ($marker) => str_contains($raw, $marker));
                abort_unless(str_contains($type, 'LICENCIA') && preg_match('/^\d{8,}-\d{2,}$/', (string) $profile->document_number) && $hasMarker && in_array(strtolower((string) $profile->ine_scan_status), ['scanned', 'partial'], true), 409, 'La licencia debe conservar evidencia AFAC válida.');
                abort_unless(!empty($application['license_path']) && !empty($application['license_disk']) && Storage::disk($application['license_disk'])->exists($application['license_path']), 409, 'La licencia AFAC no está disponible.');
            }
            $review = $this->reviewMetadata($request, $data);
            $taxData['crew_application'] = array_merge($application, $review);
            $profile->update(['tax_data' => $taxData]);
            if ($data['status'] === 'approved') {
                $user->syncRoles(['client', 'sobrecargo'], 'sobrecargo');
            }
            $this->audit($request, $user, 'crew_application_review', 'pending', $review);
            return $this->ok(['status' => $data['status'], 'review' => $review]);
        });
    }

    public function evidence(Request $request, Usuario $user)
    {
        $files = $request->validate([
            'ine_front' => ['nullable', 'file', 'image', 'max:8192'],
            'ine_back' => ['nullable', 'file', 'image', 'max:8192'],
            'selfie' => ['nullable', 'file', 'image', 'max:8192'],
        ]);
        abort_unless(count($files), 422, 'Selecciona evidencia para completar el expediente.');
        $storage = app(IdentityStorageServicio::class);
        $stored = [];
        try {
            return DB::transaction(function () use ($request, $user, $files, $storage, &$stored) {
                $user = Usuario::whereKey($user->id)->lockForUpdate()->firstOrFail();
                abort_unless($user->identity_verification_status === 'pending', 409, 'Solo puede completarse un expediente pendiente.');
                $profile = $user->profile()->lockForUpdate()->firstOrFail();
                foreach ($files as $field => $file) {
                    $existing = $field === 'selfie' ? $user->biometric_selfie_path : $profile->{$field.'_path'};
                    abort_if($existing, 409, 'La evidencia ya existe; no se reemplaza desde esta acción.');
                    $path = $storage->store($file, 'identity/admin/'.$user->id);
                    $stored[] = $path;
                    if ($field === 'selfie') {
                        $user->update(['biometric_selfie_path' => $path, 'biometric_selfie_disk' => $storage->diskName(), 'biometric_selfie_uploaded_at' => now(), 'biometric_image_saved' => true]);
                        $verification = $user->identityVerifications()->latest('id')->firstOrFail();
                        $verification->update(['image_path' => $path]);
                    } else {
                        $profile->update([$field.'_path' => $path]);
                    }
                }
                $this->audit($request, $user, 'identity_evidence_received', 'pending', ['status' => 'pending', 'fields' => array_keys($files)]);
                return $this->ok(['status' => 'pending']);
            });
        } catch (\Throwable $error) {
            foreach ($stored as $path) {
                Storage::disk($storage->diskName())->delete($path);
            }
            throw $error;
        }
    }

    public function license(Usuario $user)
    {
        $application = $user->profile?->tax_data['crew_application'] ?? [];
        abort_unless(!empty($application['license_path']) && !empty($application['license_disk']), 404);
        abort_unless(Storage::disk($application['license_disk'])->exists($application['license_path']), 404);
        return Storage::disk($application['license_disk'])->response($application['license_path']);
    }

    private function reviewMetadata(Request $request, array $data): array
    {
        return ['status' => $data['status'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()->toIso8601String(), 'rejection_reason' => $data['status'] === 'rejected' ? $data['reason'] : null];
    }

    private function audit(Request $request, Usuario $user, string $action, ?string $before, array $review): void
    {
        RegistroAuditoria::create(['user_id' => $user->id, 'admin_user_id' => $request->user()->id, 'action' => $action, 'module' => 'identity_and_crew_review', 'description' => $action, 'old_values' => ['status' => $before], 'new_values' => $review]);
    }
}
