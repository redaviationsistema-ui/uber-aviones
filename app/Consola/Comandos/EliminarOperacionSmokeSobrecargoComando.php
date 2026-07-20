<?php

namespace App\Consola\Comandos;

use App\Modelos\SolicitudVuelo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EliminarOperacionSmokeSobrecargoComando extends Command
{
    protected $signature = 'crew:delete-smoke-operation';

    protected $description = 'Elimina exclusivamente la operacion identificada por crew:create-smoke-operation';

    public function handle(): int
    {
        $flight = SolicitudVuelo::query()->where('idempotency_key', CrearOperacionSmokeSobrecargoComando::KEY)->first();
        if (! $flight) {
            $this->info('No existe una operacion smoke para eliminar.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($flight) {
            $operationIds = DB::table('operations')->where('flight_request_id', $flight->id)->pluck('id');
            $checklistIds = DB::table('checklists')->whereIn('operation_id', $operationIds)->pluck('id');
            DB::table('checklist_items')->whereIn('checklist_id', $checklistIds)->delete();
            DB::table('checklists')->whereIn('id', $checklistIds)->delete();
            DB::table('operation_timeline')->whereIn('operation_id', $operationIds)->delete();
            DB::table('sobrecargo_assignments')->whereIn('operation_id', $operationIds)->delete();
            DB::table('operations')->whereIn('id', $operationIds)->delete();
            $flight->delete();
        });
        $this->info("Se elimino exclusivamente el smoke test flight_request #{$flight->id}; los usuarios y catalogos reutilizables se conservaron.");

        return self::SUCCESS;
    }
}
