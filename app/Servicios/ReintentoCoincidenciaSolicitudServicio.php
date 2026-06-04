<?php

namespace App\Servicios;

use App\Enumeraciones\EstadoAeronave;
use App\Enumeraciones\EstadoDisponibilidad;
use App\Enumeraciones\EstadoProveedor;
use App\Enumeraciones\EstadoSolicitudVuelo;
use App\Enumeraciones\EstadoWorkflowSolicitud;
use App\Modelos\Aeronave;
use App\Modelos\SolicitudVuelo;
use Carbon\Carbon;

class ReintentoCoincidenciaSolicitudServicio
{
    public function manejarRechazo(SolicitudVuelo $flightRequest): array
    {
        $flightRequest->loadMissing('matches');

        $acceptedCount = $flightRequest->matches()
            ->where('status', 'accepted')
            ->count();

        if ($acceptedCount > 0) {
            $flightRequest->update([
                'status' => EstadoSolicitudVuelo::Matched->value,
                'workflow_status' => EstadoWorkflowSolicitud::Aceptada->value,
            ]);

            return [
                'status' => 'already_accepted',
                'created_matches' => 0,
                'pending_matches' => $flightRequest->matches()->where('status', 'pending')->count(),
            ];
        }

        $pendingCount = $flightRequest->matches()
            ->where('status', 'pending')
            ->count();

        if ($pendingCount > 0) {
            $visibilityPayload = $flightRequest->visibility_payload ?? [];
            $flightRequest->update([
                'status' => EstadoSolicitudVuelo::Matched->value,
                'workflow_status' => EstadoWorkflowSolicitud::BuscandoOperador->value,
                'assigned_provider_id' => null,
                'assigned_aircraft_id' => null,
                'assigned_aircraft_model' => null,
                'visibility_payload' => [
                    ...$visibilityPayload,
                    'selected_provider_id' => null,
                    'selected_aircraft_id' => null,
                    'aircraft_model' => null,
                    'aircraft_category' => null,
                    'aircraft_capacity' => null,
                ],
            ]);

            return [
                'status' => 'pending_matches_available',
                'created_matches' => 0,
                'pending_matches' => $pendingCount,
            ];
        }

        $newMatches = $this->buscarNuevasOpciones($flightRequest);

        if ($newMatches > 0) {
            $visibilityPayload = $flightRequest->visibility_payload ?? [];
            $flightRequest->update([
                'status' => EstadoSolicitudVuelo::Matched->value,
                'workflow_status' => EstadoWorkflowSolicitud::BuscandoOperador->value,
                'assigned_provider_id' => null,
                'assigned_aircraft_id' => null,
                'assigned_aircraft_model' => null,
                'visibility_payload' => [
                    ...$visibilityPayload,
                    'selected_provider_id' => null,
                    'selected_aircraft_id' => null,
                    'aircraft_model' => null,
                    'aircraft_category' => null,
                    'aircraft_capacity' => null,
                ],
            ]);

            return [
                'status' => 'rematched',
                'created_matches' => $newMatches,
                'pending_matches' => $flightRequest->matches()->where('status', 'pending')->count(),
            ];
        }

        $visibilityPayload = $flightRequest->visibility_payload ?? [];
        $flightRequest->update([
            'status' => EstadoSolicitudVuelo::Pending->value,
            'workflow_status' => EstadoWorkflowSolicitud::SinOpcionesDisponibles->value,
            'assigned_provider_id' => null,
            'assigned_aircraft_id' => null,
            'assigned_aircraft_model' => null,
            'visibility_payload' => [
                ...$visibilityPayload,
                'selected_provider_id' => null,
                'selected_aircraft_id' => null,
                'aircraft_model' => null,
                'aircraft_category' => null,
                'aircraft_capacity' => null,
            ],
        ]);

        return [
            'status' => 'no_options_available',
            'created_matches' => 0,
            'pending_matches' => 0,
        ];
    }

    private function buscarNuevasOpciones(SolicitudVuelo $flightRequest): int
    {
        $start = $flightRequest->departure_datetime
            ? Carbon::parse($flightRequest->departure_datetime)
            : Carbon::parse($flightRequest->departure_date->format('Y-m-d').' '.$flightRequest->departure_time);
        $end = ($flightRequest->return_datetime ? Carbon::parse($flightRequest->return_datetime) : $start->copy())->addHours(4);

        $existingAircraftIds = $flightRequest->matches()
            ->pluck('aircraft_id')
            ->filter()
            ->values();

        $query = Aeronave::with('provider')
            ->where('status', EstadoAeronave::Active->value)
            ->where('capacity', '>=', $flightRequest->passengers)
            ->whereHas('provider', fn ($scope) => $scope->where('approval_status', EstadoProveedor::Approved->value))
            ->whereDoesntHave('availability', function ($scope) use ($start, $end) {
                $scope->whereIn('status', [
                    EstadoDisponibilidad::Occupied->value,
                    EstadoDisponibilidad::Blocked->value,
                    EstadoDisponibilidad::Maintenance->value,
                ])
                    ->where('start_datetime', '<', $end)
                    ->where('end_datetime', '>', $start);
            });

        if ($flightRequest->origin_airport_id || $flightRequest->origin) {
            $originCode = $flightRequest->resolvedOriginCode();

            $query->where(function ($scope) use ($flightRequest, $originCode) {
                if ($flightRequest->origin_airport_id) {
                    $scope->where('base_airport_id', $flightRequest->origin_airport_id);
                }

                if ($originCode) {
                    $scope->orWhere('base_airport', $originCode);
                }
            });
        }

        if ($existingAircraftIds->isNotEmpty()) {
            $query->whereNotIn('id', $existingAircraftIds);
        }

        $aircraft = $query
            ->limit(10)
            ->get();

        foreach ($aircraft as $item) {
            $score = max(1, 100 - abs($item->capacity - $flightRequest->passengers) * 5);

            $flightRequest->matches()->create([
                'aircraft_id' => $item->id,
                'provider_id' => $item->provider_id,
                'match_score' => $score,
                'status' => 'pending',
            ]);
        }

        return $aircraft->count();
    }
}
