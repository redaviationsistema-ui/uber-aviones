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
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
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
            'curp' => ['nullable', 'string', 'max:32'],
            'document_type' => ['nullable', 'string', 'max:50'],
            'document_number' => ['nullable', 'string', 'max:120'],
            'document_expiration' => ['nullable', 'date'],
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

        $role = $data['role'];
        $operationalRole = $data['operational_role']
            ?? ($role === Usuario::ROLE_SOBRECARGO ? Usuario::ROLE_SOBRECARGO : null);
        $persistedRole = $role === Usuario::ROLE_SOBRECARGO ? Usuario::ROLE_CLIENT : $role;

        if ($role === Usuario::ROLE_SOBRECARGO && ! $this->hasValidAfacLicenseEvidence($data)) {
            return response()->json([
                'success' => false,
                'message' => 'La licencia no fue validada como documento AFAC. Escanea una licencia AFAC valida antes de registrarte.',
                'errors' => [
                    'document_type' => ['La licencia debe mostrar evidencia AFAC valida.'],
                ],
            ], 422);
        }

        $ineFrontPath = $request->hasFile('ine_front')
            ? $request->file('ine_front')->store('identity/ine/front', 'private')
            : null;
        $ineBackPath = $request->hasFile('ine_back')
            ? $request->file('ine_back')->store('identity/ine/back', 'private')
            : null;
        $selfiePath = $request->hasFile('selfie_biometric')
            ? $request->file('selfie_biometric')->store('biometric/selfies', 'public')
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
            'biometric_selfie_path' => $selfiePath,
        ]);
        $user->syncRoles(
            array_values(array_filter(array_unique([$persistedRole, $operationalRole]))),
            $operationalRole ?: $role
        );

        $user->profile()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'birth_date' => $birthDate,
                'nationality' => $data['nationality'] ?? null,
                'document_type' => $data['document_type'] ?? null,
                'document_number' => $data['document_number'] ?? null,
                'document_expiration' => $data['document_expiration'] ?? null,
                'identity_validation_required' => $request->boolean('identity_validation_required'),
                'ine_curp' => $data['curp'] ?? $data['ine_curp'] ?? null,
                'ine_cic' => $data['ine_cic'] ?? null,
                'ine_ocr' => $data['ine_ocr'] ?? null,
                'ine_scan_raw' => $data['ine_scan_raw'] ?? null,
                'ine_scan_status' => $data['ine_scan_status'] ?? null,
                'ine_front_path' => $ineFrontPath,
                'ine_back_path' => $ineBackPath,
                'city' => $baseCity,
                'base_airport' => $baseAirport,
                'base_airport_id' => $resolvedBaseAirport?->id,
            ]
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

        return $this->authenticatedResponse($request, $user->fresh(), 201, $responseExtras);
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
            'business_type' => ['nullable', 'string', 'max:255'],
            'tax_data' => ['nullable', 'array'],
            'country' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'avatar' => ['nullable', 'string', 'max:255'],
            'avatar_url' => ['nullable', 'string', 'max:255'],
        ]);

        if (isset($userData['avatar_url']) && ! isset($userData['avatar'])) {
            $userData['avatar'] = $userData['avatar_url'];
        }

        $request->user()->update(collect($userData)->only(['name', 'phone'])->all());
        $request->user()->profile()->updateOrCreate(
            ['user_id' => $request->user()->id],
            collect($userData)->except(['name', 'phone'])->all()
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
        $relations = $this->usesSlimClientAuthPayload($user)
            ? [
                'profile:id,user_id,company_name,business_type,country,city,base_airport,base_airport_id,address,avatar,avatar_url,tax_data',
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
                'profile:id,user_id,company_name,business_type,country,city,base_airport,base_airport_id,address,avatar,avatar_url,tax_data,birth_date,nationality,document_type,document_number,document_expiration,identity_validation_required,ine_curp,ine_cic,ine_ocr,ine_scan_raw,ine_scan_status,ine_front_path,ine_back_path',
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
            'biometric_selfie_url' => $user->biometric_selfie_path
                ? Storage::disk('public')->url($user->biometric_selfie_path)
                : null,
            'profile' => $profile ? [
                'company_name' => $profile->company_name,
                'business_type' => $profile->business_type,
                'country' => $profile->country,
                'city' => $profile->city,
                'base_airport' => $profile->base_airport,
                'base_airport_id' => $profile->base_airport_id,
                'address' => $profile->address,
                'avatar' => $profile->avatar,
                'avatar_url' => $profile->avatar_url,
                'tax_data' => $profile->tax_data,
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
