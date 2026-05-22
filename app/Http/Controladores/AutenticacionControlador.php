<?php

namespace App\Http\Controladores;

use App\Modelos\TokenApi;
use App\Modelos\Proveedor;
use App\Modelos\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AutenticacionControlador extends ControladorBase
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['required', Rule::in(['client', 'provider', 'sobrecargo'])],
            'company_name' => ['required_if:role,provider', 'nullable', 'string', 'max:255'],
            'commercial_name' => ['nullable', 'string', 'max:255'],
        ]);

        $role = $data['role'];
        $persistedRole = $role === Usuario::ROLE_SOBRECARGO ? Usuario::ROLE_CLIENT : $role;

        $user = Usuario::create($data + [
            'role' => $persistedRole,
            'operational_role' => $role === Usuario::ROLE_SOBRECARGO ? Usuario::ROLE_SOBRECARGO : null,
        ]);
        $user->syncRoles(
            $role === Usuario::ROLE_SOBRECARGO
                ? [Usuario::ROLE_CLIENT, Usuario::ROLE_SOBRECARGO]
                : [$persistedRole],
            $role
        );

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
            'user' => $user,
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
