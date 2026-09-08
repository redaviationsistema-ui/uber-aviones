<?php

namespace App\Servicios\Sobrecargo;

use App\Dominio\Sobrecargo\CrewAssignmentStatus;
use App\Modelos\ChecklistItem;
use App\Modelos\ChecklistOperacion;
use App\Modelos\Operacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CrewOperationWorkflowService
{
    public function loadOperationWorkflow(Operacion $operation): Operacion
    {
        $operation->loadMissing([
            'checklists.items',
            'timeline' => fn ($query) => $query->oldest('id'),
            'latestCrewAssignment',
        ]);

        return $operation;
    }

    public function buildWorkflowPayload(Operacion $operation): array
    {
        $loadedOperation = $this->loadOperationWorkflow($operation);
        $incidents = $this->loadIncidents($loadedOperation);
        $allowedActions = $this->allowedActions($loadedOperation);
        $recoverableActions = $this->recoverableActions($loadedOperation);
        $currentStep = $this->currentStep($loadedOperation);

        return [
            'operation_id' => $loadedOperation->id,
            'assignment_id' => $loadedOperation->latestCrewAssignment?->id,
            'flight_request_id' => $loadedOperation->flight_request_id,
            'assignment_status' => $loadedOperation->latestCrewAssignment?->status,
            'operation_status' => $loadedOperation->status,
            'status' => CrewAssignmentStatus::normalize($loadedOperation->crew_status),
            'crew_status' => CrewAssignmentStatus::normalize($loadedOperation->crew_status),
            'checklists' => $loadedOperation->checklists
                ->map(fn (ChecklistOperacion $checklist) => $this->serializeChecklist($checklist))
                ->values(),
            'report' => $loadedOperation->crew_final_report,
            'final_report' => $loadedOperation->crew_final_report,
            'timeline' => $loadedOperation->timeline,
            'tracking_events' => $loadedOperation->timeline,
            'incidents' => $incidents,
            'closure' => $loadedOperation->crew_final_report,
            'allowed_actions' => $allowedActions,
            'recoverable_actions' => $recoverableActions,
            'recoverable' => $recoverableActions !== [],
            'current_step' => $currentStep,
            'current_phase' => $this->currentPhase($currentStep, $allowedActions),
            'next_action' => $allowedActions[0] ?? null,
            'workflow_inconsistent' => $this->inconsistencyReasons($loadedOperation) !== [],
            'inconsistency_reasons' => $this->inconsistencyReasons($loadedOperation),
            'blocking_reason' => $this->blockingReason($loadedOperation),
            'crew_checkin_at' => optional($loadedOperation->crew_checkin_at)?->toISOString(),
            'editable_checklists' => $this->editableChecklists($loadedOperation),
            'checkin' => $this->checkinEvidence($loadedOperation),
        ];
    }

    private function currentStep(Operacion $operation): string
    {
        if (! $this->checklistComplete($operation, 'preparation')) {
            return 'preparation';
        }
        if (! $this->hasCheckin($operation)) {
            return 'airport_arrival';
        }
        if (! $this->checklistComplete($operation, 'preflight')) {
            return 'preflight';
        }

        return match (CrewAssignmentStatus::normalize($operation->crew_status)) {
            CrewAssignmentStatus::POSTFLIGHT_PENDING => 'postflight',
            CrewAssignmentStatus::REPORT_PENDING => 'closure',
            CrewAssignmentStatus::CREW_COMPLETED,
            CrewAssignmentStatus::ADMINISTRATIVELY_CLOSED => 'completed',
            default => 'tracking',
        };
    }

    private function currentPhase(string $currentStep, array $allowedActions): string
    {
        if ($currentStep !== 'tracking') {
            return $currentStep;
        }

        return match ($allowedActions[0]['type'] ?? null) {
            'departure' => 'departure',
            'landing' => 'landing',
            'disembark' => 'disembark',
            default => 'tracking',
        };
    }

    private function checkinEvidence(Operacion $operation): ?array
    {
        $this->loadOperationWorkflow($operation);
        $entry = $operation->timeline->whereIn('status', ['crew_checkin', 'checked_in'])->sortBy('id')->first();
        $timestamp = $operation->crew_checkin_at ?: $entry?->created_at;
        if (! $timestamp) {
            return null;
        }
        $actor = $entry?->created_by ? DB::table('users')->where('id', $entry->created_by)->value('name') : null;

        return ['recorded_at' => $timestamp->toISOString(), 'actor_name' => $actor, 'actor_id' => $entry?->created_by];
    }

    private function editableChecklists(Operacion $operation): array
    {
        if ($this->blockingReason($operation)) {
            return [];
        }

        return match (CrewAssignmentStatus::normalize($operation->crew_status)) {
            CrewAssignmentStatus::CONFIRMED, CrewAssignmentStatus::PREPARATION_PENDING => ['preparation'],
            CrewAssignmentStatus::PREFLIGHT_IN_PROGRESS => $this->hasCheckin($operation) ? ['preflight'] : [],
            CrewAssignmentStatus::POSTFLIGHT_PENDING => $this->hasCheckin($operation) ? ['postflight'] : [],
            default => [],
        };
    }

    public function hasCheckin(Operacion $operation): bool
    {
        $this->loadOperationWorkflow($operation);
        return $operation->crew_checkin_at !== null || $operation->timeline->whereIn('status', ['crew_checkin', 'checked_in'])->isNotEmpty();
    }

    public function hasCabinEvidence(Operacion $operation): bool
    {
        $this->loadOperationWorkflow($operation);
        return $operation->timeline->contains('status', 'cabina_lista');
    }

    public function checklistComplete(Operacion $operation, string $type): bool
    {
        $this->loadOperationWorkflow($operation);
        $checklist = $operation->checklists->where('type', $type)
            ->where('sobrecargo_user_id', $operation->latestCrewAssignment?->sobrecargo_user_id)->sortByDesc('id')->first();
        $required = $checklist?->items->where('is_required', true);
        return $required && $required->isNotEmpty()
            && $required->every(fn ($item) => in_array($item->status, ['completed', 'not_applicable'], true));

    }

    public function inconsistencyReasons(Operacion $operation): array
    {
        $status = CrewAssignmentStatus::normalize($operation->crew_status);
        $afterArrival = [CrewAssignmentStatus::CHECKED_IN, CrewAssignmentStatus::PREFLIGHT_IN_PROGRESS,
            CrewAssignmentStatus::CABIN_READY, CrewAssignmentStatus::BOARDING,
            CrewAssignmentStatus::BOARDING_COMPLETED, CrewAssignmentStatus::IN_FLIGHT,
            CrewAssignmentStatus::LANDED, CrewAssignmentStatus::POSTFLIGHT_PENDING,
            CrewAssignmentStatus::REPORT_PENDING, CrewAssignmentStatus::CREW_COMPLETED,
            CrewAssignmentStatus::ADMINISTRATIVELY_CLOSED];
        $reasons = [];
        if (in_array($status, $afterArrival, true) && ! $this->hasCheckin($operation)) {
            $reasons[] = 'Falta evidencia de llegada al aeropuerto.';
        }
        if (in_array($status, array_slice($afterArrival, 3), true) && ! $this->hasCabinEvidence($operation)) {
            $reasons[] = 'Falta la confirmación de aeronave, catering e insumos.';
        }
        $evidenceOrder = [
            CrewAssignmentStatus::BOARDING_COMPLETED => ['pasajeros_recibidos', 'boarding_completed'],
            CrewAssignmentStatus::IN_FLIGHT => ['in_flight'],
            CrewAssignmentStatus::LANDED => ['landed'],
            CrewAssignmentStatus::POSTFLIGHT_PENDING => ['postflight_pending'],
        ];
        $stateIndex = array_search($status, $afterArrival, true);
        foreach ($evidenceOrder as $milestone => $statuses) {
            if ($stateIndex !== false && $stateIndex >= array_search($milestone, $afterArrival, true)
                && ! $operation->timeline->whereIn('status', $statuses)->isNotEmpty()) {
                $reasons[] = 'Falta evidencia del avance '.str_replace('_', ' ', $milestone).'.';
            }
        }

        return $reasons;
    }

    public function recoveryAction(Operacion $operation): ?array
    {
        $status = CrewAssignmentStatus::normalize($operation->crew_status);
        if (!in_array($status, ['checked_in', 'preflight_in_progress', 'cabin_ready', 'boarding'], true)
            || $operation->crew_service_started_at || !$this->checklistComplete($operation, 'preparation')) return null;
        if (!$this->hasCheckin($operation)) {
            return ['type' => 'crew_checkin', 'label' => 'Registrar llegada', 'recovery' => true];
        }
        if (in_array($status, ['cabin_ready', 'boarding'], true)
            && $this->checklistComplete($operation, 'preflight') && !$this->hasCabinEvidence($operation)) {
            return ['type' => 'cabin_ready', 'label' => 'Confirmar cabina, catering e insumos listos', 'recovery' => true];
        }
        return null;
    }

    public function blockingReason(Operacion $operation): ?string
    {
        if ($this->incidentRows($operation)->whereIn('status', ['open', 'in_review'])->contains('priority', 'critica')) {
            return 'Resuelve primero la incidencia crítica abierta de esta operación.';
        }
        if ($this->inconsistencyReasons($operation) !== [] && !$this->recoveryAction($operation)) {
            return 'Esta operación requiere regularización del flujo. '.implode(' ', $this->inconsistencyReasons($operation));
        }
        return null;
    }

    public function allowedActions(Operacion $operation): array
    {
        if ($this->blockingReason($operation)) {
            return [];
        }
        if ($recovery = $this->recoveryAction($operation)) return [$recovery];
        $status = CrewAssignmentStatus::normalize($operation->crew_status);
        $action = fn (string $type, string $label, array $extra = []) => [array_merge(['type' => $type, 'label' => $label], $extra)];
        if ($status === CrewAssignmentStatus::READY_FOR_OPERATION && ! $this->hasCheckin($operation)
            && $this->checklistComplete($operation, 'preparation')) {
            return $action('crew_checkin', 'Registrar llegada');
        }
        if ($status === CrewAssignmentStatus::CHECKED_IN && $this->hasCheckin($operation)) {
            return $action('start_preflight', 'Iniciar checklist pre-vuelo', ['status' => CrewAssignmentStatus::PREFLIGHT_IN_PROGRESS]);
        }
        if ($status === CrewAssignmentStatus::CABIN_READY && ! $operation->crew_service_started_at && $this->checklistComplete($operation, 'preflight')) {
            return ! $this->hasCabinEvidence($operation)
                ? $action('cabin_ready', 'Confirmar aeronave y catering listos')
                : $action('transition', 'Registrar pasajeros llegaron', ['status' => CrewAssignmentStatus::BOARDING]);
        }
        if ($status === CrewAssignmentStatus::BOARDING && $this->hasCabinEvidence($operation) && ! $operation->crew_service_started_at) {
            return $action('passengers_ready', 'Confirmar pasajeros a bordo');
        }
        if ($status === CrewAssignmentStatus::REPORT_PENDING && ! $operation->crew_report_submitted_at
            && $this->checklistComplete($operation, 'postflight')) {
            return $action('submit_report', 'Enviar reporte final');
        }

        return match ($status) {
            CrewAssignmentStatus::CONFIRMED => $action('transition', 'Iniciar preparación', ['status' => CrewAssignmentStatus::PREPARATION_PENDING]),
            CrewAssignmentStatus::BOARDING_COMPLETED => $action('departure', 'Registrar despegue', ['status' => CrewAssignmentStatus::IN_FLIGHT]),
            CrewAssignmentStatus::IN_FLIGHT => $action('landing', 'Registrar aterrizaje', ['status' => CrewAssignmentStatus::LANDED]),
            CrewAssignmentStatus::LANDED => $action('disembark', 'Registrar desembarque', ['status' => CrewAssignmentStatus::POSTFLIGHT_PENDING]),
            default => [],
        };
    }

    public function recoverableActions(Operacion $operation): array
    {
        $action = $this->recoveryAction($operation);

        return $action && !$this->blockingReason($operation) ? [$action] : [];
    }

    public function assertActionAllowed(Operacion $operation, string $type, ?string $target = null): void
    {
        abort_if($this->blockingReason($operation) !== null, 409, $this->blockingReason($operation));
        $allowed = collect($this->allowedActions($operation))->contains(function ($action) use ($type, $target) {
            if ($type === 'transition') {
                return isset($action['status']) && $action['status'] === $target;
            }

            return $action['type'] === $type;
        });
        abort_unless($allowed, 409, 'La acción no está permitida en el estado actual. Actualiza el flujo.');
    }

    public function assertChecklistEditable(Operacion $operation, string $type): void
    {
        if (in_array($type, ['preflight', 'postflight'], true)) {
            abort_unless($this->hasCheckin($operation), 409, 'Registra primero tu llegada al aeropuerto.');
        }
        abort_if($this->blockingReason($operation) !== null, 409, $this->blockingReason($operation));
        $states = match ($type) {
            'preparation' => [CrewAssignmentStatus::CONFIRMED, CrewAssignmentStatus::PREPARATION_PENDING],
            'preflight' => [CrewAssignmentStatus::PREFLIGHT_IN_PROGRESS],
            'postflight' => [CrewAssignmentStatus::POSTFLIGHT_PENDING],
            default => [],
        };
        abort_unless(in_array(CrewAssignmentStatus::normalize($operation->crew_status), $states, true), 409,
            'Este checklist no admite cambios en el estado actual.');
    }

    private function incidentRows(Operacion $operation)
    {
        if (! $operation->relationLoaded('workflowIncidentRows')) {
            $operation->setRelation('workflowIncidentRows', DB::table('crew_operation_incidents')
                ->where('crew_operation_id', $operation->id)->orderByDesc('reported_at')->orderByDesc('id')->get());
        }
        return $operation->getRelation('workflowIncidentRows');
    }

    private function loadIncidents(Operacion $operation): array
    {
        $incidents = $this->incidentRows($operation);
        $files = $incidents->isEmpty() ? collect() : DB::table('crew_operation_incident_files')
            ->whereIn('incident_id', $incidents->pluck('id'))->orderBy('id')->get()->groupBy('incident_id');
        return $incidents->map(function ($incident) use ($files) {
            return array_merge((array) $incident, ['files' => ($files[$incident->id] ?? collect())->map(fn ($file) => [
                'id' => $file->id, 'storage_disk' => $file->storage_disk, 'file_path' => $file->file_path,
                'file_type' => $file->file_type, 'original_name' => $file->original_name,
                'file_url' => $this->resolveChecklistEvidenceFileUrl((string) $file->storage_disk, (string) $file->file_path),
            ])->values()->all()]);
        })->values()->all();
    }

    private function serializeChecklist(ChecklistOperacion $checklist): array
    {
        return [
            'id' => $checklist->id,
            'type' => $checklist->type,
            'status' => $checklist->status,
            'submitted_at' => optional($checklist->submitted_at)?->toISOString(),
            'items' => $checklist->items
                ->map(fn (ChecklistItem $item) => $this->serializeChecklistItem($item))
                ->values(),
        ];
    }

    private function serializeChecklistItem(ChecklistItem $item): array
    {
        return [
            'id' => $item->id,
            'code' => $item->code,
            'category' => $item->category,
            'label' => $item->label,
            'description' => $item->label,
            'status' => $item->status,
            'is_required' => $item->is_required,
            'is_critical' => $item->is_critical,
            'notes' => $item->notes,
            'is_completed' => $item->is_completed,
            'completed_at' => optional($item->completed_at)?->toISOString(),
            'evidence_files' => collect($item->evidence_files ?? [])
                ->map(fn ($file) => $this->serializeChecklistEvidenceFile((array) $file))
                ->filter(fn ($file) => filled($file['file_path'] ?? null))
                ->values()
                ->all(),
        ];
    }

    private function serializeChecklistEvidenceFile(array $file): array
    {
        $disk = trim((string) ($file['storage_disk'] ?? 's3')) ?: 's3';
        $path = trim((string) ($file['file_path'] ?? ''));

        return [
            'storage_disk' => $disk,
            'file_path' => $path,
            'file_type' => $file['file_type'] ?? null,
            'original_name' => $file['original_name'] ?? null,
            'size' => $file['size'] ?? null,
            'uploaded_at' => $file['uploaded_at'] ?? null,
            'uploaded_by' => $file['uploaded_by'] ?? null,
            'file_url' => $this->resolveChecklistEvidenceFileUrl($disk, $path),
        ];
    }

    private function resolveChecklistEvidenceFileUrl(string $disk, string $path): ?string
    {
        if ($disk !== 's3' || $path === '') {
            return null;
        }

        if (! $this->canGenerateChecklistEvidenceTemporaryS3Urls()) {
            return null;
        }

        try {
            return Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(30));
        } catch (\Throwable) {
            return null;
        }
    }

    private function canGenerateChecklistEvidenceTemporaryS3Urls(): bool
    {
        $key = trim((string) config('filesystems.disks.s3.key', ''));
        $secret = trim((string) config('filesystems.disks.s3.secret', ''));
        $bucket = trim((string) config('filesystems.disks.s3.bucket', ''));
        $region = trim((string) config('filesystems.disks.s3.region', ''));

        return $key !== '' && $secret !== '' && $bucket !== '' && $region !== '';
    }
}
