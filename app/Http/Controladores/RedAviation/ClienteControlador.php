<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
use App\Jobs\DispatchProviderFlightRequestNotificationsJob;
use App\Modelos\Aeronave;
use App\Modelos\Aeropuerto;
use App\Modelos\Cotizacion;
use App\Modelos\ImagenAeronave;
use App\Modelos\Operacion;
use App\Modelos\ReglaGastoAeropuerto;
use App\Modelos\ReglaPrecioCategoria;
use App\Modelos\SolicitudVuelo;
use App\Modelos\TokenApi;
use App\Modelos\Usuario;
use App\Servicios\Aeronaves\AircraftAvailabilityService;
use App\Servicios\Aeronaves\AircraftEligibilityService;
use App\Servicios\Aeronaves\AircraftRepositioningService;
use App\Servicios\Billing\BillingPlanServicio;
use App\Servicios\Pagos\PaymentFeeCalculationServicio;
use App\Servicios\RedAviation\MatchingRedAviationServicio;
use App\Servicios\RedAviation\ProviderFlightRequestNotificationService;
use App\Servicios\RedAviation\VisibilidadServicio;
use App\Servicios\Vuelos\FlightDurationService;
use App\Servicios\Vuelos\FlightPricingService;
use App\Servicios\Vuelos\FlightRouteService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ClienteControlador extends ControladorBase
{
    private const DUPLICATE_FLIGHT_REQUEST_TERMINAL_STATUSES = [
        'cancelled',
        'cancelada',
        'canceled',
        'completed',
        'finalizada',
        'expired',
    ];

    private const DUPLICATE_FLIGHT_REQUEST_TERMINAL_WORKFLOWS = [
        'cancelled',
        'cancelada',
        'completed',
        'finalizada',
        'servicio completado',
    ];

    private const DEFAULT_CLIENT_AIRCRAFT_PAGE_SIZE = 18;

    private const MAX_CLIENT_AIRCRAFT_PAGE_SIZE = 36;

    private const DEFAULT_CLIENT_QUOTES_LIMIT = 12;

    private const DEFAULT_CLIENT_FLIGHT_REQUESTS_PAGE_SIZE = 10;

    private const MAX_CLIENT_FLIGHT_REQUESTS_PAGE_SIZE = 25;

    private const TIME_MODE_DIRECT = 'direct';

    private const TIME_MODE_DIRECT_PLUS_CLIMB = 'direct_plus_climb';

    private const TIME_MODE_OPERATIONAL = 'operational';

    private const ROUNDING_MODE_NONE = 'none';

    private const ROUNDING_MODE_QUARTER_NEAREST = 'quarter_nearest';

    private const ROUNDING_MODE_QUARTER_UP = 'quarter_up';

    private const DEFAULT_OPERATIONAL_FACTOR = 1.25;

    private const DEFAULT_FIXED_MINUTES_PER_LEG = 20;

    private const DEFAULT_MINIMUM_MINUTES_PER_LEG = 35;

    private const SHORT_ROUTE_DISTANCE_KM = 300.0;

    private const MACH_1_KMH = 1062.0;

    private const COMMERCIAL_OVERNIGHT_HOURS_PER_NIGHT = 0.5;

    private const DEFAULT_IVA_RATE = 0.16;

    private const DEFAULT_AIRPORT_EXPENSE_USD = 1000.0;

    private const AIRCRAFT_CATEGORY_MINIMUM_PRICE = [
        'Helicoptero' => ['minimum_route_price' => 2200.0, 'redsky_markup' => 20.0],
        'Turboprop' => ['minimum_route_price' => 2800.0, 'redsky_markup' => 20.0],
        'Light Jet' => ['minimum_route_price' => 3800.0, 'redsky_markup' => 22.0],
        'Mid Jet' => ['minimum_route_price' => 4800.0, 'redsky_markup' => 25.0],
        'Heavy Jet' => ['minimum_route_price' => 7000.0, 'redsky_markup' => 30.0],
    ];

    private ?bool $categoryPricingRulesTableExists = null;

    private array $categoryPricingRuleCache = [];

    private ?bool $airportExpenseRulesTableExists = null;

    private array $activeAirportCache = [];

    private ?array $activeAirportSearchColumns = null;

    private ?Collection $airportExpenseRulesCache = null;

    private ?bool $flightRequestIdempotencyColumnExists = null;

    private const CATEGORY_MACH_BANDS = [
        'Helicoptero' => 0.35,
        'Light Jet' => 0.75,
        'Mid Jet' => 0.81,
        'Heavy Jet' => 0.87,
        'Ultra Long Range' => 0.92,
    ];

    private const CATEGORY_CLIMB_DESCENT_MINUTES = [
        'Helicoptero' => 15,
        'Turboprop' => 25,
        'Light Jet' => 30,
        'Mid Jet' => 35,
        'Heavy Jet' => 45,
        'Ultra Long Range' => 45,
    ];

    private const CATEGORY_OPERATIONAL_FACTORS = [
        'Helicoptero' => 1.10,
        'Turboprop' => 1.20,
        'Light Jet' => 1.32,
        'Mid Jet' => 1.25,
        'Heavy Jet' => 1.18,
        'Ultra Long Range' => 1.18,
    ];

    private const CATEGORY_FIXED_MINUTES_PER_LEG = [
        'Helicoptero' => 10,
        'Turboprop' => 15,
        'Light Jet' => 18,
        'Mid Jet' => 20,
        'Heavy Jet' => 25,
        'Ultra Long Range' => 25,
    ];

    private const CATEGORY_MINIMUM_MINUTES_PER_LEG = [
        'Helicoptero' => 20,
        'Turboprop' => 30,
        'Light Jet' => 35,
        'Mid Jet' => 40,
        'Heavy Jet' => 45,
        'Ultra Long Range' => 45,
    ];

    public function __construct(
        private readonly AircraftAvailabilityService $aircraftAvailabilityService,
        private readonly AircraftEligibilityService $aircraftEligibilityService,
        private readonly AircraftRepositioningService $aircraftRepositioningService,
        private readonly BillingPlanServicio $billingPlanServicio,
        private readonly MatchingRedAviationServicio $matchingServicio,
        private readonly VisibilidadServicio $visibilidadServicio,
        private readonly PaymentFeeCalculationServicio $paymentFeeCalculationServicio,
        private readonly ProviderFlightRequestNotificationService $providerFlightRequestNotificationService,
        private readonly FlightRouteService $flightRouteService,
        private readonly FlightDurationService $flightDurationService,
        private readonly FlightPricingService $flightPricingService,
    ) {}

    public function dashboard(Request $request)
    {
        return $this->ok([
            'metrics' => [
                'solicitudes' => SolicitudVuelo::where('client_id', $request->user()->id)->count(),
                'operaciones_activas' => Operacion::whereHas('solicitudVuelo', fn ($query) => $query->where('client_id', $request->user()->id))
                    ->whereNotIn('status', ['finalizada', 'cancelada'])
                    ->count(),
            ],
            'access' => $request->user()->accessStatus(),
        ]);
    }

    public function indexAircraft(Request $request)
    {
        $data = $request->validate([
            'origin' => ['nullable', 'string', 'max:20'],
            'base_airport' => ['nullable', 'string', 'max:20'],
            'destination' => ['nullable', 'string', 'max:20'],
            'departure_datetime' => ['nullable', 'date'],
            'return_date' => ['nullable', 'date'],
            'return_datetime' => ['nullable', 'date'],
            'passengers' => ['nullable', 'integer', 'min:1'],
            'trip_type' => ['nullable', 'string', 'max:50'],
            'trip_label' => ['nullable', 'string', 'max:50'],
            'round_trip' => ['nullable', 'boolean'],
            'return' => ['nullable', 'boolean'],
            'return_to_origin' => ['nullable', 'boolean'],
            'return_to_start' => ['nullable', 'boolean'],
            'close_route' => ['nullable', 'boolean'],
            'open_route' => ['nullable', 'boolean'],
            'overnights' => ['nullable', 'integer', 'min:0'],
            'overnight_nights' => ['nullable', 'integer', 'min:0'],
            'include_iva' => ['nullable', 'boolean'],
            'airport_expenses' => ['nullable', 'boolean'],
            'apply_margin' => ['nullable', 'boolean'],
            'include_repositioning_in_billed_hours' => ['nullable', 'boolean'],
            'include_return_to_base_in_billed_hours' => ['nullable', 'boolean'],
            'include_overnight_in_billed_hours' => ['nullable', 'boolean'],
            'flight_base_source' => ['nullable', 'string', 'in:pricing_trip_hours,billable_hours'],
            'time_display_mode' => ['nullable', 'string', 'in:direct,direct_plus_climb,operational'],
            'billing_hours_mode' => ['nullable', 'string', 'in:direct,direct_plus_climb,operational'],
            'rounding_mode' => ['nullable', 'string', 'in:none,quarter_nearest,quarter_up'],
            'legs' => ['nullable', 'array'],
            'legs.*' => ['nullable', 'array'],
            'requirements' => ['nullable', 'array'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_CLIENT_AIRCRAFT_PAGE_SIZE],
        ]);

        $origin = strtoupper(trim((string) ($data['origin'] ?? $data['base_airport'] ?? '')));
        $destination = strtoupper(trim((string) ($data['destination'] ?? '')));
        $passengers = (int) ($data['passengers'] ?? 0);
        $perPage = (int) ($data['per_page'] ?? self::DEFAULT_CLIENT_AIRCRAFT_PAGE_SIZE);
        $routePricingAvailable = false;
        $routeLegs = [];
        $routeDistanceKm = null;
        $routeDistanceNm = null;

        if ($origin !== '' && $destination !== '') {
            $originAirport = $this->findActiveAirport($origin);
            $destinationAirport = $this->findActiveAirport($destination);

            abort_if(! $originAirport, 422, 'No encontramos el aeropuerto de origen activo.');
            abort_if(! $destinationAirport, 422, 'No encontramos el aeropuerto de destino activo.');
            abort_if(! $originAirport->latitude || ! $originAirport->longitude, 422, 'El aeropuerto de origen no tiene coordenadas.');
            abort_if(! $destinationAirport->latitude || ! $destinationAirport->longitude, 422, 'El aeropuerto de destino no tiene coordenadas.');

            $routeLegs = $this->quoteLegs($data, $originAirport, $destinationAirport);
            $routeDistanceKm = (float) collect($routeLegs)->sum('distance_km');
            $routeDistanceNm = (float) collect($routeLegs)->sum('distance_nm');
            $routePricingAvailable = true;
        }

        [$requestedStart, $requestedEnd] = $this->aircraftAvailabilityService->resolveWindowFromPayload($data);

        $aircraft = Aeronave::query()
            ->select([
                'id',
                'provider_id',
                'model',
                'manufacturer',
                'registration',
                'capacity',
                'category',
                'range_km',
                'base_airport',
                'speed_kmh',
                'hourly_rate',
                'airport_expenses_usd',
                'minimum_hours',
                'climb_descent_minutes',
                'overnight_fee',
                'currency',
                'status',
            ])
            ->with([
                'images:id,aircraft_id,kind,title,image_url,is_main,sort_order,visible_to_client',
                'provider:id,company_name,commercial_name,jet_a_price',
            ])
            ->whereIn('status', ['active', 'trial_active', 'aprobada', 'available', 'disponible'])
            ->when($passengers > 0, fn ($query) => $query->where('capacity', '>=', $passengers))
            ->tap(fn ($query) => $this->aircraftAvailabilityService->applyAvailabilityConstraints($query, $requestedStart, $requestedEnd))
            ->when($origin !== '', function ($query) use ($origin) {
                $query->orderByRaw(
                    'case when upper(coalesce(base_airport, \'\')) = ? then 0 else 1 end',
                    [$origin],
                );
            })
            ->orderByRaw('upper(coalesce(base_airport, \'\')) asc')
            ->orderByRaw('coalesce(hourly_rate, 0) asc')
            ->paginate($perPage);

        return $this->ok([
            'aircraft' => $aircraft->getCollection()->map(
                fn (Aeronave $aircraft) => $this->aircraftCatalogPayload(
                    $aircraft,
                    $routePricingAvailable ? $this->previewPricingForAircraft(
                        $aircraft,
                        $this->resolveQuoteTripType($data),
                        $routeLegs,
                        $data
                    ) : null,
                    $routeDistanceKm,
                    $routeDistanceNm
                )
            )->values(),
            'origin' => $origin ?: null,
            'destination' => $destination ?: null,
            'passengers' => $passengers ?: null,
            'route_pricing_available' => $routePricingAvailable,
            'meta' => [
                'current_page' => $aircraft->currentPage(),
                'per_page' => $aircraft->perPage(),
                'total' => $aircraft->total(),
                'last_page' => $aircraft->lastPage(),
            ],
        ]);
    }

    public function previewQuotes(Request $request): JsonResponse
    {
        $previewStartedAt = microtime(true);
        $user = $this->resolveOptionalApiUser($request);
        $commercialGate = null;

        if ($user && ($user->hasRole(Usuario::ROLE_CLIENT) || $user->hasRole(Usuario::ROLE_ADMIN))) {
            $commercialGate = $this->resolveCommercialAccessGate($user);

            if (! $commercialGate['allowed']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Necesitas activar tu acceso comercial para cotizar de nuevo.',
                    'access' => $user->accessStatus(),
                    'billing_plan' => $commercialGate['plan'],
                ], 402);
            }
        }

        $data = $request->validate([
            'origin' => ['required', 'string', 'max:20'],
            'destination' => ['required', 'string', 'max:20'],
            'origin_airport_id' => ['nullable', 'integer'],
            'destination_airport_id' => ['nullable', 'integer'],
            'origin_icao' => ['nullable', 'string', 'max:10'],
            'destination_icao' => ['nullable', 'string', 'max:10'],
            'origin_iata' => ['nullable', 'string', 'max:10'],
            'destination_iata' => ['nullable', 'string', 'max:10'],
            'origin_airport' => ['nullable', 'array'],
            'destination_airport' => ['nullable', 'array'],
            'departure_datetime' => ['nullable', 'date'],
            'return_date' => ['nullable', 'date'],
            'return_datetime' => ['nullable', 'date'],
            'passengers' => ['required', 'integer', 'min:1'],
            'trip_type' => ['nullable', 'string', 'max:50'],
            'trip_label' => ['nullable', 'string', 'max:50'],
            'round_trip' => ['nullable', 'boolean'],
            'return' => ['nullable', 'boolean'],
            'return_to_origin' => ['nullable', 'boolean'],
            'return_to_start' => ['nullable', 'boolean'],
            'close_route' => ['nullable', 'boolean'],
            'open_route' => ['nullable', 'boolean'],
            'overnights' => ['nullable', 'integer', 'min:0'],
            'overnight_nights' => ['nullable', 'integer', 'min:0'],
            'include_iva' => ['nullable', 'boolean'],
            'airport_expenses' => ['nullable', 'boolean'],
            'apply_margin' => ['nullable', 'boolean'],
            'include_repositioning_in_billed_hours' => ['nullable', 'boolean'],
            'include_return_to_base_in_billed_hours' => ['nullable', 'boolean'],
            'include_overnight_in_billed_hours' => ['nullable', 'boolean'],
            'flight_base_source' => ['nullable', 'string', 'in:pricing_trip_hours,billable_hours'],
            'time_display_mode' => ['nullable', 'string', 'in:direct,direct_plus_climb,operational'],
            'billing_hours_mode' => ['nullable', 'string', 'in:direct,direct_plus_climb,operational'],
            'rounding_mode' => ['nullable', 'string', 'in:none,quarter_nearest,quarter_up'],
            'aircraft_type' => ['nullable', 'string', 'max:100'],
            'legs' => ['nullable', 'array'],
            'legs.*' => ['nullable', 'array'],
            'requirements' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);

        $canonicalRoute = $this->flightRouteService->buildCanonicalRoute($data);
        $legs = $canonicalRoute['legs'];
        if (app()->environment('local')) {
            Log::debug('[QUOTE PREVIEW ROUTE INPUT]', [
                'trip_type' => $request->input('trip_type'),
                'origin' => $request->input('origin'),
                'destination' => $request->input('destination'),
                'received_legs_count' => count((array) $request->input('legs', [])),
                'calculated_legs_count' => count($legs),
                'legs' => $request->input('legs'),
                'return_to_origin' => $request->boolean('return_to_origin'),
                'return_to_start' => $request->boolean('return_to_start'),
                'close_route' => $request->boolean('close_route'),
            ]);
        }
        $originAirport = $this->airportFromPayload($legs[0]['origin_airport']);
        $destinationAirport = $this->airportFromPayload($legs[0]['destination_airport']);
        $distanceKm = (float) collect($legs)->sum('distance_km');
        $distanceNm = (float) collect($legs)->sum('distance_nm');
        $passengers = (int) $data['passengers'];
        $tripType = $this->resolveQuoteTripType($data);
        $quotesLimit = (int) ($data['limit'] ?? self::DEFAULT_CLIENT_QUOTES_LIMIT);

        Log::info('Flight quote preview flow started', [
            'user_id' => $user?->id,
            'origin' => $data['origin'],
            'destination' => $data['destination'],
            'trip_type' => $tripType,
            'passengers' => $passengers,
            'legs_count' => count($legs),
            'route_signature' => $canonicalRoute['route_signature'] ?? null,
            'distance_km' => round($distanceKm, 2),
            'distance_nm' => round($distanceNm, 2),
            'quotes_limit' => $quotesLimit,
        ]);

        if ($user && (($commercialGate['consume_trial_quote'] ?? false) === true)) {
            $nextUsed = (int) ($user->free_quotes_used ?? 0) + 1;
            $limit = max(1, (int) ($user->free_quote_limit ?? 1));

            DB::table('users')->where('id', $user->id)->update([
                'trial_started_at' => $user->trial_started_at ?? now(),
                'trial_ends_at' => $user->trial_ends_at ?? now()->addDays(7),
                'access_status' => $nextUsed >= $limit ? 'trial_used' : 'trial_active',
                'free_quotes_used' => $nextUsed,
                'updated_at' => now(),
            ]);

            $user = $user->fresh(['activeSuscripcion', 'demo', 'roles']);
        }

        if ($tripType === 'multi_leg') {
            logger()->info('DEBUG MULTI LEG INPUT', [
                'trip_type' => $tripType,
                'legs_count' => count($legs),
                'legs' => collect($legs)->map(fn (array $leg) => [
                    'origin' => $leg['origin'] ?? null,
                    'destination' => $leg['destination'] ?? null,
                    'distance_km' => $leg['distance_km'] ?? null,
                    'distance_nm' => $leg['distance_nm'] ?? null,
                    'departure_datetime' => $leg['departure_datetime'] ?? null,
                ])->values()->all(),
            ]);
        }

        [$requestedStart, $requestedEnd] = $this->aircraftAvailabilityService->resolveWindowFromPayload([
            ...$data,
            'legs' => $legs,
        ]);
        $loadCandidatesStartedAt = microtime(true);
        $aircraftQuery = Aeronave::query()
            ->with([
                'images:id,aircraft_id,kind,title,image_url,is_main,sort_order,visible_to_client',
                'provider',
                'documents',
                'baseAirport',
            ])
            ->where('hourly_rate', '>', 0);
        $this->aircraftAvailabilityService->excludeConflictingAircraft(
            $aircraftQuery,
            $requestedStart,
            $requestedEnd,
        );
        $candidateAircraft = $aircraftQuery
            ->when(! empty($data['origin']), function ($query) use ($data) {
                $origin = strtoupper(trim((string) $data['origin']));
                $query->orderByRaw(
                    'case when upper(coalesce(base_airport, \'\')) = ? then 0 else 1 end',
                    [$origin],
                );
            })
            ->orderByRaw('coalesce(hourly_rate, 0) asc')
            ->limit(max($quotesLimit * 8, 100))
            ->get();
        $loadCandidatesMs = round((microtime(true) - $loadCandidatesStartedAt) * 1000, 2);

        $exactCandidates = $candidateAircraft
            ->filter(fn (Aeronave $aircraft) => $this->aircraftIsBasedAtOrigin($aircraft, $originAirport))
            ->values();
        Log::info('quote_preview_candidate_count', [
            'route_signature' => $canonicalRoute['route_signature'] ?? null,
            'preview_candidate_count' => $candidateAircraft->count(),
            'preview_exact_candidate_count' => $exactCandidates->count(),
            'load_candidates_ms' => $loadCandidatesMs,
        ]);

        Log::info('quote_preview_exact_candidates', [
            'route_signature' => $canonicalRoute['route_signature'] ?? null,
            'origin_airport_id' => $originAirport->id,
            'count' => $exactCandidates->count(),
            'aircraft_ids' => $exactCandidates->pluck('id')->values()->all(),
        ]);

        $exactQuoteBuild = $this->buildPreviewQuotesForCandidates(
            $exactCandidates,
            $canonicalRoute,
            $data,
            $passengers,
            $requestedStart,
            $requestedEnd,
            $distanceKm,
            $distanceNm,
            $legs,
            $tripType,
            $originAirport,
            false,
        );
        $availabilityQueryCount = (int) ($exactQuoteBuild['metrics']['availability_query_count'] ?? 0);
        $quotes = $exactQuoteBuild['quotes']->sortBy([
            fn (array $quote) => $quote['total'],
            fn (array $quote) => $quote['aircraft_id'],
        ])->take($quotesLimit)->values();

        $selectedRadiusNm = null;
        $repositioningMs = 0.0;
        $nearbyPreparedCandidates = collect();
        if ($quotes->isEmpty()) {
            $repositioningStartedAt = microtime(true);
            $nearbyCandidates = $this->aircraftRepositioningService->nearbyCandidateContexts(
                $candidateAircraft->reject(fn (Aeronave $aircraft) => $this->aircraftIsBasedAtOrigin($aircraft, $originAirport))->values(),
                $originAirport,
                $canonicalRoute,
            );
            $repositioningMs = round((microtime(true) - $repositioningStartedAt) * 1000, 2);
            $nearbyPreparedCandidates = $this->preparePreviewCandidatesForEvaluation(
                $nearbyCandidates,
                $requestedStart,
                $requestedEnd,
                $originAirport,
                true,
                null,
                $data,
            );
            $availabilityQueryCount += (int) ($nearbyPreparedCandidates->first()['availability_query_count'] ?? 0);

            $configuredRadii = $this->aircraftRepositioningService->configuredSearchRadiiNm();

            Log::info('quote_preview_nearby_candidates', [
                'route_signature' => $canonicalRoute['route_signature'] ?? null,
                'origin_airport_id' => $originAirport->id,
                'count' => $nearbyCandidates->count(),
                'radii_nm' => $configuredRadii,
                'preview_nearby_candidate_count' => $nearbyPreparedCandidates->count(),
                'repositioning_ms' => $repositioningMs,
            ]);

            foreach ($configuredRadii as $radiusNm) {
                $candidatesWithinRadius = $this->aircraftRepositioningService->withinRadius($nearbyPreparedCandidates, $radiusNm)
                    ->map(function (array $candidate) use ($radiusNm): array {
                        $candidate['operational_context']['selected_radius_nm'] = $radiusNm;

                        return $candidate;
                    })
                    ->values();
                if ($candidatesWithinRadius->isEmpty()) {
                    continue;
                }

                $radiusQuoteBuild = $this->buildPreviewQuotesForCandidates(
                    $candidatesWithinRadius,
                    $canonicalRoute,
                    $data,
                    $passengers,
                    $requestedStart,
                    $requestedEnd,
                    $distanceKm,
                    $distanceNm,
                    $legs,
                    $tripType,
                    $originAirport,
                    true,
                    $radiusNm,
                );
                $radiusQuotes = $radiusQuoteBuild['quotes']->sortBy([
                    fn (array $quote) => (float) data_get($quote, 'repositioning.distance_nm', INF),
                    fn (array $quote) => $quote['total'],
                    fn (array $quote) => $quote['aircraft_id'],
                ])->take($quotesLimit)->values();

                if ($radiusQuotes->isNotEmpty()) {
                    $quotes = $radiusQuotes;
                    $selectedRadiusNm = $radiusNm;
                    break;
                }
            }
        }

        if ($selectedRadiusNm !== null) {
            Log::info('quote_preview_selected_radius_nm', [
                'route_signature' => $canonicalRoute['route_signature'] ?? null,
                'selected_radius_nm' => $selectedRadiusNm,
            ]);
        }

        Log::info('quote_preview_repositioned_results', [
            'route_signature' => $canonicalRoute['route_signature'] ?? null,
            'selected_radius_nm' => $selectedRadiusNm,
            'matches_returned' => $quotes->count(),
            'repositioned_aircraft_ids' => $quotes
                ->filter(fn (array $quote) => (bool) ($quote['requires_repositioning'] ?? false))
                ->pluck('aircraft_id')
                ->values()
                ->all(),
        ]);
        Log::info('quote_preview_performance', [
            'route_signature' => $canonicalRoute['route_signature'] ?? null,
            'preview_candidate_count' => $candidateAircraft->count(),
            'preview_exact_candidate_count' => $exactCandidates->count(),
            'preview_nearby_candidate_count' => $nearbyPreparedCandidates->count(),
            'preview_selected_radius_nm' => $selectedRadiusNm,
            'preview_availability_query_count' => $availabilityQueryCount,
            'preview_eligible_count' => (int) ($exactQuoteBuild['metrics']['eligible_count'] ?? 0)
                + (int) collect($nearbyPreparedCandidates)->filter(fn (array $candidate) => ! ($candidate['precomputed_availability_conflict'] ?? false))->count(),
            'preview_priced_count' => $quotes->count(),
            'load_candidates_ms' => $loadCandidatesMs,
            'repositioning_ms' => $repositioningMs,
            'availability_batch_ms' => round(
                (float) ($exactQuoteBuild['metrics']['availability_batch_ms'] ?? 0)
                    + (float) ($nearbyPreparedCandidates->first()['availability_batch_ms'] ?? 0),
                2
            ),
            'eligibility_ms' => round((float) ($exactQuoteBuild['metrics']['eligibility_ms'] ?? 0), 2),
            'pricing_ms' => round((float) ($exactQuoteBuild['metrics']['pricing_ms'] ?? 0), 2),
        ]);

        Log::info('quote_preview_execution_ms', [
            'route_signature' => $canonicalRoute['route_signature'] ?? null,
            'elapsed_ms' => round((microtime(true) - $previewStartedAt) * 1000, 2),
        ]);

        Log::info('Flight quote preview flow completed', [
            'user_id' => $user?->id,
            'route_signature' => $canonicalRoute['route_signature'] ?? null,
            'trip_type' => $tripType,
            'matches_returned' => $quotes->count(),
            'match_aircraft_ids' => $quotes->pluck('aircraft_id')->values()->all(),
            'match_totals' => $quotes->map(fn (array $quote) => [
                'aircraft_id' => $quote['aircraft_id'] ?? null,
                'total_amount' => $quote['total_amount'] ?? null,
                'quoted_total' => $quote['quoted_total'] ?? null,
                'currency' => $quote['currency'] ?? null,
            ])->values()->all(),
        ]);

        return $this->ok([
            'preview' => true,
            'saved' => false,
            'origin_airport' => $this->airportPreviewPayload($originAirport),
            'destination_airport' => $this->airportPreviewPayload($destinationAirport),
            'distance_km' => round($distanceKm),
            'distance_nm' => round($distanceNm),
            'trip_type' => $tripType,
            'trip_label' => $data['trip_label'] ?? $data['notes'] ?? 'Ida',
            'segment_count' => count($legs),
            'time_display_mode' => $data['time_display_mode'] ?? $this->defaultFlightTimeMode(),
            'billing_hours_mode' => $data['billing_hours_mode'] ?? $this->defaultFlightTimeMode(),
            'legs' => $legs,
            'matches' => $quotes,
            'options' => $quotes,
            'access' => $user?->accessStatus(),
        ]);
    }

    public function storeFlightRequest(Request $request)
    {
        $user = $request->user()->fresh();
        $commercialGate = $this->resolveCommercialAccessGate($user);
        $hasActiveCommercialAccess = $user->hasRole(Usuario::ROLE_ADMIN)
            || ((bool) $user->has_paid_access && $user->access_status === 'active')
            || (bool) $user->activeSuscripcion
            || ($user->demo?->status === 'active' && $user->demo?->expires_at?->isFuture());

        if (! $commercialGate['allowed'] || ! $hasActiveCommercialAccess) {
            return response()->json([
                'success' => false,
                'message' => 'Necesitas demo activa o suscripcion vigente.',
                'access' => $user->accessStatus(),
                'billing_plan' => $commercialGate['plan'],
            ], 402);
        }

        $data = $request->validate([
            'origin' => ['required', 'string', 'max:20'],
            'destination' => ['required', 'string', 'max:20'],
            'origin_airport_id' => ['nullable', 'integer'],
            'destination_airport_id' => ['nullable', 'integer'],
            'origin_icao' => ['nullable', 'string', 'max:10'],
            'destination_icao' => ['nullable', 'string', 'max:10'],
            'origin_iata' => ['nullable', 'string', 'max:10'],
            'destination_iata' => ['nullable', 'string', 'max:10'],
            'origin_airport' => ['nullable', 'array'],
            'destination_airport' => ['nullable', 'array'],
            'departure_datetime' => ['required', 'date'],
            'return_datetime' => ['nullable', 'date'],
            'passengers' => ['required', 'integer', 'min:1'],
            'trip_type' => ['nullable', 'string', 'max:50'],
            'aircraft_type' => ['nullable', 'string', 'max:100'],
            'round_trip' => ['nullable', 'boolean'],
            'return' => ['nullable', 'boolean'],
            'return_to_origin' => ['nullable', 'boolean'],
            'return_to_start' => ['nullable', 'boolean'],
            'close_route' => ['nullable', 'boolean'],
            'open_route' => ['nullable', 'boolean'],
            'include_repositioning_in_billed_hours' => ['nullable', 'boolean'],
            'include_return_to_base_in_billed_hours' => ['nullable', 'boolean'],
            'include_overnight_in_billed_hours' => ['nullable', 'boolean'],
            'flight_base_source' => ['nullable', 'string', 'in:pricing_trip_hours,billable_hours'],
            'time_display_mode' => ['nullable', 'string', 'in:direct,direct_plus_climb,operational'],
            'billing_hours_mode' => ['nullable', 'string', 'in:direct,direct_plus_climb,operational'],
            'provider_id' => ['nullable', 'exists:providers,id'],
            'aircraft_id' => ['nullable', 'exists:aircraft,id'],
            'match_id' => ['nullable'],
            'matched_option_id' => ['nullable'],
            'currency' => ['nullable', 'string', 'max:10'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'base_price' => ['nullable', 'numeric'],
            'operational_fee' => ['nullable', 'numeric'],
            'priority_price' => ['nullable', 'numeric'],
            'total' => ['nullable', 'numeric'],
            'estimated_total' => ['nullable', 'numeric'],
            'final_price' => ['nullable', 'numeric'],
            'selected_card_price' => ['nullable', 'numeric'],
            'pricing_formula_version' => ['nullable', 'string', 'max:120'],
            'pricing_context' => ['nullable', 'array'],
            'aircraft_snapshot' => ['nullable', 'array'],
            'legs' => ['nullable', 'array'],
            'legs.*' => ['nullable', 'array'],
            'requirements' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ]);

        $departure = Carbon::parse($data['departure_datetime']);
        $data['departure_date'] = $departure->format('Y-m-d');
        $data['departure_time'] = $departure->format('H:i');
        $data['requirements'] = $data['requirements'] ?? [];
        $data['trip_type'] = $this->normalizeTripType($data['trip_type'] ?? null);
        $ignoredClientPricing = $this->extractIgnoredClientPricingFields($data);
        $data = $this->flightPricingService->sanitizeClientPayload($this->stripClientPricingFields($data));
        $idempotencyKey = $this->resolveFlightRequestIdempotencyKey($request, $data);

        if ($idempotencyKey && $this->flightRequestSupportsIdempotency()) {
            $existingRequest = $this->findExistingFlightRequestByIdempotency($user->id, $idempotencyKey);

            if ($existingRequest) {
                return $this->buildStoredFlightRequestResponse(
                    $existingRequest,
                    $user,
                    alreadyExists: true,
                    message: 'La solicitud ya había sido registrada.',
                    status: 200,
                );
            }
        }

        $duplicateRequest = $this->findExistingComparableFlightRequest($user->id, $data);

        if ($duplicateRequest) {
            return $this->buildStoredFlightRequestResponse(
                $duplicateRequest,
                $user,
                alreadyExists: true,
                message: 'La solicitud ya habia sido registrada para esta aeronave.',
                status: 200,
            );
        }

        try {
            [$user, $solicitud, $chatId, $acceptedQuote, $reusedExistingRequest] = DB::transaction(function () use ($user, $data, $ignoredClientPricing, $idempotencyKey) {
                $freshUser = $user->fresh(['activeSuscripcion', 'demo']);

                $existingComparableRequest = $this->findExistingComparableFlightRequest($freshUser->id, $data, true);

                if ($existingComparableRequest) {
                    $existingComparableRequest->loadMissing(['quotes:id,flight_request_id,aircraft_id,provider_id,status,total,currency,expires_at', 'chatsProtegidos:id,flight_request_id,status']);

                    return [
                        $freshUser,
                        $existingComparableRequest,
                        $existingComparableRequest->chatsProtegidos->sortByDesc('id')->first()?->id,
                        $existingComparableRequest->quotes->where('status', 'accepted')->sortByDesc('id')->first(),
                        true,
                    ];
                }

                $solicitud = SolicitudVuelo::create([
                    ...$data,
                    'client_id' => $freshUser->id,
                    'idempotency_key' => $this->flightRequestSupportsIdempotency() ? $idempotencyKey : null,
                    'status' => 'pending',
                    'workflow_status' => 'en_validacion',
                    'currency' => $data['currency'] ?? 'USD',
                    'final_price' => null,
                    'pricing_context' => null,
                    'package_snapshot' => [
                        'plan_id' => $freshUser->activeSuscripcion?->plan_id,
                        'demo' => $freshUser->demo?->status === 'active',
                        'commercial_access' => [
                            'status' => $freshUser->access_status ?: 'trial_active',
                            'free_quotes_used' => (int) ($freshUser->free_quotes_used ?? 0),
                            'free_quote_limit' => (int) ($freshUser->free_quote_limit ?? 1),
                            'has_paid_access' => (bool) $freshUser->has_paid_access,
                        ],
                    ],
                    'visibility_payload' => array_filter([
                        'client_pricing_ignored' => ! empty($ignoredClientPricing) ? $ignoredClientPricing : null,
                    ], fn ($value) => $value !== null),
                ]);

                $this->storeFlightRequestLegs($solicitud, $data);

                $hasExplicitSelection = ! empty($data['provider_id'])
                    || ! empty($data['aircraft_id'])
                    || ! empty($data['match_id'])
                    || ! empty($data['matched_option_id']);

                if ($hasExplicitSelection) {
                    $this->assignSelectedMatchToFlightRequest($solicitud, $data);
                } else {
                    $this->matchingServicio->ejecutar($solicitud);
                    $this->assignSelectedMatchToFlightRequest($solicitud, $data);
                }

                $acceptedQuote = $this->ensureAcceptedQuoteForFlightRequest($solicitud->fresh(['assignedAircraft', 'matches.aircraft']));

                $chat = $solicitud->chatsProtegidos()->create([
                    'client_id' => $freshUser->id,
                    'status' => 'activo',
                ]);

                return [$freshUser, $solicitud, $chat->id, $acceptedQuote, false];
            });
        } catch (QueryException $exception) {
            if ($idempotencyKey && $this->flightRequestSupportsIdempotency() && $this->isFlightRequestIdempotencyUniqueViolation($exception)) {
                $existingRequest = $this->findExistingFlightRequestByIdempotency($user->id, $idempotencyKey);

                if ($existingRequest) {
                    return $this->buildStoredFlightRequestResponse(
                        $existingRequest,
                        $user,
                        alreadyExists: true,
                        message: 'La solicitud ya había sido registrada.',
                        status: 200,
                    );
                }
            }

            throw $exception;
        }

        if ($reusedExistingRequest) {
            return $this->buildStoredFlightRequestResponse(
                $solicitud,
                $user,
                alreadyExists: true,
                message: 'La solicitud ya habia sido registrada para esta aeronave.',
                status: 200,
                acceptedQuote: $acceptedQuote,
                chatId: $chatId ? (int) $chatId : null,
            );
        }

        $this->writeAudit($request, 'create', 'red_aviation.flight_requests', 'Solicitud Red Aviation creada.');
        $this->dispatchProviderFlightRequestNotifications((int) $solicitud->id);

        return $this->buildStoredFlightRequestResponse(
            $solicitud,
            $user,
            alreadyExists: false,
            message: null,
            status: 201,
            acceptedQuote: $acceptedQuote,
            chatId: $chatId ? (int) $chatId : null,
        );
    }

    private function notifyProvidersAboutFlightRequest(SolicitudVuelo $solicitud): void
    {
        $this->providerFlightRequestNotificationService->dispatchForFlightRequest($solicitud);
    }

    private function dispatchProviderFlightRequestNotifications(int $flightRequestId): void
    {
        DispatchProviderFlightRequestNotificationsJob::dispatch($flightRequestId);
    }

    private function resolveFlightRequestIdempotencyKey(Request $request, array $validatedData): ?string
    {
        $candidate = trim((string) ($request->header('Idempotency-Key') ?: ($validatedData['idempotency_key'] ?? '')));

        return $candidate !== '' ? $candidate : null;
    }

    private function findExistingFlightRequestByIdempotency(int $clientId, ?string $idempotencyKey): ?SolicitudVuelo
    {
        if ($clientId <= 0 || ! $idempotencyKey || ! $this->flightRequestSupportsIdempotency()) {
            return null;
        }

        return SolicitudVuelo::query()
            ->where('client_id', $clientId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function findExistingComparableFlightRequest(int $clientId, array $validatedData, bool $lockForUpdate = false): ?SolicitudVuelo
    {
        if ($clientId <= 0) {
            return null;
        }

        $aircraftId = (int) ($validatedData['aircraft_id'] ?? 0);
        $providerId = (int) ($validatedData['provider_id'] ?? 0);
        $passengers = (int) ($validatedData['passengers'] ?? 0);
        $tripType = trim((string) ($validatedData['trip_type'] ?? ''));
        $origin = strtoupper(trim((string) ($validatedData['origin'] ?? '')));
        $destination = strtoupper(trim((string) ($validatedData['destination'] ?? '')));
        $departure = $this->normalizeComparableFlightRequestDateTime($validatedData['departure_datetime'] ?? null);

        if ($aircraftId <= 0 || $origin === '' || $destination === '' || ! $departure) {
            return null;
        }

        $requestedLegsSignature = $this->buildComparableLegsSignatureFromPayload($validatedData);
        $query = SolicitudVuelo::query()
            ->with(['legs:id,flight_request_id,leg_order,origin,destination,departure_datetime'])
            ->where('client_id', $clientId)
            ->where('assigned_aircraft_id', $aircraftId)
            ->where('origin', $origin)
            ->where('destination', $destination)
            ->where('departure_datetime', $departure)
            ->where('passengers', $passengers)
            ->where('trip_type', $tripType)
            ->whereNotIn(DB::raw('lower(coalesce(status, \'\'))'), self::DUPLICATE_FLIGHT_REQUEST_TERMINAL_STATUSES)
            ->whereNotIn(DB::raw('lower(coalesce(workflow_status, \'\'))'), self::DUPLICATE_FLIGHT_REQUEST_TERMINAL_WORKFLOWS)
            ->when($providerId > 0, fn ($builder) => $builder->where('assigned_provider_id', $providerId))
            ->latest('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get()->first(function (SolicitudVuelo $request) use ($requestedLegsSignature): bool {
            return $this->buildComparableLegsSignatureFromModel($request) === $requestedLegsSignature;
        });
    }

    private function buildComparableLegsSignatureFromPayload(array $validatedData): string
    {
        $legs = collect($validatedData['legs'] ?? [])
            ->map(function ($leg, int $index): array {
                return [
                    'order' => (int) ($leg['leg_order'] ?? $index + 1),
                    'origin' => strtoupper(trim((string) ($leg['origin'] ?? ''))),
                    'destination' => strtoupper(trim((string) ($leg['destination'] ?? ''))),
                    'departure' => $this->normalizeComparableFlightRequestDateTime($leg['departure_datetime'] ?? null),
                ];
            })
            ->filter(fn (array $leg): bool => $leg['origin'] !== '' && $leg['destination'] !== '')
            ->sortBy('order')
            ->values()
            ->all();

        if ($legs === []) {
            $legs = [[
                'order' => 1,
                'origin' => strtoupper(trim((string) ($validatedData['origin'] ?? ''))),
                'destination' => strtoupper(trim((string) ($validatedData['destination'] ?? ''))),
                'departure' => $this->normalizeComparableFlightRequestDateTime($validatedData['departure_datetime'] ?? null),
            ]];
        }

        return sha1(json_encode($legs));
    }

    private function buildComparableLegsSignatureFromModel(SolicitudVuelo $request): string
    {
        $request->loadMissing('legs:id,flight_request_id,leg_order,origin,destination,departure_datetime');

        $legs = $request->legs
            ->map(fn ($leg) => [
                'order' => (int) ($leg->leg_order ?? 0),
                'origin' => strtoupper(trim((string) ($leg->origin ?? ''))),
                'destination' => strtoupper(trim((string) ($leg->destination ?? ''))),
                'departure' => $this->normalizeComparableFlightRequestDateTime($leg->departure_datetime),
            ])
            ->filter(fn (array $leg): bool => $leg['origin'] !== '' && $leg['destination'] !== '')
            ->sortBy('order')
            ->values()
            ->all();

        if ($legs === []) {
            $legs = [[
                'order' => 1,
                'origin' => strtoupper(trim((string) ($request->origin ?? ''))),
                'destination' => strtoupper(trim((string) ($request->destination ?? ''))),
                'departure' => $this->normalizeComparableFlightRequestDateTime($request->departure_datetime),
            ]];
        }

        return sha1(json_encode($legs));
    }

    private function normalizeComparableFlightRequestDateTime(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->copy()->format('Y-m-d H:i:s');
        }

        $candidate = trim((string) ($value ?? ''));

        if ($candidate === '') {
            return null;
        }

        try {
            return Carbon::parse($candidate)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function isFlightRequestIdempotencyUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $message = strtolower($exception->getMessage());

        return in_array($sqlState, ['23000', '23505'], true)
            && str_contains($message, 'fr_client_idem_uq');
    }

    private function flightRequestSupportsIdempotency(): bool
    {
        if ($this->flightRequestIdempotencyColumnExists !== null) {
            return $this->flightRequestIdempotencyColumnExists;
        }

        return $this->flightRequestIdempotencyColumnExists = Schema::hasColumn('flight_requests', 'idempotency_key');
    }

    private function buildStoredFlightRequestResponse(
        SolicitudVuelo $solicitud,
        Usuario $user,
        bool $alreadyExists,
        ?string $message,
        int $status,
        $acceptedQuote = null,
        ?int $chatId = null,
    ) {
        $loadedRequest = SolicitudVuelo::query()
            ->with([
                'assignedAircraft:id,model,category,capacity',
                'assignedAircraft.images:id,aircraft_id,kind,title,image_url,sort_order,is_main,visible_to_client',
                'matches:id,flight_request_id,aircraft_id,status,estimated_price,visibility_payload',
                'matches.aircraft:id,model,category,capacity',
                'matches.aircraft.images:id,aircraft_id,kind,title,image_url,sort_order,is_main,visible_to_client',
                'chatsProtegidos:id,flight_request_id,status',
                'latestOperation',
                'latestOperation.timeline:id,operation_id,status,title,description,created_at',
                'legs:id,flight_request_id,leg_order,origin,destination,departure_datetime,arrival_datetime,passengers,distance_km',
                'quotes:id,flight_request_id,aircraft_id,provider_id,status,total,currency,expires_at',
            ])
            ->findOrFail($solicitud->id);

        $acceptedQuote ??= $loadedRequest->quotes
            ->where('status', 'accepted')
            ->sortByDesc('id')
            ->first();
        $chatId ??= $loadedRequest->chatsProtegidos->sortByDesc('id')->first()?->id;

        $flightRequestPayload = $this->visibilidadServicio->solicitudParaCliente(
            $loadedRequest,
            [
                'skip_reservation_lookup' => true,
            ],
        );

        Log::info('Stored flight request response built', [
            'flight_request_id' => $loadedRequest->id,
            'client_id' => $loadedRequest->client_id,
            'assigned_aircraft_id' => $loadedRequest->assigned_aircraft_id,
            'assigned_provider_id' => $loadedRequest->assigned_provider_id,
            'already_exists' => $alreadyExists,
            'accepted_quote_id' => $acceptedQuote?->id,
            'accepted_quote_total' => $acceptedQuote?->total,
            'flight_request_final_price' => $loadedRequest->final_price,
            'pricing_context_total_amount' => data_get($loadedRequest->pricing_context, 'total_amount'),
            'frontend_quote_total' => $flightRequestPayload['quote_total'] ?? null,
            'frontend_total_amount' => $flightRequestPayload['total_amount'] ?? null,
            'frontend_final_price' => $flightRequestPayload['final_price'] ?? null,
            'currency' => $flightRequestPayload['currency'] ?? $loadedRequest->currency,
        ]);

        return $this->ok(array_filter([
            'message' => $message,
            'already_exists' => $alreadyExists,
            'flight_request' => $flightRequestPayload + ['already_exists' => $alreadyExists],
            'accepted_quote' => $acceptedQuote ? [
                'id' => $acceptedQuote->id,
                'quote_id' => $acceptedQuote->id,
                'aircraft_id' => $acceptedQuote->aircraft_id,
                'provider_id' => $acceptedQuote->provider_id,
                'status' => $acceptedQuote->status,
                'total' => $acceptedQuote->total,
                'currency' => $acceptedQuote->currency,
                'expires_at' => optional($acceptedQuote->expires_at)->toIso8601String(),
            ] : null,
            'chat_id' => $chatId,
            'access' => $user->accessStatus(),
        ], fn ($value) => $value !== null), $status);
    }

    private function resolveCommercialAccessGate($user): array
    {
        if ($user->hasRole(Usuario::ROLE_ADMIN)) {
            return ['allowed' => true, 'consume_trial_quote' => false, 'plan' => null];
        }

        if ((bool) $user->has_paid_access && $user->access_status === 'active') {
            return ['allowed' => true, 'consume_trial_quote' => false, 'plan' => null];
        }

        if ($user->activeSuscripcion || ($user->demo?->status === 'active' && $user->demo?->expires_at?->isFuture())) {
            return ['allowed' => true, 'consume_trial_quote' => false, 'plan' => null];
        }

        $used = (int) ($user->free_quotes_used ?? 0);
        $limit = max(1, (int) ($user->free_quote_limit ?? 1));
        $trialEndsAt = $user->trial_ends_at ? Carbon::parse($user->trial_ends_at) : null;
        $trialStillActive = $trialEndsAt === null || ! $trialEndsAt->isPast();
        $status = (string) ($user->access_status ?? 'trial_active');

        if (
            in_array($status, ['trial_active', 'registered', 'payment_failed', 'trial_used', 'payment_pending'], true)
            && $used < $limit
            && $trialStillActive
        ) {
            return ['allowed' => true, 'consume_trial_quote' => true, 'plan' => null];
        }

        $plan = $this->billingPlanServicio->findActiveByCode(BillingPlanServicio::CLIENT_ACCESS_CODE);

        return [
            'allowed' => false,
            'consume_trial_quote' => false,
            'plan' => $plan ? $this->billingPlanServicio->serialize($plan) : null,
        ];
    }

    private function resolveOptionalApiUser(Request $request): ?Usuario
    {
        if ($request->user()) {
            return $request->user()->fresh(['activeSuscripcion', 'demo', 'roles']);
        }

        $plainToken = $request->bearerToken() ?: $request->cookie((string) env('AUTH_TOKEN_COOKIE', 'red_aviation_session'));

        if (! $plainToken) {
            return null;
        }

        $token = TokenApi::with([
            'user.roles',
            'user.activeSuscripcion',
            'user.demo',
        ])
            ->where('token', hash('sha256', $plainToken))
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if (! $token?->user || $token->user->status !== 'active') {
            return null;
        }

        return $token->user;
    }

    private function assignSelectedMatchToFlightRequest(SolicitudVuelo $solicitud, array $data): void
    {
        $selectedMatchId = (string) ($data['match_id'] ?? $data['matched_option_id'] ?? '');
        $selectedProviderId = (int) ($data['provider_id'] ?? 0);
        $selectedAircraftId = (int) ($data['aircraft_id'] ?? 0);
        $selectedPreviewAircraftId = 0;

        if ($selectedMatchId !== '' && preg_match('/^preview-(\d+)$/', $selectedMatchId, $matches)) {
            $selectedPreviewAircraftId = (int) ($matches[1] ?? 0);
        }

        if ($selectedMatchId === '' && ! $selectedProviderId && ! $selectedAircraftId) {
            return;
        }

        $selectedMatch = null;

        if ($selectedMatchId !== '') {
            $selectedMatchQuery = $solicitud->matches();

            if (ctype_digit($selectedMatchId)) {
                $selectedMatchQuery->whereKey((int) $selectedMatchId);
            } elseif ($selectedPreviewAircraftId > 0) {
                $selectedMatchQuery->where('aircraft_id', $selectedPreviewAircraftId);
            }

            $selectedMatch = $selectedMatchQuery->first();
        }

        if (! $selectedMatch) {
            $selectedMatch = $solicitud->matches()
                ->when($selectedProviderId > 0, fn ($query) => $query->where('provider_id', $selectedProviderId))
                ->when($selectedAircraftId > 0, fn ($query) => $query->where('aircraft_id', $selectedAircraftId))
                ->first();
        }

        if (! $selectedMatch && $selectedAircraftId > 0) {
            $selectedMatch = $solicitud->matches()
                ->where('aircraft_id', $selectedAircraftId)
                ->first();
        }

        if (! $selectedMatch && $selectedProviderId > 0) {
            $selectedMatch = $solicitud->matches()
                ->where('provider_id', $selectedProviderId)
                ->orderByDesc('match_score')
                ->first();
        }

        $resolvedAircraftId = $selectedAircraftId > 0 ? $selectedAircraftId : $selectedPreviewAircraftId;
        if (! $selectedMatch && $resolvedAircraftId > 0) {
            $selectedAircraft = Aeronave::with(['provider', 'documents', 'availability', 'availabilityBlocks', 'baseAirport'])
                ->find($resolvedAircraftId);

            if ($selectedAircraft) {
                $this->assertAircraftEligibleForFlightRequest($selectedAircraft, $solicitud, $data);
                $selectedMatch = $solicitud->matches()->updateOrCreate(
                    [
                        'aircraft_id' => $selectedAircraft->id,
                        'provider_id' => $selectedAircraft->provider_id,
                    ],
                    [
                        'match_score' => 100,
                        'status' => 'pending',
                        'response_deadline' => now()->addMinutes(30),
                        'visibility_payload' => [
                            'aircraft_model' => $selectedAircraft->model,
                            'capacity' => $selectedAircraft->capacity,
                            'provider_label' => 'Operador verificado Red Aviation',
                            'forced_from_client_selection' => true,
                        ],
                    ]
                );
            }
        }

        if (! $selectedMatch) {
            return;
        }

        $selectedMatch->loadMissing('aircraft');
        if (! $selectedMatch->aircraft) {
            return;
        }

        $this->assertAircraftEligibleForFlightRequest($selectedMatch->aircraft, $solicitud, $data);

        $serverPricing = $this->resolveServerPricingForSelectedAircraft($selectedMatch->aircraft, $solicitud, $data);
        $resolvedSelectedPrice = (float) ($serverPricing['total_amount'] ?? 0);

        Log::info('Selected aircraft priced for flight request', [
            'flight_request_id' => $solicitud->id,
            'aircraft_id' => $selectedMatch->aircraft_id,
            'provider_id' => $selectedMatch->provider_id,
            'trip_type' => $solicitud->trip_type,
            'hourly_rate' => data_get($serverPricing, 'hourly_rate'),
            'minimum_hours' => data_get($serverPricing, 'minimum_hours'),
            'airport_expenses' => data_get($serverPricing, 'airport_expenses'),
            'client_display_flight_hours' => data_get($serverPricing, 'client_display_flight_hours'),
            'billable_hours' => data_get($serverPricing, 'billable_hours'),
            'subtotal' => data_get($serverPricing, 'subtotal'),
            'tax' => data_get($serverPricing, 'tax'),
            'total_amount' => data_get($serverPricing, 'total_amount'),
            'quote_strategy' => data_get($serverPricing, 'quote_strategy'),
            'route_signature' => data_get($serverPricing, 'route_snapshot.signature'),
        ]);

        $selectedMatch->update([
            'estimated_price' => $resolvedSelectedPrice,
            'status' => 'sent_to_provider',
        ]);

        $visibilityPayload = $solicitud->visibility_payload ?? [];

        $solicitud->update([
            'assigned_provider_id' => $selectedMatch->provider_id,
            'assigned_aircraft_id' => $selectedMatch->aircraft_id,
            'assigned_aircraft_model' => $selectedMatch->aircraft?->model,
            'final_price' => $resolvedSelectedPrice > 0 ? $resolvedSelectedPrice : $solicitud->final_price,
            'pricing_context' => $serverPricing,
            'currency' => $selectedMatch->aircraft?->currency ?: ($solicitud->currency ?: 'USD'),
            'workflow_status' => 'operador_asignado',
            'visibility_payload' => [
                ...$visibilityPayload,
                'selected_provider_id' => $selectedMatch->provider_id,
                'selected_aircraft_id' => $selectedMatch->aircraft_id,
                'aircraft_model' => $selectedMatch->aircraft?->model,
                'aircraft_category' => $selectedMatch->aircraft?->category,
                'aircraft_capacity' => $selectedMatch->aircraft?->capacity,
                'selected_card_price' => $resolvedSelectedPrice > 0 ? $resolvedSelectedPrice : null,
            ],
        ]);
    }

    private function ensureAircraftIsAvailableForFlightRequest(int $aircraftId, SolicitudVuelo $solicitud, array $requestData = []): void
    {
        [$requestedStart, $requestedEnd] = $this->aircraftAvailabilityService->resolveWindowFromPayload([
            'departure_datetime' => optional($solicitud->departure_datetime)->toDateTimeString() ?? ($requestData['departure_datetime'] ?? null),
            'return_datetime' => optional($solicitud->return_datetime)->toDateTimeString() ?? ($requestData['return_datetime'] ?? null),
            'legs' => $solicitud->legs->map(fn ($leg) => [
                'departure_datetime' => $leg->departure_datetime,
                'arrival_datetime' => $leg->arrival_datetime,
            ])->values()->all(),
        ]);

        if (! $this->aircraftAvailabilityService->aircraftHasConflict($aircraftId, $requestedStart, $requestedEnd)) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'success' => false,
            'code' => 'AIRCRAFT_NOT_AVAILABLE',
            'message' => 'Esta aeronave ya no está disponible para el horario seleccionado.',
        ], 409));
    }

    private function assertAircraftEligibleForFlightRequest(
        Aeronave $aircraft,
        SolicitudVuelo $solicitud,
        array $requestData = [],
    ): array {
        $pricingRequestData = [
            ...$requestData,
            'origin' => $solicitud->origin,
            'destination' => $solicitud->destination,
            'departure_datetime' => optional($solicitud->departure_datetime)->toDateTimeString()
                ?? ($requestData['departure_datetime'] ?? null),
            'return_datetime' => optional($solicitud->return_datetime)->toDateTimeString()
                ?? ($requestData['return_datetime'] ?? null),
            'trip_type' => $solicitud->trip_type,
            'requirements' => is_array($solicitud->requirements) ? $solicitud->requirements : [],
        ];
        $route = $this->flightRouteService->buildCanonicalRoute($pricingRequestData);
        [$start, $end] = $this->aircraftAvailabilityService->resolveWindowFromPayload([
            ...$pricingRequestData,
            'legs' => $route['legs'],
        ]);
        $eligibility = $this->aircraftEligibilityService->evaluate($aircraft, [
            'route' => $route,
            'passengers' => (int) $solicitud->passengers,
            'trip_type' => $route['trip_type'],
            'preference' => $solicitud->aircraft_type,
            'requested_start' => $start,
            'requested_end' => $end,
            'flight_request_id' => $solicitud->id,
        ]);

        if (! $eligibility['eligible']) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => $eligibility['reasons'][0] ?? 'La aeronave no es elegible para este itinerario.',
                'reason_code' => $eligibility['reason_code'],
                'reason_codes' => $eligibility['reason_codes'],
                'eligibility_rule_version' => $eligibility['rule_version'],
            ], 409));
        }

        return $eligibility;
    }

    private function ensureAcceptedQuoteForFlightRequest(SolicitudVuelo $solicitud): ?Cotizacion
    {
        $solicitud->loadMissing(['quotes', 'assignedAircraft']);

        $providerId = (int) ($solicitud->assigned_provider_id ?? 0);
        $aircraftId = (int) ($solicitud->assigned_aircraft_id ?? 0);
        $totalAmount = (float) data_get($solicitud->pricing_context, 'total_amount', $solicitud->final_price ?? 0);

        if ($providerId <= 0 || $aircraftId <= 0 || $totalAmount <= 0) {
            Log::info('Accepted quote skipped for flight request', [
                'flight_request_id' => $solicitud->id,
                'provider_id' => $providerId,
                'aircraft_id' => $aircraftId,
                'total_amount' => $totalAmount,
            ]);

            return null;
        }

        $subtotal = (float) data_get(
            $solicitud->pricing_context,
            'subtotal_before_margin',
            data_get($solicitud->pricing_context, 'subtotal', max($totalAmount, 0))
        );
        $taxes = (float) data_get(
            $solicitud->pricing_context,
            'tax',
            data_get($solicitud->pricing_context, 'taxes', 0)
        );
        $fees = max(round($totalAmount - $subtotal - $taxes, 2), 0);

        $existingQuote = $solicitud->quotes()
            ->where('provider_id', $providerId)
            ->where('aircraft_id', $aircraftId)
            ->where('status', 'accepted')
            ->latest('id')
            ->first();

        $attributes = [
            'subtotal' => round($subtotal, 2),
            'taxes' => round($taxes, 2),
            'fees' => round($fees, 2),
            'total' => round($totalAmount, 2),
            'currency' => $solicitud->currency ?: 'USD',
            'provider_notes' => 'Cotizacion aceptada automaticamente a partir de la seleccion del cliente en preview.',
            'status' => 'accepted',
            'expires_at' => now()->addDay(),
        ];

        if ($existingQuote) {
            $existingQuote->update($attributes);

            Log::info('Accepted quote updated for flight request', [
                'flight_request_id' => $solicitud->id,
                'quote_id' => $existingQuote->id,
                'aircraft_id' => $aircraftId,
                'provider_id' => $providerId,
                'subtotal' => $attributes['subtotal'],
                'taxes' => $attributes['taxes'],
                'fees' => $attributes['fees'],
                'total' => $attributes['total'],
                'currency' => $attributes['currency'],
            ]);

            return $existingQuote->fresh();
        }

        $quote = $solicitud->quotes()->create([
            ...$attributes,
            'quote_code' => 'QT-'.now()->format('ymdHis').'-'.Str::upper(Str::random(6)),
            'provider_id' => $providerId,
            'aircraft_id' => $aircraftId,
        ]);

        Log::info('Accepted quote created for flight request', [
            'flight_request_id' => $solicitud->id,
            'quote_id' => $quote->id,
            'aircraft_id' => $aircraftId,
            'provider_id' => $providerId,
            'subtotal' => $attributes['subtotal'],
            'taxes' => $attributes['taxes'],
            'fees' => $attributes['fees'],
            'total' => $attributes['total'],
            'currency' => $attributes['currency'],
        ]);

        return $quote;
    }

    public function indexFlightRequests(Request $request)
    {
        $data = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_CLIENT_FLIGHT_REQUESTS_PAGE_SIZE],
        ]);
        $perPage = (int) ($data['per_page'] ?? self::DEFAULT_CLIENT_FLIGHT_REQUESTS_PAGE_SIZE);

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
                'notes',
                'status',
                'workflow_status',
            ])
            ->with([
                'assignedAircraft:id,model,category,capacity',
                'assignedAircraft.images:id,aircraft_id,kind,title,image_url,is_main,sort_order,visible_to_client',
                'latestOperation' => fn ($query) => $query->select([
                    'operations.id',
                    'operations.flight_request_id',
                    'operations.status',
                ]),
                'chatsProtegidos:id,flight_request_id,status',
                'legs:id,flight_request_id,leg_order,origin,destination,departure_datetime,arrival_datetime,passengers,distance_km',
                'reservation:id,flight_request_id,status',
                'reservation.contract:id,reservation_id,status,docusign_status,signed_at,completed_at,signed_pdf_path',
                'reservation.latestPayment' => fn ($query) => $query->select([
                    'payments.id',
                    'payments.reservation_id',
                    'payments.flight_request_id',
                    'payments.status',
                    'payments.provider',
                    'payments.paid_at',
                    'payments.stripe_checkout_session_id',
                    'payments.stripe_payment_intent_id',
                ]),
            ])
            ->where('client_id', $request->user()->id)
            ->latest()
            ->paginate($perPage);

        $normalizedSolicitudes = $solicitudes->getCollection()->map(
            fn (SolicitudVuelo $solicitud) => $this->normalizeStripePendingPaymentState($solicitud)
        );

        return $this->ok([
            'flight_requests' => $normalizedSolicitudes->map(
                fn ($solicitud) => $this->visibilidadServicio->solicitudParaCliente($solicitud, [
                    'include_matches' => false,
                    'include_timeline' => false,
                ])
            )->values(),
            'meta' => [
                'current_page' => $solicitudes->currentPage(),
                'per_page' => $solicitudes->perPage(),
                'total' => $solicitudes->total(),
                'last_page' => $solicitudes->lastPage(),
            ],
        ]);
    }

    public function showFlightRequest(Request $request, SolicitudVuelo $flightRequest)
    {
        abort_if($flightRequest->client_id !== $request->user()->id, 403);

        $flightRequest = $this->normalizeStripePendingPaymentState(
            $flightRequest->load([
                'assignedAircraft:id,model,category,capacity',
                'assignedAircraft.images:id,aircraft_id,kind,title,image_url,is_main,sort_order,visible_to_client',
                'matches:id,flight_request_id,aircraft_id,status,estimated_price,visibility_payload',
                'matches.aircraft:id,model,capacity,category',
                'matches.aircraft.images:id,aircraft_id,kind,title,image_url,is_main,sort_order,visible_to_client',
                'chatsProtegidos:id,flight_request_id,status',
                'latestOperation' => fn ($query) => $query->select([
                    'operations.id',
                    'operations.flight_request_id',
                    'operations.status',
                ]),
                'latestOperation.timeline:id,operation_id,status,title,description,created_at',
                'legs:id,flight_request_id,leg_order,origin,destination,departure_datetime,arrival_datetime,passengers,distance_km',
                'reservation:id,flight_request_id,status',
                'reservation.contract:id,reservation_id,status,docusign_status,signed_at,completed_at,signed_pdf_path',
                'reservation.latestPayment' => fn ($query) => $query->select([
                    'payments.id',
                    'payments.reservation_id',
                    'payments.flight_request_id',
                    'payments.status',
                    'payments.provider',
                    'payments.paid_at',
                    'payments.stripe_checkout_session_id',
                    'payments.stripe_payment_intent_id',
                ]),
            ])
        );

        return $this->ok([
            'flight_request' => $this->visibilidadServicio->solicitudParaCliente($flightRequest),
        ]);
    }

    private function normalizeStripePendingPaymentState(SolicitudVuelo $flightRequest): SolicitudVuelo
    {
        $reservation = $flightRequest->reservation;
        $latestPayment = $reservation?->latestPayment;

        if (! $reservation || ! $latestPayment) {
            return $flightRequest;
        }

        $paymentStatus = strtolower(trim((string) $latestPayment->status));
        $provider = strtolower(trim((string) $latestPayment->provider));
        $flightRequestPaymentStatus = strtolower(trim((string) $flightRequest->payment_status));
        $reservationStatus = strtolower(trim((string) $reservation->status));
        $hasPaidSignal = ! empty($latestPayment->paid_at)
            && $provider === 'stripe'
            && in_array($paymentStatus, ['pending', 'processing', ''], true);

        if (! $hasPaidSignal) {
            return $flightRequest;
        }

        DB::transaction(function () use ($flightRequest, $reservation, $latestPayment) {
            $flightRequest->forceFill([
                'payment_status' => 'paid',
                'payment_method' => trim((string) $flightRequest->payment_method) !== '' ? $flightRequest->payment_method : 'stripe_checkout',
                'workflow_status' => 'vuelo confirmado',
                'status' => 'reserved',
                'updated_at' => now(),
            ])->save();

            $reservation->forceFill([
                'status' => 'confirmed',
                'confirmed_at' => $reservation->confirmed_at ?: $latestPayment->paid_at ?: now(),
                'updated_at' => now(),
            ])->save();

            $latestPayment->forceFill([
                'status' => 'paid',
                'failure_reason' => null,
                'updated_at' => now(),
            ])->save();

            $this->aircraftAvailabilityService->blockAircraftForPaidReservation($reservation->fresh(['flightRequest.legs', 'legs']));
        });

        return $flightRequest->fresh([
            'assignedAircraft:id,model,category,capacity',
            'assignedAircraft.images:id,aircraft_id,kind,title,image_url,is_main,sort_order,visible_to_client',
            'matches:id,flight_request_id,aircraft_id,status,estimated_price,visibility_payload',
            'matches.aircraft:id,model,capacity,category',
            'matches.aircraft.images:id,aircraft_id,kind,title,image_url,is_main,sort_order,visible_to_client',
            'chatsProtegidos:id,flight_request_id,status',
            'latestOperation' => fn ($query) => $query->select([
                'operations.id',
                'operations.flight_request_id',
                'operations.status',
            ]),
            'latestOperation.timeline:id,operation_id,status,title,description,created_at',
            'legs:id,flight_request_id,leg_order,origin,destination,departure_datetime,arrival_datetime,passengers,distance_km',
            'reservation:id,flight_request_id,status,confirmed_at',
            'reservation.contract:id,reservation_id,status,docusign_status,signed_at,completed_at,signed_pdf_path',
            'reservation.latestPayment' => fn ($query) => $query->select([
                'payments.id',
                'payments.reservation_id',
                'payments.flight_request_id',
                'payments.status',
                'payments.provider',
                'payments.paid_at',
                'payments.stripe_checkout_session_id',
                'payments.stripe_payment_intent_id',
            ]),
        ]);
    }

    public function tracking(Request $request, Operacion $operation)
    {
        abort_if($operation->solicitudVuelo?->client_id !== $request->user()->id, 403);

        return $this->ok([
            'operation' => [
                'id' => $operation->id,
                'status' => $operation->status,
                'timeline' => $operation->timeline()->latest()->get(),
            ],
        ]);
    }

    private function findActiveAirport(string $code): ?Aeropuerto
    {
        $normalizedCode = strtoupper(trim($code));
        if ($normalizedCode === '') {
            return null;
        }

        if (array_key_exists($normalizedCode, $this->activeAirportCache)) {
            return $this->activeAirportCache[$normalizedCode];
        }

        $airport = Aeropuerto::query()
            ->where('status', 'active')
            ->where(function ($query) use ($normalizedCode) {
                foreach ($this->activeAirportSearchColumns() as $index => $column) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $query->{$method}($column, $normalizedCode);
                }
            })
            ->first();

        if (! $airport) {
            $airport = Aeropuerto::query()
                ->where('status', 'active')
                ->where(function ($query) use ($normalizedCode) {
                    foreach ($this->activeAirportSearchColumns() as $index => $column) {
                        $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                        $query->{$method}("UPPER({$column}) = ?", [$normalizedCode]);
                    }
                })
                ->first();
        }

        return $this->activeAirportCache[$normalizedCode] = $airport;
    }

    private function activeAirportSearchColumns(): array
    {
        if ($this->activeAirportSearchColumns !== null) {
            return $this->activeAirportSearchColumns;
        }

        $columns = ['icao', 'iata'];

        foreach (['icao_code', 'iata_code'] as $column) {
            if (Schema::hasColumn('airports', $column)) {
                $columns[] = $column;
            }
        }

        return $this->activeAirportSearchColumns = $columns;
    }

    private function distanceKm(float $originLat, float $originLng, float $destinationLat, float $destinationLng): float
    {
        $earthRadiusKm = 6371;
        $latDelta = deg2rad($destinationLat - $originLat);
        $lngDelta = deg2rad($destinationLng - $originLng);
        $originLat = deg2rad($originLat);
        $destinationLat = deg2rad($destinationLat);

        $angle = sin($latDelta / 2) ** 2
            + cos($originLat) * cos($destinationLat) * sin($lngDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($angle), sqrt(1 - $angle));
    }

    private function distanceNm(float $originLat, float $originLng, float $destinationLat, float $destinationLng): float
    {
        $earthRadiusNm = 3440.065;
        $latDelta = deg2rad($destinationLat - $originLat);
        $lngDelta = deg2rad($destinationLng - $originLng);
        $originLat = deg2rad($originLat);
        $destinationLat = deg2rad($destinationLat);

        $angle = sin($latDelta / 2) ** 2
            + cos($originLat) * cos($destinationLat) * sin($lngDelta / 2) ** 2;

        return $earthRadiusNm * 2 * atan2(sqrt($angle), sqrt(1 - $angle));
    }

    private function quoteLegs(array $data, Aeropuerto $originAirport, Aeropuerto $destinationAirport): array
    {
        return $this->flightRouteService->buildCanonicalRoute($data)['legs'];
    }

    private function normalizeRouteLegDefinitions(array $data): array
    {
        $explicitLegs = $this->normalizeExplicitLegDefinitions($data['legs'] ?? null);
        if ($explicitLegs !== []) {
            return $explicitLegs;
        }

        $origin = strtoupper(trim((string) ($data['origin'] ?? '')));
        $destination = strtoupper(trim((string) ($data['destination'] ?? '')));

        $points = array_values(array_filter([$origin], fn (string $code) => $code !== ''));
        $definitions = [];

        foreach (($data['requirements'] ?? []) as $requirement) {
            if (! is_array($requirement)) {
                continue;
            }

            $requirementOrigin = $this->extractAirportCode($requirement, ['origin', 'origin_code', 'from', 'from_code', 'origin_airport']);
            $requirementDestination = $this->extractAirportCode($requirement, ['destination', 'destination_code', 'to', 'to_code', 'destination_airport']);

            if ($requirementOrigin !== null && $requirementDestination !== null) {
                $definitions[] = [
                    'origin' => $requirementOrigin,
                    'destination' => $requirementDestination,
                    'departure_datetime' => $requirement['departure_datetime'] ?? null,
                ] + $this->extractLegDurationFields($requirement);

                continue;
            }

            $waypoint = $requirementDestination
                ?? $requirementOrigin
                ?? $this->extractAirportCode($requirement, ['airport', 'airport_code', 'code', 'icao', 'iata']);

            if ($waypoint !== null && ($points[count($points) - 1] ?? null) !== $waypoint) {
                $points[] = $waypoint;
            }
        }

        if ($definitions !== []) {
            $firstDefinition = $definitions[0] ?? null;
            if (
                $origin !== ''
                && $destination !== ''
                && $firstDefinition
                && ! ($firstDefinition['origin'] === $origin && $firstDefinition['destination'] === $destination)
            ) {
                array_unshift($definitions, [
                    'origin' => $origin,
                    'destination' => $destination,
                    'departure_datetime' => $data['departure_datetime'] ?? null,
                ]);
            }

            return $definitions;
        }

        if ($destination !== '' && ! in_array($destination, $points, true)) {
            $points[] = $destination;
        }

        if (count($points) < 2 && $origin !== '' && $destination !== '') {
            $points = [$origin, $destination];
        }

        for ($index = 0; $index < count($points) - 1; $index++) {
            $definitions[] = [
                'origin' => $points[$index],
                'destination' => $points[$index + 1],
                'departure_datetime' => $index === 0
                    ? ($data['departure_datetime'] ?? null)
                    : (($data['requirements'][$index - 1]['departure_datetime'] ?? null) ?: null),
            ] + $this->extractLegDurationFields((array) ($data['requirements'][$index - 1] ?? []));
        }

        return $definitions;
    }

    private function normalizeExplicitLegDefinitions(mixed $legs): array
    {
        if (! is_array($legs)) {
            return [];
        }

        $definitions = [];

        foreach ($legs as $leg) {
            if (! is_array($leg)) {
                continue;
            }

            $origin = $this->extractAirportCode($leg, ['origin', 'origin_code', 'from', 'from_code', 'origin_airport']);
            $destination = $this->extractAirportCode($leg, ['destination', 'destination_code', 'to', 'to_code', 'destination_airport']);

            if (! $origin || ! $destination) {
                continue;
            }

            $definitions[] = [
                'origin' => $origin,
                'destination' => $destination,
                'departure_datetime' => $leg['departure_datetime'] ?? null,
            ] + $this->extractLegDurationFields($leg);
        }

        return $definitions;
    }

    private function extractLegDurationFields(array $payload): array
    {
        $fields = [];

        foreach ([
            'duration_minutes',
            'estimated_minutes',
            'quoted_minutes',
            'flight_minutes',
            'leg_minutes',
            'duration_hours',
        ] as $field) {
            if (array_key_exists($field, $payload) && $payload[$field] !== null && $payload[$field] !== '') {
                $fields[$field] = $payload[$field];
            }
        }

        return $fields;
    }

    private function extractAirportCode(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return strtoupper(trim($value));
            }

            if (is_array($value)) {
                foreach (['icao', 'iata', 'code'] as $nestedKey) {
                    $nestedValue = $value[$nestedKey] ?? null;
                    if (is_string($nestedValue) && trim($nestedValue) !== '') {
                        return strtoupper(trim($nestedValue));
                    }
                }
            }
        }

        return null;
    }

    private function quoteLegPayload(
        int $position,
        Aeropuerto $originAirport,
        Aeropuerto $destinationAirport,
        ?string $departureDatetime = null,
        array $definition = []
    ): array {
        $distanceKm = $this->distanceKm(
            (float) $originAirport->latitude,
            (float) $originAirport->longitude,
            (float) $destinationAirport->latitude,
            (float) $destinationAirport->longitude
        );
        $distanceNm = $this->distanceNm(
            (float) $originAirport->latitude,
            (float) $originAirport->longitude,
            (float) $destinationAirport->latitude,
            (float) $destinationAirport->longitude
        );
        $international = $this->isInternationalLeg($originAirport, $destinationAirport);

        return [
            'position' => $position,
            'origin' => $originAirport->icao ?: $originAirport->iata,
            'destination' => $destinationAirport->icao ?: $destinationAirport->iata,
            'origin_airport' => $this->airportPreviewPayload($originAirport),
            'destination_airport' => $this->airportPreviewPayload($destinationAirport),
            'distance_km' => round($distanceKm),
            'distance_nm' => round($distanceNm),
            'international' => $international,
            'departure_datetime' => $departureDatetime,
        ] + $this->extractLegDurationFields($definition);
    }

    private function aircraftCanQuote(Aeronave $aircraft, float $distanceKm): bool
    {
        if ($this->resolveCruiseSpeedKmh($aircraft) <= 0 || (float) $aircraft->hourly_rate <= 0) {
            return false;
        }

        return ! $aircraft->range_km || (float) $aircraft->range_km >= $distanceKm;
    }

    private function formatHours(float $hours): string
    {
        $totalMinutes = max((int) round($hours * 60), 1);
        $hourPart = intdiv($totalMinutes, 60);
        $minutePart = $totalMinutes % 60;

        if ($hourPart === 0) {
            return "{$minutePart}m";
        }

        return $minutePart === 0 ? "{$hourPart}h" : "{$hourPart}h {$minutePart}m";
    }

    private function resolveTimeMode(array $requestData, string $key, string $default): string
    {
        $mode = mb_strtolower(trim((string) ($requestData[$key] ?? '')));

        return in_array($mode, [
            self::TIME_MODE_DIRECT,
            self::TIME_MODE_DIRECT_PLUS_CLIMB,
            self::TIME_MODE_OPERATIONAL,
        ], true) ? $mode : $default;
    }

    private function resolveLegHoursByMode(array $legPricing, string $mode): float
    {
        if (($legPricing['hours_source'] ?? null) === 'manual_leg_duration') {
            return (float) ($legPricing['manual_duration_hours'] ?? 0);
        }

        return match ($mode) {
            self::TIME_MODE_OPERATIONAL => (float) ($legPricing['real_flight_hours'] ?? 0),
            self::TIME_MODE_DIRECT_PLUS_CLIMB => (float) ($legPricing['direct_air_time_hours'] ?? 0) + (float) ($legPricing['climb_descent_hours'] ?? 0),
            default => (float) ($legPricing['direct_air_time_hours'] ?? 0),
        };
    }

    private function shouldIncludeBilledComponent(array $requestData, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $requestData)) {
            return $default;
        }

        return filter_var($requestData[$key], FILTER_VALIDATE_BOOL);
    }

    private function resolveFlightBaseSource(array $requestData): string
    {
        $source = mb_strtolower(trim((string) ($requestData['flight_base_source'] ?? '')));

        return in_array($source, ['pricing_trip_hours', 'billable_hours'], true)
            ? $source
            : 'billable_hours';
    }

    private function resolveRoundingMode(array $requestData, string $hoursSource): string
    {
        $mode = mb_strtolower(trim((string) ($requestData['rounding_mode'] ?? '')));

        if (in_array($mode, [
            self::ROUNDING_MODE_NONE,
            self::ROUNDING_MODE_QUARTER_NEAREST,
            self::ROUNDING_MODE_QUARTER_UP,
        ], true)) {
            return $mode;
        }

        return in_array($hoursSource, ['manual_leg_duration', 'mixed_manual_and_distance_speed'], true)
            ? self::ROUNDING_MODE_NONE
            : self::ROUNDING_MODE_QUARTER_NEAREST;
    }

    private function applyRoundingMode(float $hours, string $mode): float
    {
        return match ($mode) {
            self::ROUNDING_MODE_QUARTER_UP => ceil($hours * 4) / 4,
            self::ROUNDING_MODE_NONE => round($hours, 2),
            default => $this->roundUpQuarterHours($hours),
        };
    }

    private function resolveHoursSource(array $clientLegPricings): string
    {
        $hasManual = collect($clientLegPricings)->contains(
            fn (array $legPricing) => ($legPricing['hours_source'] ?? null) === 'manual_leg_duration'
        );
        $hasDistanceSpeed = collect($clientLegPricings)->contains(
            fn (array $legPricing) => ($legPricing['hours_source'] ?? null) === 'distance_speed'
        );

        return match (true) {
            $hasManual && $hasDistanceSpeed => 'mixed_manual_and_distance_speed',
            $hasManual => 'manual_leg_duration',
            default => 'distance_speed',
        };
    }

    private function formatMoney(float $amount, string $currency): string
    {
        return '$'.number_format(round($amount), 0, '.', ',').' '.strtoupper($currency);
    }

    private function resolveOperationalFactor(Aeronave $aircraft): float
    {
        $explicit = (float) ($aircraft->operational_factor ?? 0);
        if ($explicit > 0) {
            return $explicit;
        }

        $category = $this->normalizeAircraftCategory($aircraft->category);

        return self::CATEGORY_OPERATIONAL_FACTORS[$category ?? ''] ?? self::DEFAULT_OPERATIONAL_FACTOR;
    }

    private function resolveFixedMinutesPerLeg(Aeronave $aircraft): int
    {
        $explicit = (int) ($aircraft->fixed_minutes_per_leg ?? 0);
        if ($explicit > 0) {
            return $explicit;
        }

        $category = $this->normalizeAircraftCategory($aircraft->category);

        return self::CATEGORY_FIXED_MINUTES_PER_LEG[$category ?? ''] ?? self::DEFAULT_FIXED_MINUTES_PER_LEG;
    }

    private function resolveMinimumMinutesPerLeg(Aeronave $aircraft): int
    {
        $explicit = (int) ($aircraft->minimum_minutes_per_leg ?? 0);
        if ($explicit > 0) {
            return $explicit;
        }

        $category = $this->normalizeAircraftCategory($aircraft->category);

        return self::CATEGORY_MINIMUM_MINUTES_PER_LEG[$category ?? ''] ?? self::DEFAULT_MINIMUM_MINUTES_PER_LEG;
    }

    private function previewPricingForAircraft(
        Aeronave $aircraft,
        string $tripType,
        array $legs,
        array $requestData = [],
        ?array $canonicalRoute = null,
    ): array {
        $canonicalRoute ??= $this->flightRouteService->buildCanonicalRoute([
            ...$requestData,
            'trip_type' => $tripType,
            'legs' => $legs,
        ]);

        return $this->flightPricingService->calculate(
            $aircraft,
            $canonicalRoute,
            $requestData,
            fn (Aeronave $trustedAircraft, string $trustedTripType, array $trustedLegs, array $trustedInput) => $this->calculateLegacyPricing($trustedAircraft, $trustedTripType, $trustedLegs, $trustedInput),
        );
    }

    private function buildPreviewQuotesForCandidates(
        Collection $candidates,
        array $canonicalRoute,
        array $requestData,
        int $passengers,
        mixed $requestedStart,
        mixed $requestedEnd,
        float $distanceKm,
        float $distanceNm,
        array $legs,
        string $tripType,
        Aeropuerto $originAirport,
        bool $allowRepositioning,
        ?int $selectedRadiusNm = null,
    ): array {
        $preparedCandidates = $this->preparePreviewCandidatesForEvaluation(
            $candidates,
            $requestedStart,
            $requestedEnd,
            $originAirport,
            $allowRepositioning,
            $selectedRadiusNm,
            $requestData,
        );

        $eligibilityMs = 0.0;
        $pricingMs = 0.0;
        $eligibleCount = 0;
        $quotes = $preparedCandidates
            ->map(function (array $candidate) use (
                $canonicalRoute,
                $requestData,
                $passengers,
                $distanceKm,
                $distanceNm,
                $legs,
                $tripType,
                $originAirport,
                &$eligibilityMs,
                &$pricingMs,
                &$eligibleCount,
            ): ?array {
                $aircraft = $candidate['aircraft'] ?? null;
                if (! $aircraft instanceof Aeronave) {
                    return null;
                }

                $operationalContext = (array) ($candidate['operational_context'] ?? []);
                $eligibilityStartedAt = microtime(true);
                $eligibility = $this->aircraftEligibilityService->evaluate($aircraft, [
                    'route' => $canonicalRoute,
                    'passengers' => $passengers,
                    'trip_type' => $canonicalRoute['trip_type'],
                    'preference' => $requestData['aircraft_type'] ?? null,
                    'requested_start' => $candidate['requested_start'] ?? null,
                    'requested_end' => $candidate['requested_end'] ?? null,
                    'precomputed_availability_conflict' => (bool) ($candidate['precomputed_availability_conflict'] ?? false),
                ]);
                $eligibilityMs += (microtime(true) - $eligibilityStartedAt) * 1000;

                if (! $eligibility['eligible']) {
                    logger()->info('Aircraft excluded by canonical eligibility', [
                        'aircraft_id' => $aircraft->id,
                        'reason_codes' => $eligibility['reason_codes'],
                        'rule_version' => $eligibility['rule_version'],
                        'selected_radius_nm' => $operationalContext['selected_radius_nm'] ?? null,
                    ]);

                    return null;
                }

                $eligibleCount++;
                $pricingStartedAt = microtime(true);
                $quote = $this->buildPreviewQuotePayload(
                    $aircraft,
                    $canonicalRoute,
                    $requestData,
                    $distanceKm,
                    $distanceNm,
                    $legs,
                    $tripType,
                    $originAirport,
                    $operationalContext,
                );
                $pricingMs += (microtime(true) - $pricingStartedAt) * 1000;

                return $quote;
            })
            ->filter()
            ->values();

        return [
            'quotes' => $quotes,
            'metrics' => [
                'availability_query_count' => (int) ($preparedCandidates->first()['availability_query_count'] ?? 0),
                'availability_batch_ms' => round((float) ($preparedCandidates->first()['availability_batch_ms'] ?? 0), 2),
                'eligible_count' => $eligibleCount,
                'pricing_count' => $quotes->count(),
                'eligibility_ms' => round($eligibilityMs, 2),
                'pricing_ms' => round($pricingMs, 2),
            ],
        ];
    }

    private function preparePreviewCandidatesForEvaluation(
        Collection $candidates,
        mixed $requestedStart,
        mixed $requestedEnd,
        Aeropuerto $originAirport,
        bool $allowRepositioning,
        ?int $selectedRadiusNm,
        array $requestData,
    ): Collection {
        $prepared = $candidates
            ->map(function (Aeronave|array $candidate) use (
                $requestedStart,
                $requestedEnd,
                $originAirport,
                $allowRepositioning,
                $selectedRadiusNm,
            ): ?array {
                $aircraft = $candidate instanceof Aeronave ? $candidate : ($candidate['aircraft'] ?? null);
                if (! $aircraft instanceof Aeronave) {
                    return null;
                }

                $operationalContext = $allowRepositioning
                    ? (array) ($candidate['operational_context'] ?? [])
                    : $this->aircraftRepositioningService->exactMatchContext($aircraft, $originAirport);

                if ($selectedRadiusNm !== null) {
                    $operationalContext['selected_radius_nm'] = $selectedRadiusNm;
                }

                [$candidateStart, $candidateEnd] = $allowRepositioning
                    ? $this->aircraftRepositioningService->adjustedWindow($requestedStart, $requestedEnd, $operationalContext)
                    : [$requestedStart, $requestedEnd];

                return [
                    'aircraft' => $aircraft,
                    'operational_context' => $operationalContext,
                    'requested_start' => $candidateStart,
                    'requested_end' => $candidateEnd,
                    'repositioning_distance_nm' => (float) ($candidate['repositioning_distance_nm'] ?? data_get($operationalContext, 'repositioning_distance_nm', 0.0)),
                ];
            })
            ->filter()
            ->values();

        $availabilityStartedAt = microtime(true);
        $availabilityContext = $this->aircraftAvailabilityService->buildBatchConflictContext(
            $prepared->map(fn (array $candidate) => [
                'aircraft_id' => $candidate['aircraft']->id,
                'operational_window_start' => $candidate['requested_start'],
                'operational_window_end' => $candidate['requested_end'],
            ]),
            $requestData['reservation_id'] ?? null,
            $requestData['block_id'] ?? null,
            $requestData['quote_id'] ?? null,
            $requestData['hold_id'] ?? null,
        );
        $availabilityBatchMs = round((microtime(true) - $availabilityStartedAt) * 1000, 2);

        return $prepared
            ->map(function (array $candidate) use ($availabilityContext, $availabilityBatchMs): array {
                $candidate['precomputed_availability_conflict'] = $this->aircraftAvailabilityService->batchContextHasConflict(
                    $availabilityContext,
                    (int) $candidate['aircraft']->id,
                    $candidate['requested_start'],
                    $candidate['requested_end'],
                );
                $candidate['availability_query_count'] = (int) ($availabilityContext['query_count'] ?? 0);
                $candidate['availability_batch_ms'] = $availabilityBatchMs;

                return $candidate;
            })
            ->values();
    }

    private function buildPreviewQuotePayload(
        Aeronave $aircraft,
        array $canonicalRoute,
        array $requestData,
        float $distanceKm,
        float $distanceNm,
        array $legs,
        string $tripType,
        Aeropuerto $originAirport,
        array $operationalContext,
    ): array {
        $pricing = $this->previewPricingForAircraft(
            $aircraft,
            $tripType,
            $legs,
            [...$requestData, 'operational_context' => $operationalContext],
            $canonicalRoute,
        );
        $currency = $aircraft->currency ?: 'USD';
        $baseAirportCode = $aircraft->resolvedBaseAirportCode() ?? $aircraft->base_airport;
        $basedAtOrigin = (bool) ($operationalContext['based_at_origin'] ?? $this->aircraftIsBasedAtOrigin($aircraft, $originAirport));
        $requiresRepositioning = (bool) ($pricing['requires_repositioning'] ?? ! $basedAtOrigin);

        $clientDisplayHours = round($pricing['client_display_flight_hours'], 2);
        $clientOperationalHours = round($pricing['client_operational_flight_hours'], 2);
        $clientDirectHours = round($pricing['client_direct_flight_hours'], 2);
        $cardTime = $this->formatHours($pricing['client_display_flight_hours']);
        $billableTime = $this->formatHours($pricing['total_billed_hours']);
        $estimatedFlightMinutes = round(((float) $pricing['client_display_flight_hours']) * 60, 2);
        $billableFlightMinutes = round((float) $pricing['billable_minutes'], 2);

        return [
            'id' => 'preview-'.$aircraft->id,
            'trip_type' => $tripType,
            'aircraft_id' => $aircraft->id,
            'aircraft_name' => $aircraft->model,
            'model' => $aircraft->model,
            'cabin' => $this->normalizeAircraftCategory($aircraft->category) ?? 'Jet privado',
            'capacity' => $aircraft->capacity,
            'time' => $cardTime,
            'card_time' => $cardTime,
            'display_time' => $cardTime,
            'ui_time' => $cardTime,
            'trip_time' => $cardTime,
            'operative_time' => $this->formatHours($pricing['client_operational_flight_hours']),
            'billed_time' => $billableTime,
            'estimated_flight_time' => $cardTime,
            'billable_flight_time' => $billableTime,
            'repositioning_time' => $this->formatHours($pricing['repositioning_hours']),
            'return_to_base_time' => $this->formatHours($pricing['return_to_base_hours']),
            'flight_time' => $cardTime,
            'estimated_flight_minutes' => $estimatedFlightMinutes,
            'billable_flight_minutes' => $billableFlightMinutes,
            'display_flight_hours' => $clientDisplayHours,
            'client_display_flight_hours' => $clientDisplayHours,
            'card_flight_hours' => $clientDisplayHours,
            'ui_flight_hours' => $clientDisplayHours,
            'trip_flight_hours' => $clientDisplayHours,
            'operational_flight_hours' => $clientOperationalHours,
            'client_operational_flight_hours' => $clientOperationalHours,
            'distance_km' => round($distanceKm),
            'distance_nm' => round($distanceNm),
            'estimated_hours' => $clientDirectHours,
            'real_flight_hours' => $clientDisplayHours,
            'raw_leg_hours' => $pricing['raw_leg_hours'],
            'raw_route_hours' => $pricing['raw_route_hours'],
            'total_billed_hours' => $pricing['total_billed_hours'],
            'rounding_mode' => $pricing['rounding_mode'],
            'climb_descent_minutes' => (int) round($pricing['client_climb_descent_minutes']),
            'climb_descent_hours' => round($pricing['client_climb_descent_hours'], 2),
            'billable_hours' => round($pricing['total_billed_hours'], 2),
            'billable_minutes' => round($pricing['billable_minutes'], 2),
            'final_hours' => round($pricing['total_billed_hours'], 2),
            'hourly_rate' => round($pricing['hourly_rate'], 2),
            'price_per_minute' => round($pricing['price_per_minute'], 2),
            'minimum_hours' => round($pricing['minimum_hours'], 2),
            'minimum_route_price' => round($pricing['minimum_route_price'], 2),
            'minimum_adjustment' => round($pricing['minimum_adjustment'], 2),
            'base_cost' => round($pricing['base_price'], 2),
            'client_flight_cost' => round($pricing['client_flight_cost'], 2),
            'repositioning_hours' => round($pricing['repositioning_hours'], 2),
            'return_to_base_hours' => round($pricing['return_to_base_hours'], 2),
            'overnight_hours' => round($pricing['overnight_hours'], 2),
            'repositioning_cost' => round($pricing['initial_repositioning_cost'], 2),
            'return_to_base_cost' => round($pricing['return_to_base_cost'], 2),
            'overnight_cost' => round($pricing['overnight_cost'], 2),
            'airport_expenses' => round($pricing['airport_expenses'], 2),
            'base_price_formula' => $pricing['base_price_formula'],
            'flight_time_comparison' => $pricing['flight_time_comparison'] ?? null,
            'priority_factor' => 1,
            'subtotal_before_margin' => round($pricing['subtotal_before_margin'], 2),
            'margin_percentage' => round($pricing['margin_percentage'], 2),
            'margin_amount' => round($pricing['margin_amount'], 2),
            'flight_cost' => round($pricing['flight_cost'], 2),
            'stripe_fee' => round($pricing['stripe_fee'], 2),
            'administrative_fee' => round($pricing['administrative_fee'], 2),
            'total_amount' => round($pricing['total_amount'], 2),
            'quoted_total' => round($pricing['total_amount'], 2),
            'overnight_fee' => round($pricing['overnight_fee'], 2),
            'jet_a_price' => round($pricing['jet_a_price'], 2),
            'segment_count' => max(count($pricing['client_legs']), 1),
            'client_legs' => $pricing['client_legs'],
            'subtotal' => round($pricing['subtotal'], 2),
            'utility' => 0,
            'margin' => 0,
            'taxes' => round($pricing['tax'], 2),
            'total' => round($pricing['total_amount'], 2),
            'currency' => $currency,
            'final_price' => $this->formatMoney($pricing['total_amount'], $currency),
            'debug_pricing' => $pricing['debug_pricing'],
            'pricing_breakdown' => $pricing,
            'pricing' => [
                'estimated_flight_minutes' => $estimatedFlightMinutes,
                'billable_flight_minutes' => $billableFlightMinutes,
                'estimated_flight_time' => $cardTime,
                'billable_flight_time' => $billableTime,
                'customer_flight_cost' => round((float) ($pricing['customer_flight_cost'] ?? $pricing['client_flight_cost']), 2),
                'repositioning_cost' => round((float) ($pricing['initial_repositioning_cost'] ?? 0), 2),
                'return_to_base_cost' => round((float) ($pricing['return_to_base_cost'] ?? 0), 2),
                'airport_expenses' => round((float) ($pricing['airport_expenses'] ?? 0), 2),
                'overnight_cost' => round((float) ($pricing['overnight_cost'] ?? 0), 2),
                'margin_amount' => round((float) ($pricing['margin_amount'] ?? 0), 2),
                'payment_fees' => round((float) (($pricing['stripe_fee'] ?? 0) + ($pricing['administrative_fee'] ?? 0)), 2),
                'taxes' => round((float) ($pricing['taxes'] ?? $pricing['tax'] ?? 0), 2),
                'total_amount' => round((float) ($pricing['total_amount'] ?? 0), 2),
                'currency' => $currency,
            ],
            'source_origin' => $baseAirportCode,
            'base_airport_id' => $aircraft->base_airport_id,
            'base_airport_code' => $baseAirportCode,
            'base_airport_name' => $aircraft->baseAirport?->name,
            'base_airport_city' => $aircraft->baseAirport?->city,
            'aircraft_base_airport' => $pricing['aircraft_base_airport'] ?? ($operationalContext['aircraft_base_airport'] ?? null),
            'based_at_origin' => $basedAtOrigin,
            'base_airport_match' => $basedAtOrigin,
            'requires_repositioning' => $requiresRepositioning,
            'repositioning_distance_km' => round((float) ($pricing['repositioning_distance_km'] ?? 0), 2),
            'repositioning_distance_nm' => round((float) ($pricing['repositioning_distance_nm'] ?? 0), 2),
            'return_to_base_distance_km' => round((float) ($pricing['return_to_base_distance_km'] ?? 0), 2),
            'return_to_base_distance_nm' => round((float) ($pricing['return_to_base_distance_nm'] ?? 0), 2),
            'repositioning' => $pricing['repositioning'] ?? null,
            'return_to_base' => $pricing['return_to_base'] ?? null,
            'selected_radius_nm' => $operationalContext['selected_radius_nm'] ?? null,
            'match_reason' => $this->matchReason($basedAtOrigin, $baseAirportCode),
            'response_time' => $this->responseTime($basedAtOrigin),
            'provider' => [
                'id' => $aircraft->provider?->id,
                'company_name' => $aircraft->provider?->company_name,
                'commercial_name' => $aircraft->provider?->commercial_name,
                'jet_a_price' => round($pricing['jet_a_price'], 2),
            ],
            'ui_hints' => [
                'time_field' => 'card_time',
                'time_hours_field' => 'card_flight_hours',
                'time_source' => 'client_display_flight_hours',
                'billable_time_field' => 'billable_flight_time',
                'billable_minutes_field' => 'billable_flight_minutes',
                'time_display_mode' => $pricing['time_display_mode'],
                'billing_hours_mode' => $pricing['billing_hours_mode'],
                'time_excludes_repositioning' => true,
                'billed_time_includes_repositioning' => (bool) ($pricing['include_repositioning_in_billed_hours'] ?? false),
            ],
            'aircraft' => $this->aircraftPreviewPayload($aircraft),
        ];
    }

    /**
     * @deprecated Compatibilidad interna. Toda entrada debe pasar primero por FlightPricingService.
     */
    private function calculateLegacyPricing(Aeronave $aircraft, string $tripType, array $legs, array $requestData = []): array
    {
        $hourlyRate = $this->resolveCommercialHourlyRate($aircraft->hourly_rate);
        $pricePerMinute = round($hourlyRate / 60, 2);
        $overnightFee = round($hourlyRate / 2, 2);
        $categoryPricingRule = $this->resolveCategoryPricingRule($aircraft);
        $timeDisplayMode = $this->resolveTimeMode($requestData, 'time_display_mode', self::TIME_MODE_DIRECT);
        $billingHoursMode = $this->resolveTimeMode($requestData, 'billing_hours_mode', self::TIME_MODE_DIRECT);
        $marginRate = $this->shouldApplyCommercialMargin($requestData)
            ? $this->resolveCommercialMarginRate($aircraft, $categoryPricingRule)
            : 0.0;
        $includeRepositioningInBilledHours = $this->shouldIncludeBilledComponent(
            $requestData,
            'include_repositioning_in_billed_hours',
            true
        );
        $includeReturnToBaseInBilledHours = $this->shouldIncludeBilledComponent(
            $requestData,
            'include_return_to_base_in_billed_hours',
            true
        );
        $includeOvernightInBilledHours = $this->shouldIncludeBilledComponent(
            $requestData,
            'include_overnight_in_billed_hours',
            false
        );
        $flightBaseSource = $this->resolveFlightBaseSource($requestData);
        $clientLegs = $legs;
        $clientLegPricings = collect($clientLegs)
            ->map(function (array $leg) use ($aircraft) {
                return $this->calculateLegPricing(
                    $aircraft,
                    $this->airportFromPayload($leg['origin_airport']),
                    $this->airportFromPayload($leg['destination_airport']),
                    false,
                    $leg
                );
            })
            ->values()
            ->all();

        $distanceTotal = (float) collect($clientLegPricings)->sum('distance_km');
        $manualLegMinutesTotal = (float) collect($clientLegPricings)->sum('manual_duration_minutes');
        $distanceSpeedHoursTotal = (float) collect($clientLegPricings)->sum('distance_speed_hours');
        $clientDirectFlightHours = (float) collect($clientLegPricings)->sum('direct_air_time_hours');
        $clientClimbDescentMinutes = (int) round((float) collect($clientLegPricings)->sum('climb_descent_minutes'));
        $clientClimbDescentHours = (float) collect($clientLegPricings)->sum('climb_descent_hours');
        $clientReserveHours = (float) collect($clientLegPricings)->sum('reserve_hours');
        $clientAirTimeHours = (float) collect($clientLegPricings)->sum('air_time_hours');
        $clientOperationalFlightHours = (float) collect($clientLegPricings)->sum('real_flight_hours');
        $clientDisplayFlightHours = (float) collect($clientLegPricings)->sum(
            fn (array $legPricing) => $this->resolveLegHoursByMode($legPricing, $timeDisplayMode)
        );
        $clientPricingFlightHours = (float) collect($clientLegPricings)->sum(
            fn (array $legPricing) => $this->resolveLegHoursByMode($legPricing, $billingHoursMode)
        );
        $hoursSource = $this->resolveHoursSource($clientLegPricings);
        $minimumHours = $this->resolveMinimumHours($aircraft->category, $distanceTotal);
        $billableHoursBeforeRounding = $clientPricingFlightHours;
        $roundingRuleApplied = $this->resolveRoundingMode($requestData, $hoursSource);
        $roundedHoursBeforeMinimum = $this->applyRoundingMode($billableHoursBeforeRounding, $roundingRuleApplied);
        $clientBillableHours = max($roundedHoursBeforeMinimum, $minimumHours);
        $minimumHoursApplied = max($clientBillableHours - $roundedHoursBeforeMinimum, 0.0);
        $outboundHours = $this->resolveLegHoursByMode($clientLegPricings[0] ?? [], $timeDisplayMode);
        $returnHours = (float) collect(array_slice($clientLegPricings, 1))->sum(
            fn (array $legPricing) => $this->resolveLegHoursByMode($legPricing, $timeDisplayMode)
        );
        $initialRepositioningPricing = $this->emptyLegPricing();
        $returnToBasePricing = $this->emptyLegPricing();

        $baseAirport = $this->resolveAircraftBaseAirport($aircraft);
        $firstClientLeg = $clientLegs[0] ?? null;
        $lastClientLeg = $clientLegs[count($clientLegs) - 1] ?? null;
        $firstOriginAirport = $firstClientLeg ? $this->airportFromPayload($firstClientLeg['origin_airport']) : null;
        $lastDestinationAirport = $lastClientLeg ? $this->airportFromPayload($lastClientLeg['destination_airport']) : null;
        $firstOriginCode = (string) ($firstClientLeg['origin'] ?? '');
        $lastDestinationCode = (string) ($lastClientLeg['destination'] ?? '');

        if ($tripType === 'one_way' && $baseAirport && $firstOriginAirport && ! $this->airportsMatch($baseAirport, $firstOriginAirport)) {
            $initialRepositioningPricing = $this->calculateLegPricing($aircraft, $baseAirport, $firstOriginAirport, false);
        }

        if ($tripType === 'one_way' && $baseAirport && $lastDestinationAirport && ! $this->airportsMatch($baseAirport, $lastDestinationAirport)) {
            $returnToBasePricing = $this->calculateLegPricing($aircraft, $lastDestinationAirport, $baseAirport, false);
        }

        if ($tripType === 'multi_leg' && $baseAirport && $firstOriginAirport && ! $this->airportsMatch($baseAirport, $firstOriginAirport)) {
            $initialRepositioningPricing = $this->calculateLegPricing($aircraft, $baseAirport, $firstOriginAirport, false);
        }

        if (
            $tripType === 'multi_leg' &&
            $baseAirport &&
            $lastDestinationAirport &&
            ! $this->airportsMatch($baseAirport, $lastDestinationAirport) &&
            ! $this->airportMatchesCode($lastDestinationAirport, $firstOriginCode)
        ) {
            $returnToBasePricing = $this->calculateLegPricing($aircraft, $lastDestinationAirport, $baseAirport, false);
        }

        $repositioningHours = (float) ($initialRepositioningPricing['billable_hours'] ?? 0);
        $returnToBaseHours = (float) ($returnToBasePricing['billable_hours'] ?? 0);
        $initialRepositioningCost = (float) ($initialRepositioningPricing['leg_cost'] ?? 0);
        $returnToBaseCost = (float) ($returnToBasePricing['leg_cost'] ?? 0);
        $overnightNights = $this->resolveOvernightNights($clientLegs, $requestData);
        $overnightHours = $overnightNights * self::COMMERCIAL_OVERNIGHT_HOURS_PER_NIGHT;
        $flightHours = $clientBillableHours;
        $billableHours = $clientBillableHours
            + ($includeRepositioningInBilledHours ? $repositioningHours : 0.0)
            + ($includeReturnToBaseInBilledHours ? $returnToBaseHours : 0.0)
            + ($includeOvernightInBilledHours ? $overnightHours : 0.0);
        $billableMinutes = round($billableHours * 60, 2);
        $overnightCost = $overnightNights > 0 ? $overnightFee * $overnightNights : 0.0;
        $airportExpenseContext = $this->shouldApplyAirportExpenses($requestData)
            ? $this->resolveAirportExpenseContext($aircraft, $clientLegs)
            : ['amount' => 0.0, 'source' => 'disabled_by_request'];
        $expenseFee = (float) ($airportExpenseContext['amount'] ?? 0.0);
        $flightBaseHours = $flightBaseSource === 'billable_hours'
            ? $clientBillableHours
            : $clientPricingFlightHours;
        $flightBase = $flightBaseHours * $hourlyRate;
        $billableFlightCost = $flightBase
            + ($includeRepositioningInBilledHours ? $initialRepositioningCost : 0.0)
            + ($includeReturnToBaseInBilledHours ? $returnToBaseCost : 0.0);
        $subtotalOperative = $billableFlightCost + $overnightCost + $expenseFee;

        if ($tripType === 'multi_leg') {
            logger()->info('DEBUG CLIENT LEG PRICINGS', [
                'aircraft' => $aircraft->model ?? $aircraft->registration ?? $aircraft->id,
                'legs_count' => count($clientLegPricings),
                'legs' => collect($clientLegPricings)->map(fn (array $leg) => [
                    'origin' => $leg['origin'] ?? null,
                    'destination' => $leg['destination'] ?? null,
                    'display_hours' => $leg['display_flight_hours'] ?? null,
                    'billable_hours' => $leg['billable_hours'] ?? null,
                    'base_price' => $leg['base_price'] ?? $leg['leg_cost'] ?? null,
                ])->values()->all(),
                'client_display_flight_hours' => $clientDisplayFlightHours,
                'client_pricing_flight_hours' => $clientPricingFlightHours,
                'client_operational_flight_hours' => $clientOperationalFlightHours,
                'client_billable_hours' => $clientBillableHours,
                'flight_base_hours' => $flightBaseHours,
                'flight_base_source' => $flightBaseSource,
                'client_flight_cost' => $flightBase,
                'billable_flight_cost' => $billableFlightCost,
                'total_billed_hours' => $billableHours,
                'overnight_hours' => $overnightHours,
                'subtotal_operative' => $subtotalOperative,
                'include_repositioning_in_billed_hours' => $includeRepositioningInBilledHours,
                'include_return_to_base_in_billed_hours' => $includeReturnToBaseInBilledHours,
                'include_overnight_in_billed_hours' => $includeOvernightInBilledHours,
            ]);
        }

        logger()->info('Horas cotizador debug', [
            'aircraft' => $aircraft->model ?? $aircraft->registration ?? $aircraft->id,
            'trip_type' => $tripType,
            'clientBillableHours' => $clientBillableHours,
            'repositioningHours' => $repositioningHours,
            'returnToBaseHours' => $returnToBaseHours,
            'overnightHours' => $overnightHours,
            'totalBilledHours' => $billableHours,
            'include_repositioning_in_billed_hours' => $includeRepositioningInBilledHours,
            'include_return_to_base_in_billed_hours' => $includeReturnToBaseInBilledHours,
            'include_overnight_in_billed_hours' => $includeOvernightInBilledHours,
            'baseAirport' => $aircraft->base_airport,
            'firstOrigin' => $firstOriginCode,
            'lastDestination' => $lastDestinationCode,
        ]);

        $minimumRoutePrice = $this->resolveMinimumRoutePrice($aircraft, $distanceTotal, $categoryPricingRule);
        $subtotalBeforeMargin = max($subtotalOperative, $minimumRoutePrice);
        $minimumAdjustment = max($subtotalBeforeMargin - $subtotalOperative, 0.0);
        $marginAmount = $subtotalBeforeMargin * $marginRate;
        $subtotal = $subtotalBeforeMargin + $marginAmount;
        $nonTaxableExpenses = $expenseFee;
        $taxableSubtotal = max($subtotal - $nonTaxableExpenses, 0.0);
        $taxRate = $this->shouldIncludeIva($requestData) ? self::DEFAULT_IVA_RATE : 0.0;
        $iva = $taxableSubtotal * $taxRate;
        $total = $subtotal + $iva;
        $debugPricing = [
            'aircraft_name' => $aircraft->name ?? $aircraft->model ?? $aircraft->registration ?? null,
            'hours_source' => $hoursSource,
            'manual_leg_minutes_total' => $manualLegMinutesTotal,
            'distance_speed_hours_total' => $distanceSpeedHoursTotal,
            'client_pricing_flight_hours' => $clientPricingFlightHours,
            'direct_leg_hours_total' => $clientDirectFlightHours,
            'billable_hours_before_rounding' => $billableHoursBeforeRounding,
            'rounded_hours_before_minimum' => $roundedHoursBeforeMinimum,
            'rounding_rule_applied' => $roundingRuleApplied,
            'minimum_hours_applied' => $minimumHoursApplied,
            'minimum_hours_source' => $this->resolveMinimumHoursSource($aircraft, $distanceTotal, $minimumHours),
            'final_billable_hours' => $clientBillableHours,
            'hourly_rate_source' => $this->resolveHourlyRateSource($aircraft),
            'expense_fee_source' => (string) ($airportExpenseContext['source'] ?? 'unknown'),
            'flight_base_hours' => $flightBaseHours,
            'hourly_rate' => $hourlyRate,
            'flight_base' => $flightBase,
            'initial_repositioning_cost' => $initialRepositioningCost,
            'return_to_base_cost' => $returnToBaseCost,
            'overnight_fee' => $overnightFee,
            'overnight_nights' => $overnightNights,
            'overnight_cost' => $overnightCost,
            'expense_fee' => $expenseFee,
            'subtotal_operativo' => $subtotalOperative,
            'minimum_route_price' => $minimumRoutePrice,
            'subtotal_before_margin' => $subtotalBeforeMargin,
            'margin_amount' => $marginAmount,
            'subtotal' => $subtotal,
            'iva' => $iva,
            'total' => $total,
        ];
        $ivaAmount = $iva;
        $paymentBreakdown = $this->paymentFeeCalculationServicio->flightBreakdown($billableFlightCost);
        $chargedTotal = (float) $paymentBreakdown['total_amount'];
        $debugPricing['flight_cost'] = $paymentBreakdown['flight_cost'];
        $debugPricing['stripe_fee'] = $paymentBreakdown['stripe_fee'];
        $debugPricing['administrative_fee'] = $paymentBreakdown['administrative_fee'];
        $debugPricing['charged_total_amount'] = $chargedTotal;

        Log::info('Flight pricing calculated', [
            'aircraft_id' => $aircraft->id,
            'aircraft_model' => $aircraft->model,
            'trip_type' => $tripType,
            'legs_count' => count($clientLegs),
            'route_signature' => $this->buildRouteSignature($clientLegs),
            'distance_total_km' => round($distanceTotal, 2),
            'hourly_rate' => $hourlyRate,
            'minimum_hours' => $minimumHours,
            'minimum_route_price' => $minimumRoutePrice,
            'airport_expense_source' => $airportExpenseContext['source'] ?? null,
            'airport_expenses' => $expenseFee,
            'margin_rate' => $marginRate,
            'margin_amount' => $marginAmount,
            'tax_rate' => $taxRate,
            'tax' => $ivaAmount,
            'flight_base_hours' => round($flightBaseHours, 4),
            'client_display_flight_hours' => round($clientDisplayFlightHours, 4),
            'client_operational_flight_hours' => round($clientOperationalFlightHours, 4),
            'client_billable_hours' => round($clientBillableHours, 4),
            'repositioning_hours' => round($repositioningHours, 4),
            'return_to_base_hours' => round($returnToBaseHours, 4),
            'overnight_hours' => round($overnightHours, 4),
            'billable_flight_cost' => round($billableFlightCost, 2),
            'subtotal_before_margin' => round($subtotalBeforeMargin, 2),
            'subtotal' => round($subtotal, 2),
            'flight_cost' => $paymentBreakdown['flight_cost'],
            'stripe_fee' => $paymentBreakdown['stripe_fee'],
            'administrative_fee' => $paymentBreakdown['administrative_fee'],
            'total_amount' => $chargedTotal,
            'time_display_mode' => $timeDisplayMode,
            'billing_hours_mode' => $billingHoursMode,
            'flight_base_source' => $flightBaseSource,
        ]);

        return [
            'trip_type' => $tripType,
            'quote_strategy' => 'official_backend_pricing_v2',
            'hourly_rate' => $hourlyRate,
            'price_per_minute' => $pricePerMinute,
            'minimum_hours' => $minimumHours,
            'minimum_route_price' => $minimumRoutePrice,
            'minimum_adjustment' => $minimumAdjustment,
            'margin_percentage' => round($marginRate * 100, 2),
            'margin_rate' => $marginRate,
            'margin_amount' => $marginAmount,
            'redsky_markup_percent' => round($marginRate * 100, 2),
            'redsky_markup_factor' => 1 + $marginRate,
            'fuel_burn_gph' => 0,
            'engine_reserve_rate' => 0,
            'insurance_rate' => 0,
            'maintenance_rate' => 0,
            'crew_rate' => 0,
            'overnight_fee' => $overnightFee,
            'parking_fee' => 0,
            'operational_expenses' => 0,
            'jet_a_price' => 0,
            'fixed_fee' => 0,
            'fixed_fee_total' => 0,
            'margin_percent' => round($marginRate * 100, 4),
            'client_legs' => $clientLegs,
            'client_leg_pricing' => $clientLegPricings,
            'initial_repositioning_leg' => $initialRepositioningCost > 0 ? $initialRepositioningPricing : null,
            'return_to_base_leg' => $returnToBaseCost > 0 ? $returnToBasePricing : null,
            'distance_total' => $distanceTotal,
            'client_flight_hours' => $clientDisplayFlightHours,
            'client_display_flight_hours' => $clientDisplayFlightHours,
            'client_pricing_flight_hours' => $clientPricingFlightHours,
            'client_direct_flight_hours' => $clientDirectFlightHours,
            'client_operational_flight_hours' => $clientOperationalFlightHours,
            'client_climb_descent_minutes' => $clientClimbDescentMinutes,
            'client_climb_descent_hours' => $clientClimbDescentHours,
            'client_reserve_hours' => $clientReserveHours,
            'client_air_time_hours' => $clientAirTimeHours,
            'outbound_hours' => $outboundHours,
            'return_hours' => $returnHours,
            'repositioning_hours' => $repositioningHours,
            'return_to_base_hours' => $returnToBaseHours,
            'flight_hours' => $flightHours,
            'overnight_hours' => $overnightHours,
            'total_billed_hours' => $billableHours,
            'billable_hours' => $billableHours,
            'billable_minutes' => $billableMinutes,
            'flight_base_hours' => $flightBaseHours,
            'time_breakdown' => [
                'trip_hours' => $clientDisplayFlightHours,
                'pricing_trip_hours' => $clientPricingFlightHours,
                'operational_trip_hours' => $clientOperationalFlightHours,
                'repositioning_hours' => $repositioningHours,
                'return_to_base_hours' => $returnToBaseHours,
                'overnight_hours' => $overnightHours,
                'total_billed_hours' => $billableHours,
                'flight_base_hours' => $flightBaseHours,
                'flight_base_source' => $flightBaseSource,
                'display_excludes_repositioning' => true,
                'billed_includes_repositioning' => $includeRepositioningInBilledHours,
                'billed_includes_return_to_base' => $includeReturnToBaseInBilledHours,
                'include_repositioning_in_billed_hours' => $includeRepositioningInBilledHours,
                'include_return_to_base_in_billed_hours' => $includeReturnToBaseInBilledHours,
                'include_overnight_in_billed_hours' => $includeOvernightInBilledHours,
            ],
            'time_display_mode' => $timeDisplayMode,
            'billing_hours_mode' => $billingHoursMode,
            'flight_base_source' => $flightBaseSource,
            'flight_base' => $flightBase,
            'client_flight_base_cost' => $flightBase,
            'client_flight_cost' => $flightBase,
            'billable_flight_cost' => $billableFlightCost,
            'flight_cost' => $paymentBreakdown['flight_cost'],
            'stripe_fee' => $paymentBreakdown['stripe_fee'],
            'administrative_fee' => $paymentBreakdown['administrative_fee'],
            'operator_subtotal' => $subtotalBeforeMargin,
            'subtotal_operativo' => $subtotalOperative,
            'taxable_subtotal' => $taxableSubtotal,
            'non_taxable_expenses' => $nonTaxableExpenses,
            'subtotal_before_margin' => $subtotalBeforeMargin,
            'base_price' => $flightBase,
            'base_price_formula' => [
                'hourly_rate' => round($hourlyRate, 2),
                'price_per_minute' => $pricePerMinute,
                'billable_minutes' => $billableMinutes,
                'flight_base_hours' => round($flightBaseHours, 4),
                'flight_base_source' => $flightBaseSource,
                'client_flight_cost' => round($flightBase, 2),
                'billable_flight_cost' => round($billableFlightCost, 2),
                'repositioning_cost' => round($initialRepositioningCost, 2),
                'return_to_base_cost' => round($returnToBaseCost, 2),
                'overnight_cost' => round($overnightCost, 2),
                'airport_expenses' => round($expenseFee, 2),
                'minimum_adjustment' => round($minimumAdjustment, 2),
                'margin_amount' => round($marginAmount, 2),
                'subtotal_before_margin' => round($subtotalBeforeMargin, 2),
                'taxable_subtotal' => round($taxableSubtotal, 2),
                'non_taxable_expenses' => round($nonTaxableExpenses, 2),
                'expression' => sprintf(
                    'base %.2f + repo %.2f + return %.2f + overnight %.2f + airport %.2f => %.2f; taxable %.2f; margen %.2f%% => %.2f',
                    round($flightBase, 2),
                    round($includeRepositioningInBilledHours ? $initialRepositioningCost : 0.0, 2),
                    round($includeReturnToBaseInBilledHours ? $returnToBaseCost : 0.0, 2),
                    round($overnightCost, 2),
                    round($expenseFee, 2),
                    round($subtotalBeforeMargin, 2),
                    round($taxableSubtotal, 2),
                    round($marginRate * 100, 2),
                    round($subtotal, 2)
                ),
            ],
            'markup_amount' => $marginAmount,
            'initial_repositioning_cost' => $initialRepositioningCost,
            'return_to_base_cost' => $returnToBaseCost,
            'overnight_nights' => $overnightNights,
            'overnight_cost' => $overnightCost,
            'airport_fees' => $expenseFee,
            'airport_expenses' => $expenseFee,
            'expense_fee' => $expenseFee,
            'include_repositioning_in_billed_hours' => $includeRepositioningInBilledHours,
            'include_return_to_base_in_billed_hours' => $includeReturnToBaseInBilledHours,
            'include_overnight_in_billed_hours' => $includeOvernightInBilledHours,
            'flight_base_source' => $flightBaseSource,
            'tax_rate' => $taxRate,
            'tax' => $ivaAmount,
            'iva' => $ivaAmount,
            'iva_amount' => $ivaAmount,
            'subtotal' => $subtotal,
            'utility' => $marginAmount,
            'selected_card_price' => $chargedTotal,
            'final_price' => $chargedTotal,
            'total' => $chargedTotal,
            'total_amount' => $chargedTotal,
            'debug_pricing' => $debugPricing,
        ];
    }

    private function calculateLegPricing(
        Aeronave $aircraft,
        Aeropuerto $originAirport,
        Aeropuerto $destinationAirport,
        bool $applyMinimumHours = true,
        array $legContext = []
    ): array {
        $distanceNm = $this->distanceNm(
            (float) $originAirport->latitude,
            (float) $originAirport->longitude,
            (float) $destinationAirport->latitude,
            (float) $destinationAirport->longitude
        );
        $distanceKm = $distanceNm * 1.852;

        return $this->flightDurationService->calculateLeg(
            $aircraft,
            $originAirport,
            $destinationAirport,
            $distanceKm,
            $distanceNm,
            $applyMinimumHours,
        );
    }

    private function resolveCruiseSpeedKmh(Aeronave $aircraft): float
    {
        $explicitSpeedKmh = (float) $aircraft->speed_kmh;
        if ($explicitSpeedKmh > 0) {
            return $explicitSpeedKmh;
        }

        $cruiseCategory = $this->normalizeCruiseCategory($aircraft->category);
        if ($cruiseCategory !== null) {
            $mach = self::CATEGORY_MACH_BANDS[$cruiseCategory] ?? self::CATEGORY_MACH_BANDS['Mid Jet'];

            return $mach * self::MACH_1_KMH;
        }

        return 740.0;
    }

    private function resolveManualLegDuration(array $legContext): ?array
    {
        foreach ([
            'duration_minutes',
            'estimated_minutes',
            'quoted_minutes',
            'flight_minutes',
            'leg_minutes',
        ] as $field) {
            $value = $legContext[$field] ?? null;
            if (! is_numeric($value)) {
                continue;
            }

            $minutes = (float) $value;
            if ($minutes <= 0) {
                continue;
            }

            return [
                'field' => $field,
                'minutes' => $minutes,
                'hours' => round($minutes / 60, 4),
            ];
        }

        $durationHours = $legContext['duration_hours'] ?? null;
        if (is_numeric($durationHours) && (float) $durationHours > 0) {
            $hours = (float) $durationHours;

            return [
                'field' => 'duration_hours',
                'minutes' => round($hours * 60, 2),
                'hours' => round($hours, 4),
            ];
        }

        return null;
    }

    private function normalizeCruiseCategory(mixed $value): ?string
    {
        $normalized = mb_strtolower(trim((string) ($value ?? '')));

        return match ($normalized) {
            'helicoptero', 'helicÃ³ptero', 'helicopter' => 'Helicoptero',
            'light jet', 'light_jet', 'lightjet' => 'Light Jet',
            'mid jet', 'mid_jet', 'midjet', 'midsize jet', 'midsize_jet', 'super mid', 'super_mid' => 'Mid Jet',
            'heavy jet', 'heavy_jet', 'heavyjet', 'long range', 'long_range' => 'Heavy Jet',
            'ultra long', 'ultra_long', 'ultra long range', 'ultra_long_range' => 'Ultra Long Range',
            default => null,
        };
    }

    private function calculateClimbDescentMinutes(
        Aeronave $aircraft,
        Aeropuerto $originAirport,
        Aeropuerto $destinationAirport
    ): int {
        $baseMinutes = $this->resolveAircraftClimbDescentBaseMinutes($aircraft);

        $originAdjustment = (int) ($originAirport->climb_descent_adjustment_minutes ?? 0);
        $destinationAdjustment = (int) ($destinationAirport->climb_descent_adjustment_minutes ?? 0);

        return max(15, $baseMinutes + $originAdjustment + $destinationAdjustment);
    }

    private function normalizePricingCategory(mixed $value): string
    {
        $normalized = mb_strtolower(trim((string) ($value ?? '')));

        return match ($normalized) {
            'helicoptero', 'helicÃ³ptero', 'helicopter' => 'helicopter',
            'turboprop', 'turbo prop', 'turbo_prop' => 'turboprop',
            'light jet', 'light_jet', 'lightjet' => 'light_jet',
            'mid jet', 'mid_jet', 'midjet', 'midsize jet', 'midsize_jet', 'super mid', 'super_mid' => 'mid_jet',
            'heavy jet', 'heavy_jet', 'heavyjet', 'long range', 'long_range' => 'heavy_jet',
            'ultra long', 'ultra_long', 'ultra long range', 'ultra_long_range' => 'ultra_long',
            default => 'default',
        };
    }

    private function resolveAircraftClimbDescentBaseMinutes(Aeronave $aircraft): int
    {
        $explicitMinutes = (int) ($aircraft->climb_descent_minutes ?? 0);
        if ($explicitMinutes > 0) {
            return $explicitMinutes;
        }

        $normalizedCategory = $this->normalizeAircraftCategory($aircraft->category);

        return self::CATEGORY_CLIMB_DESCENT_MINUTES[$normalizedCategory ?? ''] ?? 30;
    }

    private function resolveMinimumHours(mixed $aircraftCategory, ?float $distanceKm = null): float
    {
        if ($distanceKm !== null && $distanceKm >= self::SHORT_ROUTE_DISTANCE_KM) {
            return 0.0;
        }

        return match ($this->normalizePricingCategory($aircraftCategory)) {
            'helicopter' => 1.0,
            'turboprop' => 1.5,
            'light_jet' => 2.0,
            'mid_jet' => 2.5,
            'heavy_jet' => 3.0,
            'ultra_long' => 4.0,
            default => 2.0,
        };
    }

    private function resolveCommercialMarginRate(Aeronave $aircraft, array $categoryPricingRule): float
    {
        $providerMargin = (float) ($aircraft->provider?->margin_percent ?? 0);
        if ($providerMargin > 0) {
            return $providerMargin > 1 ? $providerMargin / 100 : $providerMargin;
        }

        $categoryMarkup = (float) ($categoryPricingRule['redsky_markup'] ?? 0);

        return $categoryMarkup > 0 ? $categoryMarkup / 100 : 0.20;
    }

    private function resolveAircraftBaseAirport(Aeronave $aircraft): ?Aeropuerto
    {
        $baseAirportCode = strtoupper(trim((string) $aircraft->base_airport));
        if ($baseAirportCode === '') {
            return null;
        }

        return $this->findActiveAirport($baseAirportCode);
    }

    private function airportsMatch(?Aeropuerto $left, ?Aeropuerto $right): bool
    {
        if (! $left || ! $right) {
            return false;
        }

        return $this->airportMatchesCode($left, (string) ($right->icao ?: $right->iata))
            || $this->airportMatchesCode($right, (string) ($left->icao ?: $left->iata));
    }

    private function airportMatchesCode(?Aeropuerto $airport, string $code): bool
    {
        if (! $airport) {
            return false;
        }

        $normalizedCode = strtoupper(trim($code));
        if ($normalizedCode === '') {
            return false;
        }

        return in_array($normalizedCode, array_filter([
            strtoupper((string) $airport->icao),
            strtoupper((string) $airport->iata),
        ]), true);
    }

    private function emptyLegPricing(): array
    {
        return [
            'distance_nm' => 0,
            'adjusted_distance_nm' => 0,
            'direct_air_time_hours' => 0,
            'air_time_hours' => 0,
            'climb_descent_minutes' => 0,
            'climb_descent_hours' => 0,
            'reserve_hours' => 0,
            'display_flight_hours' => 0,
            'commercial_flight_hours' => 0,
            'real_flight_hours' => 0,
            'minimum_hours' => 0,
            'buffer_hours' => 0,
            'billable_hours' => 0,
            'final_hours' => 0,
            'leg_cost' => 0,
            'international' => false,
        ];
    }

    private function airportFromPayload(array $payload): Aeropuerto
    {
        return new Aeropuerto($payload);
    }

    private function countAirportsForFees(array $legs): int
    {
        if ($legs === []) {
            return 0;
        }

        $airports = [data_get($legs, '0.origin')];
        foreach ($legs as $leg) {
            $airports[] = $leg['destination'] ?? null;
        }

        return count(array_filter($airports));
    }

    private function calculateOvernightNights(array $legs): int
    {
        $nights = 0;

        for ($index = 0; $index < count($legs) - 1; $index++) {
            $current = $legs[$index]['departure_datetime'] ?? null;
            $next = $legs[$index + 1]['departure_datetime'] ?? null;

            if (! $current || ! $next) {
                continue;
            }

            $currentDate = Carbon::parse($current)->startOfDay();
            $nextDate = Carbon::parse($next)->startOfDay();

            if ($nextDate->greaterThan($currentDate)) {
                $nights += $currentDate->diffInDays($nextDate);
            }
        }

        return $nights;
    }

    private function resolveOvernightNights(array $legs, array $requestData = []): int
    {
        $explicitNights = $requestData['overnights'] ?? $requestData['overnight_nights'] ?? null;
        if ($explicitNights !== null && $explicitNights !== '') {
            return max((int) $explicitNights, 0);
        }

        return $this->calculateOvernightNights($legs);
    }

    private function operationalBufferHours(float $distanceNm): float
    {
        if ($distanceNm < 300) {
            return 0.25;
        }

        if ($distanceNm < 600) {
            return 0.35;
        }

        if ($distanceNm < 1000) {
            return 0.45;
        }

        return 0.50;
    }

    private function roundUpQuarterHours(float $hours): float
    {
        return round($hours * 4) / 4;
    }

    private function isInternationalLeg(Aeropuerto $originAirport, Aeropuerto $destinationAirport): bool
    {
        return strtoupper((string) $originAirport->country) !== strtoupper((string) $destinationAirport->country);
    }

    private function normalizeTripType(?string $tripType): string
    {
        return match (strtolower((string) $tripType)) {
            'redondo', 'round_trip', 'roundtrip' => 'round_trip',
            'multi-destino', 'multidestino', 'multi_city', 'multi_leg' => 'multi_leg',
            default => 'one_way',
        };
    }

    private function resolveQuoteTripType(array $data): string
    {
        $normalizedTripType = $this->normalizeTripType($data['trip_type'] ?? $data['trip_label'] ?? null);

        if ($normalizedTripType !== 'one_way') {
            return $normalizedTripType;
        }

        $roundTrip = filter_var($data['round_trip'] ?? false, FILTER_VALIDATE_BOOL);
        $returnTrip = filter_var($data['return'] ?? false, FILTER_VALIDATE_BOOL);

        return ($roundTrip || $returnTrip) ? 'round_trip' : 'one_way';
    }

    private function shouldReturnToOrigin(array $data): bool
    {
        foreach (['return_to_origin', 'return_to_start', 'close_route'] as $key) {
            if (array_key_exists($key, $data)) {
                return filter_var($data[$key], FILTER_VALIDATE_BOOL);
            }
        }

        if ($this->resolveQuoteTripType($data) === 'multi_leg') {
            if (! empty($data['return_datetime'])) {
                return true;
            }

            return ! filter_var($data['open_route'] ?? false, FILTER_VALIDATE_BOOL);
        }

        return filter_var($data['round_trip'] ?? false, FILTER_VALIDATE_BOOL)
            || filter_var($data['return'] ?? false, FILTER_VALIDATE_BOOL);
    }

    private function shouldTreatAsOpenRoute(array $data): bool
    {
        if ($this->resolveQuoteTripType($data) !== 'multi_leg') {
            return false;
        }

        if (array_key_exists('open_route', $data)) {
            return filter_var($data['open_route'], FILTER_VALIDATE_BOOL);
        }

        foreach (['return_to_origin', 'return_to_start', 'close_route'] as $key) {
            if (array_key_exists($key, $data)) {
                return ! filter_var($data[$key], FILTER_VALIDATE_BOOL);
            }
        }

        return false;
    }

    private function shouldIncludeIva(array $data): bool
    {
        if (! array_key_exists('include_iva', $data)) {
            return true;
        }

        return filter_var($data['include_iva'], FILTER_VALIDATE_BOOL);
    }

    private function shouldApplyAirportExpenses(array $data): bool
    {
        if (! array_key_exists('airport_expenses', $data)) {
            return true;
        }

        return filter_var($data['airport_expenses'], FILTER_VALIDATE_BOOL);
    }

    private function shouldApplyCommercialMargin(array $data): bool
    {
        if (! array_key_exists('apply_margin', $data)) {
            return false;
        }

        return filter_var($data['apply_margin'], FILTER_VALIDATE_BOOL);
    }

    private function resolveCommercialHourlyRate(mixed $value): float
    {
        $hourlyRate = (float) $value;
        if ($hourlyRate > 0 && $hourlyRate < 100) {
            return $hourlyRate * 1000;
        }

        return $hourlyRate;
    }

    private function resolveHourlyRateSource(Aeronave $aircraft): string
    {
        $rawHourlyRate = (float) ($aircraft->hourly_rate ?? 0);

        if ($rawHourlyRate > 0 && $rawHourlyRate < 100) {
            return sprintf('aircraft.hourly_rate (%s) x 1000', $rawHourlyRate);
        }

        return sprintf('aircraft.hourly_rate (%s)', $rawHourlyRate);
    }

    private function resolveAirportExpenseForAircraft(Aeronave $aircraft): float
    {
        $airportExpense = (float) ($aircraft->airport_expenses_usd ?? 0);
        if ($airportExpense > 0) {
            return $airportExpense > 0 && $airportExpense < 100 ? $airportExpense * 1000 : $airportExpense;
        }

        return self::DEFAULT_AIRPORT_EXPENSE_USD;
    }

    private function resolveAirportExpenseContext(Aeronave $aircraft, array $legs): array
    {
        $ruleContext = $this->resolveAirportExpenseRule($aircraft, $legs);
        if ($ruleContext !== null) {
            return $ruleContext;
        }

        $airportExpense = (float) ($aircraft->airport_expenses_usd ?? 0);

        if ($airportExpense > 0) {
            if ($airportExpense < 100) {
                return [
                    'amount' => $airportExpense * 1000,
                    'source' => sprintf('aircraft.airport_expenses_usd (%s) x 1000', $airportExpense),
                ];
            }

            return [
                'amount' => $airportExpense,
                'source' => sprintf('aircraft.airport_expenses_usd (%s)', $airportExpense),
            ];
        }

        return [
            'amount' => self::DEFAULT_AIRPORT_EXPENSE_USD,
            'source' => sprintf('DEFAULT_AIRPORT_EXPENSE_USD (%s)', self::DEFAULT_AIRPORT_EXPENSE_USD),
        ];
    }

    private function resolveAirportExpenseRule(Aeronave $aircraft, array $legs): ?array
    {
        if (! $this->airportExpenseRulesTableExists()) {
            return null;
        }

        $routeSignature = $this->buildRouteSignature($legs);
        $firstLeg = $legs[0] ?? [];
        $originCode = strtoupper(trim((string) ($firstLeg['origin'] ?? '')));
        $destinationCode = strtoupper(trim((string) ($firstLeg['destination'] ?? '')));
        $category = $this->normalizeAircraftCategory($aircraft->category);

        $rule = $this->activeAirportExpenseRules()
            ->filter(function (ReglaGastoAeropuerto $rule) use ($aircraft, $category, $routeSignature, $originCode, $destinationCode) {
                return ($rule->aircraft_id === null || (int) $rule->aircraft_id === (int) $aircraft->id)
                    && ($rule->category === null || ($category && $rule->category === $category))
                    && ($rule->route_signature === null || ($routeSignature !== '' && strtoupper((string) $rule->route_signature) === $routeSignature))
                    && ($rule->origin_airport_code === null || ($originCode !== '' && strtoupper((string) $rule->origin_airport_code) === $originCode))
                    && ($rule->destination_airport_code === null || ($destinationCode !== '' && strtoupper((string) $rule->destination_airport_code) === $destinationCode));
            })
            ->sort(function (ReglaGastoAeropuerto $left, ReglaGastoAeropuerto $right) {
                $leftScore = [
                    $left->aircraft_id === null ? 0 : 1,
                    $left->route_signature === null ? 0 : 1,
                    $left->origin_airport_code === null ? 0 : 1,
                    $left->destination_airport_code === null ? 0 : 1,
                    $left->category === null ? 0 : 1,
                    (int) $left->priority,
                ];
                $rightScore = [
                    $right->aircraft_id === null ? 0 : 1,
                    $right->route_signature === null ? 0 : 1,
                    $right->origin_airport_code === null ? 0 : 1,
                    $right->destination_airport_code === null ? 0 : 1,
                    $right->category === null ? 0 : 1,
                    (int) $right->priority,
                ];

                return $rightScore <=> $leftScore;
            })
            ->first();

        if (! $rule) {
            return null;
        }

        if (! $rule || (float) $rule->expense_fee <= 0) {
            return null;
        }

        return [
            'amount' => (float) $rule->expense_fee,
            'source' => sprintf(
                'airport_expense_rules.id=%s route=%s aircraft_id=%s category=%s',
                $rule->id,
                $rule->route_signature ?: 'null',
                $rule->aircraft_id ?: 'null',
                $rule->category ?: 'null'
            ),
        ];
    }

    private function airportExpenseRulesTableExists(): bool
    {
        if ($this->airportExpenseRulesTableExists === null) {
            $this->airportExpenseRulesTableExists = Schema::hasTable('airport_expense_rules');
        }

        return $this->airportExpenseRulesTableExists;
    }

    private function activeAirportExpenseRules(): Collection
    {
        if ($this->airportExpenseRulesCache !== null) {
            return $this->airportExpenseRulesCache;
        }

        try {
            $this->airportExpenseRulesCache = ReglaGastoAeropuerto::query()
                ->select([
                    'id',
                    'aircraft_id',
                    'category',
                    'origin_airport_code',
                    'destination_airport_code',
                    'route_signature',
                    'expense_fee',
                    'priority',
                ])
                ->where('is_active', true)
                ->get();
        } catch (QueryException) {
            $this->airportExpenseRulesCache = collect();
        }

        return $this->airportExpenseRulesCache;
    }

    private function buildRouteSignature(array $legs): string
    {
        $codes = [];

        foreach ($legs as $index => $leg) {
            $origin = strtoupper(trim((string) ($leg['origin'] ?? '')));
            $destination = strtoupper(trim((string) ($leg['destination'] ?? '')));

            if ($index === 0 && $origin !== '') {
                $codes[] = $origin;
            }

            if ($destination !== '') {
                $codes[] = $destination;
            }
        }

        return implode('>', $codes);
    }

    private function resolveMinimumHoursSource(Aeronave $aircraft, float $distanceKm, float $minimumHours): string
    {
        $aircraftMinimumHours = (float) ($aircraft->minimum_hours ?? 0);
        $category = $this->normalizeAircraftCategory($aircraft->category) ?? 'default';

        if ($distanceKm >= self::SHORT_ROUTE_DISTANCE_KM) {
            return sprintf(
                'distance_km >= SHORT_ROUTE_DISTANCE_KM (%.2f >= %.2f) => %.2f; aircraft.minimum_hours=%s not used by current client pricing logic',
                $distanceKm,
                self::SHORT_ROUTE_DISTANCE_KM,
                $minimumHours,
                $aircraftMinimumHours
            );
        }

        return sprintf(
            'category rule (%s) => %.2f; aircraft.minimum_hours=%s not used by current client pricing logic',
            $category,
            $minimumHours,
            $aircraftMinimumHours
        );
    }

    private function stripClientPricingFields(array $data): array
    {
        foreach ([
            'base_price',
            'operational_fee',
            'priority_price',
            'total',
            'estimated_total',
            'final_price',
            'selected_card_price',
            'pricing_formula_version',
            'pricing_context',
            'aircraft_snapshot',
            'commercial_margin',
            'priority_factor',
            'billable_hours',
            'real_flight_hours',
            'minimum_hours',
            'minimum_route_price',
            'extra_services_total',
            'subtotal_before_multipliers',
            'subtotal',
        ] as $field) {
            unset($data[$field]);
        }

        return $data;
    }

    private function extractIgnoredClientPricingFields(array $data): array
    {
        $ignored = [];

        foreach ([
            'base_price',
            'operational_fee',
            'priority_price',
            'total',
            'estimated_total',
            'final_price',
            'selected_card_price',
            'pricing_formula_version',
            'pricing_context',
            'aircraft_snapshot',
            'commercial_margin',
            'priority_factor',
            'billable_hours',
            'real_flight_hours',
            'minimum_hours',
            'minimum_route_price',
            'extra_services_total',
            'subtotal_before_multipliers',
            'subtotal',
        ] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '') {
                $ignored[$field] = $field === 'pricing_context' || $field === 'aircraft_snapshot'
                    ? '[ignored]'
                    : $data[$field];
            }
        }

        return $ignored;
    }

    private function resolveServerPricingForSelectedAircraft(Aeronave $aircraft, SolicitudVuelo $solicitud, array $requestData): array
    {
        $originAirport = $this->findActiveAirport((string) $solicitud->origin);
        $destinationAirport = $this->findActiveAirport((string) $solicitud->destination);

        abort_if(! $originAirport, 422, 'No encontramos el aeropuerto de origen activo.');
        abort_if(! $destinationAirport, 422, 'No encontramos el aeropuerto de destino activo.');
        abort_if(! $originAirport->latitude || ! $originAirport->longitude, 422, 'El aeropuerto de origen no tiene coordenadas.');
        abort_if(! $destinationAirport->latitude || ! $destinationAirport->longitude, 422, 'El aeropuerto de destino no tiene coordenadas.');

        $pricingRequestData = array_merge($requestData, [
            'origin' => $solicitud->origin,
            'destination' => $solicitud->destination,
            'departure_datetime' => optional($solicitud->departure_datetime)->toDateTimeString() ?? $requestData['departure_datetime'] ?? null,
            'return_datetime' => optional($solicitud->return_datetime)->toDateTimeString() ?? $requestData['return_datetime'] ?? null,
            'passengers' => $solicitud->passengers,
            'trip_type' => $solicitud->trip_type,
            'requirements' => is_array($solicitud->requirements) ? $solicitud->requirements : [],
        ]);
        $legs = $this->quoteLegs($pricingRequestData, $originAirport, $destinationAirport);
        $tripType = $this->resolveQuoteTripType($pricingRequestData);

        return $this->previewPricingForAircraft($aircraft, $tripType, $legs, $pricingRequestData);
    }

    private function storeFlightRequestLegs(SolicitudVuelo $solicitud, array $data): void
    {
        $allLegs = array_map(fn (array $leg) => $leg + [
            'passengers' => $data['passengers'] ?? 1,
        ], $this->normalizeRouteLegDefinitions($data));

        if (
            $this->resolveQuoteTripType($data) === 'multi_leg' &&
            $this->shouldReturnToOrigin($data) &&
            $allLegs !== []
        ) {
            $lastLeg = $allLegs[count($allLegs) - 1];
            $lastDestination = strtoupper(trim((string) ($lastLeg['destination'] ?? '')));
            $origin = strtoupper(trim((string) ($data['origin'] ?? '')));

            if ($lastDestination !== '' && $origin !== '' && $lastDestination !== $origin) {
                $allLegs[] = [
                    'origin' => $lastDestination,
                    'destination' => $origin,
                    'departure_datetime' => $data['return_datetime'] ?? $lastLeg['departure_datetime'] ?? $data['departure_datetime'],
                    'passengers' => $data['passengers'] ?? 1,
                ];
            }
        }

        foreach ($allLegs as $index => $leg) {
            $origin = strtoupper(trim((string) ($leg['origin'] ?? '')));
            $destination = strtoupper(trim((string) ($leg['destination'] ?? '')));

            if (! $origin || ! $destination) {
                continue;
            }

            $departureDatetime = $this->resolveLegDepartureDatetime($leg, $data['departure_datetime']);
            $distanceKm = $this->resolveLegDistanceKm($origin, $destination);

            $solicitud->legs()->create([
                'leg_order' => $index + 1,
                'origin' => $origin,
                'destination' => $destination,
                'departure_datetime' => $departureDatetime,
                'passengers' => (int) ($leg['passengers'] ?? $data['passengers'] ?? 1),
                'distance_km' => $distanceKm,
            ]);
        }
    }

    private function resolveLegDepartureDatetime(array $leg, string $fallbackDepartureDatetime): string
    {
        if (! empty($leg['departure_datetime'])) {
            return Carbon::parse($leg['departure_datetime'])->toDateTimeString();
        }

        if (! empty($leg['date'])) {
            $time = ! empty($leg['time']) ? $leg['time'] : '09:00';

            return Carbon::parse($leg['date'].' '.$time)->toDateTimeString();
        }

        return Carbon::parse($fallbackDepartureDatetime)->toDateTimeString();
    }

    private function resolveLegDistanceKm(string $originCode, string $destinationCode): ?int
    {
        $originAirport = $this->findActiveAirport($originCode);
        $destinationAirport = $this->findActiveAirport($destinationCode);

        if (
            ! $originAirport ||
            ! $destinationAirport ||
            ! $originAirport->latitude ||
            ! $originAirport->longitude ||
            ! $destinationAirport->latitude ||
            ! $destinationAirport->longitude
        ) {
            return null;
        }

        return (int) round($this->distanceKm(
            (float) $originAirport->latitude,
            (float) $originAirport->longitude,
            (float) $destinationAirport->latitude,
            (float) $destinationAirport->longitude
        ));
    }

    private function aircraftIsBasedAtOrigin(Aeronave $aircraft, Aeropuerto $originAirport): bool
    {
        if ($aircraft->baseAirport?->is($originAirport)) {
            return true;
        }

        $baseReference = trim((string) ($aircraft->resolvedBaseAirportCode() ?? $aircraft->base_airport));

        return $baseReference !== ''
            && $this->flightRouteService->referencesMatch($baseReference, (string) $originAirport->icao);
    }

    private function matchReason(bool $basedAtOrigin, ?string $baseAirportCode): string
    {
        if ($basedAtOrigin) {
            return 'Aeronave con base en el aeropuerto de origen';
        }

        return filled($baseAirportCode)
            ? "Requiere reposicionamiento desde {$baseAirportCode}"
            : 'Opción elegible con reposicionamiento';
    }

    private function responseTime(bool $basedAtOrigin): string
    {
        return $basedAtOrigin ? '~12 min' : '~15 min';
    }

    private function airportPreviewPayload(Aeropuerto $airport): array
    {
        return [
            'id' => $airport->id,
            'icao' => $airport->icao,
            'iata' => $airport->iata,
            'name' => $airport->name,
            'city' => $airport->city,
            'country' => $airport->country,
            'latitude' => $airport->latitude,
            'longitude' => $airport->longitude,
            'climb_descent_adjustment_minutes' => (int) ($airport->climb_descent_adjustment_minutes ?? 0),
        ];
    }

    private function aircraftPreviewPayload(Aeronave $aircraft): array
    {
        $sortedImages = $aircraft->images
            ->filter(fn (ImagenAeronave $image) => filled($image->image_url))
            ->sortBy([
                ['is_main', 'desc'],
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->values();
        $visibleImages = $sortedImages
            ->where('visible_to_client', true)
            ->values();

        if ($visibleImages->isEmpty()) {
            $visibleImages = $sortedImages;
        }

        return [
            'id' => $aircraft->id,
            'model' => $aircraft->model,
            'manufacturer' => $aircraft->manufacturer,
            'registration' => $aircraft->registration,
            'capacity' => $aircraft->capacity,
            'category' => $this->normalizeAircraftCategory($aircraft->category) ?? 'Jet privado',
            'range_km' => $aircraft->range_km,
            'base_airport' => $aircraft->base_airport,
            'speed_kmh' => $aircraft->speed_kmh,
            'hourly_rate' => $aircraft->hourly_rate,
            'airport_expenses_usd' => $aircraft->airport_expenses_usd,
            'minimum_hours' => $aircraft->minimum_hours,
            'climb_descent_minutes' => $this->resolveAircraftClimbDescentBaseMinutes($aircraft),
            'overnight_fee' => $aircraft->overnight_fee,
            'provider' => $aircraft->provider ? [
                'id' => $aircraft->provider->id,
                'company_name' => $aircraft->provider->company_name,
                'commercial_name' => $aircraft->provider->commercial_name,
                'jet_a_price' => $aircraft->provider->jet_a_price,
            ] : null,
            'main_image' => $visibleImages->firstWhere('is_main', true)?->image_url ?? $visibleImages->first()?->image_url,
            'images' => $visibleImages->map(fn (ImagenAeronave $image) => [
                'id' => $image->id,
                'kind' => $image->kind,
                'title' => $image->title,
                'image_url' => $image->image_url,
                'is_main' => $image->is_main,
            ])->values(),
        ];
    }

    private function aircraftCatalogPayload(
        Aeronave $aircraft,
        ?array $pricing = null,
        ?float $distanceKm = null,
        ?float $distanceNm = null
    ): array {
        $payload = $this->aircraftPreviewPayload($aircraft);

        if ($pricing === null) {
            $payload['pricing_available'] = false;

            return $payload;
        }

        $debugPricing = is_array($pricing['debug_pricing'] ?? null) ? $pricing['debug_pricing'] : [];

        $payload['pricing_available'] = true;
        $payload['source'] = 'backend_catalog_quote';
        $payload['pricing_source'] = $pricing['quote_strategy'] ?? 'official_backend_pricing_v2';
        $payload['hours_source'] = $debugPricing['hours_source'] ?? 'distance_speed';
        $payload['hourly_rate_source'] = $debugPricing['hourly_rate_source'] ?? 'aircraft.hourly_rate';
        $payload['expense_fee_source'] = $debugPricing['expense_fee_source'] ?? 'backend_catalog_quote';
        $payload['distance_km'] = $distanceKm !== null ? round($distanceKm) : null;
        $payload['distance_nm'] = $distanceNm !== null ? round($distanceNm) : null;
        $payload['display_flight_hours'] = round((float) $pricing['client_display_flight_hours'], 2);
        $payload['operational_flight_hours'] = round((float) $pricing['client_operational_flight_hours'], 2);
        $payload['billable_hours'] = round((float) $pricing['billable_hours'], 2);
        $payload['final_hours'] = round((float) $pricing['billable_hours'], 2);
        $payload['hourly_rate'] = round((float) $pricing['hourly_rate'], 2);
        $payload['price_per_minute'] = round((float) $pricing['price_per_minute'], 2);
        $payload['airport_expenses'] = round((float) $pricing['airport_expenses'], 2);
        $payload['expense_fee'] = round((float) $pricing['expense_fee'], 2);
        $payload['flight_base'] = round((float) $pricing['flight_base'], 2);
        $payload['base_cost'] = round((float) $pricing['base_price'], 2);
        $payload['client_flight_cost'] = round((float) $pricing['client_flight_cost'], 2);
        $payload['flight_cost'] = round((float) $pricing['flight_cost'], 2);
        $payload['stripe_fee'] = round((float) $pricing['stripe_fee'], 2);
        $payload['administrative_fee'] = round((float) $pricing['administrative_fee'], 2);
        $payload['overnight_cost'] = round((float) $pricing['overnight_cost'], 2);
        $payload['overnight_nights'] = (int) ($pricing['overnight_nights'] ?? 0);
        $payload['subtotal'] = round((float) $pricing['subtotal'], 2);
        $payload['taxes'] = round((float) $pricing['tax'], 2);
        $payload['total'] = round((float) $pricing['total'], 2);
        $payload['total_amount'] = round((float) $pricing['total_amount'], 2);
        $payload['quoted_total'] = round((float) $pricing['total_amount'], 2);
        $payload['currency'] = $aircraft->currency ?: 'USD';
        $payload['debug_pricing'] = $debugPricing;
        $payload['pricing_breakdown'] = $pricing;
        $payload['pricing'] = [
            'total_amount' => round((float) $pricing['total_amount'], 2),
            'currency' => $aircraft->currency ?: 'USD',
        ];

        return $payload;
    }

    private function normalizeAircraftCategory(mixed $value): ?string
    {
        $normalized = mb_strtolower(trim((string) ($value ?? '')));

        return match ($normalized) {
            'helicoptero', 'helicÃ³ptero', 'helicopter' => 'Helicoptero',
            'turboprop', 'turbo prop' => 'Turboprop',
            'light jet', 'light_jet', 'lightjet' => 'Light Jet',
            'mid jet', 'mid_jet', 'midjet', 'midsize jet', 'midsize_jet', 'super mid', 'super_mid' => 'Mid Jet',
            'heavy jet', 'heavy_jet', 'heavyjet', 'long range', 'long_range', 'ultra long', 'ultra_long' => 'Heavy Jet',
            '' => null,
            default => trim((string) $value),
        };
    }

    private function defaultFlightTimeMode(): string
    {
        return (bool) config('vuelos.dynamic_flight_time_enabled', false)
            && (string) config('vuelos.flight_time_model', 'direct') === self::TIME_MODE_OPERATIONAL
            ? self::TIME_MODE_OPERATIONAL
            : self::TIME_MODE_DIRECT;
    }

    private function resolveCategoryPricingRule(Aeronave $aircraft): array
    {
        $category = $this->normalizeAircraftCategory($aircraft->category);
        $defaultRule = self::AIRCRAFT_CATEGORY_MINIMUM_PRICE[$category] ?? [
            'minimum_route_price' => 3000.0,
            'redsky_markup' => 20.0,
        ];

        if (! $category) {
            return $defaultRule;
        }

        if ($this->categoryPricingRulesTableExists === null) {
            $this->categoryPricingRulesTableExists = Schema::hasTable('category_pricing_rules');
        }

        if (! $this->categoryPricingRulesTableExists) {
            return $defaultRule;
        }

        if (array_key_exists($category, $this->categoryPricingRuleCache)) {
            return $this->categoryPricingRuleCache[$category] ?: $defaultRule;
        }

        try {
            $rule = ReglaPrecioCategoria::query()
                ->where('category', $category)
                ->where('is_active', true)
                ->first();
        } catch (QueryException) {
            $this->categoryPricingRuleCache[$category] = null;

            return $defaultRule;
        }

        if (! $rule) {
            $this->categoryPricingRuleCache[$category] = null;

            return $defaultRule;
        }

        return $this->categoryPricingRuleCache[$category] = [
            'minimum_route_price' => (float) $rule->minimum_route_price,
            'redsky_markup' => (float) $rule->redsky_markup,
        ];
    }

    private function resolveMinimumRoutePrice(Aeronave $aircraft, float $distanceKm, array $categoryPricingRule): float
    {
        if ($distanceKm >= self::SHORT_ROUTE_DISTANCE_KM) {
            return 0;
        }

        $minimumRoutePrice = (float) ($categoryPricingRule['minimum_route_price'] ?? 0);

        return $minimumRoutePrice > 0 ? $minimumRoutePrice : 3000.0;
    }
}
