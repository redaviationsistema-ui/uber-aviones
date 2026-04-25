<?php

namespace App\Http\Controladores;

use App\Modelos\SolicitudVuelo;
use App\Modelos\Comision;
use App\Modelos\Pago;
use App\Modelos\Reserva;
use Illuminate\Http\Request;

class ProveedorControlador extends ControladorBase
{
    public function dashboard(Request $request)
    {
        $provider = $request->user()->provider;
        abort_if(! $provider, 404, 'Proveedor no encontrado.');

        return $this->ok([
            'provider' => $provider,
            'metrics' => [
                'aircraft' => $provider->aircraft()->count(),
                'active_aircraft' => $provider->aircraft()->where('status', 'active')->count(),
                'pending_quotes' => $provider->quotes()->where('status', 'pending')->count(),
                'reservations' => $provider->reservations()->count(),
            ],
        ]);
    }

    public function requests(Request $request)
    {
        $provider = $request->user()->provider;
        abort_if(! $provider, 404, 'Proveedor no encontrado.');

        $requests = SolicitudVuelo::whereHas('matches', fn ($query) => $query->where('provider_id', $provider->id))
            ->with(['matches' => fn ($query) => $query->where('provider_id', $provider->id), 'client'])
            ->latest()
            ->paginate(20);

        return $this->ok(['flight_requests' => $requests]);
    }

    public function showRequest(Request $request, SolicitudVuelo $flightRequest)
    {
        $provider = $request->user()->provider;
        abort_if(! $provider || ! $flightRequest->matches()->where('provider_id', $provider->id)->exists(), 403);

        return $this->ok(['flight_request' => $flightRequest->load(['matches.aircraft', 'quotes'])]);
    }

    public function acceptRequest(Request $request, SolicitudVuelo $flightRequest)
    {
        $provider = $request->user()->provider;
        abort_if(! $provider, 404);

        $flightRequest->matches()->where('provider_id', $provider->id)->update(['status' => 'accepted']);

        return $this->ok(['message' => 'Solicitud aceptada para cotizar.']);
    }

    public function rejectRequest(Request $request, SolicitudVuelo $flightRequest)
    {
        $provider = $request->user()->provider;
        abort_if(! $provider, 404);

        $flightRequest->matches()->where('provider_id', $provider->id)->update(['status' => 'rejected']);

        return $this->ok(['message' => 'Solicitud rechazada.']);
    }

    public function payments(Request $request)
    {
        $provider = $request->user()->provider;
        abort_if(! $provider, 404);

        return $this->ok([
            'payments' => Pago::whereHas('reservation', fn ($query) => $query->where('provider_id', $provider->id))->paginate(20),
        ]);
    }

    public function commissions(Request $request)
    {
        $provider = $request->user()->provider;
        abort_if(! $provider, 404);

        return $this->ok(['commissions' => Comision::where('provider_id', $provider->id)->paginate(20)]);
    }
}
