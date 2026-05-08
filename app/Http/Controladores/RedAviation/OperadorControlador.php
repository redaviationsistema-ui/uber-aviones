<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
use App\Modelos\Aeronave;
use App\Modelos\DisponibilidadAeronave;
use App\Modelos\Operacion;
use App\Modelos\Plan;
use App\Modelos\SolicitudVuelo;
use App\Modelos\SuscripcionAeronave;
use App\Servicios\ReintentoCoincidenciaSolicitudServicio;
use App\Servicios\RedAviation\VisibilidadServicio;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OperadorControlador extends ControladorBase
{
    public function __construct(
        private readonly VisibilidadServicio $visibilidadServicio,
        private readonly ReintentoCoincidenciaSolicitudServicio $reintentoServicio,
    )
    {
    }

    public function dashboard(Request $request)
    {
        $provider = $request->user()->provider;
        $user = $request->user()->loadMissing('activeSuscripcion.plan');
        $plan = $user->activeSuscripcion?->plan;

        return $this->ok([
            'metrics' => [
                'aeronaves' => Aeronave::where('provider_id', $provider?->id)->count(),
                'solicitudes_pendientes' => $provider
                    ? SolicitudVuelo::query()
                        ->whereHas('matches', function ($query) use ($provider) {
                            $query->where('provider_id', $provider->id)
                                ->whereIn('status', ['pending', 'sent_to_provider']);
                        })
                        ->count()
                    : 0,
            ],
            'provider' => [
                'id' => $provider?->id,
                'company_name' => $provider?->company_name,
                'commercial_name' => $provider?->commercial_name,
                'approval_status' => $provider?->approval_status,
            ],
            'membership' => $plan ? [
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'code' => $plan->code,
                'billing_cycle' => $plan->billing_cycle,
                'price_monthly' => $plan->price_monthly,
                'price_yearly' => $plan->price_yearly,
                'max_aircraft' => $plan->max_aircraft,
                'max_users' => $plan->max_users,
                'has_priority' => $plan->has_priority,
                'has_concierge' => $plan->has_concierge,
                'has_reports' => $plan->has_reports,
                'is_enterprise' => $plan->is_enterprise,
                'expires_at' => $user->activeSuscripcion?->expires_at,
            ] : null,
        ]);
    }

    public function storeAircraft(Request $request)
    {
        abort_if(! $request->user()->provider_id, 422, 'El usuario proveedor no tiene provider_id asignado.');

        $data = $request->validate($this->aircraftRules());
        $isApproved = $request->user()->provider?->approval_status === 'approved';

        $aeronave = Aeronave::create($data + [
            'provider_id' => $request->user()->provider_id,
            'status' => $isApproved ? 'active' : 'blocked',
            'currency' => 'USD',
        ]);

        return $this->ok([
            'aircraft' => $this->formatAircraftPayload(
                $aeronave->fresh(['provider.user.activeSuscripcion.plan', 'availability', 'documents', 'images'])
            ),
            'message' => $isApproved
                ? 'La aeronave fue registrada y quedó activa.'
                : 'La aeronave fue registrada y quedó bloqueada hasta activación admin.',
        ], 201);
    }

    public function indexAircraft(Request $request)
    {
        $user = $request->user()->loadMissing('activeSuscripcion.plan');
        $plan = $user->activeSuscripcion?->plan;
        $providerId = $request->user()->provider_id;
        abort_if(! $providerId, 422, 'El usuario proveedor no tiene provider_id asignado.');

        $aircraft = Aeronave::with([
            'provider.user.activeSuscripcion.plan',
            'availability',
            'documents',
            'images',
            'suscripcionesAeronave' => fn ($q) => $q->where('status', 'active')->with('plan')->latest('id'),
        ])->where('provider_id', $providerId)->latest()->get();

        return $this->ok([
            'aircraft' => $aircraft->map(fn (Aeronave $item) => $this->formatAircraftPayload($item, $plan, $aircraft->count())),
            'membership' => $plan ? [
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'billing_cycle' => $plan->billing_cycle,
                'price_monthly' => $plan->price_monthly,
                'price_yearly' => $plan->price_yearly,
                'max_aircraft' => $plan->max_aircraft,
            ] : null,
        ]);
    }

    public function updateAircraft(Request $request, Aeronave $aircraft)
    {
        abort_if($aircraft->provider_id !== $request->user()->provider_id, 403);

        $aircraft->update($request->validate($this->aircraftRules(false)));

        $user = $request->user()->loadMissing('activeSuscripcion.plan');

        return $this->ok([
            'aircraft' => $this->formatAircraftPayload(
                $aircraft->fresh(['provider.user.activeSuscripcion.plan', 'availability', 'documents', 'images']),
                $user->activeSuscripcion?->plan,
                Aeronave::where('provider_id', $request->user()->provider_id)->count()
            ),
        ]);
    }

    public function storeAvailability(Request $request)
    {
        $data = $request->validate([
            'aircraft_id' => ['required', 'exists:aircraft,id'],
            'start_datetime' => ['required', 'date'],
            'end_datetime' => ['required', 'date', 'after:start_datetime'],
            'status' => ['required', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ]);

        $aircraft = Aeronave::findOrFail($data['aircraft_id']);
        abort_if($aircraft->provider_id !== $request->user()->provider_id, 403);

        $availability = DisponibilidadAeronave::create($data);

        return $this->ok(['availability' => $availability], 201);
    }

    public function requests(Request $request)
    {
        $providerId = $request->user()->provider_id;
        abort_if(! $providerId, 422, 'El usuario proveedor no tiene provider_id asignado.');
        $solicitudes = SolicitudVuelo::with(['matches' => fn ($query) => $query->where('provider_id', $providerId), 'matches.aircraft'])
            ->whereHas('matches', fn ($query) => $query->where('provider_id', $providerId))
            ->latest()
            ->get()
            ->map(fn ($solicitud) => $this->visibilidadServicio->solicitudParaOperador($solicitud));

        return $this->ok(['requests' => $solicitudes]);
    }

    public function accept(Request $request, SolicitudVuelo $flightRequest)
    {
        $providerId = $request->user()->provider_id;
        abort_if(! $providerId, 422, 'El usuario proveedor no tiene provider_id asignado.');
        $match = $flightRequest->matches()->where('provider_id', $providerId)->firstOrFail();

        $match->update([
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        $flightRequest->update(['workflow_status' => 'aceptada']);

        $operacion = Operacion::create([
            'flight_request_id' => $flightRequest->id,
            'provider_id' => $providerId,
            'aircraft_id' => $match->aircraft_id,
            'status' => 'confirmada',
        ]);

        $operacion->timeline()->create([
            'status' => 'confirmada',
            'title' => 'Operador asignado',
            'description' => 'La operacion fue aceptada por un operador verificado.',
            'created_by' => $request->user()->id,
        ]);

        $chat = $flightRequest->chatsProtegidos()->first();
        if ($chat && ! $chat->provider_id) {
            $chat->update(['provider_id' => $providerId]);
        }

        return $this->ok(['operation' => $operacion->load('timeline')]);
    }

    public function reject(Request $request, SolicitudVuelo $flightRequest)
    {
        $providerId = $request->user()->provider_id;
        abort_if(! $providerId, 422, 'El usuario proveedor no tiene provider_id asignado.');
        $match = $flightRequest->matches()->where('provider_id', $providerId)->firstOrFail();
        $match->update([
            'status' => 'rejected',
            'rejected_at' => now(),
        ]);

        return $this->ok([
            'match' => $match->fresh(),
            'retry' => $this->reintentoServicio->manejarRechazo($flightRequest),
        ]);
    }

    public function subscribeAircraft(Request $request, Aeronave $aircraft)
    {
        abort_if($aircraft->provider_id !== $request->user()->provider_id, 403, 'No puedes suscribir aeronaves de otro proveedor.');

        $data = $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
            'payment_provider' => ['nullable', 'string', 'max:100'],
        ]);

        $plan = Plan::findOrFail($data['plan_id']);

        SuscripcionAeronave::query()
            ->where('aircraft_id', $aircraft->id)
            ->where('status', 'active')
            ->update(['status' => 'cancelled', 'ends_at' => now()]);

        $subscription = SuscripcionAeronave::create([
            'aircraft_id' => $aircraft->id,
            'plan_id' => $plan->id,
            'user_id' => $request->user()->id,
            'status' => 'active',
            'payment_provider' => $data['payment_provider'] ?? 'manual',
            'payment_reference' => 'AC-'.strtoupper(Str::random(10)),
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);

        if (! in_array($aircraft->status, ['blocked', 'maintenance'], true)) {
            $aircraft->update(['status' => 'active']);
        }

        $user = $request->user()->loadMissing('activeSuscripcion.plan');
        $count = Aeronave::where('provider_id', $request->user()->provider_id)->count();

        return $this->ok([
            'subscription' => $subscription->load('plan'),
            'aircraft' => $this->formatAircraftPayload(
                $aircraft->fresh([
                    'provider.user.activeSuscripcion.plan',
                    'availability',
                    'documents',
                    'images',
                    'suscripcionesAeronave' => fn ($q) => $q->where('status', 'active')->with('plan')->latest('id'),
                ]),
                $user->activeSuscripcion?->plan,
                $count
            ),
        ], 201);
    }

    private function aircraftRules(bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'model' => [$required, 'string', 'max:255'],
            'registration' => [$required, 'string', 'max:50'],
            'capacity' => [$required, 'integer', 'min:1'],
            'base_airport' => [$required, 'string', 'max:20'],
            'range_km' => ['nullable', 'integer', 'min:0'],
            'speed_kmh' => ['nullable', 'integer', 'min:0'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'status' => ['sometimes', 'in:active,inactive,maintenance,blocked'],
            'security_filter' => ['nullable', 'string', 'max:50'],
            'security_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'airworthiness_status' => ['nullable', 'string', 'max:100'],
            'last_maintenance_at' => ['nullable', 'date'],
            'engine_run_at' => ['nullable', 'date'],
            'captain_training_at' => ['nullable', 'date'],
            'lodging_location' => ['nullable', 'string', 'max:150'],
            'client_fbo' => ['nullable', 'string', 'max:120'],
            'dispatch_center' => ['nullable', 'string', 'max:120'],
            'dispatch_notes' => ['nullable', 'string'],
            'security_notes' => ['nullable', 'string'],
        ];
    }

    private function formatAircraftPayload(Aeronave $aircraft, $plan = null, ?int $fleetCount = null): array
    {
        $providerUser = $aircraft->provider?->user;
        $resolvedPlan = $plan ?? $providerUser?->activeSuscripcion?->plan;
        $aircraftCount = max($fleetCount ?? ($aircraft->provider?->aircraft()->count() ?? 1), 1);
        $monthlyBase = (float) ($resolvedPlan?->price_monthly ?? $resolvedPlan?->price_yearly ?? $resolvedPlan?->price ?? 0);
        $monthlyPerAircraft = $monthlyBase > 0 ? round($monthlyBase / $aircraftCount, 2) : null;

        $activeAircraftSub = $aircraft->relationLoaded('suscripcionesAeronave')
            ? $aircraft->suscripcionesAeronave->first()
            : null;

        return [
            ...$aircraft->toArray(),
            'main_image' => $aircraft->images
                ->sortBy([
                    ['is_main', 'desc'],
                    ['sort_order', 'asc'],
                    ['id', 'asc'],
                ])
                ->values()
                ->first()?->image_url,
            'aircraft_subscription' => $activeAircraftSub ? [
                'id' => $activeAircraftSub->id,
                'plan_id' => $activeAircraftSub->plan_id,
                'status' => $activeAircraftSub->status,
                'payment_provider' => $activeAircraftSub->payment_provider,
                'payment_reference' => $activeAircraftSub->payment_reference,
                'ends_at' => $activeAircraftSub->ends_at,
                'plan' => $activeAircraftSub->plan,
            ] : null,
            'membership_context' => $resolvedPlan ? [
                'plan_id' => $resolvedPlan->id,
                'plan_name' => $resolvedPlan->name,
                'billing_cycle' => $resolvedPlan->billing_cycle,
                'max_aircraft' => $resolvedPlan->max_aircraft,
                'monthly_cost_per_aircraft' => $monthlyPerAircraft,
                'within_plan_limit' => $resolvedPlan->max_aircraft ? $aircraftCount <= $resolvedPlan->max_aircraft : true,
            ] : null,
        ];
    }
}
