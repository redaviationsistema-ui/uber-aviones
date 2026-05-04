<?php

namespace App\Http\Controladores;

use App\Modelos\SolicitudVuelo;
use App\Modelos\Comision;
use App\Modelos\Pago;
use App\Modelos\Reserva;
use App\Servicios\ReintentoCoincidenciaSolicitudServicio;
use Illuminate\Http\Request;

class ProveedorControlador extends ControladorBase
{
    public function __construct(private readonly ReintentoCoincidenciaSolicitudServicio $reintentoServicio)
    {
    }

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
        $providerId = $request->user()->provider_id;
        abort_if(! $providerId, 404, 'Proveedor no encontrado.');

        $requests = SolicitudVuelo::whereHas('matches', fn ($query) => $query->where('provider_id', $providerId))
            ->with(['matches' => fn ($query) => $query->where('provider_id', $providerId), 'client'])
            ->latest()
            ->paginate(20);

        return $this->ok(['flight_requests' => $requests]);
    }

    public function showRequest(Request $request, SolicitudVuelo $flightRequest)
    {
        $providerId = $request->user()->provider_id;
        abort_if(! $providerId || ! $flightRequest->matches()->where('provider_id', $providerId)->exists(), 403);

        return $this->ok(['flight_request' => $flightRequest->load(['matches.aircraft', 'quotes'])]);
    }

    public function acceptRequest(Request $request, SolicitudVuelo $flightRequest)
    {
        $providerId = $request->user()->provider_id;
        abort_if(! $providerId, 404);

        $flightRequest->matches()->where('provider_id', $providerId)->update([
            'status' => 'accepted',
            'accepted_at' => now(),
            'rejected_at' => null,
        ]);
        $flightRequest->update([
            'status' => 'matched',
            'workflow_status' => 'aceptada',
        ]);

        return $this->ok(['message' => 'Solicitud aceptada para cotizar.']);
    }

    public function rejectRequest(Request $request, SolicitudVuelo $flightRequest)
    {
        $providerId = $request->user()->provider_id;
        abort_if(! $providerId, 404);

        $flightRequest->matches()->where('provider_id', $providerId)->update([
            'status' => 'rejected',
            'rejected_at' => now(),
        ]);
        $retry = $this->reintentoServicio->manejarRechazo($flightRequest);

        return $this->ok([
            'message' => 'Solicitud rechazada.',
            'retry' => $retry,
        ]);
    }

    public function payments(Request $request)
    {
        $providerId = $request->user()->provider_id;
        abort_if(! $providerId, 404);

        return $this->ok([
            'payments' => Pago::whereHas('reservation', fn ($query) => $query->where('provider_id', $providerId))->paginate(20),
        ]);
    }

    public function commissions(Request $request)
    {
        $providerId = $request->user()->provider_id;
        abort_if(! $providerId, 404);

        return $this->ok(['commissions' => Comision::where('provider_id', $providerId)->paginate(20)]);
    }
}
