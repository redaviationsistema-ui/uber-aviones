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
        return $this->ok([
            'users' => Usuario::with([
                'profile',
                'provider',
                'ownedProvider',
                'demo',
                'activeSuscripcion.plan',
                'roles',
                'identityVerifications',
            ])->paginate(25),
        ]);
    }

    public function showUsuario(Usuario $user)
    {
        return $this->ok([
            'user' => $user->load([
                'profile',
                'provider',
                'ownedProvider',
                'demo',
                'subscriptions.plan',
                'activeSuscripcion.plan',
                'roles',
                'identityVerifications',
            ]),
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
}
