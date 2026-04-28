<?php

namespace App\Servicios\RedAviation;

use App\Modelos\Operacion;
use App\Modelos\SolicitudVuelo;
use App\Modelos\Usuario;

class VisibilidadServicio
{
    public function solicitudParaCliente(SolicitudVuelo $solicitud): array
    {
        return [
            'id' => $solicitud->id,
            'origin' => $solicitud->origin,
            'destination' => $solicitud->destination,
            'departure_datetime' => $solicitud->departure_datetime,
            'passengers' => $solicitud->passengers,
            'aircraft_type' => $solicitud->aircraft_type,
            'status' => $solicitud->workflow_status ?? $solicitud->status,
            'matched_options' => $solicitud->matches->map(fn ($match) => [
                'id' => $match->id,
                'aircraft_id' => $match->aircraft_id,
                'status' => $match->status,
                'aircraft' => [
                    'model' => $match->aircraft?->model,
                    'capacity' => $match->aircraft?->capacity,
                    'category' => $solicitud->aircraft_type,
                ],
            ])->values(),
        ];
    }

    public function solicitudParaOperador(SolicitudVuelo $solicitud): array
    {
        return [
            'id' => $solicitud->id,
            'origin' => $solicitud->origin,
            'destination' => $solicitud->destination,
            'departure_datetime' => $solicitud->departure_datetime,
            'passengers' => $solicitud->passengers,
            'aircraft_type' => $solicitud->aircraft_type,
            'requirements' => $solicitud->requirements,
            'status' => $solicitud->workflow_status ?? $solicitud->status,
            'client' => [
                'display_name' => 'Cliente Red Aviation #'.$solicitud->client_id,
            ],
        ];
    }

    public function operacionParaSobrecargo(Operacion $operacion): array
    {
        $solicitud = $operacion->solicitudVuelo;

        return [
            'id' => $operacion->id,
            'status' => $operacion->status,
            'briefing' => [
                'origen' => $solicitud?->origin,
                'destino' => $solicitud?->destination,
                'salida' => $solicitud?->departure_datetime,
                'pasajeros_autorizados' => $solicitud?->passengers,
            ],
            'timeline' => $operacion->timeline()->latest()->get(),
        ];
    }

    public function usuarioVisible(Usuario $usuario): array
    {
        return [
            'id' => $usuario->id,
            'name' => $usuario->name,
            'role' => $usuario->effectiveRole(),
            'status' => $usuario->status,
            'contact_strikes' => $usuario->contact_strikes,
        ];
    }
}
