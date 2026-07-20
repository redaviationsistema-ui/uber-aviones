<?php

namespace App\Consola\Comandos;

use App\Dominio\Sobrecargo\CrewAssignmentStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NormalizarDatosLegacySobrecargoComando extends Command
{
    protected $signature = 'crew:normalize-legacy-data {--dry-run : Solo informa; no modifica registros}';

    protected $description = 'Normaliza estados operativos legacy de sobrecargo de forma idempotente';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->components->info($dryRun ? 'Modo dry-run: no se escribiran datos.' : 'Normalizando datos legacy.');

        $unknown = [];
        $changes = ['operations' => 0, 'assignments' => 0, 'checklists' => 0, 'checklist_items' => 0];
        $valid = array_keys(CrewAssignmentStatus::TRANSITIONS) + [];
        $valid = array_values(array_unique(array_merge($valid, [
            CrewAssignmentStatus::REJECTED, CrewAssignmentStatus::CANCELLED,
            CrewAssignmentStatus::ADMINISTRATIVELY_CLOSED, CrewAssignmentStatus::NO_SHOW,
        ])));

        DB::table('operations')->select(['id', 'status', 'crew_status', 'completed_at'])
            ->orderBy('id')->chunkById(250, function ($rows) use (&$changes, &$unknown, $dryRun, $valid) {
                foreach ($rows as $row) {
                    $candidate = $row->crew_status ?: $row->status;
                    $normalized = CrewAssignmentStatus::normalize($candidate);
                    if ($normalized === CrewAssignmentStatus::CREW_COMPLETED && $row->completed_at) {
                        $normalized = CrewAssignmentStatus::ADMINISTRATIVELY_CLOSED;
                    }
                    if (! in_array($normalized, $valid, true)) {
                        $unknown['operations:'.($candidate ?: 'null')] = ($unknown['operations:'.($candidate ?: 'null')] ?? 0) + 1;

                        continue;
                    }
                    if ($row->crew_status !== $normalized) {
                        $changes['operations']++;
                        if (! $dryRun) {
                            DB::table('operations')->where('id', $row->id)->where('crew_status', $row->crew_status)->update(['crew_status' => $normalized, 'updated_at' => now()]);
                        }
                    }
                }
            }, 'id');

        if (Schema::hasTable('sobrecargo_assignments')) {
            DB::table('sobrecargo_assignments')->select(['id', 'status'])->orderBy('id')->chunkById(250, function ($rows) use (&$changes, &$unknown, $dryRun, $valid) {
                foreach ($rows as $row) {
                    $normalized = CrewAssignmentStatus::normalize($row->status);
                    if (! in_array($normalized, $valid, true)) {
                        $unknown['assignments:'.($row->status ?: 'null')] = ($unknown['assignments:'.($row->status ?: 'null')] ?? 0) + 1;

                        continue;
                    }
                    if ($row->status !== $normalized) {
                        $changes['assignments']++;
                        if (! $dryRun) {
                            DB::table('sobrecargo_assignments')->where('id', $row->id)->where('status', $row->status)->update(['status' => $normalized, 'updated_at' => now()]);
                        }
                    }
                }
            }, 'id');
        }

        if (Schema::hasColumn('checklist_items', 'status')) {
            DB::table('checklist_items')->whereNull('status')->orWhere('status', '')->orderBy('id')->chunkById(250, function ($rows) use (&$changes, $dryRun) {
                foreach ($rows as $row) {
                    $status = $row->is_completed ? 'completed' : 'pending';
                    $changes['checklist_items']++;
                    if (! $dryRun) {
                        DB::table('checklist_items')->where('id', $row->id)->update(['status' => $status, 'updated_at' => now()]);
                    }
                }
            }, 'id');
        }

        $this->table(['Entidad', 'Registros por modificar'], collect($changes)->map(fn ($count, $entity) => [$entity, $count])->values()->all());
        if ($unknown) {
            $this->warn('Estados no reconocidos; no fueron modificados:');
            $this->table(['Origen/estado', 'Cantidad'], collect($unknown)->map(fn ($count, $state) => [$state, $count])->values()->all());
        }

        return self::SUCCESS;
    }
}
