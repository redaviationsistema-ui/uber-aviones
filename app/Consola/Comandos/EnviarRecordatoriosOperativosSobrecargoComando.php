<?php

namespace App\Consola\Comandos;

use App\Dominio\Sobrecargo\CrewAssignmentStatus;
use App\Modelos\AsignacionSobrecargo;
use App\Modelos\Operacion;
use App\Servicios\Sobrecargo\CrewOperationalNotificationService;
use Illuminate\Console\Command;

class EnviarRecordatoriosOperativosSobrecargoComando extends Command
{
    protected $signature = 'crew:send-operational-reminders {--dry-run}';

    protected $description = 'Envia recordatorios operativos idempotentes de asignaciones y reportes de sobrecargo';

    public function handle(CrewOperationalNotificationService $notifications): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $counters = ['deadline_soon' => 0, 'deadline_expired' => 0, 'presentation_soon' => 0, 'possible_no_show' => 0, 'pending_report' => 0];

        AsignacionSobrecargo::query()->with(['operacion.sobrecargo'])
            ->where('status', CrewAssignmentStatus::PENDING_CONFIRMATION)
            ->whereNotNull('response_deadline')->whereBetween('response_deadline', [now(), now()->addHours(2)])
            ->each(function (AsignacionSobrecargo $assignment) use (&$counters, $dryRun, $notifications) {
                $counters['deadline_soon']++;
                if (! $dryRun && $assignment->operacion?->sobrecargo) {
                    $notifications->send($assignment->operacion->sobrecargo, $assignment->operacion, 'assignment_deadline_soon', 'Asignacion por vencer', 'Tu asignacion vence en menos de dos horas.', 'warning', $assignment->id);
                }
            });

        AsignacionSobrecargo::query()->with(['operacion.sobrecargo'])
            ->where('status', CrewAssignmentStatus::PENDING_CONFIRMATION)
            ->whereNotNull('response_deadline')->where('response_deadline', '<', now())
            ->each(function (AsignacionSobrecargo $assignment) use (&$counters, $dryRun, $notifications) {
                $counters['deadline_expired']++;
                if (! $dryRun && $assignment->operacion?->sobrecargo) {
                    $notifications->send($assignment->operacion->sobrecargo, $assignment->operacion, 'assignment_deadline_expired', 'Asignacion vencida', 'La fecha limite para responder esta asignacion vencio.', 'critical', $assignment->id);
                }
            });

        AsignacionSobrecargo::query()->with(['operacion.sobrecargo'])
            ->where('status', CrewAssignmentStatus::CONFIRMED)->whereNotNull('presentation_time')
            ->whereBetween('presentation_time', [now(), now()->addHour()])
            ->each(function (AsignacionSobrecargo $assignment) use (&$counters, $dryRun, $notifications) {
                $counters['presentation_soon']++;
                if (! $dryRun && $assignment->operacion?->sobrecargo) {
                    $notifications->send($assignment->operacion->sobrecargo, $assignment->operacion, 'presentation_time_soon', 'Presentacion proxima', 'Tu hora de presentacion es dentro de la proxima hora.', 'warning', $assignment->id);
                }
            });

        AsignacionSobrecargo::query()->with(['operacion.sobrecargo'])
            ->where('status', CrewAssignmentStatus::CONFIRMED)->whereNotNull('presentation_time')
            ->where('presentation_time', '<=', now()->subMinutes(15))
            ->whereHas('operacion', fn ($query) => $query->whereNull('crew_checkin_at'))
            ->each(function (AsignacionSobrecargo $assignment) use (&$counters, $dryRun, $notifications) {
                $counters['possible_no_show']++;
                if (! $dryRun && $assignment->operacion?->sobrecargo) {
                    $notifications->send($assignment->operacion->sobrecargo, $assignment->operacion, 'possible_no_show', 'Check-in pendiente', 'Tu hora de presentacion ya paso y no existe check-in registrado.', 'critical', $assignment->id);
                }
            });

        Operacion::query()->with('sobrecargo')->where('crew_status', CrewAssignmentStatus::REPORT_PENDING)
            ->each(function (Operacion $operation) use (&$counters, $dryRun, $notifications) {
                $counters['pending_report']++;
                if (! $dryRun && $operation->sobrecargo) {
                    $notifications->send($operation->sobrecargo, $operation, 'report_pending', 'Reporte final pendiente', 'Completa y envia el reporte final de la operacion.', 'warning');
                }
            });

        $this->table(['Tipo', 'Detectados'], collect($counters)->map(fn ($count, $type) => [$type, $count])->values()->all());
        $this->info($dryRun ? 'Dry-run completado; no se crearon notificaciones.' : 'Recordatorios procesados con idempotencia.');

        return self::SUCCESS;
    }
}
