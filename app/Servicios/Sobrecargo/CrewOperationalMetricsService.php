<?php

namespace App\Servicios\Sobrecargo;

use App\Dominio\Sobrecargo\CrewAssignmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CrewOperationalMetricsService
{
    public const TIMEZONE = 'America/Mexico_City';

    public function build(array $filters): array
    {
        [$from, $to] = $this->period($filters);
        $crewId = isset($filters['crew_member_id']) ? (int) $filters['crew_member_id'] : null;
        $assignments = (array) $this->assignmentMetricsQuery($filters, $from, $to, $crewId)
            ->selectRaw('COUNT(*)::int as created')
            ->selectRaw(
                'COUNT(*) FILTER (WHERE status = ?)::int as pending',
                [CrewAssignmentStatus::PENDING_CONFIRMATION]
            )
            ->selectRaw(
                'COUNT(*) FILTER (WHERE status = ?)::int as accepted',
                [CrewAssignmentStatus::CONFIRMED]
            )
            ->selectRaw(
                'COUNT(*) FILTER (WHERE status = ?)::int as rejected',
                [CrewAssignmentStatus::REJECTED]
            )
            ->selectRaw(
                'AVG(EXTRACT(EPOCH FROM (COALESCE(accepted_at, rejected_at) - assigned_at)) / 60.0) FILTER (WHERE assigned_at IS NOT NULL AND (accepted_at IS NOT NULL OR rejected_at IS NOT NULL)) as average_response_time_minutes'
            )
            ->first();
        $created = (int) ($assignments['created'] ?? 0);
        $pending = (int) ($assignments['pending'] ?? 0);
        $accepted = (int) ($assignments['accepted'] ?? 0);
        $rejected = (int) ($assignments['rejected'] ?? 0);
        $responded = $accepted + $rejected;
        $averageResponse = isset($assignments['average_response_time_minutes'])
            ? (float) $assignments['average_response_time_minutes']
            : null;

        $operations = (array) $this->operationsMetricsQuery($filters, $from, $to, $crewId)
            ->selectRaw("COUNT(*) FILTER (WHERE crew_checkin_status = 'on_time')::int as on_time")
            ->selectRaw("COUNT(*) FILTER (WHERE crew_checkin_status IN ('late', 'very_late'))::int as late")
            ->selectRaw('COUNT(*) FILTER (WHERE crew_status = ?)::int as no_shows', [CrewAssignmentStatus::NO_SHOW])
            ->selectRaw('COUNT(*) FILTER (WHERE crew_status = ?)::int as pending_reports', [CrewAssignmentStatus::REPORT_PENDING])
            ->selectRaw('COUNT(*) FILTER (WHERE crew_administratively_closed_at IS NOT NULL)::int as completed')
            ->selectRaw(
                'AVG(EXTRACT(EPOCH FROM (crew_administratively_closed_at - created_at)) / 60.0) as average_closure_time_minutes'
            )
            ->first();
        $onTime = (int) ($operations['on_time'] ?? 0);
        $late = (int) ($operations['late'] ?? 0);
        $checkIns = $onTime + $late;
        $noShows = (int) ($operations['no_shows'] ?? 0);
        $pendingReports = (int) ($operations['pending_reports'] ?? 0);
        $completed = (int) ($operations['completed'] ?? 0);
        $averageClosure = isset($operations['average_closure_time_minutes'])
            ? (float) $operations['average_closure_time_minutes']
            : null;

        $incidents = (array) $this->incidentsMetricsQuery($filters, $from, $to, $crewId)
            ->selectRaw("COUNT(*) FILTER (WHERE crew_operation_incidents.priority = 'baja')::int as low")
            ->selectRaw("COUNT(*) FILTER (WHERE crew_operation_incidents.priority = 'media')::int as medium")
            ->selectRaw("COUNT(*) FILTER (WHERE crew_operation_incidents.priority = 'alta')::int as high")
            ->selectRaw("COUNT(*) FILTER (WHERE crew_operation_incidents.priority = 'critica')::int as critical")
            ->selectRaw("COUNT(*) FILTER (WHERE crew_operation_incidents.status = 'open')::int as open")
            ->first();

        return [
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'timezone' => self::TIMEZONE],
            'metrics' => [
                'assignments' => ['created' => $created, 'pending' => $pending, 'accepted' => $accepted, 'rejected' => $rejected],
                'response' => [
                    'acceptance_rate' => $this->rate($accepted, $responded),
                    'rejection_rate' => $this->rate($rejected, $responded),
                    'average_response_time_minutes' => $averageResponse === null ? null : round((float) $averageResponse, 2),
                ],
                'punctuality' => ['on_time' => $onTime, 'late' => $late, 'rate' => $this->rate($onTime, $checkIns), 'no_shows' => $noShows],
                'incidents' => [
                    'low' => (int) ($incidents['low'] ?? 0),
                    'medium' => (int) ($incidents['medium'] ?? 0),
                    'high' => (int) ($incidents['high'] ?? 0),
                    'critical' => (int) ($incidents['critical'] ?? 0),
                    'open' => (int) ($incidents['open'] ?? 0),
                ],
                'reports' => ['pending' => $pendingReports],
                'operations' => ['completed' => $completed, 'average_closure_time_minutes' => $averageClosure === null ? null : round((float) $averageClosure, 2)],
                'documents' => ['expiring' => $this->expiringDocuments($crewId)],
            ],
        ];
    }

    private function period(array $filters): array
    {
        $now = CarbonImmutable::now(self::TIMEZONE);
        $preset = $filters['period'] ?? 'month';
        $from = match ($preset) {
            'today' => $now->startOfDay(),
            'week' => $now->startOfWeek(),
            'custom' => CarbonImmutable::parse($filters['from'] ?? throw ValidationException::withMessages(['from' => 'El inicio es obligatorio.']), self::TIMEZONE)->startOfDay(),
            default => $now->startOfMonth(),
        };
        $to = $preset === 'custom'
            ? CarbonImmutable::parse($filters['to'] ?? throw ValidationException::withMessages(['to' => 'El fin es obligatorio.']), self::TIMEZONE)->endOfDay()
            : $now->endOfDay();
        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages(['to' => 'El fin debe ser posterior al inicio.']);
        }

        return [$from, $to];
    }

    private function rate(int $numerator, int $denominator): array
    {
        return ['numerator' => $numerator, 'denominator' => $denominator, 'percentage' => $denominator === 0 ? null : round(($numerator / $denominator) * 100, 2)];
    }

    private function assignmentMetricsQuery(array $filters, CarbonImmutable $from, CarbonImmutable $to, ?int $crewId)
    {
        return DB::table('sobrecargo_assignments')
            ->when(
                ($filters['base'] ?? null) || ($filters['operation_type'] ?? null),
                fn ($query) => $query->join('operations as assignment_operations', 'assignment_operations.id', '=', 'sobrecargo_assignments.operation_id')
            )
            ->when(
                $filters['base'] ?? null,
                fn ($query, string $base) => $query
                    ->join('profiles as assignment_profiles', 'assignment_profiles.user_id', '=', 'assignment_operations.sobrecargo_user_id')
                    ->where('assignment_profiles.base_airport', $base)
            )
            ->when(
                $filters['operation_type'] ?? null,
                fn ($query, string $type) => $query
                    ->join('flight_requests as assignment_flight_requests', 'assignment_flight_requests.id', '=', 'assignment_operations.flight_request_id')
                    ->where('assignment_flight_requests.trip_type', $type)
            )
            ->whereBetween('sobrecargo_assignments.assigned_at', [$from->utc(), $to->utc()])
            ->when($crewId, fn ($query) => $query->where('sobrecargo_assignments.sobrecargo_user_id', $crewId))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('sobrecargo_assignments.status', $status));
    }

    private function operationsMetricsQuery(array $filters, CarbonImmutable $from, CarbonImmutable $to, ?int $crewId)
    {
        return DB::table('operations')
            ->when(
                $filters['base'] ?? null,
                fn ($query, string $base) => $query
                    ->join('profiles as operation_profiles', 'operation_profiles.user_id', '=', 'operations.sobrecargo_user_id')
                    ->where('operation_profiles.base_airport', $base)
            )
            ->when(
                $filters['operation_type'] ?? null,
                fn ($query, string $type) => $query
                    ->join('flight_requests as operation_flight_requests', 'operation_flight_requests.id', '=', 'operations.flight_request_id')
                    ->where('operation_flight_requests.trip_type', $type)
            )
            ->whereBetween('operations.created_at', [$from->utc(), $to->utc()])
            ->when($crewId, fn ($query) => $query->where('operations.sobrecargo_user_id', $crewId))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('operations.crew_status', $status));
    }

    private function incidentsMetricsQuery(array $filters, CarbonImmutable $from, CarbonImmutable $to, ?int $crewId)
    {
        return DB::table('crew_operation_incidents')
            ->join('operations', 'operations.id', '=', 'crew_operation_incidents.crew_operation_id')
            ->when(
                $filters['base'] ?? null,
                fn ($query, string $base) => $query
                    ->join('profiles as incident_profiles', 'incident_profiles.user_id', '=', 'crew_operation_incidents.crew_id')
                    ->where('incident_profiles.base_airport', $base)
            )
            ->when(
                $filters['operation_type'] ?? null,
                fn ($query, string $type) => $query
                    ->join('flight_requests as incident_flight_requests', 'incident_flight_requests.id', '=', 'operations.flight_request_id')
                    ->where('incident_flight_requests.trip_type', $type)
            )
            ->whereBetween('crew_operation_incidents.reported_at', [$from->utc(), $to->utc()])
            ->when($crewId, fn ($query) => $query->where('crew_operation_incidents.crew_id', $crewId))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('operations.crew_status', $status))
            ->when(
                $filters['incident_severity'] ?? null,
                fn ($query, string $severity) => $query->where('crew_operation_incidents.priority', $severity)
            );
    }

    private function expiringDocuments(?int $crewId): int
    {
        $limit = CarbonImmutable::now(self::TIMEZONE)->addDays(30)->endOfDay();
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();

        return DB::table('profiles')->when($crewId, fn ($query) => $query->where('user_id', $crewId))
            ->pluck('tax_data')->sum(function ($taxData) use ($today, $limit) {
                $payload = is_string($taxData) ? json_decode($taxData, true) : (array) $taxData;

                return collect($payload['documents'] ?? [])->filter(function ($document) use ($today, $limit) {
                    $expiresAt = $document['expires_at'] ?? null;
                    if (! $expiresAt) {
                        return false;
                    }
                    $expiration = CarbonImmutable::parse($expiresAt, self::TIMEZONE);

                    return $expiration->betweenIncluded($today, $limit);
                })->count();
            });
    }
}
