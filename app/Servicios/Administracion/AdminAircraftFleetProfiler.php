<?php

namespace App\Servicios\Administracion;

use Illuminate\Database\Events\QueryExecuted;
use Symfony\Component\HttpFoundation\JsonResponse;

class AdminAircraftFleetProfiler
{
    private array $queries = [];

    private array $phaseDurations = [];

    private array $aircraftEvaluations = [];

    private array $aircraftTransforms = [];

    private ?array $payloadMetrics = null;

    public function recordQuery(QueryExecuted $query): void
    {
        $this->queries[] = [
            'sql' => $query->sql,
            'bindings' => array_map([$this, 'normalizeBinding'], $query->bindings),
            'time_ms' => (float) $query->time,
            'connection' => $query->connectionName,
        ];
    }

    public function recordPhaseDuration(string $key, float $durationMs): void
    {
        $this->phaseDurations[$key] = ($this->phaseDurations[$key] ?? 0.0) + round($durationMs, 2);
    }

    public function recordAircraftEvaluation(int $aircraftId, float $elapsedMs, array $segments = []): void
    {
        $this->aircraftEvaluations[] = [
            'aircraft_id' => $aircraftId,
            'elapsed_ms' => round($elapsedMs, 2),
            'segments' => array_map(
                static fn ($value) => round((float) $value, 2),
                $segments,
            ),
        ];
    }

    public function recordAircraftTransform(int $aircraftId, float $elapsedMs): void
    {
        $this->aircraftTransforms[] = [
            'aircraft_id' => $aircraftId,
            'elapsed_ms' => round($elapsedMs, 2),
        ];
    }

    public function capturePayloadFromResponse(JsonResponse $response, array $records): void
    {
        $content = (string) $response->getContent();
        $aircraftCount = count($records);
        $fieldSizes = [];

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            foreach ($record as $field => $value) {
                $fieldSizes[$field] = ($fieldSizes[$field] ?? 0) + strlen((string) json_encode($value));
            }
        }

        arsort($fieldSizes);

        $this->payloadMetrics = [
            'aircraft_count' => $aircraftCount,
            'bytes_total' => strlen($content),
            'bytes_average_per_aircraft' => $aircraftCount > 0 ? (int) round(strlen($content) / $aircraftCount) : 0,
            'heaviest_fields' => array_slice($fieldSizes, 0, 10, true),
        ];
    }

    public function summary(int $slowQueryLimit = 5): array
    {
        $slowQueries = $this->queries;
        usort($slowQueries, static fn ($left, $right) => $right['time_ms'] <=> $left['time_ms']);
        $slowQueries = array_slice($slowQueries, 0, $slowQueryLimit);

        $slowestEvaluation = null;
        $averageEvaluation = 0.0;
        $segmentTotals = [];

        if ($this->aircraftEvaluations !== []) {
            $slowestEvaluation = collect($this->aircraftEvaluations)->sortByDesc('elapsed_ms')->first();
            $averageEvaluation = round(collect($this->aircraftEvaluations)->avg('elapsed_ms') ?: 0, 2);

            foreach ($this->aircraftEvaluations as $evaluation) {
                foreach (($evaluation['segments'] ?? []) as $segment => $elapsed) {
                    $segmentTotals[$segment] = ($segmentTotals[$segment] ?? 0.0) + (float) $elapsed;
                }
            }
        }

        arsort($segmentTotals);

        return [
            'query_count' => count($this->queries),
            'sql_total_ms' => round(array_sum(array_column($this->queries, 'time_ms')), 2),
            'phase_durations_ms' => $this->phaseDurations,
            'slow_queries' => $slowQueries,
            'evaluate' => [
                'average_ms' => $averageEvaluation,
                'slowest_aircraft' => $slowestEvaluation,
                'segment_totals_ms' => array_map(
                    static fn ($value) => round((float) $value, 2),
                    $segmentTotals,
                ),
            ],
            'transform' => [
                'average_ms' => round(collect($this->aircraftTransforms)->avg('elapsed_ms') ?: 0, 2),
                'slowest_aircraft' => collect($this->aircraftTransforms)->sortByDesc('elapsed_ms')->first(),
            ],
            'payload' => $this->payloadMetrics,
        ];
    }

    private function normalizeBinding(mixed $binding): mixed
    {
        if ($binding instanceof \DateTimeInterface) {
            return $binding->format(DATE_ATOM);
        }

        if (is_bool($binding) || is_numeric($binding) || $binding === null) {
            return $binding;
        }

        return mb_strimwidth((string) $binding, 0, 200, '...');
    }
}
