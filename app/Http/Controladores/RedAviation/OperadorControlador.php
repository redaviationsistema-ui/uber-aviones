<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
use App\Modelos\Aeronave;
use App\Modelos\DisponibilidadAeronave;
use App\Modelos\DocumentoAeronave;
use App\Modelos\Operacion;
use App\Modelos\Plan;
use App\Modelos\SolicitudVuelo;
use App\Modelos\SuscripcionAeronave;
use App\Servicios\Billing\BillingPlanServicio;
use App\Servicios\ReintentoCoincidenciaSolicitudServicio;
use App\Servicios\RedAviation\VisibilidadServicio;
use App\Servicios\Vuelos\ClimbDescentCategoryResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class OperadorControlador extends ControladorBase
{
    private const PROVIDER_FORBIDDEN_AIRCRAFT_FIELDS = [
        'provider_id',
        'proveedor_id',
        'status',
        'is_active',
        'estado',
        'operational_status',
        'billing_status',
        'subscription_status',
        'approved',
        'review_status',
        'validation_status',
        'activated_at',
        'subscription_started_at',
        'subscription_ends_at',
        'last_payment_at',
        'stripe_*',
        'billing_*',
        'subscription_*',
        'checkout_session_id',
    ];

    private const DEFAULT_OPERATOR_AIRCRAFT_PAGE_SIZE = 24;
    private const MAX_OPERATOR_AIRCRAFT_PAGE_SIZE = 50;

    private const AIRCRAFT_CATEGORIES = [
        'Helicoptero',
        'Turboprop',
        'Light Jet',
        'Mid Jet',
        'Heavy Jet',
    ];

    public function __construct(
        private readonly VisibilidadServicio $visibilidadServicio,
        private readonly ReintentoCoincidenciaSolicitudServicio $reintentoServicio,
        private readonly ClimbDescentCategoryResolver $climbDescentCategoryResolver,
    )
    {
    }


    private function ensureOperationalProviderAccess(Request $request): void
    {
        if ($request->user()?->hasRole('admin')) {
            return;
        }

        $provider = $request->user()?->provider ?: $request->user()?->ownedProvider;
        abort_if(! $provider, 404, 'Proveedor no encontrado.');
        abort_if(! $provider->isApprovedForOperations(), 403, 'Tu expediente sigue en revision administrativa. El acceso operativo se habilita cuando un administrador aprueba completamente al proveedor.');
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
                'legal_name' => $provider?->legal_name,
                'rfc' => $provider?->rfc,
                'company_phone' => $provider?->company_phone,
                'company_email' => $provider?->company_email,
                'base_airport' => $provider?->base_airport,
                'status' => $provider?->status ?? $provider?->approval_status,
                'representative_name' => $provider?->representative_name,
                'representative_phone' => $provider?->representative_phone,
                'birth_date' => optional($provider?->birth_date)?->toDateString(),
                'curp' => $provider?->curp,
                'nationality' => $provider?->nationality,
                'document_type' => $provider?->document_type,
                'document_number' => $provider?->document_number,
                'document_expiration' => optional($provider?->document_expiration)?->toDateString(),
                'jet_a_price' => $provider?->jet_a_price,
                'margin_percent' => $provider?->margin_percent,
                'fixed_fee' => $provider?->fixed_fee,
                'approval_status' => $provider?->approval_status,
                'admin_validation_status' => $provider?->admin_validation_status,
                'operator_status' => $provider?->operator_status,
                'access_enabled' => (bool) ($provider?->access_enabled ?? false),
                'can_register_aircraft' => (bool) ($provider?->access_enabled ?? false),
                'admin_review_submitted_at' => optional($provider?->admin_review_submitted_at)?->toISOString(),
                'admin_notes' => $provider?->admin_notes ?? $provider?->admin_validation_notes ?? $provider?->notes,
                'admin_validation_notes' => $provider?->admin_validation_notes ?? $provider?->admin_notes ?? $provider?->notes,
                'changes_notes' => $provider?->changes_notes,
                'rejection_reason' => $provider?->rejection_reason,
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
        $this->ensureOperationalProviderAccess($request);
        $this->rejectForbiddenPayloadFields($request, self::PROVIDER_FORBIDDEN_AIRCRAFT_FIELDS, 'El proveedor no puede definir estados comerciales u operativos de la aeronave.');
        $providerId = $this->resolvedProviderIdOrAbort($request);

        $data = $this->normalizeAircraftInput($request->validate($this->aircraftRules()));
        $user = $request->user()->loadMissing('activeSuscripcion.plan');

        $aeronave = Aeronave::create($data + [
            'provider_id' => $providerId,
            'status' => 'inactive',
            'billing_status' => 'pending_payment',
            'subscription_status' => 'inactive',
            'currency' => $data['currency'] ?? 'USD',
        ]);

        return $this->ok([
            'aircraft' => $this->formatAircraftPayload(
                $aeronave->fresh(['images', 'documents', 'suscripcionesAeronave' => fn ($q) => $q->where('status', 'active')->with('plan')->latest('id')]),
                $user->activeSuscripcion?->plan,
                Aeronave::where('provider_id', $providerId)->count(),
                true
            ),
            'message' => 'Aeronave registrada y enviada a revisión administrativa.',
            'review_status' => 'pending_review',
            'status' => 'inactive',
            'redirect_to' => '/provider/aircraft/'.$aeronave->id.'/billing',
        ], 201);
    }

    public function indexAircraft(Request $request)
    {
        $user = $request->user()->loadMissing('activeSuscripcion.plan');
        $plan = $user->activeSuscripcion?->plan;
        $providerId = $this->resolvedProviderIdOrAbort($request);
        $perPage = min(max((int) $request->integer('per_page', self::DEFAULT_OPERATOR_AIRCRAFT_PAGE_SIZE), 1), self::MAX_OPERATOR_AIRCRAFT_PAGE_SIZE);

        $aircraft = Aeronave::query()
            ->select([
                'id',
                'provider_id',
                'model',
                'manufacturer',
                'category',
                'model_year',
                'registration',
                'capacity',
                'range_km',
                'speed_kmh',
                'amenities',
                'base_airport',
                'coverage',
                'airport_expenses_usd',
                'hourly_rate',
                'minimum_hours',
                'climb_descent_minutes',
                'climb_descent_source',
                'fuel_burn_gph',
                'engine_reserve_rate',
                'insurance_rate',
                'maintenance_rate',
                'crew_rate',
                'repositioning_fee',
                'overnight_fee',
                'operational_cost',
                'currency',
                'status',
                'billing_status',
                'billing_plan_id',
                'subscription_status',
                'subscription_started_at',
                'subscription_ends_at',
                'last_payment_at',
                'notes',
                'created_at',
                'updated_at',
            ])
            ->with([
                'images:id,aircraft_id,kind,title,image_url,is_main,visible_to_client,sort_order',
                'documents:id,aircraft_id,type,document_type,document_name,status,expires_at',
                'suscripcionesAeronave' => fn ($q) => $q
                    ->select([
                        'id',
                        'aircraft_id',
                        'plan_id',
                        'status',
                        'payment_provider',
                        'payment_reference',
                        'ends_at',
                    ])
                    ->where('status', 'active')
                    ->with('plan:id,name,code,billing_cycle,max_aircraft')
                    ->latest('id'),
            ])
            ->where('provider_id', $providerId)
            ->latest()
            ->paginate($perPage);

        return $this->ok([
            'aircraft' => $aircraft->getCollection()->map(
                fn (Aeronave $item) => $this->formatAircraftPayload($item, $plan, $aircraft->total(), false)
            )->values(),
            'membership' => $plan ? [
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'billing_cycle' => $plan->billing_cycle,
                'price_monthly' => $plan->price_monthly,
                'price_yearly' => $plan->price_yearly,
                'max_aircraft' => $plan->max_aircraft,
            ] : null,
            'meta' => [
                'current_page' => $aircraft->currentPage(),
                'per_page' => $aircraft->perPage(),
                'total' => $aircraft->total(),
                'last_page' => $aircraft->lastPage(),
            ],
        ]);
    }

    public function showAircraft(Request $request, Aeronave $aircraft)
    {
        abort_if($aircraft->provider_id !== $this->resolvedProviderIdOrAbort($request, 403), 403);

        $user = $request->user()->loadMissing('activeSuscripcion.plan');

        return $this->ok([
            'aircraft' => $this->formatAircraftPayload(
                $aircraft->load([
                    'images',
                    'availability',
                    'documents',
                    'suscripcionesAeronave' => fn ($q) => $q->where('status', 'active')->with('plan')->latest('id'),
                ]),
                $user->activeSuscripcion?->plan,
                Aeronave::where('provider_id', $this->resolvedProviderIdOrAbort($request))->count(),
                true
            ),
        ]);
    }

    public function updateAircraft(Request $request, Aeronave $aircraft)
    {
        $this->ensureOperationalProviderAccess($request);
        abort_if($aircraft->provider_id !== $this->resolvedProviderIdOrAbort($request, 403), 403);
        $this->rejectForbiddenPayloadFields($request, self::PROVIDER_FORBIDDEN_AIRCRAFT_FIELDS, 'El proveedor no puede modificar estados comerciales u operativos de la aeronave.');

        $aircraft->update($this->normalizeAircraftInput($request->validate($this->aircraftRules(false, $aircraft)), $aircraft));

        $user = $request->user()->loadMissing('activeSuscripcion.plan');

        return $this->ok([
            'aircraft' => $this->formatAircraftPayload(
                $aircraft->fresh([
                    'images',
                    'availability',
                    'documents',
                    'suscripcionesAeronave' => fn ($q) => $q->where('status', 'active')->with('plan')->latest('id'),
                ]),
                $user->activeSuscripcion?->plan,
                Aeronave::where('provider_id', $this->resolvedProviderIdOrAbort($request))->count(),
                true
            ),
        ]);
    }

    public function storeAvailability(Request $request)
    {
        $this->ensureOperationalProviderAccess($request);
        $data = $request->validate([
            'aircraft_id' => ['required', 'exists:aircraft,id'],
            'start_datetime' => ['required', 'date'],
            'end_datetime' => ['required', 'date', 'after:start_datetime'],
            'status' => ['required', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ]);

        $aircraft = Aeronave::findOrFail($data['aircraft_id']);
        abort_if($aircraft->provider_id !== $this->resolvedProviderIdOrAbort($request, 403), 403);

        $availability = DisponibilidadAeronave::create($data);

        return $this->ok(['availability' => $availability], 201);
    }

    public function requests(Request $request)
    {
        $providerId = $this->resolvedProviderIdOrAbort($request);

        $perPage = min(max((int) $request->integer('per_page', 20), 1), 100);
        $solicitudes = SolicitudVuelo::query()
            ->select([
                'id',
                'client_id',
                'assigned_provider_id',
                'assigned_aircraft_id',
                'assigned_aircraft_model',
                'origin',
                'destination',
                'departure_datetime',
                'passengers',
                'trip_type',
                'aircraft_type',
                'requirements',
                'visibility_payload',
                'base_price',
                'operational_fee',
                'priority_price',
                'final_price',
                'currency',
                'pricing_context',
                'payment_status',
                'status',
                'workflow_status',
                'created_at',
            ])
            ->with([
                'matches' => fn ($query) => $query
                    ->select([
                        'id',
                        'flight_request_id',
                        'aircraft_id',
                        'provider_id',
                        'estimated_price',
                        'status',
                        'response_deadline',
                        'visibility_payload',
                    ])
                    ->where('provider_id', $providerId),
                'matches.aircraft:id,model,category,capacity',
                'assignedAircraft:id,model,category,capacity',
                'legs:id,flight_request_id,leg_order,origin,destination,departure_datetime,arrival_datetime,passengers,distance_km',
                'reservation:id,flight_request_id,status',
                'reservation.contract:id,reservation_id,status',
                'reservation.latestPayment' => fn ($query) => $query->select([
                    'payments.id',
                    'payments.reservation_id',
                    'payments.status',
                ]),
                'latestOperation' => fn ($query) => $query->select([
                    'operations.id',
                    'operations.flight_request_id',
                    'operations.status',
                ]),
            ])
            ->where(function ($query) use ($providerId) {
                $query
                    ->where('assigned_provider_id', $providerId)
                    ->orWhereHas('matches', fn ($matchQuery) => $matchQuery->where('provider_id', $providerId));
            })
            ->latest()
            ->paginate($perPage);

        $requests = $solicitudes->getCollection()
            ->map(fn ($solicitud) => $this->visibilidadServicio->solicitudParaOperador($solicitud))
            ->values();

        $solicitudes->setCollection($requests);

        return $this->ok([
            'requests' => $requests,
            'pagination' => [
                'current_page' => $solicitudes->currentPage(),
                'last_page' => $solicitudes->lastPage(),
                'per_page' => $solicitudes->perPage(),
                'total' => $solicitudes->total(),
                'has_more_pages' => $solicitudes->hasMorePages(),
            ],
        ]);
    }

    public function accept(Request $request, SolicitudVuelo $flightRequest)
    {
        $providerId = $this->resolvedProviderIdOrAbort($request);
        $this->ensureOperationalProviderAccess($request);
        $match = $flightRequest->matches()->where('provider_id', $providerId)->firstOrFail();
        $match->loadMissing('aircraft');

        $match->update([
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        $visibilityPayload = $flightRequest->visibility_payload ?? [];
        $flightRequest->update([
            'workflow_status' => 'aceptada',
            'assigned_provider_id' => $providerId,
            'assigned_aircraft_id' => $match->aircraft_id,
            'assigned_aircraft_model' => $match->aircraft?->model,
            'visibility_payload' => [
                ...$visibilityPayload,
                'selected_provider_id' => $providerId,
                'selected_aircraft_id' => $match->aircraft_id,
                'aircraft_model' => $match->aircraft?->model,
                'aircraft_category' => $match->aircraft?->category,
                'aircraft_capacity' => $match->aircraft?->capacity,
            ],
        ]);

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
        $providerId = $this->resolvedProviderIdOrAbort($request);
        $this->ensureOperationalProviderAccess($request);
        $match = $flightRequest->matches()->where('provider_id', $providerId)->firstOrFail();
        $match->update([
            'status' => 'rejected',
            'rejected_at' => now(),
        ]);

        if ((int) $flightRequest->assigned_provider_id === (int) $providerId) {
            $visibilityPayload = $flightRequest->visibility_payload ?? [];
            $flightRequest->update([
                'assigned_provider_id' => null,
                'assigned_aircraft_id' => null,
                'assigned_aircraft_model' => null,
                'visibility_payload' => [
                    ...$visibilityPayload,
                    'selected_provider_id' => null,
                    'selected_aircraft_id' => null,
                    'aircraft_model' => null,
                    'aircraft_category' => null,
                    'aircraft_capacity' => null,
                ],
            ]);
        }

        return $this->ok([
            'match' => $match->fresh(),
            'retry' => $this->reintentoServicio->manejarRechazo($flightRequest),
        ]);
    }

    public function subscribeAircraft(Request $request, Aeronave $aircraft)
    {
        abort_if($aircraft->provider_id !== $this->resolvedProviderIdOrAbort($request, 403), 403, 'No puedes suscribir aeronaves de otro proveedor.');
        $this->ensureOperationalProviderAccess($request);

        $data = $request->validate([
            'plan_id' => ['nullable', 'exists:plans,id'],
            'payment_provider' => ['nullable', 'string', 'max:100'],
        ]);

        $plan = isset($data['plan_id'])
            ? Plan::findOrFail($data['plan_id'])
            : Plan::query()
                ->where('code', BillingPlanServicio::PROVIDER_AIRCRAFT_MONTHLY_CODE)
                ->where(function ($query) {
                    $query->where('status', 'active')->orWhere('is_active', true);
                })
                ->first();

        $aircraft->update([
            'status' => 'inactive',
            'billing_status' => 'pending_payment',
            'billing_plan_id' => $plan?->id,
            'subscription_status' => 'inactive',
            'subscription_started_at' => null,
            'subscription_ends_at' => null,
            'last_payment_at' => null,
        ]);

        $user = $request->user()->loadMissing('activeSuscripcion.plan');
        $count = Aeronave::where('provider_id', $this->resolvedProviderIdOrAbort($request))->count();

        return $this->ok([
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
            'subscription' => null,
            'billing_status' => 'pending_payment',
            'subscription_status' => 'inactive',
            'redirect_to' => '/provider/aircraft/'.$aircraft->id.'/billing',
            'message' => 'La aeronave quedó pendiente de pago. Continúa con la activación mensual en Stripe.',
        ], 201);
    }

    private function aircraftRules(bool $creating = true, ?Aeronave $aircraft = null): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'model' => [$required, 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', Rule::in(self::AIRCRAFT_CATEGORIES)],
            'model_year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'registration' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('aircraft', 'registration')->ignore($aircraft?->id),
            ],
            'capacity' => [$required, 'integer', 'min:1'],
            'base_airport' => [$required, 'string', 'max:20'],
            'range_km' => ['nullable', 'integer', 'min:0'],
            'speed_kmh' => ['nullable', 'integer', 'min:0'],
            'speed_knots' => ['nullable', 'numeric', 'min:0'],
            'coverage' => ['nullable', 'string', 'max:255'],
            'amenities' => ['nullable'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'airport_expenses_usd' => ['nullable', 'numeric', 'min:0'],
            'minimum_hours' => ['nullable', 'numeric', 'min:0'],
            'climb_descent_minutes' => ['nullable', 'integer', 'min:0'],
            'operational_cost' => ['nullable', 'numeric', 'min:0'],
            'fuel_burn_gph' => ['nullable', 'numeric', 'min:0'],
            'engine_reserve_rate' => ['nullable', 'numeric', 'min:0'],
            'insurance_rate' => ['nullable', 'numeric', 'min:0'],
            'maintenance_rate' => ['nullable', 'numeric', 'min:0'],
            'crew_rate' => ['nullable', 'numeric', 'min:0'],
            'repositioning_fee' => ['nullable', 'numeric', 'min:0'],
            'overnight_fee' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
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

    private function normalizeAircraftInput(array $data, ?Aeronave $aircraft = null): array
    {
        if (! array_key_exists('speed_kmh', $data) && array_key_exists('speed_knots', $data)) {
            $data['speed_kmh'] = (int) round(((float) $data['speed_knots']) * 1.852);
        }

        unset($data['speed_knots']);

        if (! array_key_exists('model_year', $data) && array_key_exists('year', $data)) {
            $data['model_year'] = $data['year'];
        }

        unset($data['year']);

        if (array_key_exists('manufacturer', $data)) {
            $data['manufacturer'] = $this->normalizeNullableString($data['manufacturer']);
        }

        if (array_key_exists('category', $data)) {
            $data['category'] = $this->normalizeAircraftCategory($data['category']);
        }

        $resolvedCategory = $data['category'] ?? $this->normalizeAircraftCategory($aircraft?->category);
        if ($resolvedCategory) {
            $data['minimum_hours'] = $this->resolveMinimumHoursForCategory($resolvedCategory);
        }
        $data = $this->normalizeClimbDescentInput($data, $resolvedCategory, $aircraft);

        if (array_key_exists('coverage', $data)) {
            $data['coverage'] = $this->normalizeNullableString($data['coverage']);
        }

        if (array_key_exists('base_airport', $data)) {
            $data['base_airport'] = $this->normalizeNullableString($data['base_airport']);
        }

        if (array_key_exists('registration', $data)) {
            $data['registration'] = $this->normalizeRegistration($data['registration']);
        }

        if (array_key_exists('model', $data)) {
            $data['model'] = $this->normalizeNullableString($data['model']);
        }

        if (array_key_exists('amenities', $data)) {
            $data['amenities'] = $this->normalizeAmenities($data['amenities']);
        }

        return $data;
    }

    private function resolveMinimumHoursForCategory(?string $category): float
    {
        return match ($this->normalizeAircraftCategory($category)) {
            'Turboprop' => 1.5,
            'Heavy Jet' => 3.0,
            'Light Jet', 'Mid Jet', 'Helicoptero', null => 2.0,
            default => 2.0,
        };
    }

    private function resolveClimbDescentMinutesForCategory(?string $category): int
    {
        return $this->climbDescentCategoryResolver->resolveClimbDescentMinutesForCategory($category);
    }

    private function normalizeClimbDescentInput(array $data, ?string $resolvedCategory, ?Aeronave $aircraft = null): array
    {
        if (! $resolvedCategory) {
            return $data;
        }

        $hasExplicitInput = array_key_exists('climb_descent_minutes', $data);
        $explicitMinutes = (int) ($data['climb_descent_minutes'] ?? 0);
        if ($hasExplicitInput && $explicitMinutes > 0) {
            $data['climb_descent_minutes'] = $explicitMinutes;
            $data['climb_descent_source'] = Aeronave::CLIMB_DESCENT_SOURCE_MANUAL;

            return $data;
        }

        if (! $hasExplicitInput) {
            if ((int) ($aircraft?->climb_descent_minutes ?? 0) > 0) {
                return $data;
            }

            $data['climb_descent_minutes'] = $this->resolveClimbDescentMinutesForCategory($resolvedCategory);
            $data['climb_descent_source'] = Aeronave::CLIMB_DESCENT_SOURCE_CATEGORY_DEFAULT;

            return $data;
        }

        $data['climb_descent_minutes'] = $this->resolveClimbDescentMinutesForCategory($resolvedCategory);
        $data['climb_descent_source'] = Aeronave::CLIMB_DESCENT_SOURCE_CATEGORY_DEFAULT;

        return $data;
    }

    private function formatAircraftPayload(Aeronave $aircraft, $plan = null, ?int $fleetCount = null, bool $includeDetails = false): array
    {
        $resolvedPlan = $plan;
        $aircraftCount = max($fleetCount ?? 1, 1);
        $monthlyBase = (float) ($resolvedPlan?->price_monthly ?? $resolvedPlan?->price_yearly ?? $resolvedPlan?->price ?? 0);
        $monthlyPerAircraft = $monthlyBase > 0 ? round($monthlyBase / $aircraftCount, 2) : null;
        $images = ($aircraft->relationLoaded('images') ? $aircraft->images : collect())
            ->sortBy([
                ['is_main', 'desc'],
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->values();
        $mainImage = $images->firstWhere('is_main', true)?->image_url ?? $images->first()?->image_url;

        $activeAircraftSub = $aircraft->relationLoaded('suscripcionesAeronave')
            ? $aircraft->suscripcionesAeronave->first()
            : null;
        $documents = $aircraft->relationLoaded('documents') ? $aircraft->documents : collect();
        $availability = $aircraft->relationLoaded('availability') ? $aircraft->availability : collect();

        $payload = [
            'id' => $aircraft->id,
            'provider_id' => $aircraft->provider_id,
            'model' => $aircraft->model,
            'manufacturer' => $aircraft->manufacturer,
            'category' => $aircraft->category,
            'model_year' => $aircraft->model_year,
            'registration' => $aircraft->registration,
            'capacity' => $aircraft->capacity,
            'range_km' => $aircraft->range_km,
            'speed_kmh' => $aircraft->speed_kmh,
            'base_airport' => $aircraft->base_airport,
            'coverage' => $aircraft->coverage,
            'airport_expenses_usd' => $aircraft->airport_expenses_usd,
            'hourly_rate' => $aircraft->hourly_rate,
            'year' => $aircraft->model_year,
            'minimum_hours' => $aircraft->minimum_hours ?: $this->resolveMinimumHoursForCategory($aircraft->category),
            'climb_descent_minutes' => $this->climbDescentCategoryResolver->resolveAircraftMinutes($aircraft),
            'climb_descent_source' => $aircraft->climb_descent_source ?: Aeronave::CLIMB_DESCENT_SOURCE_LEGACY_UNKNOWN,
            'amenities' => $this->parseAmenities($aircraft->amenities),
            'fuel_burn_gph' => $aircraft->fuel_burn_gph,
            'engine_reserve_rate' => $aircraft->engine_reserve_rate,
            'insurance_rate' => $aircraft->insurance_rate,
            'maintenance_rate' => $aircraft->maintenance_rate,
            'crew_rate' => $aircraft->crew_rate,
            'repositioning_fee' => $aircraft->repositioning_fee,
            'overnight_fee' => $aircraft->overnight_fee,
            'operational_cost' => $aircraft->operational_cost,
            'currency' => $aircraft->currency,
            'status' => $aircraft->status,
            'approved_at' => $aircraft->approved_at,
            'approved' => $aircraft->isAdministrativelyApproved(),
            'review_status' => $aircraft->resolvedReviewStatus(),
            'billing_status' => $aircraft->billing_status,
            'billing_plan_id' => $aircraft->billing_plan_id,
            'subscription_status' => $aircraft->subscription_status,
            'subscription_started_at' => $aircraft->subscription_started_at,
            'subscription_ends_at' => $aircraft->subscription_ends_at,
            'last_payment_at' => $aircraft->last_payment_at,
            'notes' => $aircraft->notes,
            'created_at' => $aircraft->created_at,
            'updated_at' => $aircraft->updated_at,
            'main_image' => $mainImage,
            'images' => ($includeDetails ? $images : $images->take(1))->map(fn ($image) => [
                'id' => $image->id,
                'kind' => $image->kind,
                'title' => $image->title,
                'image_url' => $image->image_url,
                'is_main' => $image->is_main,
                'visible_to_client' => $image->visible_to_client,
                'sort_order' => $image->sort_order,
            ])->values(),
            'documents_count' => $documents->count(),
            'documents' => ($includeDetails
                ? $documents->map(fn (DocumentoAeronave $document) => $this->formatAircraftDocumentPayload($document))
                : $documents->map(fn (DocumentoAeronave $document) => [
                    'id' => $document->id,
                    'type' => $document->type,
                    'document_type' => $document->document_type,
                    'document_name' => $document->document_name,
                    'status' => $document->status,
                    'expires_at' => $document->expires_at,
                ]))->values(),
            'availability_status' => $availability->sortByDesc('end_datetime')->first()?->status,
            'availability_count' => $availability->count(),
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

        if ($includeDetails) {
            $payload['availability'] = $availability->map(fn (DisponibilidadAeronave $entry) => [
                'id' => $entry->id,
                'aircraft_id' => $entry->aircraft_id,
                'start_datetime' => $entry->start_datetime,
                'end_datetime' => $entry->end_datetime,
                'status' => $entry->status,
                'notes' => $entry->notes,
            ])->values();
        }

        return $payload;
    }

    private function normalizeAmenities(mixed $value): ?string
    {
        if (is_array($value)) {
            $items = array_values(array_filter(array_map(
                fn ($item) => trim((string) $item),
                $value
            )));

            return $items === [] ? null : implode(', ', $items);
        }

        $normalized = $this->normalizeNullableString($value);
        return $normalized === null ? null : $normalized;
    }

    private function parseAmenities(mixed $value): array
    {
        $normalized = $this->normalizeNullableString($value);
        if ($normalized === null) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $normalized))));
    }

    private function normalizeAircraftCategory(mixed $value): ?string
    {
        $normalized = mb_strtolower(trim((string) ($value ?? '')));

        return match ($normalized) {
            'helicoptero', 'helicóptero', 'helicopter' => 'Helicoptero',
            'turboprop', 'turbo prop' => 'Turboprop',
            'light jet', 'light_jet', 'lightjet' => 'Light Jet',
            'mid jet', 'mid_jet', 'midjet', 'midsize jet', 'midsize_jet', 'super mid', 'super_mid' => 'Mid Jet',
            'heavy jet', 'heavy_jet', 'heavyjet', 'long range', 'long_range', 'ultra long', 'ultra_long' => 'Heavy Jet',
            '' => null,
            default => trim((string) $value),
        };
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));
        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeRegistration(mixed $value): ?string
    {
        $registration = $this->normalizeNullableString($value);
        if ($registration === null) {
            return null;
        }

        return preg_match('/^PENDIENTE\d*$/i', $registration) ? null : $registration;
    }

    private function formatAircraftDocumentPayload(DocumentoAeronave $document): array
    {
        $resolvedUrl = $this->resolveAircraftDocumentUrl($document);
        $resolvedThumbnailUrl = $this->resolveAircraftDocumentThumbnailUrl($document);

        return [
            'id' => $document->id,
            'aircraft_id' => $document->aircraft_id,
            'type' => $document->type,
            'document_type' => $document->document_type,
            'document_name' => $document->document_name,
            'status' => $document->status,
            'expires_at' => $document->expires_at,
            'file_type' => $document->file_type,
            'created_at' => $document->created_at,
            'updated_at' => $document->updated_at,
            'file_url' => $resolvedUrl,
            'document_url' => $resolvedUrl,
            'url' => $resolvedUrl,
            'thumbnail_url' => $resolvedThumbnailUrl,
        ];
    }

    private function resolveAircraftDocumentUrl(DocumentoAeronave $document): string
    {
        $disk = (string) ($document->storage_disk ?: 'public');
        $path = (string) ($document->storage_path ?: '');

        if ($disk === 's3' && $path !== '' && $this->canGenerateTemporaryS3Urls()) {
            try {
                return Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(30));
            } catch (\Throwable) {
                return $document->document_url ?: $document->file_url ?: '';
            }
        }

        return $document->document_url ?: $document->file_url ?: '';
    }

    private function resolveAircraftDocumentThumbnailUrl(DocumentoAeronave $document): ?string
    {
        $thumbnailPath = (string) ($document->thumbnail_path ?: '');
        $thumbnailUrl = $document->thumbnail_url ?: null;
        $disk = (string) ($document->storage_disk ?: 'public');

        if ($disk === 's3' && $thumbnailPath !== '' && $this->canGenerateTemporaryS3Urls()) {
            try {
                return Storage::disk('s3')->temporaryUrl($thumbnailPath, now()->addMinutes(30));
            } catch (\Throwable) {
                return $thumbnailUrl;
            }
        }

        return $thumbnailUrl;
    }

    private function canGenerateTemporaryS3Urls(): bool
    {
        $key = trim((string) config('filesystems.disks.s3.key', ''));
        $secret = trim((string) config('filesystems.disks.s3.secret', ''));
        $bucket = trim((string) config('filesystems.disks.s3.bucket', ''));
        $region = trim((string) config('filesystems.disks.s3.region', ''));

        return $key !== '' && $secret !== '' && $bucket !== '' && $region !== '';
    }
}
