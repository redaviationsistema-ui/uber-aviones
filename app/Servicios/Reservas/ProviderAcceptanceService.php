<?php
namespace App\Servicios\Reservas;
use App\Modelos\{SolicitudVuelo, Operacion};
use Illuminate\Support\Facades\DB;

class ProviderAcceptanceService
{
    public function accept(int $requestId, int $providerId, int $actorId, bool $createOperation): ?Operacion
    {
        return DB::transaction(function () use ($requestId, $providerId, $actorId, $createOperation) {
            $flight = SolicitudVuelo::query()->lockForUpdate()->findOrFail($requestId);
            $match = $flight->matches()->where('provider_id', $providerId)->lockForUpdate()->first();
            abort_unless($match, 403, 'No puedes responder esta solicitud.');
            abort_if($flight->assigned_provider_id && (int) $flight->assigned_provider_id !== $providerId, 409, 'La solicitud ya tiene otro proveedor asignado.');
            abort_if(in_array(strtolower((string) $flight->status), ['cancelled', 'canceled', 'cancelada', 'completed', 'finalizada', 'expired'], true), 409, 'La solicitud ya no permite aceptación.');
            abort_unless(in_array($match->status, ['pending', 'sent_to_provider', 'accepted'], true), 409, 'La propuesta ya no permite aceptación.');
            $operation = Operacion::where('flight_request_id', $flight->id)->lockForUpdate()->first();
            abort_if($operation && (int) $operation->provider_id !== $providerId, 409, 'La operación ya pertenece a otro proveedor.');
            if ($operation) return $operation->load('timeline');

            if ($match->status !== 'accepted') {
                $match->loadMissing('aircraft');
                abort_unless($match->aircraft_id, 409, 'La propuesta no tiene aeronave.');
                $match->update(['status' => 'accepted', 'accepted_at' => now(), 'rejected_at' => null]);
                $flight->update([
                    ...(! $createOperation ? ['status' => 'matched'] : []),
                    'workflow_status' => 'aceptada', 'assigned_provider_id' => $providerId,
                    'assigned_aircraft_id' => $match->aircraft_id, 'assigned_aircraft_model' => $match->aircraft?->model,
                    'visibility_payload' => [
                        ...($flight->visibility_payload ?? []), 'selected_provider_id' => $providerId,
                        'selected_aircraft_id' => $match->aircraft_id, 'aircraft_model' => $match->aircraft?->model,
                        'aircraft_category' => $match->aircraft?->category, 'aircraft_capacity' => $match->aircraft?->capacity,
                    ],
                ]);
            }
            if (! $createOperation) return null;
            $operation = Operacion::create([
                'flight_request_id' => $flight->id, 'provider_id' => $providerId,
                'aircraft_id' => $match->aircraft_id, 'status' => 'confirmada',
            ]);
            $operation->timeline()->create([
                'status' => 'confirmada', 'title' => 'Operador asignado',
                'description' => 'La operacion fue aceptada por un operador verificado.', 'created_by' => $actorId,
            ]);
            $chat = $flight->chatsProtegidos()->first();
            if ($chat && ! $chat->provider_id) $chat->update(['provider_id' => $providerId]);
            return $operation->load('timeline');
        });
    }
}
