<?php

namespace App\Http\Controladores;

use App\Modelos\Aeronave;
use App\Modelos\RegistroAuditoria;
use App\Modelos\Comision;
use App\Modelos\Demo;
use App\Modelos\SolicitudVuelo;
use App\Modelos\Pago;
use App\Modelos\Plan;
use App\Modelos\Proveedor;
use App\Modelos\Cotizacion;
use App\Modelos\Reserva;
use App\Modelos\Suscripcion;
use App\Modelos\ConfiguracionSistema;
use App\Modelos\Usuario;
use Illuminate\Http\Request;

class AdministradorControlador extends ControladorBase
{
    public function dashboard()
    {
        return $this->ok([
            'metrics' => [
                'users_registered' => Usuario::count(),
                'active_demos' => Demo::where('status', 'active')->where('expires_at', '>', now())->count(),
                'active_subscriptions' => Suscripcion::where('status', 'active')->where('expires_at', '>', now())->count(),
                'approved_providers' => Proveedor::where('approval_status', 'approved')->count(),
                'aircraft_registered' => Aeronave::count(),
                'flight_requests' => SolicitudVuelo::count(),
                'closed_quotes' => Cotizacion::where('status', 'accepted')->count(),
                'confirmed_reservations' => Reserva::where('status', 'confirmed')->count(),
                'revenue' => Pago::where('status', 'paid')->sum('amount'),
            ],
        ]);
    }

    public function users()
    {
        $users = Usuario::query()
            ->select([
                'id',
                'name',
                'email',
                'phone',
                'role',
                'operational_role',
                'provider_id',
                'status',
                'updated_at',
            ])
            ->with([
                'profile:id,user_id,company_name,city,base_airport,tax_data',
                'provider:id,user_id,company_name,commercial_name,approval_status',
                'ownedProvider:id,user_id,company_name,commercial_name,approval_status',
                'demo:id,user_id,status,started_at,expires_at',
                'activeSuscripcion:id,user_id,plan_id,status,started_at,expires_at',
                'activeSuscripcion.plan:id,name,code,billing_cycle',
                'roles:id,code,name',
            ])
            ->paginate(25);

        return $this->ok([
            'users' => $users->through(fn (Usuario $user) => $this->serializeAdminUserSummary($user)),
        ]);
    }

    public function showUsuario(Usuario $user)
    {
        $user->load([
            'profile',
            'provider',
            'ownedProvider',
            'demo',
            'subscriptions.plan',
            'activeSuscripcion.plan',
            'roles',
            'identityVerifications',
        ]);

        return $this->ok([
            'user' => $this->serializeAdminUserDetail($user),
        ]);
    }

    public function updateUsuario(Request $request, Usuario $user)
    {
        $user->update($request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['sometimes', 'in:client,provider,admin,sobrecargo'],
            'status' => ['sometimes', 'in:active,inactive,blocked'],
        ]));

        if ($request->filled('role')) {
            $selectedRole = $request->string('role')->toString();
            $user->syncRoles(
                $selectedRole === Usuario::ROLE_SOBRECARGO
                    ? [Usuario::ROLE_CLIENT, Usuario::ROLE_SOBRECARGO]
                    : [$selectedRole],
                $selectedRole
            );
        }

        return $this->ok(['user' => $user->fresh()]);
    }

    public function blockUsuario(Usuario $user)
    {
        $user->update(['status' => 'blocked']);

        return $this->ok(['user' => $user->fresh()]);
    }

    public function activateUsuario(Usuario $user)
    {
        $user->update(['status' => 'active']);

        return $this->ok(['user' => $user->fresh()]);
    }

    public function clients()
    {
        return $this->ok([
            'clients' => Usuario::whereHas('roles', fn ($query) => $query->where('code', Usuario::ROLE_CLIENT))
                ->whereDoesntHave('roles', fn ($query) => $query->where('code', Usuario::ROLE_SOBRECARGO))
                ->with(['profile', 'demo', 'activeSuscripcion.plan', 'roles'])
                ->paginate(25),
        ]);
    }

    public function providers()
    {
        return $this->ok(['providers' => Proveedor::with(['user', 'aircraft'])->paginate(25)]);
    }

    public function showProveedor(Proveedor $provider)
    {
        return $this->ok(['provider' => $provider->load(['user', 'aircraft'])]);
    }

    public function approveProveedor(Proveedor $provider)
    {
        $provider->update(['approval_status' => 'approved']);

        return $this->ok(['provider' => $provider->fresh('user')]);
    }

    public function rejectProveedor(Proveedor $provider)
    {
        $provider->update(['approval_status' => 'rejected']);

        return $this->ok(['provider' => $provider->fresh('user')]);
    }

    public function suspendProveedor(Proveedor $provider)
    {
        $provider->update(['approval_status' => 'suspended']);

        return $this->ok(['provider' => $provider->fresh('user')]);
    }

    public function aircraft()
    {
        return $this->ok(['aircraft' => Aeronave::with(['provider.user', 'availability'])->paginate(25)]);
    }

    public function showAeronave(Aeronave $aircraft)
    {
        return $this->ok(['aircraft' => $aircraft->load(['provider.user', 'images', 'documents', 'availability'])]);
    }

    public function blockAeronave(Aeronave $aircraft)
    {
        $aircraft->update(['status' => 'blocked']);

        return $this->ok(['aircraft' => $aircraft->fresh()]);
    }

    public function activateAeronave(Aeronave $aircraft)
    {
        $aircraft->update(['status' => 'active']);

        return $this->ok(['aircraft' => $aircraft->fresh()]);
    }

    public function flightRequests()
    {
        return $this->ok(['flight_requests' => SolicitudVuelo::with(['client', 'matches.aircraft'])->latest()->paginate(25)]);
    }

    public function quotes()
    {
        return $this->ok(['quotes' => Cotizacion::with(['flightRequest', 'provider', 'aircraft'])->latest()->paginate(25)]);
    }

    public function reservations()
    {
        return $this->ok(['reservations' => Reserva::with(['client', 'provider', 'aircraft', 'quote'])->latest()->paginate(25)]);
    }

    public function payments()
    {
        return $this->ok(['payments' => Pago::latest()->paginate(25)]);
    }

    public function commissions()
    {
        return $this->ok(['commissions' => Comision::with(['provider', 'reservation'])->latest()->paginate(25)]);
    }

    public function releaseComision(Comision $commission)
    {
        $commission->update(['status' => 'released']);

        return $this->ok(['commission' => $commission->fresh()]);
    }

    public function demos()
    {
        return $this->ok(['demos' => Demo::with('user')->latest()->paginate(25)]);
    }

    public function subscriptions()
    {
        return $this->ok(['subscriptions' => Suscripcion::with(['user', 'plan'])->latest()->paginate(25)]);
    }

    public function plans()
    {
        return $this->ok(['plans' => Plan::latest()->paginate(25)]);
    }

    public function storePlan(Request $request)
    {
        $plan = Plan::create($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:plans,slug'],
            'price' => ['required', 'numeric', 'min:0'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
            'features' => ['nullable', 'array'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]));

        return $this->ok(['plan' => $plan], 201);
    }

    public function updatePlan(Request $request, Plan $plan)
    {
        $plan->update($request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'billing_cycle' => ['sometimes', 'in:monthly,yearly'],
            'features' => ['nullable', 'array'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]));

        return $this->ok(['plan' => $plan->fresh()]);
    }

    public function reports()
    {
        return $this->ok([
            'payments_by_type' => Pago::selectRaw('payment_type, status, count(*) as count, sum(amount) as total')
                ->groupBy('payment_type', 'status')
                ->get(),
            'reservations_by_status' => Reserva::selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->get(),
            'quotes_by_status' => Cotizacion::selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->get(),
        ]);
    }

    public function audit()
    {
        return $this->ok(['audit_logs' => RegistroAuditoria::latest()->paginate(50)]);
    }

    public function settings()
    {
        return $this->ok(['settings' => ConfiguracionSistema::orderBy('group')->orderBy('key')->get()]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*.key' => ['required', 'string', 'max:255'],
            'settings.*.value' => ['nullable'],
            'settings.*.group' => ['nullable', 'string', 'max:100'],
        ]);

        foreach ($data['settings'] as $setting) {
            ConfiguracionSistema::updateOrCreate(
                ['key' => $setting['key']],
                ['value' => $setting['value'] ?? null, 'group' => $setting['group'] ?? 'general']
            );
        }

        return $this->settings();
    }

    private function serializeAdminUserSummary(Usuario $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'operational_role' => $user->operational_role,
            'effective_role' => $user->effectiveRole(),
            'provider_id' => $user->provider_id,
            'proveedor_id' => $user->provider_id,
            'status' => $user->status,
            'updated_at' => $user->updated_at,
            'roles' => $user->roles,
            'profile' => $user->profile ? [
                'company_name' => $user->profile->company_name,
                'city' => $user->profile->city,
                'base_airport' => $user->profile->base_airport,
                'tax_data' => $user->profile->tax_data,
            ] : null,
            'provider' => $user->provider ? [
                'id' => $user->provider->id,
                'company_name' => $user->provider->company_name,
                'commercial_name' => $user->provider->commercial_name,
                'approval_status' => $user->provider->approval_status,
            ] : null,
            'ownedProvider' => $user->ownedProvider ? [
                'id' => $user->ownedProvider->id,
                'company_name' => $user->ownedProvider->company_name,
                'commercial_name' => $user->ownedProvider->commercial_name,
                'approval_status' => $user->ownedProvider->approval_status,
            ] : null,
            'demo' => $user->demo,
            'active_suscripcion' => $user->activeSuscripcion,
            'activeSuscripcion' => $user->activeSuscripcion,
        ];
    }

    private function serializeAdminUserDetail(Usuario $user): array
    {
        $summary = $this->serializeAdminUserSummary($user);
        $summary['profile'] = $user->profile;
        $summary['provider'] = $user->provider;
        $summary['ownedProvider'] = $user->ownedProvider;
        $summary['subscriptions'] = $user->subscriptions;
        $summary['identityVerifications'] = $user->identityVerifications;
        $summary['identity_verifications'] = $user->identityVerifications;

        return $summary;
    }
}
