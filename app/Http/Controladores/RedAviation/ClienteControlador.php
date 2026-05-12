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
        $distanceKm = collect($legs)->sum('distance_km');
        $passengers = (int) $data['passengers'];
        $marginPercent = (float) config('red_aviation.preview_margin_percent', 0.12);
        $taxPercent = (float) config('red_aviation.preview_tax_percent', 0.16);

        $quotes = Aeronave::with('images')
            ->whereIn('status', ['active', 'trial_active', 'aprobada', 'available', 'disponible'])
            ->where('capacity', '>=', $passengers)
            ->get()
            ->filter(fn (Aeronave $aircraft) => $this->aircraftCanQuote($aircraft, $distanceKm))
            ->map(function (Aeronave $aircraft) use ($data, $distanceKm, $marginPercent, $taxPercent) {
                $speedKmh = max((float) $aircraft->speed_kmh, 1);
                $estimatedHours = $distanceKm / $speedKmh;
                $minimumHours = max((float) $aircraft->minimum_hours, 0);
                $billableHours = max($estimatedHours, $minimumHours);
                $hourlyRate = (float) $aircraft->hourly_rate;
                $operationalCost = (float) $aircraft->operational_cost;
                $subtotal = $billableHours * $hourlyRate;
                $margin = $subtotal * $marginPercent;
                $taxBase = $subtotal + $operationalCost + $margin;
                $taxes = $taxBase * $taxPercent;
                $total = $taxBase + $taxes;
                $currency = $aircraft->currency ?: 'USD';

                return [
                    'id' => 'preview-'.$aircraft->id,
                    'aircraft_id' => $aircraft->id,
                    'aircraft_name' => $aircraft->model,
                    'model' => $aircraft->model,
                    'cabin' => $aircraft->category ?? $data['aircraft_type'] ?? 'Jet privado',
                    'capacity' => $aircraft->capacity,
                    'time' => $this->formatHours($estimatedHours),
                    'flight_time' => $this->formatHours($estimatedHours),
                    'distance_km' => round($distanceKm),
                    'estimated_hours' => round($estimatedHours, 2),
                    'billable_hours' => round($billableHours, 2),
                    'hourly_rate' => round($hourlyRate, 2),
                    'operational_cost' => round($operationalCost, 2),
                    'subtotal' => round($subtotal, 2),
                    'margin' => round($margin, 2),
                    'taxes' => round($taxes, 2),
                    'total' => round($total, 2),
                    'currency' => $currency,
                    'final_price' => $this->formatMoney($total, $currency),
                    'source_origin' => $aircraft->base_airport,
                    'match_reason' => $this->matchReason($aircraft, $data['origin']),
                    'response_time' => $this->responseTime($aircraft, $data['origin']),
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
            'trip_type' => $data['trip_type'] ?? 'one_way',
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
            'aircraft_type' => ['nullable', 'string', 'max:100'],
            'requirements' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ]);

        $departure = Carbon::parse($data['departure_datetime']);
        $data['departure_date'] = $departure->format('Y-m-d');
        $data['departure_time'] = $departure->format('H:i');
        $data['requirements'] = $data['requirements'] ?? [];

        $solicitud = SolicitudVuelo::create($data + [
            'client_id' => $request->user()->id,
            'status' => 'pending',
            'workflow_status' => 'en_validacion',
            'package_snapshot' => [
                'plan_id' => $request->user()->activeSuscripcion?->plan_id,
                'demo' => $request->user()->demo?->status === 'active',
            ],
        ]);

        $this->matchingServicio->ejecutar($solicitud);
        $chat = $solicitud->chatsProtegidos()->create([
            'client_id' => $request->user()->id,
            'status' => 'activo',
        ]);

        $this->writeAudit($request, 'create', 'red_aviation.flight_requests', 'Solicitud Red Aviation creada.');

        return $this->ok([
            'flight_request' => $this->visibilidadServicio->solicitudParaCliente(
                $solicitud->fresh(['matches.aircraft.images', 'chatsProtegidos', 'operaciones.timeline'])
            ),
            'chat_id' => $chat->id,
        ], 201);
    }

    public function indexFlightRequests(Request $request)
    {
        $solicitudes = SolicitudVuelo::with(['matches.aircraft.images', 'chatsProtegidos', 'operaciones.timeline'])
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
                $flightRequest->load(['matches.aircraft.images', 'chatsProtegidos', 'operaciones.timeline'])
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

    private function quoteLegs(array $data, Aeropuerto $originAirport, Aeropuerto $destinationAirport): array
    {
        $legs = [
            $this->quoteLegPayload(1, $originAirport, $destinationAirport),
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

            $legs[] = $this->quoteLegPayload($index + 2, $extraOrigin, $extraDestination);
        }

        return $legs;
    }

    private function quoteLegPayload(int $position, Aeropuerto $originAirport, Aeropuerto $destinationAirport): array
    {
        $distanceKm = $this->distanceKm(
            (float) $originAirport->latitude,
            (float) $originAirport->longitude,
            (float) $destinationAirport->latitude,
            (float) $destinationAirport->longitude
        );

        return [
            'position' => $position,
            'origin' => $originAirport->icao ?: $originAirport->iata,
            'destination' => $destinationAirport->icao ?: $destinationAirport->iata,
            'distance_km' => round($distanceKm),
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
