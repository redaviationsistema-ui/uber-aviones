<?php

namespace App\Servicios\Sobrecargo;

use App\Dominio\Sobrecargo\CrewAssignmentStatus;
use App\Modelos\AsignacionSobrecargo;
use App\Modelos\Operacion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CrewOperationalMetricsService
{
    public const TIMEZONE = 'America/Mexico_City';

    public function build(array $filters): array
    {
        [$from, $to] = $this->period($filters);
        $crewId = isset($filters['crew_member_id']) ? (int) $filters['crew_member_id'] : null;

        $assignments = AsignacionSobrecargo::query()
            ->whereBetween('assigned_at', [$from->utc(), $to->utc()])
            ->when($crewId, fn (Builder $query) => $query->where('sobrecargo_user_id', $crewId))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['base'] ?? null, fn (Builder $query, string $base) => $query->whereHas('operacion.sobrecargo.profile', fn (Builder $profile) => $profile->where('base_airport', $base)))
            ->when($filters['operation_type'] ?? null, fn (Builder $query, string $type) => $query->whereHas('operacion.solicitudVuelo', fn (Builder $flight) => $flight->where('trip_type', $type)));
        $created = (clone $assignments)->count();
        $pending = (clone $assignments)->where('status', CrewAssignmentStatus::PENDING_CONFIRMATION)->count();
        $accepted = (clone $assignments)->where('status', CrewAssignmentStatus::CONFIRMED)->count();
        $rejected = (clone $assignments)->where('status', CrewAssignmentStatus::REJECTED)->count();
        $responded = $accepted + $rejected;
        $averageResponse = (clone $assignments)
            ->whereNotNull('assigned_at')
            ->where(fn (Builder $query) => $query->whereNotNull('accepted_at')->orWhereNotNull('rejected_at'))
            ->get(['assigned_at', 'accepted_at', 'rejected_at'])
            ->average(fn (AsignacionSobrecargo $assignment) => $assignment->assigned_at?->diffInMinutes($assignment->accepted_at ?: $assignment->rejected_at));

        $operations = Operacion::query()
            ->whereBetween('created_at', [$from->utc(), $to->utc()])
            ->when($crewId, fn (Builder $query) => $query->where('sobrecargo_user_id', $crewId))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('crew_status', $status))
            ->when($filters['base'] ?? null, fn (Builder $query, string $base) => $query->whereHas('sobrecargo.profile', fn (Builder $profile) => $profile->where('base_airport', $base)))
            ->when($filters['operation_type'] ?? null, fn (Builder $query, string $type) => $query->whereHas('solicitudVuelo', fn (Builder $flight) => $flight->where('trip_type', $type)));
        $onTime = (clone $operations)->where('crew_checkin_status', 'on_time')->count();
        $late = (clone $operations)->whereIn('crew_checkin_status', ['late', 'very_late'])->count();
        $checkIns = $onTime + $late;
        $noShows = (clone $operations)->where('crew_status', CrewAssignmentStatus::NO_SHOW)->count();
        $pendingReports = (clone $operations)->where('crew_status', CrewAssignmentStatus::REPORT_PENDING)->count();
        $completed = (clone $operations)->whereNotNull('crew_administratively_closed_at')->count();
        $averageClosure = (clone $operations)->whereNotNull('crew_administratively_closed_at')
            ->get(['created_at', 'crew_administratively_closed_at'])
            ->average(fn (Operacion $operation) => $operation->created_at?->diffInMinutes($operation->crew_administratively_closed_at));

        $incidents = DB::table('crew_operation_incidents')
            ->join('operations', 'operations.id', '=', 'crew_operation_incidents.crew_operation_id')
            ->whereBetween('crew_operation_incidents.reported_at', [$from->utc(), $to->utc()])
            ->when($crewId, fn ($query) => $query->where('crew_operation_incidents.crew_id', $crewId))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('operations.crew_status', $status))
            ->when($filters['operation_type'] ?? null, fn ($query, string $type) => $query->join('flight_requests', 'flight_requests.id', '=', 'operations.flight_request_id')->where('flight_requests.trip_type', $type))
            ->when($filters['base'] ?? null, fn ($query, string $base) => $query->join('profiles', 'profiles.user_id', '=', 'crew_operation_incidents.crew_id')->where('profiles.base_airport', $base))
            ->when($filters['incident_severity'] ?? null, fn ($query, string $severity) => $query->where('crew_operation_incidents.priority', $severity));
        $severity = collect(['baja', 'media', 'alta', 'critica'])->mapWithKeys(
            fn (string $level) => [$level => (clone $incidents)->where('crew_operation_incidents.priority', $level)->count()]
        );

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
                'incidents' => ['low' => $severity['baja'], 'medium' => $severity['media'], 'high' => $severity['alta'], 'critical' => $severity['critica'], 'open' => (clone $incidents)->where('crew_operation_incidents.status', 'open')->count()],
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
