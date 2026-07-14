<?php

namespace App\Http\Controladores;

use App\Modelos\SolicitudVuelo;
use App\Modelos\Cotizacion;
use App\Servicios\Aeronaves\AircraftAvailabilityService;
use App\Servicios\Billing\FlightMembershipService;
use Illuminate\Http\Request;
use RuntimeException;

class CotizacionControlador extends ControladorBase
{
    public function __construct(
        private readonly FlightMembershipService $flightMembershipService,
        private readonly AircraftAvailabilityService $aircraftAvailabilityService,
    )
    {
    }

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

    public function createAircraftHold(Request $request, Cotizacion $quote)
    {
        abort_if($quote->flightRequest?->client_id !== $request->user()->id && ! $request->user()->hasRole('admin'), 403);

        $reservation = $quote->flightRequest?->reservation;

        try {
            $hold = $this->aircraftAvailabilityService->holdAircraftForQuote($quote->load(['flightRequest.legs', 'aircraft']), (int) $request->user()->id, $reservation);
        } catch (RuntimeException $exception) {
            abort(409, $exception->getMessage());
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
            'aircraft_id' => $hold->aircraft_id,
            'status' => $hold->status,
            'starts_at' => $hold->start_datetime,
            'ends_at' => $hold->end_datetime,
            'hold_expires_at' => $expiresAt,
            'expires_in_seconds' => $secondsRemaining,
            'is_active' => $hold->status === AircraftAvailabilityService::STATUS_HELD
                && $secondsRemaining !== null
                && $secondsRemaining > 0,
        ];
    }
}
