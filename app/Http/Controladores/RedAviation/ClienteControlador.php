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
    private const SHORT_ROUTE_DISTANCE_KM = 300.0;
    private const MACH_1_KMH = 1062.0;
    private const COMMERCIAL_OVERNIGHT_HOURS_PER_NIGHT = 0.5;
    private const DEFAULT_IVA_RATE = 0.16;
    private const AIRPORT_EXPENSE_BY_CATEGORY = [
        'HEAVY JET' => 2000.0,
        'MID JET' => 1000.0,
        'LIGHT JET' => 800.0,
        'TURBOPROP' => 600.0,
        'HELICOPTERO' => 700.0,
        'HELICÓPTERO' => 700.0,
    ];

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
            'overnights' => ['nullable', 'integer', 'min:0'],
            'overnight_nights' => ['nullable', 'integer', 'min:0'],
            'include_iva' => ['nullable', 'boolean'],
            'airport_expenses' => ['nullable', 'boolean'],
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
        $maxLegDistanceKm = (float) collect($legs)->max('distance_km');
        $quotes = Aeronave::with(['images', 'provider'])
            ->whereIn('status', ['active', 'trial_active', 'aprobada', 'available', 'disponible'])
            ->where('capacity', '>=', $passengers)
            ->get()
            ->filter(fn (Aeronave $aircraft) => $this->aircraftCanQuote($aircraft, $maxLegDistanceKm))
            ->map(function (Aeronave $aircraft) use ($data, $distanceKm, $distanceNm, $legs, $tripType) {
                $pricing = $this->previewPricingForAircraft($aircraft, $tripType, $legs, $data);
                $currency = $aircraft->currency ?: 'USD';

                return [
                    'id' => 'preview-'.$aircraft->id,
                    'aircraft_id' => $aircraft->id,
                    'aircraft_name' => $aircraft->model,
                    'model' => $aircraft->model,
                    'cabin' => $this->normalizeAircraftCategory($aircraft->category) ?? 'Jet privado',
                    'capacity' => $aircraft->capacity,
                    'time' => $this->formatHours($pricing['total_billed_hours']),
                    'flight_time' => $this->formatHours($pricing['client_display_flight_hours']),
                    'distance_km' => round($distanceKm),
                    'distance_nm' => round($distanceNm),
                    'estimated_hours' => round($pricing['client_direct_flight_hours'], 2),
                    'real_flight_hours' => round($pricing['client_display_flight_hours'], 2),
                    'climb_descent_minutes' => (int) round($pricing['client_climb_descent_minutes']),
                    'climb_descent_hours' => round($pricing['client_climb_descent_hours'], 2),
                    'billable_hours' => round($pricing['total_billed_hours'], 2),
                    'billable_minutes' => round($pricing['billable_minutes'], 2),
                    'final_hours' => round($pricing['total_billed_hours'], 2),
                    'hourly_rate' => round($pricing['hourly_rate'], 2),
                    'price_per_minute' => round($pricing['price_per_minute'], 2),
                    'minimum_hours' => round($pricing['minimum_hours'], 2),
                    'minimum_route_price' => round($pricing['minimum_route_price'], 2),
                    'base_cost' => round($pricing['base_price'], 2),
                    'base_price_formula' => $pricing['base_price_formula'],
                    'repositioning_cost' => round($pricing['initial_repositioning_cost'] + $pricing['return_to_base_cost'], 2),
                    'operational_costs_total' => round(
                        $pricing['overnight_cost'] + $pricing['operational_expenses'] + $pricing['airport_fees'] + $pricing['fixed_fee_total'],
                        2
                    ),
                    'subtotal_before_multipliers' => round($pricing['operator_subtotal'], 2),
                    'commercial_margin' => round($pricing['redsky_markup_factor'], 4),
                    'priority_factor' => 1,
                    'total_amount' => round($pricing['total'], 2),
                    'quoted_total' => round($pricing['total'], 2),
                    'operational_cost' => round($pricing['operational_expenses'], 2),
                    'fuel_burn_gph' => round($pricing['fuel_burn_gph'], 2),
                    'engine_reserve_rate' => round($pricing['engine_reserve_rate'], 2),
                    'insurance_rate' => round($pricing['insurance_rate'], 2),
                    'maintenance_rate' => round($pricing['maintenance_rate'], 2),
                    'crew_rate' => round($pricing['crew_rate'], 2),
                    'repositioning_fee' => round($pricing['initial_repositioning_cost'] + $pricing['return_to_base_cost'], 2),
                    'overnight_fee' => round($pricing['overnight_fee'], 2),
                    'jet_a_price' => round($pricing['jet_a_price'], 2),
                    'fixed_fee' => round($pricing['fixed_fee'], 2),
                    'fixed_fee_total' => round($pricing['fixed_fee_total'], 2),
                    'segment_count' => max(count($pricing['client_legs']), 1),
                    'subtotal' => round($pricing['subtotal'], 2),
                    'utility' => 0,
                    'margin' => 0,
                    'margin_percent' => round($pricing['margin_percent'], 4),
                    'redsky_markup' => round($pricing['redsky_markup_percent'], 2),
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
                        'margin_percent' => round($pricing['margin_percent'], 4),
                        'redsky_markup' => round($pricing['redsky_markup_percent'], 2),
                        'fixed_fee' => round($pricing['fixed_fee'], 2),
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
            'passengers' => ['required', 'integer', 'min:1'],
            'trip_type' => ['nullable', 'string', 'max:50'],
            'aircraft_type' => ['nullable', 'string', 'max:100'],
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
        $selectedCardPrice = (float) (
            $data['selected_card_price']
            ?? $data['total']
            ?? $data['estimated_total']
            ?? $data['final_price']
            ?? 0
        );
        if ($selectedCardPrice > 0) {
            $data['final_price'] = $selectedCardPrice;
        }
        $data['pricing_context'] = $this->normalizeStoredPricingContext($data);
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
        $selectedMatch->update([
            'estimated_price' => (float) (
                $data['selected_card_price']
                ?? $data['total']
                ?? $data['estimated_total']
                ?? $data['final_price']
                ?? $selectedMatch->estimated_price
                ?? 0
            ),
            'status' => 'sent_to_provider',
        ]);

        $visibilityPayload = $solicitud->visibility_payload ?? [];
        $selectedCardPrice = (float) (
            $data['selected_card_price']
            ?? $data['total']
            ?? $data['estimated_total']
            ?? $data['final_price']
            ?? $solicitud->final_price
            ?? 0
        );

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
        $legs = [
            $this->quoteLegPayload(1, $originAirport, $destinationAirport, $data['departure_datetime'] ?? null),
        ];

        foreach (($data['requirements'] ?? []) as $index => $requirement) {
            $originCode = $requirement['origin'] ?? null;
            $destinationCode = $requirement['destination'] ?? null;

            if (! $originCode || ! $destinationCode) {
                continue;
            }

            $extraOrigin = $this->findActiveAirport($originCode);
            $extraDestination = $this->findActiveAirport($destinationCode);

            abort_if(! $extraOrigin, 422, "No encontramos el aeropuerto de origen del tramo ".($index + 2).".");
            abort_if(! $extraDestination, 422, "No encontramos el aeropuerto de destino del tramo ".($index + 2).".");
            abort_if(! $extraOrigin->latitude || ! $extraOrigin->longitude, 422, "El origen del tramo ".($index + 2)." no tiene coordenadas.");
            abort_if(! $extraDestination->latitude || ! $extraDestination->longitude, 422, "El destino del tramo ".($index + 2)." no tiene coordenadas.");

            $legs[] = $this->quoteLegPayload($index + 2, $extraOrigin, $extraDestination, $requirement['departure_datetime'] ?? null);
        }

        if ($this->resolveQuoteTripType($data) === 'round_trip' && count($legs) === 1) {
            $legs[] = $this->quoteLegPayload(2, $destinationAirport, $originAirport, $data['return_datetime'] ?? null);
        }

        return $legs;
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
        if ((float) $aircraft->speed_kmh <= 0 || (float) $aircraft->hourly_rate <= 0) {
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

    private function formatMoney(float $amount, string $currency): string
    {
        return '$'.number_format(round($amount), 0, '.', ',').' '.strtoupper($currency);
    }

    private function previewPricingForAircraft(Aeronave $aircraft, string $tripType, array $legs, array $requestData = []): array
    {
        $hourlyRate = $this->resolveCommercialHourlyRate($aircraft->hourly_rate);
        $pricePerMinute = round($hourlyRate / 60, 2);
        $overnightFee = round($hourlyRate / 2, 2);
        $clientLegs = $legs;
        $clientLegPricings = collect($clientLegs)
            ->map(function (array $leg) use ($aircraft) {
                return $this->calculateLegPricing(
                    $aircraft,
                    $this->airportFromPayload($leg['origin_airport']),
                    $this->airportFromPayload($leg['destination_airport'])
                );
            })
            ->values()
            ->all();

        $distanceTotal = (float) collect($clientLegPricings)->sum('distance_km');
        $clientDisplayFlightHours = (float) collect($clientLegPricings)->sum('display_flight_hours');
        $clientDirectFlightHours = (float) collect($clientLegPricings)->sum('direct_air_time_hours');
        $clientClimbDescentMinutes = (int) round((float) collect($clientLegPricings)->sum('climb_descent_minutes'));
        $clientClimbDescentHours = (float) collect($clientLegPricings)->sum('climb_descent_hours');
        $clientReserveHours = (float) collect($clientLegPricings)->sum('reserve_hours');
        $clientAirTimeHours = (float) collect($clientLegPricings)->sum('air_time_hours');
        $minimumHours = (float) collect($clientLegPricings)->sum('minimum_hours');
        $outboundHours = (float) ($clientLegPricings[0]['billable_hours'] ?? 0);
        $returnHours = (float) collect(array_slice($clientLegPricings, 1))->sum('billable_hours');
        $repositioningHours = 0.0;
        $overnightNights = $this->resolveOvernightNights($clientLegs, $requestData);
        $overnightHours = $overnightNights * self::COMMERCIAL_OVERNIGHT_HOURS_PER_NIGHT;
        $flightHours = $outboundHours + $returnHours;
        $billableHours = $outboundHours + $returnHours + $repositioningHours + $overnightHours;
        $billableMinutes = round($billableHours * 60, 2);
        $overnightCost = $overnightNights > 0 ? $overnightFee * $overnightNights : 0.0;
        $expenseFee = $this->shouldApplyAirportExpenses($requestData)
            ? $this->resolveAirportExpenseForAircraft($aircraft)
            : 0.0;
        $basePrice = $billableHours * $hourlyRate;
        $subtotal = $basePrice + $expenseFee;
        $taxRate = $this->shouldIncludeIva($requestData) ? self::DEFAULT_IVA_RATE : 0.0;
        $ivaAmount = $subtotal * $taxRate;
        $total = $subtotal + $ivaAmount;

        return [
            'trip_type' => $tripType,
            'quote_strategy' => 'distance_total_per_hour',
            'hourly_rate' => $hourlyRate,
            'price_per_minute' => $pricePerMinute,
            'minimum_hours' => $minimumHours,
            'minimum_route_price' => 0,
            'redsky_markup_percent' => 0,
            'redsky_markup_factor' => 1,
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
            'margin_percent' => 0,
            'client_legs' => $clientLegs,
            'client_leg_pricing' => $clientLegPricings,
            'distance_total' => $distanceTotal,
            'client_flight_hours' => $clientDisplayFlightHours,
            'client_display_flight_hours' => $clientDisplayFlightHours,
            'client_direct_flight_hours' => $clientDirectFlightHours,
            'client_climb_descent_minutes' => $clientClimbDescentMinutes,
            'client_climb_descent_hours' => $clientClimbDescentHours,
            'client_reserve_hours' => $clientReserveHours,
            'client_air_time_hours' => $clientAirTimeHours,
            'outbound_hours' => $outboundHours,
            'return_hours' => $returnHours,
            'repositioning_hours' => $repositioningHours,
            'flight_hours' => $flightHours,
            'overnight_hours' => $overnightHours,
            'total_billed_hours' => $billableHours,
            'billable_hours' => $billableHours,
            'billable_minutes' => $billableMinutes,
            'flight_base' => $basePrice,
            'client_flight_base_cost' => $basePrice,
            'client_flight_cost' => $basePrice,
            'flight_cost' => $basePrice,
            'operator_subtotal' => $subtotal,
            'base_price' => $basePrice,
            'base_price_formula' => [
                'hourly_rate' => round($hourlyRate, 2),
                'price_per_minute' => $pricePerMinute,
                'billable_minutes' => $billableMinutes,
                'base_price' => round($basePrice, 2),
                'expression' => sprintf(
                    '%.2f / 60 = %.2f; %.2f * %.2f = %.2f',
                    round($hourlyRate, 2),
                    $pricePerMinute,
                    $pricePerMinute,
                    $billableMinutes,
                    round($basePrice, 2)
                ),
            ],
            'markup_amount' => 0,
            'initial_repositioning_cost' => 0,
            'return_to_base_cost' => 0,
            'overnight_nights' => $overnightNights,
            'overnight_cost' => $overnightCost,
            'overnight' => $overnightCost,
            'airport_fees' => $expenseFee,
            'airport_expenses' => $expenseFee,
            'expense_fee' => $expenseFee,
            'tax_rate' => $taxRate,
            'tax' => $ivaAmount,
            'iva' => $ivaAmount,
            'iva_amount' => $ivaAmount,
            'subtotal' => $subtotal,
            'utility' => 0,
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
        $speedKnots = max($this->resolveCruiseSpeedKmh($aircraft) / 1.852, 1);
        $directAirTime = $distanceNm / $speedKnots;
        $distanceKm = $distanceNm * 1.852;
        $climbDescentMinutes = $this->calculateClimbDescentMinutes($aircraft, $originAirport, $destinationAirport);
        $climbDescentHours = $climbDescentMinutes / 60;
        $displayFlightHours = $directAirTime + $climbDescentHours;
        $reserveHours = $this->operationalBufferHours($distanceNm);
        $realFlightHours = $directAirTime + $climbDescentHours + $reserveHours;
        $minimumHours = $applyMinimumHours ? $this->resolveMinimumHours($aircraft->category) : 0.0;
        // La tarifa comercial se redondea al siguiente cuarto de hora por tramo.
        $billableHours = $this->roundUpQuarterHours($displayFlightHours);
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
            'air_time_hours' => $displayFlightHours,
            'climb_descent_minutes' => $climbDescentMinutes,
            'climb_descent_hours' => $climbDescentHours,
            'reserve_hours' => $reserveHours,
            'display_flight_hours' => $displayFlightHours,
            'commercial_flight_hours' => $realFlightHours,
            'real_flight_hours' => $realFlightHours,
            'minimum_hours' => $minimumHours,
            'buffer_hours' => $reserveHours,
            'billable_hours' => $billableHours,
            'final_hours' => $finalHours,
            'raw_leg_cost' => $rawLegCost,
            'minimum_route_price' => 0,
            'leg_cost' => $rawLegCost,
            'international' => $this->isInternationalLeg($originAirport, $destinationAirport),
        ];
    }

    private function resolveCruiseSpeedKmh(Aeronave $aircraft): float
    {
        $cruiseCategory = $this->normalizeCruiseCategory($aircraft->category);
        if ($cruiseCategory !== null) {
            $mach = self::CATEGORY_MACH_BANDS[$cruiseCategory] ?? self::CATEGORY_MACH_BANDS['Mid Jet'];

            return $mach * self::MACH_1_KMH;
        }

        $explicitSpeedKmh = (float) $aircraft->speed_kmh;
        if ($explicitSpeedKmh > 0) {
            return $explicitSpeedKmh;
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
            'heavy jet', 'heavy_jet', 'heavyjet', 'long range', 'long_range', 'ultra long', 'ultra_long', 'ultra long range', 'ultra_long_range' => 'heavy_jet',
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

    private function resolveMinimumHours(mixed $aircraftCategory): float
    {
        return match ($this->normalizePricingCategory($aircraftCategory)) {
            'helicopter' => 1.0,
            'turboprop' => 1.5,
            'heavy_jet' => 3.0,
            'light_jet', 'mid_jet' => 2.0,
            default => 2.0,
        };
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
        return ceil($hours * 4) / 4;
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

        return $this->resolveAirportExpenseByCategory($aircraft->category);
    }

    private function resolveAirportExpenseByCategory(mixed $category): float
    {
        $normalizedCategory = strtoupper(trim((string) $category));

        return self::AIRPORT_EXPENSE_BY_CATEGORY[$normalizedCategory] ?? 0.0;
    }

    private function normalizeStoredPricingContext(array $data): ?array
    {
        $pricingContext = is_array($data['pricing_context'] ?? null) ? $data['pricing_context'] : [];
        $basePrice = (float) ($data['base_price'] ?? ($pricingContext['flight_base'] ?? $pricingContext['base_cost'] ?? 0));
        $expenseFee = (float) ($data['operational_fee'] ?? ($pricingContext['expense_fee'] ?? 0));
        $subtotal = (float) ($pricingContext['subtotal'] ?? $pricingContext['subtotal_before_multipliers'] ?? ($basePrice + $expenseFee));
        $ivaAmount = (float) ($pricingContext['iva_amount'] ?? $pricingContext['tax'] ?? $pricingContext['iva'] ?? 0);
        $finalPrice = (float) ($data['final_price'] ?? ($pricingContext['total'] ?? $pricingContext['final_price'] ?? ($subtotal + $ivaAmount)));

        return $pricingContext + [
            'flight_base' => $basePrice,
            'base_price' => $basePrice,
            'expense_fee' => $expenseFee,
            'subtotal' => $subtotal,
            'iva_amount' => $ivaAmount,
            'total' => $finalPrice,
            'final_price' => $finalPrice,
            'billable_hours' => (float) ($pricingContext['billable_hours'] ?? 0),
        ];
    }

    private function storeFlightRequestLegs(SolicitudVuelo $solicitud, array $data): void
    {
        $allLegs = array_merge([
            [
                'origin' => $data['origin'],
                'destination' => $data['destination'],
                'departure_datetime' => $data['departure_datetime'],
                'passengers' => $data['passengers'] ?? 1,
            ],
        ], $data['requirements'] ?? []);

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
        $categoryPricingRule = $this->resolveCategoryPricingRule($aircraft);
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
            'minimum_route_price' => $categoryPricingRule['minimum_route_price'],
            'climb_descent_minutes' => $this->resolveAircraftClimbDescentBaseMinutes($aircraft),
            'redsky_markup' => $categoryPricingRule['redsky_markup'],
            'operational_cost' => $aircraft->operational_cost,
            'fuel_burn_gph' => $aircraft->fuel_burn_gph,
            'engine_reserve_rate' => $aircraft->engine_reserve_rate,
            'insurance_rate' => $aircraft->insurance_rate,
            'maintenance_rate' => $aircraft->maintenance_rate,
            'crew_rate' => $aircraft->crew_rate,
            'repositioning_fee' => $aircraft->repositioning_fee,
            'overnight_fee' => $aircraft->overnight_fee,
            'provider' => $aircraft->provider ? [
                'id' => $aircraft->provider->id,
                'company_name' => $aircraft->provider->company_name,
                'commercial_name' => $aircraft->provider->commercial_name,
                'jet_a_price' => $aircraft->provider->jet_a_price,
                'margin_percent' => $aircraft->provider->margin_percent,
                'fixed_fee' => $aircraft->provider->fixed_fee,
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
