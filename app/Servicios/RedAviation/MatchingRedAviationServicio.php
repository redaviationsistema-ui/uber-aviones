<?php

namespace App\Servicios\RedAviation;

use App\Modelos\Aeronave;
use App\Modelos\SolicitudVuelo;
use App\Servicios\Aeronaves\AircraftEligibilityService;
use App\Servicios\Vuelos\FlightRouteService;

class MatchingRedAviationServicio
{
    public function __construct(
        private readonly AircraftEligibilityService $aircraftEligibilityService,
        private readonly FlightRouteService $flightRouteService,
    ) {}

    public function ejecutar(SolicitudVuelo $solicitud): void
    {
        $inicio = $solicitud->departure_datetime ?? now();
        $fin = ($solicitud->return_datetime ?? $inicio)->copy()->addHours(4);
        $route = $this->flightRouteService->buildCanonicalRoute([
            'origin' => $solicitud->origin,
            'destination' => $solicitud->destination,
            'departure_datetime' => optional($solicitud->departure_datetime)->toDateTimeString(),
            'return_datetime' => optional($solicitud->return_datetime)->toDateTimeString(),
            'trip_type' => $solicitud->trip_type,
            'requirements' => is_array($solicitud->requirements) ? $solicitud->requirements : [],
        ]);

        $aeronaves = Aeronave::with(['provider', 'documents', 'availability', 'availabilityBlocks', 'baseAirport'])
            ->where('hourly_rate', '>', 0)
            ->get()
            ->filter(fn (Aeronave $aircraft) => $this->aircraftEligibilityService->evaluate($aircraft, [
                'route' => $route,
                'passengers' => (int) $solicitud->passengers,
                'trip_type' => $route['trip_type'],
                'preference' => $solicitud->aircraft_type,
                'requested_start' => $inicio,
                'requested_end' => $fin,
                'flight_request_id' => $solicitud->id,
            ])['eligible'])
            ->sortBy(fn (Aeronave $aircraft) => abs((int) $aircraft->capacity - (int) $solicitud->passengers))
            ->take(10);

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
