<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
use App\Modelos\Aeronave;
use App\Modelos\Aeropuerto;
use App\Modelos\ImagenAeronave;
use App\Modelos\Operacion;
use App\Modelos\SolicitudVuelo;
use App\Servicios\RedAviation\MatchingRedAviationServicio;
use App\Servicios\RedAviation\VisibilidadServicio;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ClienteControlador extends ControladorBase
{
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

    public function previewQuotes(Request $request)
    {
        $data = $request->validate([
            'origin' => ['required', 'string', 'max:20'],
            'destination' => ['required', 'string', 'max:20'],
            'departure_datetime' => ['nullable', 'date'],
            'passengers' => ['required', 'integer', 'min:1'],
            'trip_type' => ['nullable', 'string', 'max:50'],
            'trip_label' => ['nullable', 'string', 'max:50'],
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
        $tripType = $this->normalizeTripType($data['trip_type'] ?? $data['trip_label'] ?? null);
        $maxLegDistanceKm = (float) collect($legs)->max('distance_km');
        $quotes = Aeronave::with(['images', 'provider'])
            ->whereIn('status', ['active', 'trial_active', 'aprobada', 'available', 'disponible'])
            ->where('capacity', '>=', $passengers)
            ->get()
            ->filter(fn (Aeronave $aircraft) => $this->aircraftCanQuote($aircraft, $maxLegDistanceKm))
            ->map(function (Aeronave $aircraft) use ($data, $distanceKm, $distanceNm, $legs, $tripType) {
                $pricing = $this->previewPricingForAircraft($aircraft, $tripType, $legs);
                $currency = $aircraft->currency ?: 'USD';

                return [
                    'id' => 'preview-'.$aircraft->id,
                    'aircraft_id' => $aircraft->id,
                    'aircraft_name' => $aircraft->model,
                    'model' => $aircraft->model,
                    'cabin' => $aircraft->category ?? $data['aircraft_type'] ?? 'Jet privado',
                    'capacity' => $aircraft->capacity,
                    'time' => $this->formatHours($pricing['client_flight_hours']),
                    'flight_time' => $this->formatHours($pricing['client_flight_hours']),
                    'distance_km' => round($distanceKm),
                    'distance_nm' => round($distanceNm),
                    'estimated_hours' => round($pricing['client_air_time_hours'], 2),
                    'billable_hours' => round($pricing['client_flight_hours'], 2),
                    'final_hours' => round($pricing['total_billed_hours'], 2),
                    'hourly_rate' => round($pricing['hourly_rate'], 2),
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
                        'fixed_fee' => round($pricing['fixed_fee'], 2),
                    ],
                    'aircraft' => $this->aircraftPreviewPayload($aircraft, $data['aircraft_type'] ?? null),
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
            'requirements' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ]);

        $departure = Carbon::parse($data['departure_datetime']);
        $data['departure_date'] = $departure->format('Y-m-d');
        $data['departure_time'] = $departure->format('H:i');
        $data['requirements'] = $data['requirements'] ?? [];
        $data['trip_type'] = $this->normalizeTripType($data['trip_type'] ?? null);

        $solicitud = SolicitudVuelo::create($data + [
            'client_id' => $request->user()->id,
            'status' => 'pending',
            'workflow_status' => 'en_validacion',
            'package_snapshot' => [
                'plan_id' => $request->user()->activeSuscripcion?->plan_id,
                'demo' => $request->user()->demo?->status === 'active',
            ],
        ]);
        $this->storeFlightRequestLegs($solicitud, $data);

        $this->matchingServicio->ejecutar($solicitud);
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

        if ($this->normalizeTripType($data['trip_type'] ?? $data['trip_label'] ?? null) === 'round_trip' && count($legs) === 1) {
            $legs[] = $this->quoteLegPayload(2, $destinationAirport, $originAirport);
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

    private function previewPricingForAircraft(Aeronave $aircraft, string $tripType, array $legs): array
    {
        $provider = $aircraft->provider;
        $hourlyRate = (float) $aircraft->hourly_rate;
        $fuelBurn = (float) $aircraft->fuel_burn_gph;
        $engineReserveRate = (float) $aircraft->engine_reserve_rate;
        $insuranceRate = (float) $aircraft->insurance_rate;
        $maintenanceRate = (float) $aircraft->maintenance_rate;
        $crewRate = (float) $aircraft->crew_rate;
        $overnightFee = max((float) $aircraft->overnight_fee, 800);
        $parkingFee = max((float) $aircraft->repositioning_fee, 0);
        $operationalExpenses = max((float) $aircraft->operational_cost, 0);
        $jetAPrice = (float) ($provider?->jet_a_price ?? 0);
        $fixedFee = (float) ($provider?->fixed_fee ?? 0);
        $marginPercent = (float) ($provider?->margin_percent ?? 0);

        $baseAirport = $this->findActiveAirport((string) $aircraft->base_airport);
        $clientLegs = $legs;
        $initialOriginAirport = $legs[0]['origin_airport'] ?? null;
        $finalDestinationAirport = $legs[count($legs) - 1]['destination_airport'] ?? null;
        $initialRepositioning = $baseAirport && $initialOriginAirport
            ? $this->calculateLegPricing($aircraft, $baseAirport, $this->airportFromPayload($initialOriginAirport), false)
            : $this->emptyLegPricing();
        $returnToBase = $baseAirport && $finalDestinationAirport
            ? $this->calculateLegPricing($aircraft, $this->airportFromPayload($finalDestinationAirport), $baseAirport, false)
            : $this->emptyLegPricing();
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

        $clientFlightCost = (float) collect($clientLegPricings)->sum('leg_cost');
        $clientFlightHours = (float) collect($clientLegPricings)->sum('final_hours');
        $clientAirTimeHours = (float) collect($clientLegPricings)->sum('air_time_hours');
        $nightCount = $this->calculateOvernightNights($clientLegs);
        $overnightCost = $nightCount * $overnightFee;
        $airportFees = 500 * $this->countAirportsForFees($clientLegs);
        $fixedFeeTotal = $fixedFee * max(count($clientLegs), 1);
        $isInternationalRoute = collect($clientLegs)->contains(fn (array $leg) => (bool) ($leg['international'] ?? false));
        $taxRate = $isInternationalRoute ? 0.04 : 0.16;
        $selectedExtraHours = $initialRepositioning['final_hours'] + $returnToBase['final_hours'];

        if ($tripType === 'round_trip' && count($clientLegPricings) >= 2) {
            $waitOptionSubtotal = $clientFlightCost
                + $initialRepositioning['leg_cost']
                + $returnToBase['leg_cost']
                + $overnightCost
                + $parkingFee
                + $operationalExpenses
                + $airportFees
                + $fixedFeeTotal;
            $returnOptionSubtotal = $clientFlightCost + $initialRepositioning['leg_cost'] + $returnToBase['leg_cost'];

            $middleAirport = $this->airportFromPayload($clientLegs[0]['destination_airport']);
            $backToPickup = $baseAirport
                ? $this->calculateLegPricing($aircraft, $baseAirport, $middleAirport, false)
                : $this->emptyLegPricing();
            $returnOptionSubtotal += $backToPickup['leg_cost'] + $operationalExpenses + $airportFees + $fixedFeeTotal;

            $subtotal = min($waitOptionSubtotal, $returnOptionSubtotal);
            $quoteStrategy = $waitOptionSubtotal <= $returnOptionSubtotal ? 'wait_option' : 'return_to_base_option';
            $selectedRepositioning = $quoteStrategy === 'return_to_base_option'
                ? $initialRepositioning['leg_cost'] + $backToPickup['leg_cost']
                : $initialRepositioning['leg_cost'];
            $selectedReturnToBase = $returnToBase['leg_cost'];
            $selectedExtraHours = $initialRepositioning['final_hours'] + $returnToBase['final_hours'];

            if ($quoteStrategy === 'return_to_base_option') {
                $selectedExtraHours += $backToPickup['final_hours'];
            }
        } else {
            $subtotal = $clientFlightCost
                + $initialRepositioning['leg_cost']
                + $returnToBase['leg_cost']
                + $overnightCost
                + $operationalExpenses
                + $airportFees
                + $fixedFeeTotal;
            $quoteStrategy = $tripType;
            $selectedRepositioning = $initialRepositioning['leg_cost'];
            $selectedReturnToBase = $returnToBase['leg_cost'];
        }

        $tax = $subtotal * $taxRate;
        $total = $subtotal + $tax;

        return [
            'trip_type' => $tripType,
            'quote_strategy' => $quoteStrategy,
            'hourly_rate' => $hourlyRate,
            'fuel_burn_gph' => $fuelBurn,
            'engine_reserve_rate' => $engineReserveRate,
            'insurance_rate' => $insuranceRate,
            'maintenance_rate' => $maintenanceRate,
            'crew_rate' => $crewRate,
            'overnight_fee' => $overnightFee,
            'parking_fee' => $parkingFee,
            'operational_expenses' => $operationalExpenses,
            'jet_a_price' => $jetAPrice,
            'fixed_fee' => $fixedFee,
            'fixed_fee_total' => $fixedFeeTotal,
            'margin_percent' => $marginPercent,
            'client_legs' => $clientLegs,
            'client_leg_pricing' => $clientLegPricings,
            'client_flight_hours' => $clientFlightHours,
            'client_air_time_hours' => $clientAirTimeHours,
            'total_billed_hours' => $clientFlightHours + $selectedExtraHours,
            'client_flight_cost' => $clientFlightCost,
            'initial_repositioning_cost' => $selectedRepositioning,
            'return_to_base_cost' => $selectedReturnToBase,
            'overnight_nights' => $nightCount,
            'overnight_cost' => $overnightCost,
            'airport_fees' => $airportFees,
            'tax_rate' => $taxRate,
            'tax' => $tax,
            'subtotal' => $subtotal,
            'utility' => 0,
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
        $adjustmentFactor = $this->isInternationalLeg($originAirport, $destinationAirport) ? 1.15 : 1.12;
        $adjustedDistanceNm = $distanceNm * $adjustmentFactor;
        $speedKnots = max(((float) $aircraft->speed_kmh) / 1.852, 1);
        $airTime = $adjustedDistanceNm / $speedKnots;
        $buffer = $this->operationalBufferHours($distanceNm);
        $billableHours = $this->roundUpQuarterHours($airTime + $buffer);
        $minimumHours = $applyMinimumHours ? max((float) $aircraft->minimum_hours, 0) : 0;
        $finalHours = max($billableHours, $minimumHours);
        $hourlyRate = (float) $aircraft->hourly_rate;

        return [
            'origin' => $originAirport->icao ?: $originAirport->iata,
            'destination' => $destinationAirport->icao ?: $destinationAirport->iata,
            'distance_nm' => $distanceNm,
            'adjusted_distance_nm' => $adjustedDistanceNm,
            'air_time_hours' => $airTime,
            'buffer_hours' => $buffer,
            'billable_hours' => $billableHours,
            'final_hours' => $finalHours,
            'leg_cost' => $finalHours * $hourlyRate,
            'international' => $this->isInternationalLeg($originAirport, $destinationAirport),
        ];
    }

    private function emptyLegPricing(): array
    {
        return [
            'distance_nm' => 0,
            'adjusted_distance_nm' => 0,
            'air_time_hours' => 0,
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
        ];
    }

    private function aircraftPreviewPayload(Aeronave $aircraft, ?string $fallbackCategory = null): array
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
            'category' => $aircraft->category ?? $fallbackCategory ?? 'Jet privado',
            'range_km' => $aircraft->range_km,
            'base_airport' => $aircraft->base_airport,
            'hourly_rate' => $aircraft->hourly_rate,
            'minimum_hours' => $aircraft->minimum_hours,
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
}
