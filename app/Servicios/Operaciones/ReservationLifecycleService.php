<?php

namespace App\Servicios\Operaciones;

use App\Modelos\AircraftAvailabilityBlock;
use App\Modelos\Aeronave;
use App\Modelos\AsignacionSobrecargo;
use App\Modelos\ContratoReserva;
use App\Modelos\Notificacion;
use App\Modelos\Operacion;
use App\Modelos\Pago;
use App\Modelos\RegistroAuditoria;
use App\Modelos\Reserva;
use App\Modelos\SolicitudVuelo;
use App\Modelos\TramoReserva;
use App\Modelos\TramoSolicitudVuelo;
use App\Modelos\Usuario;
use App\Servicios\Aeronaves\AircraftAvailabilityService;
use App\Servicios\Billing\FlightMembershipService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ReservationLifecycleService
{
    public function __construct(
        private readonly AircraftAvailabilityService $aircraftAvailabilityService,
        private readonly FlightMembershipService $flightMembershipService,
    )
    {
    }

    public function rescheduleReservation(Reserva $reservation, array $data, ?Usuario $actor = null): Reserva
    {
        $reservation->loadMissing([
            'aircraft',
            'flightRequest.legs',
            'legs',
            'latestPayment',
            'contract',
        ]);

        $flightRequest = $reservation->flightRequest;
        if (! $flightRequest) {
            throw new RuntimeException('La reserva no tiene una solicitud de vuelo asociada para reprogramarse.');
        }

        $oldSnapshot = $this->reservationSnapshot($reservation);
        $newAircraftId = (int) ($data['aircraft_id'] ?? $reservation->aircraft_id ?? $flightRequest->assigned_aircraft_id);
        $newProviderId = (int) ($data['provider_id'] ?? $reservation->provider_id ?? $flightRequest->assigned_provider_id);
        $normalizedPayload = $this->normalizeSchedulePayload($reservation, $data);
        [$requestedStart, $requestedEnd] = $this->aircraftAvailabilityService->resolveWindowFromPayload($normalizedPayload);

        $this->aircraftAvailabilityService->ensureAircraftAvailable($newAircraftId, $requestedStart, $requestedEnd, (int) $reservation->id);

        $operation = $flightRequest->operaciones()->latest('id')->first();
        if ($operation?->sobrecargo_user_id && $this->crewHasConflict((int) $operation->sobrecargo_user_id, $requestedStart, $requestedEnd, (int) $operation->id)) {
            throw new RuntimeException('El concierge asignado ya tiene un conflicto operativo en ese horario.');
        }

        DB::transaction(function () use ($reservation, $flightRequest, $operation, $data, $normalizedPayload, $newAircraftId, $newProviderId, $requestedStart, $requestedEnd, $actor, $oldSnapshot) {
            $flightRequest->update([
                'assigned_provider_id' => $newProviderId > 0 ? $newProviderId : $flightRequest->assigned_provider_id,
                'assigned_aircraft_id' => $newAircraftId > 0 ? $newAircraftId : $flightRequest->assigned_aircraft_id,
                'assigned_aircraft_model' => $data['assigned_aircraft_model'] ?? $data['aircraft_model'] ?? $flightRequest->assigned_aircraft_model,
                'origin' => $normalizedPayload['origin'],
                'destination' => $normalizedPayload['destination'],
                'departure_datetime' => $normalizedPayload['departure_datetime'],
                'return_datetime' => $normalizedPayload['return_datetime'],
                'departure_date' => optional($requestedStart)->toDateString(),
                'departure_time' => optional($requestedStart)->format('H:i'),
                'return_date' => optional($requestedEnd)->toDateString(),
                'return_time' => optional($requestedEnd)->format('H:i'),
                'updated_at' => now(),
            ]);

            $reservation->update([
                'provider_id' => $newProviderId > 0 ? $newProviderId : $reservation->provider_id,
                'aircraft_id' => $newAircraftId > 0 ? $newAircraftId : $reservation->aircraft_id,
                'updated_at' => now(),
            ]);

            $this->syncFlightRequestLegs($flightRequest, $normalizedPayload['legs']);
            $this->syncReservationLegs($reservation, $normalizedPayload['legs']);

            if ($operation) {
                $operation->update([
                    'provider_id' => $newProviderId > 0 ? $newProviderId : $operation->provider_id,
                    'aircraft_id' => $newAircraftId > 0 ? $newAircraftId : $operation->aircraft_id,
                    'updated_at' => now(),
                ]);

                $operation->timeline()->create([
                    'status' => 'rescheduled',
                    'title' => 'Reserva reprogramada',
                    'description' => 'Se actualizo aeronave/horario/ruta de la reserva.',
                    'created_by' => $actor?->id,
                ]);
            }

            $isPaidReservation = Str::lower(trim((string) ($flightRequest->payment_status ?: $reservation->latestPayment?->status ?: ''))) === 'paid';
            if ($isPaidReservation) {
                $this->aircraftAvailabilityService->blockAircraftForPaidReservation($reservation->fresh(['flightRequest.legs', 'legs']));
            } else {
                $this->aircraftAvailabilityService->releaseReservationBlock($reservation->fresh(['flightRequest', 'latestPayment']), 'Bloqueo anterior liberado por reprogramacion.');
            }

            $newSnapshot = $this->reservationSnapshot($reservation->fresh(['flightRequest', 'legs', 'aircraft', 'provider']));
            $this->writeAudit($actor, 'reservation_rescheduled', 'operations_history', 'Reserva reprogramada.', $oldSnapshot, $newSnapshot);
            $this->notifyAdmins(
                'reservation_rescheduled',
                'Vuelo reprogramado',
                sprintf('La reserva %s fue reprogramada.', $reservation->reservation_code ?: '#'.$reservation->id),
                [
                    'reservation_id' => $reservation->id,
                    'flight_request_id' => $reservation->flight_request_id,
                    'old' => $oldSnapshot,
                    'new' => $newSnapshot,
                ],
                $reservation->provider_id,
            );
        });

        return $reservation->fresh(['aircraft', 'provider', 'flightRequest', 'legs', 'latestPayment', 'contract']);
    }

    public function cancelReservation(Reserva $reservation, string $reason = '', ?Usuario $actor = null): Reserva
    {
        $reservation->loadMissing(['flightRequest', 'latestPayment', 'contract', 'aircraft', 'provider']);

        $flightRequest = $reservation->flightRequest;
        $oldSnapshot = $this->reservationSnapshot($reservation);
        $cancellationReason = trim($reason) !== '' ? trim($reason) : 'Cancelada por administracion.';

        DB::transaction(function () use ($reservation, $flightRequest, $actor, $cancellationReason, $oldSnapshot) {
            $reservation->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $cancellationReason,
            ]);

            if ($flightRequest) {
                $flightRequest->update([
                    'status' => 'cancelled',
                    'workflow_status' => 'cancelada',
                    'updated_at' => now(),
                ]);
            }

            $this->aircraftAvailabilityService->releaseReservationBlock(
                $reservation->fresh(['flightRequest', 'latestPayment']),
                'Bloqueo liberado por cancelacion de la reserva.'
            );

            $this->flightMembershipService->reverseBenefitsForReservation($reservation, $cancellationReason);

            $operation = $flightRequest?->operaciones()->latest('id')->first();
            if ($operation) {
                $previousCrewId = $operation->sobrecargo_user_id;
                $operation->update([
                    'status' => 'cancelled',
                    'sobrecargo_user_id' => null,
                    'crew_status' => null,
                    'crew_confirmed_at' => null,
                    'crew_notes' => $cancellationReason,
                    'updated_at' => now(),
                ]);

                AsignacionSobrecargo::query()
                    ->where('operation_id', $operation->id)
                    ->update(['status' => 'cancelled']);

                $operation->timeline()->create([
                    'status' => 'cancelled',
                    'title' => 'Reserva cancelada',
                    'description' => $cancellationReason,
                    'created_by' => $actor?->id,
                ]);

                if ($previousCrewId) {
                    $crew = Usuario::query()->find($previousCrewId);
                    if ($crew) {
                        $this->notifyUser(
                            $crew,
                            'reservation_cancelled',
                            'Vuelo cancelado',
                            'La operacion asignada fue cancelada y tu agenda fue liberada.',
                            [
                                'reservation_id' => $reservation->id,
                                'operation_id' => $operation->id,
                            ],
                            $reservation->provider_id,
                        );
                    }
                }
            }

            $newSnapshot = $this->reservationSnapshot($reservation->fresh(['flightRequest', 'aircraft', 'provider']));
            $this->writeAudit($actor, 'reservation_cancelled', 'operations_history', 'Reserva cancelada.', $oldSnapshot, $newSnapshot + ['reason' => $cancellationReason]);
            $this->notifyAdmins(
                'reservation_cancelled',
                'Reserva cancelada',
                sprintf('La reserva %s fue cancelada.', $reservation->reservation_code ?: '#'.$reservation->id),
                [
                    'reservation_id' => $reservation->id,
                    'flight_request_id' => $reservation->flight_request_id,
                    'reason' => $cancellationReason,
                ],
                $reservation->provider_id,
            );
        });

        return $reservation->fresh(['aircraft', 'provider', 'flightRequest', 'latestPayment', 'contract']);
    }

    public function createManualAircraftBlock(Aeronave $aircraft, array $data, ?Usuario $actor = null): AircraftAvailabilityBlock
    {
        $block = DB::transaction(function () use ($aircraft, $data) {
            return $this->aircraftAvailabilityService->createManualBlock($aircraft, $data);
        });

        $this->writeAudit(
            $actor,
            'aircraft_block_created',
            'operations_history',
            'Bloqueo manual de aeronave creado.',
            null,
            [
                'block_id' => $block->id,
                'aircraft_id' => $aircraft->id,
                'block_type' => $block->block_type,
                'start_datetime' => optional($block->start_datetime)?->toIso8601String(),
                'end_datetime' => optional($block->end_datetime)?->toIso8601String(),
                'reason' => $block->reason,
            ],
        );
        $this->notifyAdmins(
            'aircraft_block_created',
            'Bloqueo manual creado',
            sprintf('Se bloqueo manualmente la aeronave %s.', trim((string) ($aircraft->registration ?: $aircraft->model))),
            [
                'block_id' => $block->id,
                'aircraft_id' => $aircraft->id,
                'block_type' => $block->block_type,
            ],
            $aircraft->provider_id,
        );

        return $block->fresh(['aircraft.provider']);
    }

    public function releaseAircraftBlock(AircraftAvailabilityBlock $block, string $reason = '', ?Usuario $actor = null): AircraftAvailabilityBlock
    {
        $released = $this->aircraftAvailabilityService->releaseBlock(
            $block,
            trim($reason) !== '' ? trim($reason) : 'Bloqueo liberado por administracion.'
        );

        $this->writeAudit(
            $actor,
            'aircraft_block_released',
            'operations_history',
            'Bloqueo de aeronave liberado.',
            [
                'block_id' => $block->id,
                'status' => $block->status,
            ],
            [
                'block_id' => $released->id,
                'status' => $released->status,
                'reason' => $released->reason,
            ],
        );

        return $released;
    }

    public function crewHasConflict(int $crewUserId, Carbon $requestedStart, Carbon $requestedEnd, ?int $ignoreOperationId = null): bool
    {
        return Operacion::query()
            ->where('sobrecargo_user_id', $crewUserId)
            ->when($ignoreOperationId, fn ($query) => $query->whereKeyNot($ignoreOperationId))
            ->whereNotIn('status', ['cancelled', 'completed', 'finalizada', 'cancelada'])
            ->whereHas('solicitudVuelo', function ($query) use ($requestedStart, $requestedEnd) {
                $query->where(function ($inner) use ($requestedStart, $requestedEnd) {
                    $inner->where('departure_datetime', '<', $requestedEnd)
                        ->where(function ($window) use ($requestedStart) {
                            $window->where('return_datetime', '>', $requestedStart)
                                ->orWhere(function ($fallback) use ($requestedStart) {
                                    $fallback->whereNull('return_datetime')
                                        ->where('departure_datetime', '>', $requestedStart->copy()->subHours(4));
                                });
                        });
                });
            })
            ->exists();
    }

    public function operationsDashboard(?int $providerId = null): array
    {
        $todayStart = now()->startOfDay();
        $todayEnd = now()->endOfDay();

        $aircraftQuery = Aeronave::query()->when($providerId, fn ($query) => $query->where('provider_id', $providerId));
        $totalAircraft = (clone $aircraftQuery)->count();

        $activeBlocks = AircraftAvailabilityBlock::query()
            ->whereIn('status', ['active', 'booked'])
            ->where('start_datetime', '<', $todayEnd)
            ->where('end_datetime', '>', $todayStart)
            ->whereHas('aircraft', fn ($query) => $query->when($providerId, fn ($inner) => $inner->where('provider_id', $providerId)))
            ->get();

        $occupiedAircraft = $activeBlocks->pluck('aircraft_id')->unique()->count();
        $maintenanceAircraft = $activeBlocks
            ->filter(fn (AircraftAvailabilityBlock $block) => in_array(Str::lower((string) $block->block_type), ['maintenance', 'inspection', 'out_of_service'], true))
            ->pluck('aircraft_id')
            ->unique()
            ->count();

        $pendingPayments = SolicitudVuelo::query()
            ->when($providerId, fn ($query) => $query->where('assigned_provider_id', $providerId))
            ->whereIn('payment_status', ['pending', 'processing', 'pending_manual_payment', 'pending_manual_validation'])
            ->count();

        $pendingContracts = ContratoReserva::query()
            ->whereHas('reservation', fn ($query) => $query->when($providerId, fn ($inner) => $inner->where('provider_id', $providerId)))
            ->whereNotIn('status', ['signed', 'completed'])
            ->count();

        $upcomingFlights = AircraftAvailabilityBlock::query()
            ->with(['aircraft.provider', 'reservation.client'])
            ->whereIn('status', ['active', 'booked'])
            ->where('start_datetime', '>=', now())
            ->whereHas('aircraft', fn ($query) => $query->when($providerId, fn ($inner) => $inner->where('provider_id', $providerId)))
            ->orderBy('start_datetime')
            ->limit(8)
            ->get()
            ->map(fn (AircraftAvailabilityBlock $block) => [
                'block_id' => $block->id,
                'aircraft_id' => $block->aircraft_id,
                'aircraft_name' => trim((string) (($block->aircraft?->registration ? $block->aircraft->registration.' · ' : '').($block->aircraft?->model ?? ''))),
                'company_name' => $block->aircraft?->provider?->commercial_name ?: $block->aircraft?->provider?->company_name,
                'client_name' => $block->reservation?->client?->name,
                'start' => optional($block->start_datetime)?->toIso8601String(),
                'end' => optional($block->end_datetime)?->toIso8601String(),
                'status' => $block->block_type,
                'reason' => $block->reason,
            ])
            ->values();

        $alerts = collect();
        foreach ($activeBlocks->groupBy('aircraft_id') as $aircraftId => $blocks) {
            $sorted = $blocks->sortBy('start_datetime')->values();
            for ($index = 1; $index < $sorted->count(); $index++) {
                $previous = $sorted[$index - 1];
                $current = $sorted[$index];
                if ($current->start_datetime < $previous->end_datetime) {
                    $alerts->push([
                        'type' => 'availability_conflict',
                        'title' => 'Conflicto de disponibilidad detectado',
                        'message' => sprintf('La aeronave %s tiene bloques solapados.', $aircraftId),
                        'aircraft_id' => $aircraftId,
                    ]);
                    break;
                }
            }
        }

        return [
            'flights_today' => $activeBlocks->count(),
            'aircraft_available' => max($totalAircraft - $occupiedAircraft, 0),
            'aircraft_occupied' => $occupiedAircraft,
            'aircraft_maintenance' => $maintenanceAircraft,
            'payments_pending' => $pendingPayments,
            'contracts_pending' => $pendingContracts,
            'upcoming_flights' => $upcomingFlights,
            'operational_alerts' => $alerts->values()->all(),
        ];
    }

    public function operationsHistory(int $perPage = 30)
    {
        return RegistroAuditoria::query()
            ->with('user:id,name,email')
            ->whereIn('module', ['operations_history', 'reservations', 'reservation_contracts', 'payments'])
            ->latest('id')
            ->paginate($perPage);
    }

    public function adminNotifications(int $perPage = 20)
    {
        return Notificacion::query()
            ->where(function ($query) {
                $query->whereNotNull('provider_id')
                    ->orWhereIn('type', [
                        'reservation_paid',
                        'availability_conflict',
                        'reservation_rescheduled',
                        'reservation_cancelled',
                        'flight_completed',
                        'aircraft_block_created',
                    ]);
            })
            ->latest('id')
            ->paginate($perPage);
    }

    private function normalizeSchedulePayload(Reserva $reservation, array $data): array
    {
        $flightRequest = $reservation->flightRequest;
        $hasExplicitLegs = is_array($data['legs'] ?? null) && count($data['legs']) > 0;
        $hasTopLevelOverrides = collect(['origin', 'destination', 'departure_datetime', 'return_datetime'])
            ->contains(fn ($field) => array_key_exists($field, $data) && filled($data[$field]));

        $legs = $hasExplicitLegs
            ? $data['legs']
            : collect($hasTopLevelOverrides ? [] : ($reservation->legs->count() ? $reservation->legs : $flightRequest?->legs))->map(function ($leg) {
                return [
                    'origin' => $leg->origin,
                    'destination' => $leg->destination,
                    'departure_datetime' => optional($leg->departure_datetime)->toDateTimeString() ?: $leg->departure_datetime,
                    'arrival_datetime' => optional($leg->arrival_datetime)->toDateTimeString() ?: $leg->arrival_datetime,
                ];
            })->values()->all();

        $origin = trim((string) ($data['origin'] ?? ($legs[0]['origin'] ?? $flightRequest?->origin ?? '')));
        $destination = trim((string) ($data['destination'] ?? ($legs[count($legs) - 1]['destination'] ?? $flightRequest?->destination ?? '')));
        $departureDatetime = $data['departure_datetime'] ?? ($legs[0]['departure_datetime'] ?? $flightRequest?->departure_datetime);
        $returnDatetime = $data['return_datetime'] ?? (count($legs) ? ($legs[count($legs) - 1]['arrival_datetime'] ?? null) : $flightRequest?->return_datetime);

        if (! is_array($legs) || ! count($legs)) {
            $legs = [[
                'origin' => $origin,
                'destination' => $destination,
                'departure_datetime' => $departureDatetime,
                'arrival_datetime' => $returnDatetime,
            ]];
        }

        return [
            'origin' => $origin,
            'destination' => $destination,
            'departure_datetime' => $departureDatetime,
            'return_datetime' => $returnDatetime,
            'legs' => $legs,
        ];
    }

    private function syncFlightRequestLegs(SolicitudVuelo $flightRequest, array $legs): void
    {
        $flightRequest->legs()->delete();

        foreach ($legs as $index => $leg) {
            TramoSolicitudVuelo::query()->create([
                'flight_request_id' => $flightRequest->id,
                'leg_order' => $index + 1,
                'origin' => $leg['origin'] ?? $flightRequest->origin,
                'destination' => $leg['destination'] ?? $flightRequest->destination,
                'departure_datetime' => $leg['departure_datetime'] ?? $flightRequest->departure_datetime,
                'arrival_datetime' => $leg['arrival_datetime'] ?? $flightRequest->return_datetime,
                'passengers' => $flightRequest->passengers,
            ]);
        }
    }

    private function syncReservationLegs(Reserva $reservation, array $legs): void
    {
        $reservation->legs()->delete();

        foreach ($legs as $index => $leg) {
            TramoReserva::query()->create([
                'reservation_id' => $reservation->id,
                'leg_order' => $index + 1,
                'origin' => $leg['origin'] ?? '',
                'destination' => $leg['destination'] ?? '',
                'departure_datetime' => $leg['departure_datetime'] ?? null,
                'arrival_datetime' => $leg['arrival_datetime'] ?? null,
                'passengers' => $reservation->flightRequest?->passengers ?? 0,
                'status' => 'scheduled',
            ]);
        }
    }

    private function reservationSnapshot(Reserva $reservation): array
    {
        $reservation->loadMissing(['aircraft', 'provider', 'flightRequest', 'legs', 'latestPayment', 'contract']);

        return [
            'reservation_id' => $reservation->id,
            'reservation_code' => $reservation->reservation_code,
            'status' => $reservation->status,
            'aircraft_id' => $reservation->aircraft_id,
            'aircraft_name' => trim((string) (($reservation->aircraft?->registration ? $reservation->aircraft->registration.' · ' : '').($reservation->aircraft?->model ?? ''))),
            'provider_id' => $reservation->provider_id,
            'origin' => $reservation->flightRequest?->origin,
            'destination' => $reservation->flightRequest?->destination,
            'departure_datetime' => optional($reservation->flightRequest?->departure_datetime)->toIso8601String(),
            'return_datetime' => optional($reservation->flightRequest?->return_datetime)->toIso8601String(),
            'payment_status' => $reservation->flightRequest?->payment_status ?: $reservation->latestPayment?->status,
            'contract_status' => $reservation->contract?->status,
        ];
    }

    private function notifyAdmins(string $type, string $title, string $message, array $payload = [], ?int $providerId = null): void
    {
        Usuario::query()
            ->where('role', Usuario::ROLE_ADMIN)
            ->orWhere('operational_role', Usuario::ROLE_ADMIN)
            ->get(['id'])
            ->each(fn (Usuario $admin) => $this->notifyUser($admin, $type, $title, $message, $payload, $providerId));
    }

    private function notifyUser(Usuario $user, string $type, string $title, string $message, array $payload = [], ?int $providerId = null): void
    {
        Notificacion::query()->create([
            'user_id' => $user->id,
            'provider_id' => $providerId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'payload' => $payload,
            'data' => $payload,
        ]);
    }

    private function writeAudit(
        ?Usuario $actor,
        string $action,
        string $module,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): void {
        RegistroAuditoria::query()->create([
            'user_id' => $actor?->id,
            'action' => $action,
            'module' => $module,
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }
}
