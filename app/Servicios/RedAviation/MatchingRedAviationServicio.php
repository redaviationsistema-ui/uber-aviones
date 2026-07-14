<?php

namespace App\Servicios\RedAviation;

use App\Modelos\Aeronave;
use App\Modelos\SolicitudVuelo;
use App\Servicios\Aeronaves\AircraftAvailabilityService;

class MatchingRedAviationServicio
{
    public function __construct(private readonly AircraftAvailabilityService $aircraftAvailabilityService)
    {
    }

    public function ejecutar(SolicitudVuelo $solicitud): void
    {
        $inicio = $solicitud->departure_datetime ?? now();
        $fin = ($solicitud->return_datetime ?? $inicio)->copy()->addHours(4);

        $aeronaves = Aeronave::with('provider')
            ->whereIn('status', ['active', 'trial_active'])
            ->where('capacity', '>=', $solicitud->passengers)
            ->whereHas('provider', fn ($query) => $query->approvedForOperations())
            ->tap(fn ($query) => $this->aircraftAvailabilityService->applyAvailabilityConstraints($query, $inicio, $fin))
            ->limit(10)
            ->get();

        foreach ($aeronaves as $aeronave) {
            $solicitud->matches()->updateOrCreate(
                [
                    'aircraft_id' => $aeronave->id,
                    'provider_id' => $aeronave->provider_id,
                ],
                [
                    'match_score' => max(1, 100 - abs($aeronave->capacity - $solicitud->passengers) * 5),
                    'status' => 'pending',
                    'response_deadline' => now()->addMinutes(30),
                    'visibility_payload' => [
                        'aircraft_model' => $aeronave->model,
                        'capacity' => $aeronave->capacity,
                        'provider_label' => 'Operador verificado Red Aviation',
                    ],
                ]
            );
        }

        $solicitud->update([
            'workflow_status' => $aeronaves->isEmpty() ? 'buscando_operador' : 'operador_asignado',
            'visibility_payload' => [
                'operators_contact_hidden' => true,
                'matched_count' => $aeronaves->count(),
            ],
        ]);
    }
}
