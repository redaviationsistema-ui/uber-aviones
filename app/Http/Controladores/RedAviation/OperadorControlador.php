<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
use App\Modelos\Aeronave;
use App\Modelos\DisponibilidadAeronave;
use App\Modelos\Operacion;
use App\Modelos\SolicitudVuelo;
use App\Servicios\RedAviation\VisibilidadServicio;
use Illuminate\Http\Request;

class OperadorControlador extends ControladorBase
{
    public function __construct(private readonly VisibilidadServicio $visibilidadServicio)
    {
    }

    public function dashboard(Request $request)
    {
        $provider = $request->user()->provider;

        return $this->ok([
            'metrics' => [
                'aeronaves' => Aeronave::where('provider_id', $provider?->id)->count(),
                'solicitudes_pendientes' => $provider
                    ? $provider->aircraft()->whereHas('availability')->count()
                    : 0,
            ],
        ]);
    }

    public function storeAircraft(Request $request)
    {
        $data = $request->validate([
            'model' => ['required', 'string', 'max:255'],
            'registration' => ['required', 'string', 'max:50'],
            'capacity' => ['required', 'integer', 'min:1'],
            'base_airport' => ['required', 'string', 'max:20'],
            'range_km' => ['nullable', 'integer', 'min:0'],
            'speed_kmh' => ['nullable', 'integer', 'min:0'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
        ]);

        $aeronave = Aeronave::create($data + [
            'provider_id' => $request->user()->provider->id,
            'status' => 'active',
            'currency' => 'USD',
        ]);

        return $this->ok(['aircraft' => $aeronave], 201);
    }

    public function indexAircraft(Request $request)
    {
        return $this->ok([
            'aircraft' => Aeronave::where('provider_id', $request->user()->provider->id)->latest()->get(),
        ]);
    }

    public function updateAircraft(Request $request, Aeronave $aircraft)
    {
        abort_if($aircraft->provider_id !== $request->user()->provider->id, 403);

        $aircraft->update($request->validate([
            'model' => ['sometimes', 'string', 'max:255'],
            'capacity' => ['sometimes', 'integer', 'min:1'],
            'base_airport' => ['sometimes', 'string', 'max:20'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->ok(['aircraft' => $aircraft->fresh()]);
    }

    public function storeAvailability(Request $request)
    {
        $data = $request->validate([
            'aircraft_id' => ['required', 'exists:aircraft,id'],
            'start_datetime' => ['required', 'date'],
            'end_datetime' => ['required', 'date', 'after:start_datetime'],
            'status' => ['required', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ]);

        $aircraft = Aeronave::findOrFail($data['aircraft_id']);
        abort_if($aircraft->provider_id !== $request->user()->provider->id, 403);

        $availability = DisponibilidadAeronave::create($data);

        return $this->ok(['availability' => $availability], 201);
    }

    public function requests(Request $request)
    {
        $providerId = $request->user()->provider->id;
        $solicitudes = SolicitudVuelo::with(['matches' => fn ($query) => $query->where('provider_id', $providerId), 'matches.aircraft'])
            ->whereHas('matches', fn ($query) => $query->where('provider_id', $providerId))
            ->latest()
            ->get()
            ->map(fn ($solicitud) => $this->visibilidadServicio->solicitudParaOperador($solicitud));

        return $this->ok(['requests' => $solicitudes]);
    }

    public function accept(Request $request, SolicitudVuelo $flightRequest)
    {
        $providerId = $request->user()->provider->id;
        $match = $flightRequest->matches()->where('provider_id', $providerId)->firstOrFail();

        $match->update([
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        $flightRequest->update(['workflow_status' => 'aceptada']);

        $operacion = Operacion::create([
            'flight_request_id' => $flightRequest->id,
            'provider_id' => $providerId,
            'aircraft_id' => $match->aircraft_id,
            'status' => 'confirmada',
        ]);

        $operacion->timeline()->create([
            'status' => 'confirmada',
            'title' => 'Operador asignado',
            'description' => 'La operacion fue aceptada por un operador verificado.',
            'created_by' => $request->user()->id,
        ]);

        $chat = $flightRequest->chatsProtegidos()->first();
        if ($chat && ! $chat->provider_id) {
            $chat->update(['provider_id' => $providerId]);
        }

        return $this->ok(['operation' => $operacion->load('timeline')]);
    }

    public function reject(Request $request, SolicitudVuelo $flightRequest)
    {
        $providerId = $request->user()->provider->id;
        $match = $flightRequest->matches()->where('provider_id', $providerId)->firstOrFail();
        $match->update([
            'status' => 'rejected',
            'rejected_at' => now(),
        ]);

        return $this->ok(['match' => $match->fresh()]);
    }
}
