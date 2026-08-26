<?php

namespace App\Http\Controladores;

use App\Modelos\Aeropuerto;
use App\Modelos\IdentityVerification;
use App\Modelos\TokenApi;
use App\Modelos\Proveedor;
use App\Modelos\Usuario;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AutenticacionControlador extends ControladorBase
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['required', Rule::in(['client', 'provider', 'sobrecargo'])],
            'operational_role' => ['nullable', Rule::in(['sobrecargo'])],
            'base' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'base_airport' => ['nullable', 'string', 'max:20'],
            'birth_date' => ['nullable', 'date'],
            'birthDate' => ['nullable', 'date'],
            'nationality' => ['nullable', 'string', 'max:120'],
            'client_type' => ['nullable', Rule::in(['individual', 'company'])],
            'curp' => ['nullable', 'string', 'max:32'],
            'tax_id' => ['nullable', 'string', 'max:80'],
            'document_type' => ['nullable', 'string', 'max:50'],
            'document_number' => ['nullable', 'string', 'max:120'],
            'document_issuing_country' => ['nullable', 'string', 'max:120'],
            'issuing_country' => ['nullable', 'string', 'max:120'],
            'identification_document_id' => ['nullable', 'string', 'max:100'],
            'identity_validation_required' => ['nullable', 'boolean'],
            'ine_curp' => ['nullable', 'string', 'max:32'],
            'ine_cic' => ['nullable', 'string', 'max:64'],
            'ine_ocr' => ['nullable', 'string', 'max:64'],
            'ine_scan_raw' => ['nullable', 'string'],
            'ine_scan_status' => ['nullable', 'string', 'max:40'],
            'company_name' => [
                Rule::requiredIf(fn () => ($request->input('role') === 'provider') && ($request->input('operational_role') !== 'sobrecargo')),
                'nullable',
                'string',
                'max:255',
            ],
            'commercial_name' => ['nullable', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'rfc' => ['nullable', 'string', 'max:50'],
            'company_phone' => ['nullable', 'string', 'max:50'],
            'company_email' => ['nullable', 'email', 'max:255'],
            'representative_name' => ['nullable', 'string', 'max:255'],
            'representative_phone' => ['nullable', 'string', 'max:50'],
            'identity_verification_status' => ['nullable', 'string', 'max:60'],
            'identity_verification_message' => ['nullable', 'string'],
            'identity_verified' => ['nullable', 'boolean'],
            'face_detected' => ['nullable', 'boolean'],
            'faces_count' => ['nullable', 'integer', 'min:0', 'max:10'],
            'face_confidence' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'face_match_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'liveness_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'image_storage_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'biometric_image_saved' => ['nullable', 'boolean'],
            'biometric_captured_at' => ['nullable', 'date'],
            'biometric_provider' => ['nullable', 'string', 'max:80'],
            'biometric_template_type' => ['nullable', 'string', 'max:80'],
            'quality_brightness' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'quality_sharpness' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pose_yaw' => ['nullable', 'numeric', 'min:0', 'max:180'],
            'pose_pitch' => ['nullable', 'numeric', 'min:0', 'max:180'],
            'pose_roll' => ['nullable', 'numeric', 'min:0', 'max:180'],
            'face_occluded' => ['nullable', 'boolean'],
            'ine_front' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'ine_back' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'selfie_biometric' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $data['client_type'] = $this->normalizeClientType($data['client_type'] ?? null);
        $data['document_type'] = $this->normalizeDocumentType($data['document_type'] ?? null);
        $data['document_issuing_country'] = $data['document_issuing_country']
            ?? $data['issuing_country']
            ?? null;

        $role = $data['role'];
        $operationalRole = $data['operational_role']
            ?? ($role === Usuario::ROLE_SOBRECARGO ? Usuario::ROLE_SOBRECARGO : null);
        $persistedRole = $role === Usuario::ROLE_SOBRECARGO ? Usuario::ROLE_CLIENT : $role;
        $registrationIdentification = $this->resolveRegistrationIdentificationRecord(
            $data['identification_document_id'] ?? null
        );

        if ($role === Usuario::ROLE_SOBRECARGO && ! $this->hasValidAfacLicenseEvidence($data)) {
            return response()->json([
                'success' => false,
                'message' => 'La licencia no fue validada como documento AFAC. Escanea una licencia AFAC valida antes de registrarte.',
                'errors' => [
                    'document_type' => ['La licencia debe mostrar evidencia AFAC valida.'],
                ],
            ], 422);
        }

        $documentType = $data['document_type'] ?? null;
        $requiresBackImage = $this->documentRequiresBackImage($documentType);
        $requiresCurp = $this->documentRequiresCurp($documentType);
        $hasFrontIdentityFile = $request->hasFile('ine_front');
        $hasBackIdentityFile = $request->hasFile('ine_back');
        $hasScannedIdentityFiles = $hasFrontIdentityFile || $hasBackIdentityFile;

        if (
            $role !== Usuario::ROLE_SOBRECARGO
            && $request->boolean('identity_validation_required')
            && $documentType === 'INE'
            && $hasFrontIdentityFile
            && ! $hasBackIdentityFile
        ) {
            return response()->json([
                'success' => false,
                'message' => 'La INE debe incluir frente y reverso para completar el registro.',
                'errors' => [
                    'ine_back' => ['El reverso de la INE es obligatorio.'],
                ],
            ], 422);
        }

        if (
            $role !== Usuario::ROLE_SOBRECARGO
            && $request->boolean('identity_validation_required')
            && ! $registrationIdentification
            && ! $hasScannedIdentityFiles
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Escanea tu identificación oficial o súbela en PDF antes de completar el registro.',
                'errors' => [
                    'identification_document_id' => ['La identificación oficial aún no fue guardada o escaneada.'],
                ],
            ], 422);
        }

        if (
            $role !== Usuario::ROLE_SOBRECARGO
            && $request->boolean('identity_validation_required')
            && $requiresCurp
            && blank($data['curp'] ?? $data['ine_curp'] ?? null)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'La CURP es obligatoria para registrar una INE.',
                'errors' => [
                    'ine_curp' => ['La CURP es obligatoria cuando el documento es INE.'],
                ],
            ], 422);
        }

        $ineFrontPath = $request->hasFile('ine_front')
            ? $request->file('ine_front')->store('identity/ine/front', 'private')
            : null;
        $ineBackPath = $request->hasFile('ine_back')
            ? $request->file('ine_back')->store('identity/ine/back', 'private')
            : null;
        $birthDate = $data['birth_date'] ?? $data['birthDate'] ?? null;
        $baseAirport = $data['base_airport'] ?? $data['base'] ?? null;
        $baseCity = $data['city'] ?? $data['base'] ?? null;
        $resolvedBaseAirport = $this->findAirportByCode($baseAirport);

        $user = Usuario::create($data + [
            'role' => $persistedRole,
            'operational_role' => $operationalRole,
            'identity_verification_status' => $data['identity_verification_status'] ?? null,
            'identity_verification_message' => $data['identity_verification_message'] ?? null,
            'identity_verified' => (bool) ($data['identity_verified'] ?? false),
            'face_detected' => (bool) ($data['face_detected'] ?? false),
            'face_match_score' => $data['face_match_score'] ?? null,
            'liveness_score' => $data['liveness_score'] ?? null,
            'image_storage_score' => $data['image_storage_score'] ?? null,
            'biometric_image_saved' => (bool) ($data['biometric_image_saved'] ?? false),
            'biometric_captured_at' => $data['biometric_captured_at'] ?? null,
            'biometric_provider' => $data['biometric_provider'] ?? null,
            'biometric_template_type' => $data['biometric_template_type'] ?? null,
            'biometric_selfie_path' => null,
            'biometric_selfie_disk' => null,
            'biometric_selfie_uploaded_at' => null,
        ]);

        $selfiePath = null;
        if ($request->hasFile('selfie_biometric')) {
            [$selfiePath, $selfieDisk] = $this->storeBiometricSelfie(
                $request->file('selfie_biometric'),
                $user
            );

            $user->forceFill([
                'biometric_selfie_path' => $selfiePath,
                'biometric_selfie_disk' => $selfieDisk,
                'biometric_selfie_uploaded_at' => now(),
                'biometric_image_saved' => true,
            ])->save();
        }

        $user->syncRoles(
            array_values(array_filter(array_unique([$persistedRole, $operationalRole]))),
            $operationalRole ?: $role
        );

        $profileTaxData = [];

        if ($registrationIdentification) {
            $profileTaxData['official_identification'] = $registrationIdentification;
        }

        $user->profile()->updateOrCreate(
            ['user_id' => $user->id],
            $this->filterPersistableProfileAttributes([
                'client_type' => $data['client_type'] ?? null,
                'company_name' => $role === Usuario::ROLE_CLIENT ? ($data['company_name'] ?? null) : null,
                'tax_id' => $data['tax_id'] ?? null,
                'birth_date' => $birthDate,
                'nationality' => $data['nationality'] ?? null,
                'document_type' => $data['document_type'] ?? null,
                'document_number' => $data['document_number'] ?? null,
                'document_issuing_country' => $data['document_issuing_country'] ?? null,
                'identity_validation_required' => $request->boolean('identity_validation_required'),
                'ine_curp' => $data['curp'] ?? $data['ine_curp'] ?? null,
                'ine_cic' => $data['ine_cic'] ?? null,
                'ine_ocr' => $data['ine_ocr'] ?? null,
                'ine_scan_raw' => $data['ine_scan_raw'] ?? null,
                'ine_scan_status' => $data['ine_scan_status'] ?? null,
                'ine_front_path' => $ineFrontPath,
                'ine_back_path' => $ineBackPath,
                'tax_data' => $profileTaxData ?: null,
                'city' => $baseCity,
                'base_airport' => $baseAirport,
                'base_airport_id' => $resolvedBaseAirport?->id,
            ])
        );

        if (
            $selfiePath
            || ! empty($data['identity_verification_status'])
            || array_key_exists('face_confidence', $data)
        ) {
            IdentityVerification::create([
                'user_id' => $user->id,
                'provider' => $data['biometric_provider'] ?? 'camera_capture',
                'template_type' => $data['biometric_template_type'] ?? 'selfie-photo',
                'identity_verified' => (bool) ($data['identity_verified'] ?? false),
                'status' => $data['identity_verification_status']
                    ?? ((bool) ($data['identity_verified'] ?? false) ? 'approved' : 'pending'),
                'face_confidence' => $data['face_confidence'] ?? null,
                'face_match_score' => $data['face_match_score'] ?? null,
                'liveness_score' => $data['liveness_score'] ?? null,
                'brightness' => $data['quality_brightness'] ?? null,
                'sharpness' => $data['quality_sharpness'] ?? null,
                'yaw' => $data['pose_yaw'] ?? null,
                'pitch' => $data['pose_pitch'] ?? null,
                'roll' => $data['pose_roll'] ?? null,
                'face_occluded' => (bool) ($data['face_occluded'] ?? false),
                'image_path' => $selfiePath,
            ]);
        }

        $responseExtras = [];

        if ($user->role === Usuario::ROLE_PROVIDER && $user->operational_role !== Usuario::ROLE_SOBRECARGO) {
            $provider = Proveedor::create([
                'user_id' => $user->id,
                'company_name' => $data['company_name'],
                'commercial_name' => $data['commercial_name'] ?? $data['company_name'],
                'legal_name' => $data['legal_name'] ?? null,
                'rfc' => $data['rfc'] ?? null,
                'company_phone' => $data['company_phone'] ?? $data['phone'] ?? null,
                'company_email' => $data['company_email'] ?? $data['email'] ?? null,
                'base_airport' => $baseAirport,
                'status' => 'pending',
                'representative_name' => $data['representative_name'] ?? $data['name'],
                'representative_phone' => $data['representative_phone'] ?? $data['phone'] ?? null,
                'birth_date' => $birthDate,
                'curp' => $data['curp'] ?? $data['ine_curp'] ?? null,
                'nationality' => $data['nationality'] ?? null,
                'document_type' => $data['document_type'] ?? null,
                'document_number' => $data['document_number'] ?? null,
                'document_expiration' => $data['document_expiration'] ?? null,
                'approval_status' => 'pending',
            ]);

            $user->forceFill(['provider_id' => $provider->id])->save();

            $responseExtras = [
                'message' => 'Proveedor registrado. Pendiente de validacion por Admin.',
                'provider_status' => 'pending_validation',
                'approval_status' => 'pending',
            ];
        }

        if ($registrationIdentification) {
            $this->forgetRegistrationIdentificationRecord($registrationIdentification['id'] ?? null);
        }

        return $this->authenticatedResponse($request, $user->fresh(), 201, $responseExtras);
    }

    public function storeRegistrationIdentification(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:20480'],
            'document_name' => ['required', 'string', 'max:150'],
            'document_type' => ['required', 'string', 'max:50'],
            'document_category' => ['required', 'string', 'max:50'],
            'document_slot' => ['required', 'string', 'max:50'],
            'full_name' => ['required', 'string', 'max:200'],
            'phone' => ['required', 'string', 'max:50'],
            'birth_date' => ['required', 'date'],
            'document_number' => ['required', 'string', 'max:120'],
            'nationality' => ['required', 'string', 'max:120'],
            'curp' => ['nullable', 'string', 'max:32'],
            'document_issuing_country' => ['nullable', 'string', 'max:120'],
            'requires_identity_validation' => ['required', 'boolean'],
            'replace_document_id' => ['nullable', 'string', 'max:100'],
        ]);

        $data['document_type'] = $this->normalizeDocumentType($data['document_type'] ?? null);

        if ($this->documentRequiresCurp($data['document_type'] ?? null) && blank($data['curp'] ?? null)) {
            return response()->json([
                'success' => false,
                'message' => 'La CURP es obligatoria cuando el documento es INE.',
                'errors' => [
                    'curp' => ['La CURP es obligatoria cuando el documento es INE.'],
                ],
            ], 422);
        }

        $previousDocument = $this->resolveRegistrationIdentificationRecord($data['replace_document_id'] ?? null);
        if ($previousDocument) {
            $this->deleteStoredRegistrationIdentification($previousDocument);
            $this->forgetRegistrationIdentificationRecord($previousDocument['id'] ?? null);
        }

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $safeBaseName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), '_') ?: 'identificacion';
        $documentId = (string) Str::uuid();
        $path = $file->storeAs(
            'registration/identification',
            $safeBaseName.'_'.Str::lower(Str::random(8)).'.pdf',
            's3'
        );

        abort_if(! $path, 500, 'No se pudo guardar la identificación oficial.');

        $documentUrl = Storage::disk('s3')->url($path);
        $documentRecord = [
            'id' => $documentId,
            'document_name' => $data['document_name'],
            'document_type' => $data['document_type'],
            'document_category' => $data['document_category'],
            'document_slot' => $data['document_slot'],
            'full_name' => $data['full_name'],
            'phone' => $data['phone'],
            'birth_date' => $data['birth_date'],
            'document_number' => $data['document_number'],
            'nationality' => $data['nationality'],
            'curp' => $data['curp'] ?? null,
            'document_issuing_country' => $data['document_issuing_country'] ?? null,
            'requires_identity_validation' => (bool) $data['requires_identity_validation'],
            'storage_disk' => 's3',
            'storage_path' => $path,
            'file_url' => $documentUrl,
            'document_url' => $documentUrl,
            'mime_type' => $file->getClientMimeType() ?: 'application/pdf',
            'file_size_bytes' => $file->getSize(),
            'file_name' => basename($path),
            'original_name' => $file->getClientOriginalName(),
            'uploaded_at' => now()->toIso8601String(),
        ];

        $this->storeRegistrationIdentificationRecord($documentRecord);

        return $this->ok([
            'document' => $documentRecord,
            'message' => 'Identificación oficial guardada correctamente.',
        ], 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = Usuario::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Credenciales invalidas.',
            ], 401);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'La cuenta no esta activa.',
            ], 403);
        }

        return $this->authenticatedResponse($request, $user);
    }

    public function me(Request $request)
    {
        $user = $this->loadAuthUser($request->user());

        return $this->ok([
            'user' => $this->serializeAuthUser($user),
            'access' => $user->accessStatus(),
            'login_context' => $user->loginContext(),
        ]);
    }

    private function findAirportByCode(?string $code): ?Aeropuerto
    {
        $normalizedCode = strtoupper(trim((string) $code));

        if ($normalizedCode === '') {
            return null;
        }

        return Aeropuerto::query()
            ->where('icao', $normalizedCode)
            ->orWhere('iata', $normalizedCode)
            ->first();
    }

    private function registrationIdentificationCacheKey(?string $documentId): string
    {
        return 'registration_identification:'.trim((string) $documentId);
    }

    private function storeRegistrationIdentificationRecord(array $documentRecord): void
    {
        Cache::put(
            $this->registrationIdentificationCacheKey($documentRecord['id'] ?? ''),
            $documentRecord,
            now()->addHours(6)
        );
    }

    private function resolveRegistrationIdentificationRecord(?string $documentId): ?array
    {
        $normalizedId = trim((string) $documentId);
        if ($normalizedId === '') {
            return null;
        }

        $record = Cache::get($this->registrationIdentificationCacheKey($normalizedId));

        return is_array($record) ? $record : null;
    }

    private function forgetRegistrationIdentificationRecord(?string $documentId): void
    {
        $normalizedId = trim((string) $documentId);
        if ($normalizedId === '') {
            return;
        }

        Cache::forget($this->registrationIdentificationCacheKey($normalizedId));
    }

    private function deleteStoredRegistrationIdentification(?array $documentRecord): void
    {
        if (! is_array($documentRecord)) {
            return;
        }

        $disk = trim((string) ($documentRecord['storage_disk'] ?? ''));
        $path = trim((string) ($documentRecord['storage_path'] ?? ''));
        if ($disk === '' || $path === '' || config("filesystems.disks.{$disk}") === null) {
            return;
        }

        $storage = Storage::disk($disk);
        if ($storage->exists($path)) {
            $storage->delete($path);
        }
    }

    private function hasValidAfacLicenseEvidence(array $data): bool
    {
        $documentType = Str::of((string) ($data['document_type'] ?? ''))->upper()->value();
        $scanRaw = Str::of((string) ($data['ine_scan_raw'] ?? ''))->upper()->value();
        $scanStatus = Str::of((string) ($data['ine_scan_status'] ?? ''))->lower()->value();
        $documentNumber = trim((string) ($data['document_number'] ?? ''));

        if (! str_contains($documentType, 'LICENCIA')) {
            return false;
        }

        if (! preg_match('/^\d{8,}-\d{2,}$/', $documentNumber)) {
            return false;
        }

        $hasAfacMarkers = str_contains($scanRaw, 'AFAC')
            || str_contains($scanRaw, 'LICENCIA FEDERAL')
            || str_contains($scanRaw, 'PERSONAL TECNICO AERONAUTICO')
            || str_contains($scanRaw, '"AFAC"')
            || str_contains($scanRaw, '"AFAC_DETECTED": TRUE')
            || str_contains($scanRaw, '"AUTORIDAD_DOCUMENTO": "AFAC"');

        if (! $hasAfacMarkers) {
            return false;
        }

        return in_array($scanStatus, ['scanned', 'partial'], true);
    }

    public function redirectDashboard(Request $request)
    {
        $user = $request->user()->load(['roles', 'demo', 'activeSuscripcion.plan']);

        return $this->ok([
            'dashboard' => $user->dashboardPath(),
            'login_context' => $user->loginContext(),
        ]);
    }

    public function logout(Request $request)
    {
        $plainToken = $request->bearerToken() ?: $request->cookie($this->authCookieName());

        if ($plainToken) {
            TokenApi::where('token', hash('sha256', $plainToken))->delete();
        }

        return $this->ok(['message' => 'Sesion cerrada correctamente.'])
            ->withoutCookie($this->authCookieName(), '/', env('SESSION_DOMAIN'));
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $status = Password::broker()->sendResetLink([
            'email' => $data['email'],
        ]);

        $user = Usuario::query()->where('email', $data['email'])->first();
        if ($user) {
            $this->writeAuditEntry(
                $user->id,
                'password_reset_link_sent',
                'auth',
                'Se envio un enlace de recuperacion de contrasena.',
                ['new_values' => ['email' => $data['email']]],
                $request->ip(),
                $request->userAgent(),
            );
        }

        if ($status !== Password::RESET_LINK_SENT) {
            return $this->ok(['message' => 'Si el correo existe, se enviaran instrucciones de recuperacion.']);
        }

        return $this->ok(['message' => 'Si el correo existe, se enviaran instrucciones de recuperacion.']);
    }

    public function sendEmailVerificationNotification(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return $this->ok(['message' => 'El correo ya estaba verificado.']);
        }

        $user->sendEmailVerificationNotification();
        $this->writeAudit($request, 'email_verification_link_sent', 'auth', 'Se envio un nuevo enlace de verificacion.', [
            'new_values' => ['email' => $user->email],
        ]);

        return $this->ok(['message' => 'Enviamos un nuevo enlace de verificacion al correo registrado.']);
    }

    public function showResetPassword(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = Usuario::query()->where('email', $data['email'])->first();
        abort_if(! $user, 404, 'No encontramos un usuario para este enlace de recuperacion.');
        abort_unless(Password::broker()->tokenExists($user, $token), 403, 'El enlace de recuperacion ya no es valido o expiro.');

        return $this->ok([
            'message' => 'Token de recuperacion valido.',
            'token' => $token,
            'email' => $data['email'],
        ]);
    }

    public function verifyEmail(Request $request, int $id, string $hash): JsonResponse
    {
        abort_unless($request->hasValidSignature(), 403, 'El enlace de verificacion ya no es valido.');

        $user = Usuario::query()->findOrFail($id);

        abort_unless(hash_equals((string) $hash, sha1((string) $user->getEmailForVerification())), 403, 'El enlace de verificacion no corresponde a este usuario.');

        if ($user->hasVerifiedEmail()) {
            return $this->ok([
                'message' => 'Correo verificado correctamente.',
                'verified' => true,
            ]);
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        $this->writeAuditEntry(
            $user->id,
            'email_verified',
            'auth',
            'Correo verificado correctamente.',
            ['new_values' => ['email_verified_at' => $user->fresh()->email_verified_at]],
            $request->ip(),
            $request->userAgent(),
        );

        return $this->ok([
            'message' => 'Correo verificado correctamente.',
            'verified' => true,
        ]);
    }

    public function updatePerfil(Request $request)
    {
        $userData = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'client_type' => ['nullable', Rule::in(['individual', 'company'])],
            'business_type' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:80'],
            'tax_data' => ['nullable', 'array'],
            'country' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'birth_date' => ['nullable', 'date'],
            'nationality' => ['nullable', 'string', 'max:120'],
            'document_type' => ['nullable', 'string', 'max:50'],
            'document_number' => ['nullable', 'string', 'max:120'],
            'document_issuing_country' => ['nullable', 'string', 'max:120'],
            'base_airport' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'avatar' => ['nullable', 'string', 'max:255'],
            'avatar_url' => ['nullable', 'string', 'max:255'],
        ]);

        if (array_key_exists('document_type', $userData)) {
            $userData['document_type'] = $this->normalizeDocumentType($userData['document_type']);
        }
        if (array_key_exists('client_type', $userData)) {
            $userData['client_type'] = $this->normalizeClientType($userData['client_type']);
        }
        if (array_key_exists('base_airport', $userData)) {
            $resolvedBaseAirport = $this->findAirportByCode($userData['base_airport']);
            $userData['base_airport_id'] = $resolvedBaseAirport?->id;
        }

        if (isset($userData['avatar_url']) && ! isset($userData['avatar'])) {
            $userData['avatar'] = $userData['avatar_url'];
        }

        $request->user()->update(collect($userData)->only(['name', 'phone'])->all());
        $request->user()->profile()->updateOrCreate(
            ['user_id' => $request->user()->id],
            $this->filterPersistableProfileAttributes(
                collect($userData)->except(['name', 'phone'])->all()
            )
        );

        return $this->ok(['user' => $request->user()->fresh(['profile', 'provider', 'ownedProvider'])]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::broker()->reset(
            $data,
            function (Usuario $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $user->apiTokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'success' => false,
                'message' => __($status),
            ], 422);
        }

        $user = Usuario::query()->where('email', $data['email'])->first();
        if ($user) {
            $this->writeAuditEntry(
                $user->id,
                'password_reset_completed',
                'auth',
                'Contrasena restablecida correctamente.',
                ['new_values' => ['email' => $data['email']]],
                $request->ip(),
                $request->userAgent(),
            );
        }

        return $this->ok(['message' => 'Contrasena actualizada correctamente.']);
    }

    private function authenticatedResponse(Request $request, Usuario $user, int $status = 200, array $extra = [])
    {
        $user = $this->loadAuthUser($user);
        $plainToken = TokenApi::issue($user, 'browser-session');

        return $this->ok([
            'user' => $this->serializeAuthUser($user),
            'access' => $user->accessStatus(),
            'login_context' => $user->loginContext(),
            'token' => $plainToken,
            'token_type' => 'Bearer',
            ...$extra,
        ], $status)->cookie(
            $this->authCookieName(),
            $plainToken,
            $this->authCookieLifetimeMinutes(),
            '/',
            env('SESSION_DOMAIN'),
            $this->shouldUseSecureCookies($request),
            true,
            false,
            $this->authCookieSameSite($request)
        );
    }

    private function loadAuthUser(Usuario $user): Usuario
    {
        $profileRelation = $this->buildAuthProfileRelation();
        $relations = $this->usesSlimClientAuthPayload($user)
            ? [
                $profileRelation,
                'profile.baseAirport:id,icao,iata,name',
                'roles:id,code,name',
                'demo:id,user_id,status,started_at,expires_at',
                'activeSuscripcion' => fn ($query) => $query->select([
                    'subscriptions.id',
                    'subscriptions.user_id',
                    'subscriptions.plan_id',
                    'subscriptions.status',
                    'subscriptions.started_at',
                    'subscriptions.expires_at',
                ]),
                'activeSuscripcion.plan:id,name,code,billing_cycle,price_monthly,price_yearly,max_aircraft,max_users,has_priority,has_concierge,has_reports,is_enterprise',
            ]
            : [
                'provider:id,user_id,company_name,commercial_name,legal_name,rfc,company_phone,company_email,base_airport,status,representative_name,representative_phone,birth_date,curp,nationality,document_type,document_number,document_expiration,approval_status,admin_validation_status,operator_status,access_enabled,admin_review_submitted_at,changes_notes,rejection_reason,jet_a_price,margin_percent,fixed_fee,notes',
                'ownedProvider:id,user_id,company_name,commercial_name,legal_name,rfc,company_phone,company_email,base_airport,status,representative_name,representative_phone,birth_date,curp,nationality,document_type,document_number,document_expiration,approval_status,admin_validation_status,operator_status,access_enabled,admin_review_submitted_at,changes_notes,rejection_reason,jet_a_price,margin_percent,fixed_fee,notes',
                $profileRelation,
                'profile.baseAirport:id,icao,iata,name',
                'roles:id,code,name',
                'demo:id,user_id,status,started_at,expires_at',
                'activeSuscripcion' => fn ($query) => $query->select([
                    'subscriptions.id',
                    'subscriptions.user_id',
                    'subscriptions.plan_id',
                    'subscriptions.status',
                    'subscriptions.started_at',
                    'subscriptions.expires_at',
                ]),
                'activeSuscripcion.plan:id,name,code,billing_cycle,price_monthly,price_yearly,max_aircraft,max_users,has_priority,has_concierge,has_reports,is_enterprise',
                'paymentMethods:id,user_id,type,brand,last_four,provider,is_default',
            ];

        return $user->loadMissing($relations);
    }

    private function usesSlimClientAuthPayload(Usuario $user): bool
    {
        return $user->role === Usuario::ROLE_CLIENT && $user->operational_role !== Usuario::ROLE_SOBRECARGO;
    }

    private function serializeAuthUser(Usuario $user): array
    {
        $providerId = $user->resolvedProviderId();
        $profile = $user->profile;
        $profileColumns = array_flip($this->availableProfileColumns([
            'client_type',
            'company_name',
            'business_type',
            'tax_id',
            'country',
            'city',
            'base_airport',
            'base_airport_id',
            'birth_date',
            'nationality',
            'document_type',
            'document_number',
            'document_issuing_country',
            'address',
            'avatar',
            'avatar_url',
            'tax_data',
        ]));
        $basePayload = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at,
            'has_verified_email' => $user->hasVerifiedEmail(),
            'phone' => $user->phone,
            'role' => $user->role,
            'operational_role' => $user->operational_role,
            'provider_id' => $providerId,
            'proveedor_id' => $providerId,
            'status' => $user->status,
            'identity_verification_status' => $user->identity_verification_status,
            'identity_verification_message' => $user->identity_verification_message,
            'identity_verified' => $user->identity_verified,
            'face_detected' => $user->face_detected,
            'face_match_score' => $user->face_match_score,
            'liveness_score' => $user->liveness_score,
            'image_storage_score' => $user->image_storage_score,
            'biometric_image_saved' => $user->biometric_image_saved,
            'biometric_captured_at' => $user->biometric_captured_at,
            'biometric_provider' => $user->biometric_provider,
            'biometric_template_type' => $user->biometric_template_type,
            'biometric_selfie_path' => $user->biometric_selfie_path,
            'biometric_selfie_disk' => $user->biometric_selfie_disk,
            'biometric_selfie_uploaded_at' => $user->biometric_selfie_uploaded_at,
            'biometric_selfie_url' => $user->biometric_selfie_url,
            'profile' => $profile ? [
                'client_type' => array_key_exists('client_type', $profileColumns) ? $profile->client_type : null,
                'company_name' => array_key_exists('company_name', $profileColumns) ? $profile->company_name : null,
                'business_type' => array_key_exists('business_type', $profileColumns) ? $profile->business_type : null,
                'tax_id' => array_key_exists('tax_id', $profileColumns) ? $profile->tax_id : null,
                'country' => array_key_exists('country', $profileColumns) ? $profile->country : null,
                'city' => array_key_exists('city', $profileColumns) ? $profile->city : null,
                'base_airport' => array_key_exists('base_airport', $profileColumns) ? $profile->base_airport : null,
                'base_airport_id' => array_key_exists('base_airport_id', $profileColumns) ? $profile->base_airport_id : null,
                'birth_date' => array_key_exists('birth_date', $profileColumns) ? $profile->birth_date : null,
                'nationality' => array_key_exists('nationality', $profileColumns) ? $profile->nationality : null,
                'document_type' => array_key_exists('document_type', $profileColumns) ? $profile->document_type : null,
                'document_number' => array_key_exists('document_number', $profileColumns) ? $profile->document_number : null,
                'document_issuing_country' => array_key_exists('document_issuing_country', $profileColumns) ? $profile->document_issuing_country : null,
                'address' => array_key_exists('address', $profileColumns) ? $profile->address : null,
                'avatar' => array_key_exists('avatar', $profileColumns) ? $profile->avatar : null,
                'avatar_url' => array_key_exists('avatar_url', $profileColumns) ? $profile->avatar_url : null,
                'tax_data' => array_key_exists('tax_data', $profileColumns) ? $profile->tax_data : null,
                'base_airport_record' => $profile->baseAirport ? [
                    'id' => $profile->baseAirport->id,
                    'icao' => $profile->baseAirport->icao,
                    'iata' => $profile->baseAirport->iata,
                    'name' => $profile->baseAirport->name,
                ] : null,
            ] : null,
            'roles' => $user->roles->map(fn ($role) => [
                'id' => $role->id,
                'code' => $role->code,
                'name' => $role->name,
            ])->values(),
            'demo' => $user->demo ? [
                'status' => $user->demo->status,
                'started_at' => $user->demo->started_at,
                'expires_at' => $user->demo->expires_at,
            ] : null,
            'active_suscripcion' => $user->activeSuscripcion ? [
                'id' => $user->activeSuscripcion->id,
                'plan_id' => $user->activeSuscripcion->plan_id,
                'status' => $user->activeSuscripcion->status,
                'started_at' => $user->activeSuscripcion->started_at,
                'expires_at' => $user->activeSuscripcion->expires_at,
                'plan' => $user->activeSuscripcion->plan,
            ] : null,
            'subscription_status' => $user->resolvedSubscriptionStatus(),
            'effective_role' => $user->effectiveRole(),
        ];

        $basePayload['activeSuscripcion'] = $basePayload['active_suscripcion'];

        if ($this->usesSlimClientAuthPayload($user)) {
            return $basePayload;
        }

        if ($user->provider) {
            $basePayload['company_name'] = $user->provider->company_name;
            $basePayload['commercial_name'] = $user->provider->commercial_name;
            $basePayload['legal_name'] = $user->provider->legal_name;
        }

        $basePayload['provider'] = $user->provider;
        $basePayload['owned_provider'] = $user->ownedProvider;
        $basePayload['ownedProvider'] = $user->ownedProvider;
        $basePayload['payment_methods'] = $user->paymentMethods;
        $basePayload['paymentMethods'] = $user->paymentMethods;

        return $basePayload;
    }

    private function buildAuthProfileRelation(): string
    {
        $columns = $this->availableProfileColumns([
            'id',
            'user_id',
            'client_type',
            'company_name',
            'business_type',
            'tax_id',
            'country',
            'city',
            'base_airport',
            'base_airport_id',
            'address',
            'avatar',
            'avatar_url',
            'tax_data',
            'birth_date',
            'nationality',
            'document_type',
            'document_number',
            'document_issuing_country',
            'identity_validation_required',
            'ine_curp',
            'ine_cic',
            'ine_ocr',
            'ine_scan_raw',
            'ine_scan_status',
            'ine_front_path',
            'ine_back_path',
        ]);

        return 'profile:' . implode(',', $columns);
    }

    private function filterPersistableProfileAttributes(array $attributes): array
    {
        $persistableColumns = $this->availableProfileColumns(array_keys($attributes));

        return array_intersect_key($attributes, array_flip($persistableColumns));
    }

    private function availableProfileColumns(array $columns): array
    {
        static $profileColumnMap = null;

        if ($profileColumnMap === null) {
            $profileColumnMap = array_flip(Schema::getColumnListing('profiles'));
        }

        return array_values(array_filter(
            $columns,
            static fn (string $column): bool => array_key_exists($column, $profileColumnMap)
        ));
    }

    private function normalizeClientType(mixed $value): ?string
    {
        $normalized = Str::of((string) ($value ?? ''))->trim()->lower()->value();

        return match ($normalized) {
            '', 'null' => null,
            'company', 'empresa' => 'company',
            default => 'individual',
        };
    }

    private function normalizeDocumentType(mixed $value): ?string
    {
        $normalized = Str::of((string) ($value ?? ''))->trim()->upper()->value();

        return match ($normalized) {
            '', 'NULL' => null,
            'PASAPORTE', 'PASSPORT' => 'PASSPORT',
            default => 'INE',
        };
    }

    private function documentRequiresCurp(?string $documentType): bool
    {
        return $this->normalizeDocumentType($documentType) === 'INE';
    }

    private function documentRequiresBackImage(?string $documentType): bool
    {
        return $this->normalizeDocumentType($documentType) === 'INE';
    }

    private function storeBiometricSelfie(UploadedFile $file, Usuario $user): array
    {
        $disk = 'private';
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $directory = sprintf('clientes/%d/biometria', $user->id);
        $filename = sprintf('%s.%s', (string) Str::uuid(), $extension);
        $path = $file->storeAs($directory, $filename, $disk);

        return [$path, $disk];
    }

    private function authCookieName(): string
    {
        return (string) env('AUTH_TOKEN_COOKIE', 'red_aviation_session');
    }

    private function authCookieLifetimeMinutes(): int
    {
        return (int) env('AUTH_TOKEN_TTL_MINUTES', 60 * 24 * 30);
    }

    private function authCookieSameSite(Request $request): string
    {
        if ($this->isLocalBrowserRequest($request)) {
            return 'lax';
        }

        return (string) env('AUTH_TOKEN_SAME_SITE', 'none');
    }

    private function shouldUseSecureCookies(Request $request): bool
    {
        if ($this->isLocalBrowserRequest($request)) {
            return false;
        }

        if ($request->isSecure()) {
            return true;
        }

        $configured = env('SESSION_SECURE_COOKIE');

        if ($configured !== null) {
            return filter_var($configured, FILTER_VALIDATE_BOOL);
        }

        return app()->environment('production');
    }

    private function isLocalBrowserRequest(Request $request): bool
    {
        $host = strtolower((string) $request->getHost());
        $origin = strtolower((string) $request->headers->get('origin', ''));

        return in_array($host, ['localhost', '127.0.0.1'], true)
            || str_contains($origin, 'localhost:')
            || str_contains($origin, '127.0.0.1:');
    }
}
