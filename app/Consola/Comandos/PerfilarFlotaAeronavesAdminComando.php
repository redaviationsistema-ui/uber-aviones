<?php

namespace App\Consola\Comandos;

use App\Http\Controladores\RedAviation\AdminControlador;
use App\Modelos\Usuario;
use App\Servicios\Administracion\AdminAircraftFleetProfiler;
use Illuminate\Console\Command;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PerfilarFlotaAeronavesAdminComando extends Command
{
    protected $signature = 'admin:profile-aircraft-fleet
        {--admin-user-id=1 : ID del usuario admin para resolver permisos}
        {--provider-id= : Filtrar por proveedor}
        {--per-page=40 : Registros por pagina}
        {--runs=1 : Numero de ejecuciones consecutivas}
        {--explain : Ejecuta EXPLAIN ANALYZE sobre las consultas mas lentas}';

    protected $description = 'Perfila el endpoint administrativo de flota de aeronaves sin modificar datos.';

    public function handle(): int
    {
        $adminUser = Usuario::query()->findOrFail((int) $this->option('admin-user-id'));
        $runs = max(1, (int) $this->option('runs'));
        $providerId = $this->option('provider-id');
        $perPage = max(1, min(100, (int) $this->option('per-page')));

        DB::listen(function (QueryExecuted $query): void {
            if (! app()->bound(AdminAircraftFleetProfiler::class)) {
                return;
            }

            app(AdminAircraftFleetProfiler::class)->recordQuery($query);
        });

        $results = [];

        for ($run = 1; $run <= $runs; $run++) {
            $profiler = new AdminAircraftFleetProfiler();
            app()->instance(AdminAircraftFleetProfiler::class, $profiler);

            $request = Request::create('/api/v1/admin/fleet/aircraft', 'GET', array_filter([
                'provider_id' => $providerId,
                'per_page' => $perPage,
            ], static fn ($value) => $value !== null && $value !== ''));
            $request->setUserResolver(fn () => $adminUser);

            $controller = app(AdminControlador::class);

            $totalStartedAt = microtime(true);
            $response = $controller->aircraftFleet($request);
            $controllerMs = (microtime(true) - $totalStartedAt) * 1000;

            $jsonStartedAt = microtime(true);
            $content = (string) $response->getContent();
            $serializationMs = (microtime(true) - $jsonStartedAt) * 1000;

            $summary = $profiler->summary();
            $summary['controller_total_ms'] = round($controllerMs, 2);
            $summary['json_serialization_ms'] = round($serializationMs, 2);
            $summary['response_bytes'] = strlen($content);

            if ($this->option('explain')) {
                $summary['slow_queries'] = array_map(function (array $query): array {
                    if (! str_starts_with(strtolower(trim($query['sql'])), 'select')) {
                        $query['explain'] = ['skipped' => 'Solo se ejecuta EXPLAIN ANALYZE para SELECT.'];
                        return $query;
                    }

                    try {
                        $explain = DB::select(
                            'EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$query['sql'],
                            $query['bindings'],
                        );
                        $query['explain'] = $explain[0]->{'QUERY PLAN'} ?? null;
                    } catch (\Throwable $exception) {
                        $query['explain'] = ['error' => $exception->getMessage()];
                    }

                    return $query;
                }, $summary['slow_queries']);
            }

            $results[] = $summary;
            $this->line(json_encode([
                'run' => $run,
                ...$summary,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            app()->forgetInstance(AdminAircraftFleetProfiler::class);
        }

        if ($runs > 1) {
            $this->info(json_encode([
                'runs' => $runs,
                'controller_total_avg_ms' => round(collect($results)->avg('controller_total_ms') ?: 0, 2),
                'sql_total_avg_ms' => round(collect($results)->avg('sql_total_ms') ?: 0, 2),
                'response_bytes_avg' => (int) round(collect($results)->avg('response_bytes') ?: 0),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
