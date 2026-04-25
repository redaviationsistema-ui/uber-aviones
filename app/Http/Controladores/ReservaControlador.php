<?php

namespace App\Http\Controladores;

use App\Modelos\Comision;
use App\Modelos\Cotizacion;
use App\Modelos\Reserva;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReservaControlador extends ControladorBase
{
    public function index(Request $request)
    {
        $query = Reserva::with(['quote', 'aircraft', 'provider.user'])->latest();

        if ($request->user()->role === 'client') {
            $query->where('client_id', $request->user()->id);
        }

        return $this->ok(['reservations' => $query->paginate(20)]);
    }

    public function providerIndex(Request $request)
    {
        $provider = $request->user()->provider;
        abort_if(! $provider, 404);

        return $this->ok([
            'reservations' => Reserva::with(['quote', 'aircraft', 'client'])
                ->where('provider_id', $provider->id)
                ->latest()
                ->paginate(20),
        ]);
    }

    public function show(Request $request, Reserva $reservation)
    {
        if ($request->user()->role === 'client') {
            abort_if($reservation->client_id !== $request->user()->id, 403);
        }

        if ($request->user()->role === 'provider') {
            abort_if($reservation->provider_id !== $request->user()->provider?->id, 403);
        }

        return $this->ok(['reservation' => $reservation->load(['quote', 'aircraft', 'provider', 'legs'])]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['quote_id' => ['required', 'exists:quotes,id']]);
        $quote = Cotizacion::with('flightRequest')->findOrFail($data['quote_id']);

        abort_if($quote->flightRequest->client_id !== $request->user()->id, 403, 'No puedes reservar esta cotizacion.');
        abort_if($quote->status !== 'accepted', 409, 'Primero debes aceptar la cotizacion.');

        $reservation = Reserva::create([
            'client_id' => $request->user()->id,
            'provider_id' => $quote->provider_id,
            'aircraft_id' => $quote->aircraft_id,
            'flight_request_id' => $quote->flight_request_id,
            'quote_id' => $quote->id,
            'reservation_code' => 'PV-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
            'status' => 'pending_payment',
            'total_amount' => $quote->total,
            'currency' => $quote->currency ?? 'USD',
        ]);

        Comision::create([
            'reservation_id' => $reservation->id,
            'provider_id' => $quote->provider_id,
            'platform_fee' => round(((float) $quote->total) * 0.10, 2),
            'provider_amount' => round(((float) $quote->total) * 0.90, 2),
            'status' => 'held',
        ]);

        $quote->flightRequest->update(['status' => 'reserved']);
        $this->writeAudit($request, 'create', 'reservations', 'Reserva creada desde cotizacion aceptada.');

        return $this->ok(['reservation' => $reservation->load(['quote', 'aircraft'])], 201);
    }
}
