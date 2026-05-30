<?php

namespace App\Http\Controladores;

use App\Modelos\IdentityVerification;
use App\Modelos\TokenApi;
use App\Modelos\Proveedor;
use App\Modelos\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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
            'birth_date' => ['nullable', 'date'],
            'birthDate' => ['nullable', 'date'],
            'nationality' => ['nullable', 'string', 'max:120'],
            'document_type' => ['nullable', 'string', 'max:50'],
            'document_number' => ['nullable', 'string', 'max:120'],
            'document_expiration' => ['nullable', 'date'],
            'identity_validation_required' => ['nullable', 'boolean'],
            'ine_curp' => ['nullable', 'string', 'max:32'],
            'ine_cic' => ['nullable', 'string', 'max:64'],
            'ine_ocr' => ['nullable', 'string', 'max:64'],
            'ine_scan_raw' => ['nullable', 'string'],
            'ine_scan_status' => ['nullable', 'string', 'max:40'],
            'company_name' => ['required_if:role,provider', 'nullable', 'string', 'max:255'],
            'commercial_name' => ['nullable', 'string', 'max:255'],
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
        $persistedRole = $role === Usuario::ROLE_SOBRECARGO ? Usuario::ROLE_CLIENT : $role;
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

        $user = Usuario::create($data + [
            'role' => $persistedRole,
            'operational_role' => $role === Usuario::ROLE_SOBRECARGO ? Usuario::ROLE_SOBRECARGO : null,
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
            $role === Usuario::ROLE_SOBRECARGO
                ? [Usuario::ROLE_CLIENT, Usuario::ROLE_SOBRECARGO]
                : [$persistedRole],
            $role
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
                'ine_curp' => $data['ine_curp'] ?? null,
                'ine_cic' => $data['ine_cic'] ?? null,
                'ine_ocr' => $data['ine_ocr'] ?? null,
                'ine_scan_raw' => $data['ine_scan_raw'] ?? null,
                'ine_scan_status' => $data['ine_scan_status'] ?? null,
                'ine_front_path' => $ineFrontPath,
                'ine_back_path' => $ineBackPath,
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

        if ($user->role === Usuario::ROLE_PROVIDER) {
            $provider = Proveedor::create([
                'user_id' => $user->id,
                'company_name' => $data['company_name'],
                'commercial_name' => $data['commercial_name'] ?? null,
                'approval_status' => 'pending',
            ]);

            $user->forceFill(['provider_id' => $provider->id])->save();
        }

        return $this->authenticatedResponse($request, $user->fresh(['provider', 'ownedProvider', 'profile', 'roles', 'demo', 'activeSuscripcion.plan']), 201);
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

        return $this->authenticatedResponse($request, $user->load(['provider', 'ownedProvider', 'profile', 'roles', 'demo', 'activeSuscripcion.plan']));
    }

    public function me(Request $request)
    {
        $user = $request->user()->loadMissing([
            'provider:id,user_id,company_name,commercial_name,approval_status,jet_a_price,margin_percent,fixed_fee,notes',
            'ownedProvider:id,user_id,company_name,commercial_name,approval_status,jet_a_price,margin_percent,fixed_fee,notes',
            'profile:id,user_id,company_name,business_type,country,city,address,avatar,avatar_url,tax_data',
            'roles:id,code,name',
            'demo:id,user_id,status,started_at,expires_at',
            'activeSuscripcion:id,user_id,plan_id,status,started_at,expires_at',
            'activeSuscripcion.plan:id,name,code,billing_cycle,price_monthly,price_yearly,max_aircraft,max_users,has_priority,has_concierge,has_reports,is_enterprise',
            'paymentMethods:id,user_id,type,brand,last_four,provider,is_default',
        ]);

        return $this->ok([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'operational_role' => $user->operational_role,
                'provider_id' => $user->provider_id,
                'proveedor_id' => $user->provider_id,
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
                'provider' => $user->provider,
                'owned_provider' => $user->ownedProvider,
                'ownedProvider' => $user->ownedProvider,
                'profile' => $user->profile,
                'roles' => $user->roles,
                'demo' => $user->demo,
                'active_suscripcion' => $user->activeSuscripcion,
                'activeSuscripcion' => $user->activeSuscripcion,
                'payment_methods' => $user->paymentMethods,
                'paymentMethods' => $user->paymentMethods,
                'access' => $user->accessStatus(),
                'subscription_status' => $user->resolvedSubscriptionStatus(),
                'effective_role' => $user->effectiveRole(),
            ],
            'access' => $user->accessStatus(),
            'login_context' => $user->loginContext(),
        ]);
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

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        return $this->ok(['message' => 'Si el correo existe, se enviaran instrucciones de recuperacion.']);
    }

    public function verifyEmail(Request $request)
    {
        $request->user()->forceFill(['email_verified_at' => now()])->save();

        return $this->ok(['message' => 'Correo verificado correctamente.']);
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

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        return $this->ok(['message' => 'Endpoint preparado para integrar broker de password reset.']);
    }

    private function authenticatedResponse(Request $request, Usuario $user, int $status = 200)
    {
        $plainToken = TokenApi::issue($user, 'browser-session');

        return $this->ok([
            'user' => $user,
            'access' => $user->accessStatus(),
            'login_context' => $user->loginContext(),
            'token' => $plainToken,
            'token_type' => 'Bearer',
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
