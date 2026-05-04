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

        return $this->ok([
            'token' => TokenApi::issue($user),
            'user' => $user->fresh(['provider', 'ownedProvider', 'profile', 'roles']),
            'access' => $user->fresh(['provider', 'roles', 'demo', 'activeSuscripcion.plan'])->accessStatus(),
            'login_context' => $user->fresh(['provider', 'roles', 'demo', 'activeSuscripcion.plan'])->loginContext(),
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

        return $this->ok([
            'token' => TokenApi::issue($user),
            'user' => $user->load(['provider', 'ownedProvider', 'profile', 'roles', 'demo', 'activeSuscripcion.plan']),
            'access' => $user->accessStatus(),
            'login_context' => $user->loginContext(),
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user()->load(['provider', 'ownedProvider', 'profile', 'roles', 'demo', 'activeSuscripcion.plan', 'paymentMethods']);

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
        if ($token = $request->bearerToken()) {
            TokenApi::where('token', hash('sha256', $token))->delete();
        }

        return $this->ok(['message' => 'Sesion cerrada correctamente.']);
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
}
