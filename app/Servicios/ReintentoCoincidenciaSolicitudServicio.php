<?php

namespace App\Servicios;

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
                'status' => 'matched',
                'workflow_status' => 'aceptada',
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
            $flightRequest->update([
                'status' => 'matched',
                'workflow_status' => 'buscando_operador',
                'assigned_provider_id' => null,
                'assigned_aircraft_id' => null,
            ]);

            return [
                'status' => 'pending_matches_available',
                'created_matches' => 0,
                'pending_matches' => $pendingCount,
            ];
        }

        $newMatches = $this->buscarNuevasOpciones($flightRequest);

        if ($newMatches > 0) {
            $flightRequest->update([
                'status' => 'matched',
                'workflow_status' => 'buscando_operador',
                'assigned_provider_id' => null,
                'assigned_aircraft_id' => null,
            ]);

            return [
                'status' => 'rematched',
                'created_matches' => $newMatches,
                'pending_matches' => $flightRequest->matches()->where('status', 'pending')->count(),
            ];
        }

        $flightRequest->update([
            'status' => 'unmatched',
            'workflow_status' => 'sin_opciones_disponibles',
            'assigned_provider_id' => null,
            'assigned_aircraft_id' => null,
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
            ->where('status', 'active')
            ->where('capacity', '>=', $flightRequest->passengers)
            ->whereHas('provider', fn ($scope) => $scope->where('approval_status', 'approved'))
            ->whereDoesntHave('availability', function ($scope) use ($start, $end) {
                $scope->whereIn('status', ['occupied', 'blocked', 'maintenance'])
                    ->where('start_datetime', '<', $end)
                    ->where('end_datetime', '>', $start);
            });

        if ($flightRequest->origin) {
            $query->where('base_airport', $flightRequest->origin);
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
