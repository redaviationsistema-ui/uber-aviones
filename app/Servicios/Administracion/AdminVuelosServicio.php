<?php

namespace App\Servicios\Administracion;

use App\Modelos\RegistroAuditoria;
use App\Modelos\SolicitudVuelo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class AdminVuelosServicio
{
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = SolicitudVuelo::query()
            ->with([
                'client:id,name,email',
                'assignedProvider:id,company_name,commercial_name',
                'assignedAircraft:id,provider_id,model,registration,status',
                'reservation:id,flight_request_id,reservation_code,status,provider_id,aircraft_id,total_amount,currency,confirmed_at,cancelled_at',
                'reservation.latestPayment',
                'latestOperation.sobrecargo:id,name,email',
                'latestOperation.timeline:id,operation_id,status,title,description,created_at',
                'legs:id,flight_request_id,origin,destination,departure_datetime,arrival_datetime,passengers,status',
            ])
            ->latest('id');

        $query
            ->when(filled($filters['status'] ?? null), fn ($scope) => $scope->where('status', $filters['status']))
            ->when(filled($filters['date_from'] ?? null), fn ($scope) => $scope->whereDate('departure_datetime', '>=', $filters['date_from']))
            ->when(filled($filters['date_to'] ?? null), fn ($scope) => $scope->whereDate('departure_datetime', '<=', $filters['date_to']))
            ->when(($filters['provider_id'] ?? null) !== null, fn ($scope) => $scope->where('assigned_provider_id', (int) $filters['provider_id']))
            ->when(($filters['aircraft_id'] ?? null) !== null, fn ($scope) => $scope->where('assigned_aircraft_id', (int) $filters['aircraft_id']))
            ->when(($filters['client_id'] ?? null) !== null, fn ($scope) => $scope->where('client_id', (int) $filters['client_id']))
            ->when(($filters['crew_id'] ?? null) !== null, fn ($scope) => $scope->whereHas('latestOperation', fn ($operation) => $operation->where('sobrecargo_user_id', (int) $filters['crew_id'])));

        return $query->paginate(max(1, min(100, (int) ($filters['per_page'] ?? 25))));
    }

    public function detail(SolicitudVuelo $flightRequest): array
    {
        $flightRequest->loadMissing([
            'client:id,name,email,phone',
            'assignedProvider:id,company_name,commercial_name',
            'assignedAircraft:id,provider_id,model,registration,status',
            'reservation:id,flight_request_id,reservation_code,status,provider_id,aircraft_id,total_amount,currency,confirmed_at,cancelled_at,cancellation_reason',
            'reservation.latestPayment',
            'reservation.aircraftAvailabilityBlock',
            'latestOperation.sobrecargo:id,name,email',
            'latestOperation.timeline:id,operation_id,status,title,description,created_at',
            'legs:id,flight_request_id,origin,destination,departure_datetime,arrival_datetime,passengers,status',
        ]);

        $normalizedStatus = $this->normalizeStatus($flightRequest);

        return [
            'id' => 'request:'.$flightRequest->id,
            'request_id' => $flightRequest->id,
            'reservation_id' => $flightRequest->reservation?->id,
            'status' => $normalizedStatus,
            'origin' => $flightRequest->origin,
            'destination' => $flightRequest->destination,
            'departure_at' => optional($flightRequest->departure_datetime)->toIso8601String(),
            'arrival_at' => optional($flightRequest->return_datetime)->toIso8601String(),
            'aircraft' => [
                'id' => $flightRequest->assignedAircraft?->id,
                'name' => trim((string) (($flightRequest->assignedAircraft?->registration ?? '').' '.($flightRequest->assignedAircraft?->model ?? ''))),
                'status' => $flightRequest->assignedAircraft?->status,
            ],
            'provider' => [
                'id' => $flightRequest->assignedProvider?->id,
                'name' => $flightRequest->assignedProvider?->commercial_name ?: $flightRequest->assignedProvider?->company_name,
            ],
            'client' => [
                'id' => $flightRequest->client?->id,
                'name' => $flightRequest->client?->name,
                'email' => $flightRequest->client?->email,
            ],
            'crew' => $flightRequest->latestOperation?->sobrecargo ? [[
                'id' => $flightRequest->latestOperation->sobrecargo->id,
                'name' => $flightRequest->latestOperation->sobrecargo->name,
                'email' => $flightRequest->latestOperation->sobrecargo->email,
            ]] : [],
            'passengers' => (int) ($flightRequest->passengers ?? 0),
            'incidents' => [],
            'operational_blocks' => $flightRequest->reservation?->aircraftAvailabilityBlock ? [[
                'id' => $flightRequest->reservation->aircraftAvailabilityBlock->id,
                'status' => $flightRequest->reservation->aircraftAvailabilityBlock->status,
                'block_type' => $flightRequest->reservation->aircraftAvailabilityBlock->block_type,
                'reason' => $flightRequest->reservation->aircraftAvailabilityBlock->reason,
            ]] : [],
            'payment' => $flightRequest->reservation?->latestPayment ? [
                'id' => $flightRequest->reservation->latestPayment->id,
                'status' => $flightRequest->reservation->latestPayment->status,
                'paid_at' => optional($flightRequest->reservation->latestPayment->paid_at)->toIso8601String(),
            ] : null,
            'timeline' => $flightRequest->latestOperation?->timeline?->map(fn ($entry) => [
                'status' => $entry->status,
                'title' => $entry->title,
                'description' => $entry->description,
                'created_at' => optional($entry->created_at)->toIso8601String(),
            ])->values()->all() ?? [],
            'legs' => $flightRequest->legs->map(fn ($leg) => [
                'origin' => $leg->origin,
                'destination' => $leg->destination,
                'departure_at' => optional($leg->departure_datetime)->toIso8601String(),
                'arrival_at' => optional($leg->arrival_datetime)->toIso8601String(),
                'passengers' => $leg->passengers,
                'status' => $leg->status,
            ])->values()->all(),
        ];
    }

    private function normalizeStatus(SolicitudVuelo $flightRequest): string
    {
        $workflow = Str::of((string) ($flightRequest->workflow_status ?? ''))->trim()->lower()->value();
        $status = Str::of((string) ($flightRequest->status ?? ''))->trim()->lower()->value();
        $operationStatus = Str::of((string) ($flightRequest->latestOperation?->status ?? ''))->trim()->lower()->value();

        return match (true) {
            in_array($workflow, ['cancelada', 'cancelled'], true) || in_array($status, ['cancelled', 'cancelada'], true) => 'cancelled',
            in_array($workflow, ['reprogramada', 'rescheduled'], true) => 'rescheduled',
            in_array($operationStatus, ['in_progress', 'en_curso'], true) || in_array($workflow, ['tracking_live', 'flight_live'], true) => 'in_progress',
            in_array($operationStatus, ['completed', 'finalizada'], true) || $status === 'completed' => 'completed',
            in_array($workflow, ['tracking_ready', 'operational_ready', 'preparacion', 'preparing'], true) => 'preparing',
            in_array($status, ['confirmed', 'reserved'], true) || in_array($workflow, ['flight_confirmed', 'asignada'], true) => 'confirmed',
            default => 'scheduled',
        };
    }
}
