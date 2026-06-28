<?php

namespace App\Events;

use App\Modelos\SolicitudVuelo;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewFlightRequestCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $providerId;

    public function __construct(public SolicitudVuelo $request, ?int $providerId = null)
    {
        $this->request->loadMissing(['assignedAircraft', 'matches.aircraft']);
        $this->providerId = (int) ($providerId ?: $this->resolveProviderId());
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('provider.' . $this->providerId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'flight.request.created';
    }

    public function broadcastWith(): array
    {
        $preferredMatch = $this->request->matches
            ->firstWhere('provider_id', $this->providerId)
            ?: $this->request->matches->first();
        $aircraftName = $this->request->assigned_aircraft_model
            ?: $this->request->assignedAircraft?->model
            ?: $preferredMatch?->aircraft?->model
            ?: $this->request->aircraft_type;
        $slaDeadline = $preferredMatch?->response_deadline;

        return [
            'request_id' => $this->request->id,
            'id' => $this->request->id,
            'provider_id' => $this->providerId,
            'route' => trim(($this->request->origin ?: 'N/D').' -> '.($this->request->destination ?: 'N/D')),
            'origin' => $this->request->origin,
            'destination' => $this->request->destination,
            'aircraft_name' => $aircraftName ?: 'Aeronave por confirmar',
            'aircraft' => $aircraftName ?: 'Aeronave por confirmar',
            'status' => $this->request->status,
            'priority' => $this->request->priority ?? $this->request->priority_type ?? 'normal',
            'sla_deadline' => optional($slaDeadline)->toIso8601String(),
            'created_at' => optional($this->request->created_at)->toIso8601String(),
        ];
    }

    private function resolveProviderId(): int
    {
        if ($this->request->assigned_provider_id) {
            return (int) $this->request->assigned_provider_id;
        }

        $matchProviderId = $this->request->matches->first()?->provider_id;

        return (int) ($matchProviderId ?: 0);
    }
}