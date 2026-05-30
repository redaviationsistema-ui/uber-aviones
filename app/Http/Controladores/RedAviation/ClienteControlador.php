<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
use App\Modelos\Aeronave;
use App\Modelos\Aeropuerto;
use App\Modelos\ImagenAeronave;
use App\Modelos\Operacion;
use App\Modelos\ReglaPrecioCategoria;
use App\Modelos\SolicitudVuelo;
use App\Servicios\RedAviation\MatchingRedAviationServicio;
use App\Servicios\RedAviation\VisibilidadServicio;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ClienteControlador extends ControladorBase
{
    private const TIME_MODE_DIRECT = 'direct';
    private const TIME_MODE_DIRECT_PLUS_CLIMB = 'direct_plus_climb';
    private const TIME_MODE_OPERATIONAL = 'operational';
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
        private readonly MatchingRedAviationServicio $matchingServicio,
        private readonly VisibilidadServicio $visibilidadServicio,
    ) {
    }

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
            'passengers' => ['nullable', 'integer', 'min:1'],
        ]);

        $origin = strtoupper(trim((string) ($data['origin'] ?? $data['base_airport'] ?? '')));
        $passengers = (int) ($data['passengers'] ?? 0);

        $aircraft = Aeronave::with(['images', 'provider'])
            ->whereIn('status', ['active', 'trial_active', 'aprobada', 'available', 'disponible'])
            ->when($passengers > 0, fn ($query) => $query->where('capacity', '>=', $passengers))
            ->when($origin !== '', function ($query) use ($origin) {
                $query->orderByRaw(
                    'case when upper(coalesce(base_airport, \'\')) = ? then 0 else 1 end',
                    [$origin],
                );
            })
            ->orderByRaw('upper(coalesce(base_airport, \'\')) asc')
            ->orderByRaw('coalesce(hourly_rate, 0) asc')
            ->get()
            ->values();

        return $this->ok([
            'aircraft' => $aircraft->map(fn (Aeronave $aircraft) => $this->aircraftPreviewPayload($aircraft))->values(),
            'origin' => $origin ?: null,
            'passengers' => $passengers ?: null,
        ]);
    }

    public function previewQuotes(Request $request)
    {
        $data = $request->validate([
            'origin' => ['required', 'string', 'max:20'],
            'destination' => ['required', 'string', 'max:20'],
            'departure_datetime' => ['nullable', 'date'],
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
            'aircraft_type' => ['nullable', 'string', 'max:100'],
            'requirements' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ]);

        $originAirport = $this->findActiveAirport($data['origin']);
        $destinationAirport = $this->findActiveAirport($data['destination']);

        abort_if(! $originAirport, 422, 'No encontramos el aeropuerto de origen activo.');
        abort_if(! $destinationAirport, 422, 'No encontramos el aeropuerto de destino activo.');
        abort_if(! $originAirport->latitude || ! $originAirport->longitude, 422, 'El aeropuerto de origen no tiene coordenadas.');
        abort_if(! $destinationAirport->latitude || ! $destinationAirport->longitude, 422, 'El aeropuerto de destino no tiene coordenadas.');

        $legs = $this->quoteLegs($data, $originAirport, $destinationAirport);
        $distanceKm = (float) collect($legs)->sum('distance_km');
        $distanceNm = (float) collect($legs)->sum('distance_nm');
        $passengers = (int) $data['passengers'];
        $tripType = $this->resolveQuoteTripType($data);

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

        $maxLegDistanceKm = (float) collect($legs)->max('distance_km');
        $quotes = Aeronave::with(['images', 'provider'])
            ->whereIn('status', ['active', 'trial_active', 'aprobada', 'available', 'disponible'])
            ->where('capacity', '>=', $passengers)
            ->get()
            ->filter(fn (Aeronave $aircraft) => $this->aircraftCanQuote($aircraft, $maxLegDistanceKm))
            ->map(function (Aeronave $aircraft) use ($data, $distanceKm, $distanceNm, $legs, $tripType) {
                $pricing = $this->previewPricingForAircraft($aircraft, $tripType, $legs, $data);
                $currency = $aircraft->currency ?: 'USD';

                $clientDisplayHours = round($pricing['client_display_flight_hours'], 2);
                $clientOperationalHours = round($pricing['client_operational_flight_hours'], 2);
                $clientDirectHours = round($pricing['client_direct_flight_hours'], 2);
                $cardTime = $this->formatHours($pricing['client_display_flight_hours']);

                return [
                    'id' => 'preview-'.$aircraft->id,
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
                    'billed_time' => $this->formatHours($pricing['total_billed_hours']),
                    'repositioning_time' => $this->formatHours($pricing['repositioning_hours']),
                    'return_to_base_time' => $this->formatHours($pricing['return_to_base_hours']),
                    'flight_time' => $cardTime,
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
                    'priority_factor' => 1,
                    'subtotal_before_margin' => round($pricing['subtotal_before_margin'], 2),
                    'margin_percentage' => round($pricing['margin_percentage'], 2),
                    'margin_amount' => round($pricing['margin_amount'], 2),
                    'total_amount' => round($pricing['total'], 2),
                    'quoted_total' => round($pricing['total'], 2),
                    'overnight_fee' => round($pricing['overnight_fee'], 2),
                    'jet_a_price' => round($pricing['jet_a_price'], 2),
                    'segment_count' => max(count($pricing['client_legs']), 1),
                    'subtotal' => round($pricing['subtotal'], 2),
                    'utility' => 0,
                    'margin' => 0,
                    'taxes' => round($pricing['tax'], 2),
                    'total' => round($pricing['total'], 2),
                    'currency' => $currency,
                    'final_price' => $this->formatMoney($pricing['total'], $currency),
                    'pricing_breakdown' => $pricing,
                    'source_origin' => $aircraft->base_airport,
                    'match_reason' => $this->matchReason($aircraft, $data['origin']),
                    'response_time' => $this->responseTime($aircraft, $data['origin']),
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
                        'time_display_mode' => $pricing['time_display_mode'],
                        'billing_hours_mode' => $pricing['billing_hours_mode'],
                        'time_excludes_repositioning' => true,
                        'billed_time_includes_repositioning' => true,
                    ],
                    'aircraft' => $this->aircraftPreviewPayload($aircraft),
                ];
            })
            ->sortBy([
                fn (array $quote) => strtoupper((string) $quote['source_origin']) === strtoupper((string) $data['origin']) ? 0 : 1,
                fn (array $quote) => $quote['total'],
            ])
            ->values();

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
            'time_display_mode' => $data['time_display_mode'] ?? self::TIME_MODE_DIRECT,
            'billing_hours_mode' => $data['billing_hours_mode'] ?? self::TIME_MODE_DIRECT,
            'legs' => $legs,
            'matches' => $quotes,
            'options' => $quotes,
        ]);
    }

    public function storeFlightRequest(Request $request)
    {
        $data = $request->validate([
            'origin' => ['required', 'string', 'max:20'],
            'destination' => ['required', 'string', 'max:20'],
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
            'base_price' => ['nullable', 'numeric'],
            'operational_fee' => ['nullable', 'numeric'],
            'priority_price' => ['nullable', 'numeric'],
            'total' => ['nullable', 'numeric'],
            'estimated_total' => ['nullable', 'numeric'],
            'final_price' => ['nullable', 'numeric'],
            'selected_card_price' => ['nullable', 'numeric'],
            'currency' => ['nullable', 'string', 'max:10'],
            'pricing_formula_version' => ['nullable', 'string', 'max:120'],
            'pricing_context' => ['nullable', 'array'],
            'aircraft_snapshot' => ['nullable', 'array'],
            'requirements' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ]);

        $departure = Carbon::parse($data['departure_datetime']);
        $data['departure_date'] = $departure->format('Y-m-d');
        $data['departure_time'] = $departure->format('H:i');
        $data['requirements'] = $data['requirements'] ?? [];
        $data['trip_type'] = $this->normalizeTripType($data['trip_type'] ?? null);
        $data['pricing_context'] = $this->normalizeStoredPricingContext($data);
        $selectedCardPrice = (float) (
            data_get($data, 'pricing_context.selected_card_price')
            ?? data_get($data, 'pricing_context.total')
            ?? data_get($data, 'pricing_context.final_price')
            ?? $data['selected_card_price']
            ?? $data['total']
            ?? $data['estimated_total']
            ?? $data['final_price']
            ?? 0
        );
        if ($selectedCardPrice > 0) {
            $data['final_price'] = $selectedCardPrice;
            $data['pricing_context']['selected_card_price'] = $selectedCardPrice;
            $data['pricing_context']['total'] = (float) ($data['pricing_context']['total'] ?? $selectedCardPrice);
            $data['pricing_context']['final_price'] = (float) ($data['pricing_context']['final_price'] ?? $selectedCardPrice);
        }
        $aircraftSnapshot = is_array($data['aircraft_snapshot'] ?? null) ? $data['aircraft_snapshot'] : [];

        $solicitud = SolicitudVuelo::create($data + [
            'client_id' => $request->user()->id,
            'status' => 'pending',
            'workflow_status' => 'en_validacion',
            'currency' => $data['currency'] ?? 'USD',
            'package_snapshot' => [
                'plan_id' => $request->user()->activeSuscripcion?->plan_id,
                'demo' => $request->user()->demo?->status === 'active',
            ],
            'visibility_payload' => array_filter([
                'selected_card_price' => $selectedCardPrice > 0 ? $selectedCardPrice : null,
                'aircraft_snapshot' => ! empty($aircraftSnapshot) ? $aircraftSnapshot : null,
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

        $chat = $solicitud->chatsProtegidos()->create([
            'client_id' => $request->user()->id,
            'status' => 'activo',
        ]);

        $this->writeAudit($request, 'create', 'red_aviation.flight_requests', 'Solicitud Red Aviation creada.');

        return $this->ok([
            'flight_request' => $this->visibilidadServicio->solicitudParaCliente(
                $solicitud->fresh(['matches.aircraft.images', 'chatsProtegidos', 'operaciones.timeline', 'legs'])
            ),
            'chat_id' => $chat->id,
        ], 201);
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
            $selectedAircraft = Aeronave::with('provider')->find($resolvedAircraftId);

            if ($selectedAircraft) {
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
        $resolvedSelectedPrice = (float) (
            data_get($data, 'pricing_context.selected_card_price')
            ?? data_get($data, 'pricing_context.total')
            ?? data_get($data, 'pricing_context.final_price')
            ?? $data['selected_card_price']
            ?? $data['total']
            ?? $data['estimated_total']
            ?? $data['final_price']
            ?? $selectedMatch->estimated_price
            ?? 0
        );
        $selectedMatch->update([
            'estimated_price' => $resolvedSelectedPrice,
            'status' => 'sent_to_provider',
        ]);

        $visibilityPayload = $solicitud->visibility_payload ?? [];
        $selectedCardPrice = $resolvedSelectedPrice > 0
            ? $resolvedSelectedPrice
            : (float) ($solicitud->pricing_context['total'] ?? $solicitud->pricing_context['final_price'] ?? $solicitud->final_price ?? 0);

        $solicitud->update([
            'assigned_provider_id' => $selectedMatch->provider_id,
            'assigned_aircraft_id' => $selectedMatch->aircraft_id,
            'assigned_aircraft_model' => $selectedMatch->aircraft?->model,
            'final_price' => $selectedCardPrice > 0 ? $selectedCardPrice : $solicitud->final_price,
            'workflow_status' => 'operador_asignado',
            'visibility_payload' => [
                ...$visibilityPayload,
                'selected_provider_id' => $selectedMatch->provider_id,
                'selected_aircraft_id' => $selectedMatch->aircraft_id,
                'aircraft_model' => $selectedMatch->aircraft?->model,
                'aircraft_category' => $selectedMatch->aircraft?->category,
                'aircraft_capacity' => $selectedMatch->aircraft?->capacity,
                'selected_card_price' => $selectedCardPrice > 0 ? $selectedCardPrice : null,
            ],
        ]);
    }

    public function indexFlightRequests(Request $request)
    {
        $solicitudes = SolicitudVuelo::with(['matches.aircraft.images', 'chatsProtegidos', 'operaciones.timeline', 'legs'])
            ->where('client_id', $request->user()->id)
            ->latest()
            ->get()
            ->map(fn ($solicitud) => $this->visibilidadServicio->solicitudParaCliente($solicitud));

        return $this->ok(['flight_requests' => $solicitudes]);
    }

    public function showFlightRequest(Request $request, SolicitudVuelo $flightRequest)
    {
        abort_if($flightRequest->client_id !== $request->user()->id, 403);

        return $this->ok([
            'flight_request' => $this->visibilidadServicio->solicitudParaCliente(
                $flightRequest->load(['matches.aircraft.images', 'chatsProtegidos', 'operaciones.timeline', 'legs'])
            ),
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

        return Aeropuerto::query()
            ->where('status', 'active')
            ->where(function ($query) use ($normalizedCode) {
                $query->whereRaw('UPPER(icao) = ?', [$normalizedCode])
                    ->orWhereRaw('UPPER(iata) = ?', [$normalizedCode]);
            })
            ->first();
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
        $legs = [];
        foreach ($this->normalizeRouteLegDefinitions($data) as $index => $definition) {
            $originCode = $definition['origin'] ?? null;
            $destinationCode = $definition['destination'] ?? null;

            if (! $originCode || ! $destinationCode) {
                continue;
            }

            $extraOrigin = $this->findActiveAirport($originCode);
            $extraDestination = $this->findActiveAirport($destinationCode);

            abort_if(! $extraOrigin, 422, "No encontramos el aeropuerto de origen del tramo ".($index + 1).".");
            abort_if(! $extraDestination, 422, "No encontramos el aeropuerto de destino del tramo ".($index + 1).".");
            abort_if(! $extraOrigin->latitude || ! $extraOrigin->longitude, 422, "El origen del tramo ".($index + 1)." no tiene coordenadas.");
            abort_if(! $extraDestination->latitude || ! $extraDestination->longitude, 422, "El destino del tramo ".($index + 1)." no tiene coordenadas.");

            $legs[] = $this->quoteLegPayload($index + 1, $extraOrigin, $extraDestination, $definition['departure_datetime'] ?? null);
        }

        if (
            $this->resolveQuoteTripType($data) === 'multi_leg' &&
            $this->shouldReturnToOrigin($data) &&
            count($legs) > 0
        ) {
            $lastLeg = $legs[count($legs) - 1];
            $lastDestinationAirport = $this->airportFromPayload($lastLeg['destination_airport']);

            if (! $this->airportsMatch($lastDestinationAirport, $originAirport)) {
                $legs[] = $this->quoteLegPayload(
                    count($legs) + 1,
                    $lastDestinationAirport,
                    $originAirport,
                    $data['return_datetime'] ?? null
                );
            }
        }

        if ($this->resolveQuoteTripType($data) === 'round_trip' && count($legs) === 1) {
            $legs[] = $this->quoteLegPayload(2, $destinationAirport, $originAirport, $data['return_datetime'] ?? null);
        }

        return $legs;
    }

    private function normalizeRouteLegDefinitions(array $data): array
    {
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
                if (($points[count($points) - 1] ?? null) !== $requirementOrigin) {
                    $points[] = $requirementOrigin;
                }

                if (($points[count($points) - 1] ?? null) !== $requirementDestination) {
                    $points[] = $requirementDestination;
                }

                continue;
            }

            $waypoint = $requirementDestination
                ?? $requirementOrigin
                ?? $this->extractAirportCode($requirement, ['airport', 'airport_code', 'code', 'icao', 'iata']);

            if ($waypoint !== null && ($points[count($points) - 1] ?? null) !== $waypoint) {
                $points[] = $waypoint;
            }
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
            ];
        }

        return $definitions;
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

    private function quoteLegPayload(int $position, Aeropuerto $originAirport, Aeropuerto $destinationAirport, ?string $departureDatetime = null): array
    {
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
        ];
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

    private function previewPricingForAircraft(Aeronave $aircraft, string $tripType, array $legs, array $requestData = []): array
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
                    false
                );
            })
            ->values()
            ->all();

        $distanceTotal = (float) collect($clientLegPricings)->sum('distance_km');
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
        $minimumHours = $this->resolveMinimumHours($aircraft->category, $distanceTotal);
        $clientBillableHours = max($this->roundUpQuarterHours($clientPricingFlightHours), $minimumHours);
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
        $repositioningCost = (float) ($initialRepositioningPricing['leg_cost'] ?? 0);
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
        $expenseFee = $this->shouldApplyAirportExpenses($requestData)
            ? $this->resolveAirportExpenseForAircraft($aircraft)
            : 0.0;
        $flightBaseHours = $flightBaseSource === 'billable_hours'
            ? $clientBillableHours
            : $clientPricingFlightHours;
        $clientFlightCost = $flightBaseHours * $hourlyRate;
        $billableFlightCost = $clientFlightCost
            + ($includeRepositioningInBilledHours ? $repositioningCost : 0.0)
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
                'client_flight_cost' => $clientFlightCost,
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
        $taxableSubtotal = $subtotal;
        $taxRate = $this->shouldIncludeIva($requestData) ? self::DEFAULT_IVA_RATE : 0.0;
        $ivaAmount = $taxableSubtotal * $taxRate;
        $total = $subtotal + $ivaAmount;

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
            'initial_repositioning_leg' => $repositioningCost > 0 ? $initialRepositioningPricing : null,
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
            'flight_base' => $clientFlightCost,
            'client_flight_base_cost' => $clientFlightCost,
            'client_flight_cost' => $clientFlightCost,
            'billable_flight_cost' => $billableFlightCost,
            'flight_cost' => $billableFlightCost,
            'operator_subtotal' => $subtotalBeforeMargin,
            'subtotal_operativo' => $subtotalOperative,
            'taxable_subtotal' => $taxableSubtotal,
            'non_taxable_expenses' => 0.0,
            'subtotal_before_margin' => $subtotalBeforeMargin,
            'base_price' => $clientFlightCost,
            'base_price_formula' => [
                'hourly_rate' => round($hourlyRate, 2),
                'price_per_minute' => $pricePerMinute,
                'billable_minutes' => $billableMinutes,
                'flight_base_hours' => round($flightBaseHours, 4),
                'flight_base_source' => $flightBaseSource,
                'client_flight_cost' => round($clientFlightCost, 2),
                'billable_flight_cost' => round($billableFlightCost, 2),
                'repositioning_cost' => round($repositioningCost, 2),
                'return_to_base_cost' => round($returnToBaseCost, 2),
                'overnight_cost' => round($overnightCost, 2),
                'airport_expenses' => round($expenseFee, 2),
                'minimum_adjustment' => round($minimumAdjustment, 2),
                'margin_amount' => round($marginAmount, 2),
                'subtotal_before_margin' => round($subtotalBeforeMargin, 2),
                'taxable_subtotal' => round($taxableSubtotal, 2),
                'non_taxable_expenses' => 0,
                'expression' => sprintf(
                    'base %.2f + repo %.2f + return %.2f + overnight %.2f + airport %.2f => %.2f; taxable %.2f; margen %.2f%% => %.2f',
                    round($clientFlightCost, 2),
                    round($includeRepositioningInBilledHours ? $repositioningCost : 0.0, 2),
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
            'initial_repositioning_cost' => $repositioningCost,
            'return_to_base_cost' => $returnToBaseCost,
            'overnight_nights' => $overnightNights,
            'overnight_cost' => $overnightCost,
            'overnight' => $overnightCost,
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
            'final_price' => $total,
            'total' => $total,
        ];
    }

    private function calculateLegPricing(Aeronave $aircraft, Aeropuerto $originAirport, Aeropuerto $destinationAirport, bool $applyMinimumHours = true): array
    {
        $distanceNm = $this->distanceNm(
            (float) $originAirport->latitude,
            (float) $originAirport->longitude,
            (float) $destinationAirport->latitude,
            (float) $destinationAirport->longitude
        );
        $speedKnots = (float) ($aircraft->speed_knots ?? 0);
        if ($speedKnots <= 0) {
            $speedKnots = max($this->resolveCruiseSpeedKmh($aircraft) / 1.852, 1);
        }
        $directAirTime = $distanceNm / $speedKnots;
        $distanceKm = $distanceNm * 1.852;
        $climbDescentMinutes = $this->calculateClimbDescentMinutes($aircraft, $originAirport, $destinationAirport);
        $climbDescentHours = $climbDescentMinutes / 60;
        // Para cliente y referencia comercial usamos el tiempo directo published/leg;
        // ascenso/descenso se conserva aparte como metrica operativa.
        $displayFlightHours = $directAirTime;
        $reserveHours = $this->operationalBufferHours($distanceNm);
        $directMinutes = $directAirTime * 60;
        $operationalFactor = $this->resolveOperationalFactor($aircraft);
        $fixedMinutesPerLeg = $this->resolveFixedMinutesPerLeg($aircraft);
        $minimumMinutesPerLeg = $this->resolveMinimumMinutesPerLeg($aircraft);
        $operationalMinutes = ($directMinutes * $operationalFactor) + $fixedMinutesPerLeg;
        $calculatedMinutes = (int) round(max($operationalMinutes, $minimumMinutesPerLeg));
        $realFlightHours = round($calculatedMinutes / 60, 4);
        $minimumHours = $applyMinimumHours ? $this->resolveMinimumHours($aircraft->category, $distanceKm) : 0.0;
        // En rutas largas usamos redondeo comercial al cuarto mas cercano; los minimos solo empujan rutas cortas.
        $billableHours = max($this->roundUpQuarterHours($displayFlightHours), $minimumHours);
        $finalHours = $billableHours;
        $hourlyRate = $this->resolveCommercialHourlyRate($aircraft->hourly_rate);
        $rawLegCost = $finalHours * $hourlyRate;

        return [
            'origin' => $originAirport->icao ?: $originAirport->iata,
            'destination' => $destinationAirport->icao ?: $destinationAirport->iata,
            'distance_nm' => $distanceNm,
            'distance_km' => $distanceKm,
            'adjusted_distance_nm' => $distanceNm,
            'direct_air_time_hours' => $directAirTime,
            'air_time_hours' => $directAirTime,
            'direct_minutes' => $directMinutes,
            'climb_descent_minutes' => $climbDescentMinutes,
            'climb_descent_hours' => $climbDescentHours,
            'reserve_hours' => $reserveHours,
            'display_flight_hours' => $displayFlightHours,
            'operational_factor' => $operationalFactor,
            'fixed_minutes_per_leg' => $fixedMinutesPerLeg,
            'minimum_minutes_per_leg' => $minimumMinutesPerLeg,
            'calculated_minutes' => $calculatedMinutes,
            'operational_minutes' => $calculatedMinutes,
            'operational_flight_hours' => $realFlightHours,
            'commercial_flight_hours' => $realFlightHours,
            'real_flight_hours' => $realFlightHours,
            'minimum_hours' => $minimumHours,
            'buffer_hours' => $reserveHours,
            'billable_hours' => $billableHours,
            'final_hours' => $finalHours,
            'raw_leg_cost' => $rawLegCost,
            'base_price' => $rawLegCost,
            'minimum_route_price' => 0,
            'leg_cost' => $rawLegCost,
            'international' => $this->isInternationalLeg($originAirport, $destinationAirport),
        ];
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

    private function normalizeCruiseCategory(mixed $value): ?string
    {
        $normalized = mb_strtolower(trim((string) ($value ?? '')));

        return match ($normalized) {
            'helicoptero', 'helicóptero', 'helicopter' => 'Helicoptero',
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
            'helicoptero', 'helicóptero', 'helicopter' => 'helicopter',
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

    private function resolveAirportExpenseForAircraft(Aeronave $aircraft): float
    {
        $airportExpense = (float) ($aircraft->airport_expenses_usd ?? 0);
        if ($airportExpense > 0) {
            return $airportExpense > 0 && $airportExpense < 100 ? $airportExpense * 1000 : $airportExpense;
        }

        return self::DEFAULT_AIRPORT_EXPENSE_USD;
    }

    private function normalizeStoredPricingContext(array $data): ?array
    {
        $pricingContext = is_array($data['pricing_context'] ?? null) ? $data['pricing_context'] : [];
        $basePrice = (float) ($data['base_price'] ?? ($pricingContext['flight_base'] ?? $pricingContext['base_cost'] ?? 0));
        $repositioningCost = (float) ($pricingContext['initial_repositioning_cost'] ?? $pricingContext['repositioning_cost'] ?? 0);
        $returnToBaseCost = (float) ($pricingContext['return_to_base_cost'] ?? 0);
        $overnightCost = (float) ($pricingContext['overnight_cost'] ?? $pricingContext['overnight'] ?? 0);
        $expenseFee = (float) ($data['operational_fee'] ?? ($pricingContext['expense_fee'] ?? 0));
        $marginAmount = (float) ($pricingContext['margin_amount'] ?? $pricingContext['utility'] ?? $pricingContext['markup_amount'] ?? 0);
        $subtotalBeforeMargin = (float) (
            $pricingContext['subtotal_before_margin']
            ?? $pricingContext['operator_subtotal']
            ?? $pricingContext['subtotal_operativo']
            ?? ($basePrice + $repositioningCost + $returnToBaseCost + $overnightCost + $expenseFee)
        );
        $subtotal = (float) ($pricingContext['subtotal'] ?? $pricingContext['subtotal_before_multipliers'] ?? ($subtotalBeforeMargin + $marginAmount));
        $ivaAmount = (float) ($pricingContext['iva_amount'] ?? $pricingContext['tax'] ?? $pricingContext['iva'] ?? 0);
        $finalPrice = (float) (
            $pricingContext['selected_card_price']
            ?? $pricingContext['total']
            ?? $pricingContext['final_price']
            ?? $data['selected_card_price']
            ?? $data['total']
            ?? $data['estimated_total']
            ?? $data['final_price']
            ?? ($subtotal + $ivaAmount)
        );

        return $pricingContext + [
            'flight_base' => $basePrice,
            'base_price' => $basePrice,
            'initial_repositioning_cost' => $repositioningCost,
            'return_to_base_cost' => $returnToBaseCost,
            'overnight_cost' => $overnightCost,
            'overnight' => $overnightCost,
            'expense_fee' => $expenseFee,
            'subtotal_before_margin' => $subtotalBeforeMargin,
            'subtotal' => $subtotal,
            'taxable_subtotal' => (float) ($pricingContext['taxable_subtotal'] ?? $subtotal),
            'non_taxable_expenses' => (float) ($pricingContext['non_taxable_expenses'] ?? 0),
            'iva_amount' => $ivaAmount,
            'total' => $finalPrice,
            'final_price' => $finalPrice,
            'billable_hours' => (float) ($pricingContext['billable_hours'] ?? 0),
        ];
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

    private function matchReason(Aeronave $aircraft, string $origin): string
    {
        if (strtoupper((string) $aircraft->base_airport) === strtoupper($origin)) {
            return 'Salida optimizada desde origen';
        }

        return 'Opción activa para tu ruta';
    }

    private function responseTime(Aeronave $aircraft, string $origin): string
    {
        return strtoupper((string) $aircraft->base_airport) === strtoupper($origin) ? '~12 min' : '~15 min';
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
            'hourly_rate' => $aircraft->hourly_rate,
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

        if (! Schema::hasTable('category_pricing_rules')) {
            return $defaultRule;
        }

        try {
            $rule = ReglaPrecioCategoria::query()
                ->where('category', $category)
                ->where('is_active', true)
                ->first();
        } catch (QueryException) {
            return $defaultRule;
        }

        if (! $rule) {
            return $defaultRule;
        }

        return [
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
