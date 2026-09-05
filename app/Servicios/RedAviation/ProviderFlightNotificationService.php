<?php

namespace App\Servicios\RedAviation;

use App\Events\NewFlightRequestCreated;
use App\Events\FlightConfirmed;
use App\Modelos\Notificacion;
use App\Modelos\Proveedor;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use App\Modelos\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProviderFlightNotificationService
{
    public const TYPES = ['flight.request.created', 'flight.confirmed'];

    public function notifyRequestCreated(SolicitudVuelo|int $request): void
    {
        $flight = SolicitudVuelo::with(['matches.aircraft', 'assignedAircraft', 'legs', 'reservation'])
            ->find($request instanceof SolicitudVuelo ? $request->id : $request);
        if (! $flight) {
            return;
        }
        $providerIds = $flight->matches
            ->whereIn('status', ['pending', 'sent_to_provider'])
            ->pluck('provider_id');
        if ($flight->assigned_provider_id && ! $flight->matches->contains(fn ($match) =>
            (int) $match->provider_id === (int) $flight->assigned_provider_id && $match->status === 'rejected')) {
            $providerIds->push($flight->assigned_provider_id);
        }
        foreach ($providerIds->filter()->unique() as $providerId) {
            $payload = (new NewFlightRequestCreated($flight, (int) $providerId))->broadcastWith();
            $this->persist($flight, (int) $providerId, 'flight.request.created', $payload);
        }
    }

    /** All confirmed payment paths call this inside their payment transaction. */
    public function updateConfirmedPayment(SolicitudVuelo $flight, array $attributes, ?Reserva $reservation = null): void
    {
        DB::transaction(function () use ($flight, $attributes, $reservation) {
            $locked = SolicitudVuelo::query()->lockForUpdate()->findOrFail($flight->id);
            $wasPaid = strtolower((string) $locked->payment_status) === 'paid';
            $locked->update($attributes);
            if (! $wasPaid && $locked->payment_status === 'paid'
                && in_array($locked->workflow_status, ['vuelo confirmado', 'flight_confirmed'], true)) {
                $locked->load(['reservation', 'matches', 'assignedAircraft', 'legs']);
                $reservation ??= $locked->reservation;
                $acceptedIds = $locked->matches->where('status', 'accepted')->pluck('provider_id')->unique();
                $rejectedIds = $locked->matches->where('status', 'rejected')->pluck('provider_id')->diff($acceptedIds);
                $candidates = collect([$locked->assigned_provider_id, $reservation?->provider_id,
                    $acceptedIds->count() === 1 ? $acceptedIds->first() : null]);
                $providerId = (int) $candidates->filter()->reject(fn ($id) => $rejectedIds->contains($id))->first();
                if (! $providerId) {
                    throw new \LogicException('El vuelo pagado no tiene un proveedor definitivo.');
                }
                // The assignment is canonical; a reservation is the fallback, never historical matches.
                if ($reservation?->provider_id && (int) $reservation->provider_id !== $providerId) {
                    Log::warning('Flight notification provider differs from reservation', [
                        'flight_request_id' => $locked->id, 'provider_id' => $providerId,
                        'reservation_provider_id' => $reservation->provider_id,
                    ]);
                }
                $this->persist($locked, $providerId, 'flight.confirmed', [], $reservation);
            }
            $flight->refresh();
        });
    }

    private function persist(SolicitudVuelo $flight, int $providerId, string $type, array $payload = [], ?Reserva $reservation = null): void
    {
        DB::transaction(function () use ($flight, $providerId, $type, $payload, $reservation) {
            $suffix = $type === 'flight.confirmed' ? 'flight-confirmed' : 'request-created';
            $key = "provider:{$providerId}:flight:{$flight->id}:{$suffix}";
            $ownerId = Proveedor::whereKey($providerId)->value('user_id')
                ?: Usuario::where('provider_id', $providerId)->value('id');
            // user_id is nullable; a shared provider alert survives missing/deleted users.
            $occurredAt = now()->toIso8601String();
            $legs = $flight->legs->sortBy('leg_order')->map(fn ($leg) => [
                'origin' => $leg->origin, 'destination' => $leg->destination,
                'departure_datetime' => $leg->departure_datetime?->toIso8601String(),
            ])->values()->all();
            $stops = [$flight->origin];
            foreach ($legs as $leg) {
                foreach ([$leg['origin'], $leg['destination']] as $stop) {
                    if ($stop && end($stops) !== $stop) {
                        $stops[] = $stop;
                    }
                }
            }
            if (! $legs) {
                $stops[] = $flight->destination;
            }
            $payload = array_merge($payload, [
                'event_key' => $key, 'idempotency_key' => $key, 'type' => $type,
                'notification_scope' => 'provider', 'flight_request_id' => $flight->id,
                'request_id' => $flight->id, 'provider_id' => $providerId,
                'reservation_id' => ($reservation ?? $flight->reservation)?->id,
                'aircraft_id' => (int) $flight->assigned_provider_id === $providerId
                    ? $flight->assigned_aircraft_id
                    : ((int) $reservation?->provider_id === $providerId
                        ? $reservation->aircraft_id
                        : $flight->matches->first(fn ($match) => (int) $match->provider_id === $providerId && $match->status === 'accepted')?->aircraft_id),
                'workflow_status' => $flight->workflow_status, 'payment_status' => $flight->payment_status,
                'departure_at' => $flight->departure_datetime?->toIso8601String(),
                'route' => implode(' → ', array_filter($stops)), 'legs' => $legs,
                'occurred_at' => $occurredAt, 'created_at' => $occurredAt,
                'destination_section' => 'solicitudes',
            ]);
            $title = $type === 'flight.confirmed' ? 'Vuelo confirmado' : 'Nueva solicitud de vuelo';
            $message = $type === 'flight.confirmed'
                ? 'El pago fue confirmado y el vuelo está listo para continuar con la preparación operacional.'
                : $payload['route'].' · '.($payload['aircraft_name'] ?? 'Aeronave por confirmar');
            // createOrFirst uses the existing unique index and a savepoint for concurrent deliveries.
            $notification = Notificacion::query()->createOrFirst(['idempotency_key' => $key], [
                'user_id' => $ownerId, 'provider_id' => $providerId, 'type' => $type,
                'title' => $title, 'message' => $message, 'payload' => $payload, 'data' => $payload,
            ]);
            if (! $notification->wasRecentlyCreated) {
                return;
            }
            $payload = array_merge($payload, ['notification_id' => $notification->id, 'title' => $title, 'message' => $message]);
            $notification->update(['payload' => $payload, 'data' => $payload]);
            DB::afterCommit(function () use ($type, $flight, $providerId, $payload) {
                try {
                    event($type === 'flight.confirmed'
                        ? new FlightConfirmed($providerId, $payload)
                        : new NewFlightRequestCreated($flight, $providerId, $payload));
                } catch (Throwable $error) {
                    // Payment and notification remain committed; HTTP polling recovers the alert.
                    report($error);
                }
            });
        });
    }
}
