<?php

namespace App\Http\Controladores;

use App\Http\Requests\CreateAircraftHoldRequest;
use App\Modelos\Cotizacion;
use App\Modelos\SolicitudVuelo;
use App\Servicios\Aeronaves\AircraftAvailabilityService;
use App\Servicios\Aeronaves\AircraftEligibilityService;
use App\Servicios\Billing\FlightMembershipService;
use App\Servicios\Vuelos\FlightRouteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CotizacionControlador extends ControladorBase
{
    public function __construct(
        private readonly FlightMembershipService $flightMembershipService,
        private readonly AircraftAvailabilityService $aircraftAvailabilityService,
        private readonly AircraftEligibilityService $aircraftEligibilityService,
        private readonly FlightRouteService $flightRouteService,
    ) {}

    public function index(Request $request)
    {
        $query = Cotizacion::with(['flightRequest', 'aircraft', 'provider.user'])->latest();

        if ($request->user()->hasRole('client') && ! $request->user()->hasRole('admin')) {
            $query->whereHas('flightRequest', fn ($scope) => $scope->where('client_id', $request->user()->id));
        }

        return $this->ok(['quotes' => $query->paginate(20)]);
    }

    public function providerIndex(Request $request)
    {
        $provider = $request->user()->provider;
        abort_if(! $provider, 404);

        return $this->ok([
            'quotes' => Cotizacion::with(['flightRequest', 'aircraft'])
                ->where('provider_id', $provider->id)
                ->latest()
                ->paginate(20),
        ]);
    }

    public function show(Request $request, Cotizacion $quote)
    {
        if ($request->user()->hasRole('client') && ! $request->user()->hasRole('admin')) {
            abort_if($quote->flightRequest->client_id !== $request->user()->id, 403);
        }

        if ($request->user()->hasRole('provider') && ! $request->user()->hasRole('admin')) {
            abort_if($quote->provider_id !== $request->user()->provider?->id, 403);
        }

        $quote->load(['flightRequest', 'aircraft', 'provider', 'items']);

        return $this->ok([
            'quote' => $quote,
            'flight_membership_preview' => $this->flightMembershipService->previewForQuote($request->user(), $quote),
        ]);
    }

    public function store(Request $request)
    {
        $provider = $request->user()->provider;
        abort_if(! $provider, 404, 'Proveedor no encontrado.');

        $data = $request->validate([
            'flight_request_id' => ['required', 'exists:flight_requests,id'],
            'aircraft_id' => ['required', 'exists:aircraft,id'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'taxes' => ['nullable', 'numeric', 'min:0'],
            'fees' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'provider_notes' => ['nullable', 'string'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        abort_if(
            ! $provider->aircraft()->whereKey($data['aircraft_id'])->exists(),
            403,
            'La aeronave no pertenece a este proveedor.'
        );

        $total = (float) $data['subtotal'] + (float) ($data['taxes'] ?? 0) + (float) ($data['fees'] ?? 0);

        $quote = Cotizacion::create($data + [
            'provider_id' => $provider->id,
            'total' => $total,
            'currency' => $data['currency'] ?? 'USD',
            'status' => 'sent',
        ]);

        SolicitudVuelo::whereKey($data['flight_request_id'])->update(['status' => 'quoted']);
        $this->writeAudit($request, 'create', 'quotes', 'Cotizacion enviada por proveedor.');

        return $this->ok(['quote' => $quote->load(['aircraft', 'flightRequest'])], 201);
    }

    public function respond(Request $request, Cotizacion $quote)
    {
        $provider = $request->user()->provider;
        abort_if(! $request->user()->hasRole('admin') && $quote->provider_id !== $provider?->id, 403);

        $data = $request->validate(['status' => ['required', 'in:sent,rejected,expired']]);
        $quote->update($data);

        return $this->ok(['quote' => $quote->fresh()]);
    }

    public function accept(Request $request, Cotizacion $quote)
    {
        abort_if($quote->flightRequest->client_id !== $request->user()->id, 403, 'No puedes aceptar esta cotizacion.');
        abort_if($quote->status !== 'sent', 409, 'La cotizacion no esta disponible para aceptarse.');

        $quote->update(['status' => 'accepted']);
        $this->writeAudit($request, 'accept', 'quotes', 'Cotizacion aceptada por cliente.');

        return $this->ok(['quote' => $quote->fresh()]);
    }

    public function reject(Request $request, Cotizacion $quote)
    {
        abort_if($quote->flightRequest->client_id !== $request->user()->id, 403, 'No puedes rechazar esta cotizacion.');

        $quote->update(['status' => 'rejected']);

        return $this->ok(['quote' => $quote->fresh()]);
    }

    public function createAircraftHold(CreateAircraftHoldRequest $request, Cotizacion $quote): JsonResponse
    {
        abort_if($quote->flightRequest?->client_id !== $request->user()->id && ! $request->user()->hasRole('admin'), 403);
        $data = $request->validated();
        $routeQuoteId = (int) $quote->id;
        $bodyQuoteId = (int) ($data['quote_id'] ?? 0);

        if ($bodyQuoteId > 0 && $bodyQuoteId !== $routeQuoteId) {
            return response()->json([
                'success' => false,
                'message' => 'El quote_id enviado no coincide con la cotización de la URL.',
            ], 422);
        }

        Log::info('Aircraft hold request diagnostics', [
            'route_quote_id' => $routeQuoteId,
            'request_quote_id' => $data['quote_id'] ?? null,
            'aircraft_id' => $data['aircraft_id'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'start_time' => $data['start_time'] ?? null,
            'start_datetime' => $data['start_datetime'] ?? null,
            'departure_date' => $data['departure_date'] ?? null,
            'departure_time' => $data['departure_time'] ?? null,
            'departure_datetime' => $data['departure_datetime'] ?? null,
            'first_leg' => $data['legs'][0] ?? null,
        ]);

        $reservation = $quote->flightRequest?->reservation;
        $quote->loadMissing(['flightRequest.legs', 'aircraft.provider', 'aircraft.documents']);
        $flightRequest = $quote->flightRequest;
        $route = $this->flightRouteService->buildCanonicalRoute([
            'origin' => $flightRequest?->origin,
            'destination' => $flightRequest?->destination,
            'departure_datetime' => optional($flightRequest?->departure_datetime)->toDateTimeString()
                ?? ($data['departure_datetime'] ?? null),
            'return_datetime' => optional($flightRequest?->return_datetime)->toDateTimeString()
                ?? ($data['return_datetime'] ?? null),
            'trip_type' => $flightRequest?->trip_type,
            'requirements' => is_array($flightRequest?->requirements) ? $flightRequest->requirements : [],
        ]);
        try {
            [$requestedStart, $requestedEnd] = $this->aircraftAvailabilityService->resolveWindowFromPayload([
                ...$data,
                'departure_datetime' => optional($flightRequest?->departure_datetime)->toDateTimeString()
                    ?? ($data['departure_datetime'] ?? null),
                'return_datetime' => optional($flightRequest?->return_datetime)->toDateTimeString()
                    ?? ($data['return_datetime'] ?? null),
                'departure_date' => $flightRequest?->departure_date ?? ($data['departure_date'] ?? null),
                'departure_time' => $flightRequest?->departure_time ?? ($data['departure_time'] ?? null),
                'return_date' => $flightRequest?->return_date ?? ($data['return_date'] ?? null),
                'return_time' => $flightRequest?->return_time ?? ($data['return_time'] ?? null),
                'legs' => $route['legs'],
            ]);
        } catch (RuntimeException) {
            [$requestedStart, $requestedEnd] = $this->aircraftAvailabilityService->resolveFlightRequestWindow($flightRequest);
        }
        $eligibility = $this->aircraftEligibilityService->evaluate($quote->aircraft, [
            'route' => $route,
            'passengers' => (int) ($flightRequest?->passengers ?? 0),
            'trip_type' => $route['trip_type'],
            'preference' => $flightRequest?->aircraft_type,
            'requested_start' => $requestedStart,
            'requested_end' => $requestedEnd,
            'flight_request_id' => $flightRequest?->id,
            'reservation_id' => $reservation?->id,
            'quote_id' => $quote->id,
        ]);

        if (! $eligibility['eligible']) {
            return response()->json([
                'success' => false,
                'message' => $eligibility['reasons'][0] ?? 'La aeronave ya no es elegible para esta cotización.',
                'reason_code' => $eligibility['reason_code'],
                'reason_codes' => $eligibility['reason_codes'],
                'eligibility_rule_version' => $eligibility['rule_version'],
            ], 409);
        }

        try {
            $hold = $this->aircraftAvailabilityService->holdAircraftForQuote(
                $quote->load(['flightRequest.legs', 'aircraft']),
                (int) $request->user()->id,
                $reservation,
                null,
                $data,
            );
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            $status = str_contains($message, 'No se pudo resolver la fecha de inicio') ? 422 : 409;

            return response()->json([
                'success' => false,
                'message' => $message,
                'errors' => $status === 422
                    ? [
                        'departure' => [
                            'La cotización no contiene una fecha de salida válida.',
                        ],
                    ]
                    : null,
            ], $status);
        }

        $reused = (bool) $hold->getAttribute('hold_reused');

        return $this->ok([
            'message' => $reused
                ? 'La aeronave ya estaba retenida para tu pago. Conservamos la retencion actual.'
                : 'La aeronave quedo retenida temporalmente mientras completas el pago.',
            'data' => $this->aircraftHoldPayload($hold),
        ], $reused ? 200 : 201);
    }

    public function showAircraftHold(Request $request, Cotizacion $quote)
    {
        abort_if($quote->flightRequest?->client_id !== $request->user()->id && ! $request->user()->hasRole('admin'), 403);

        $hold = $this->aircraftAvailabilityService->getActiveHoldForQuote($quote, (int) $request->user()->id);

        return $this->ok([
            'message' => $hold
                ? 'Tienes una retencion activa para esta cotizacion.'
                : 'No hay una retencion activa para esta cotizacion.',
            'data' => $hold ? $this->aircraftHoldPayload($hold) : null,
        ]);
    }

    public function releaseAircraftHold(Request $request, Cotizacion $quote)
    {
        abort_if($quote->flightRequest?->client_id !== $request->user()->id && ! $request->user()->hasRole('admin'), 403);

        $hold = $this->aircraftAvailabilityService->releaseQuoteHold($quote, (int) $request->user()->id, 'Retencion liberada por el cliente.');

        return $this->ok([
            'message' => $hold
                ? 'La retencion fue liberada correctamente.'
                : 'No habia una retencion activa que liberar.',
            'data' => $hold ? $this->aircraftHoldPayload($hold) : null,
        ]);
    }

    private function aircraftHoldPayload(object $hold): array
    {
        $expiresAt = $hold->hold_expires_at;
        $secondsRemaining = $expiresAt ? max(now()->diffInSeconds($expiresAt, false), 0) : null;

        return [
            'hold_id' => $hold->id,
            'quote_id' => $hold->quote_id,
            'aircraft_id' => $hold->aircraft_id,
            'status' => $hold->status,
            'starts_at' => $hold->start_datetime,
            'ends_at' => $hold->end_datetime,
            'expires_at' => $expiresAt,
            'hold_expires_at' => $expiresAt,
            'expires_in_seconds' => $secondsRemaining,
            'is_active' => $hold->status === AircraftAvailabilityService::STATUS_HELD
                && $secondsRemaining !== null
                && $secondsRemaining > 0,
        ];
    }
}
