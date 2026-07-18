<?php

namespace App\Servicios\RedAviation;

use App\Events\NewFlightRequestCreated;
use App\Modelos\Notificacion;
use App\Modelos\SolicitudVuelo;
use App\Modelos\Usuario;
use Illuminate\Support\Facades\DB;

class ProviderFlightRequestNotificationService
{
    public function dispatchForFlightRequest(SolicitudVuelo|int $flightRequest): void
    {
        $solicitud = $flightRequest instanceof SolicitudVuelo
            ? $flightRequest->loadMissing(['assignedAircraft', 'matches.aircraft'])
            : $this->loadFlightRequest((int) $flightRequest);

        if (! $solicitud) {
            return;
        }

        $providerIds = collect([$solicitud->assigned_provider_id])
            ->merge($solicitud->matches->pluck('provider_id'))
            ->filter()
            ->map(fn ($providerId) => (int) $providerId)
            ->unique()
            ->values();

        foreach ($providerIds as $providerId) {
            $event = new NewFlightRequestCreated($solicitud, $providerId);
            $payload = $event->broadcastWith();

            event($event);
            $this->createNotificationsForProvider($providerId, $payload);
        }
    }

    private function loadFlightRequest(int $flightRequestId): ?SolicitudVuelo
    {
        if ($flightRequestId <= 0) {
            return null;
        }

        return SolicitudVuelo::query()
            ->select([
                'id',
                'origin',
                'destination',
                'status',
                'aircraft_type',
                'assigned_provider_id',
                'assigned_aircraft_id',
                'assigned_aircraft_model',
                'created_at',
            ])
            ->with([
                'assignedAircraft:id,model',
                'matches' => fn ($query) => $query
                    ->select(['id', 'flight_request_id', 'provider_id', 'aircraft_id', 'status', 'response_deadline'])
                    ->with(['aircraft:id,model']),
            ])
            ->find($flightRequestId);
    }

    private function createNotificationsForProvider(int $providerId, array $payload): void
    {
        $userIds = Usuario::query()
            ->where('provider_id', $providerId)
            ->pluck('id')
            ->all();

        if ($userIds === []) {
            return;
        }

        $timestamp = now();
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $rows = array_map(fn (int $userId) => [
            'user_id' => $userId,
            'provider_id' => $providerId,
            'type' => 'flight.request.created',
            'title' => 'Nueva solicitud de vuelo',
            'message' => ($payload['route'] ?? 'Ruta por confirmar').' · '.($payload['aircraft_name'] ?? 'Aeronave por confirmar'),
            'payload' => $encodedPayload,
            'data' => $encodedPayload,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ], $userIds);

        DB::table((new Notificacion())->getTable())->insert($rows);
    }
}
