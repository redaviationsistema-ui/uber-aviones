<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
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
                $solicitud->fresh(['matches.aircraft', 'chatsProtegidos', 'operaciones.timeline'])
            ),
            'chat_id' => $chat->id,
        ], 201);
    }

    public function indexFlightRequests(Request $request)
    {
        $solicitudes = SolicitudVuelo::with(['matches.aircraft', 'chatsProtegidos', 'operaciones.timeline'])
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
                $flightRequest->load(['matches.aircraft', 'chatsProtegidos', 'operaciones.timeline'])
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
}
