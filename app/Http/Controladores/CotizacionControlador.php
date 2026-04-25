<?php

namespace App\Http\Controladores;

use App\Modelos\SolicitudVuelo;
use App\Modelos\Cotizacion;
use Illuminate\Http\Request;

class CotizacionControlador extends ControladorBase
{
    public function index(Request $request)
    {
        $query = Cotizacion::with(['flightRequest', 'aircraft', 'provider.user'])->latest();

        if ($request->user()->role === 'client') {
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
        if ($request->user()->role === 'client') {
            abort_if($quote->flightRequest->client_id !== $request->user()->id, 403);
        }

        if ($request->user()->role === 'provider') {
            abort_if($quote->provider_id !== $request->user()->provider?->id, 403);
        }

        return $this->ok(['quote' => $quote->load(['flightRequest', 'aircraft', 'provider', 'items'])]);
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
        abort_if($request->user()->role !== 'admin' && $quote->provider_id !== $provider?->id, 403);

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
}
