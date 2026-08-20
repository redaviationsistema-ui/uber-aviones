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
        ];
    }

    private function loadIncidents(Operacion $operation): array
    {
        return DB::table('crew_operation_incidents')
            ->where('crew_operation_id', $operation->id)
            ->orderByDesc('reported_at')
            ->orderByDesc('id')
            ->get()
            ->map(function ($incident) {
                $files = DB::table('crew_operation_incident_files')
                    ->where('incident_id', $incident->id)
                    ->orderBy('id')
                    ->get()
                    ->map(fn ($file) => [
                        'id' => $file->id,
                        'storage_disk' => $file->storage_disk,
                        'file_path' => $file->file_path,
                        'file_type' => $file->file_type,
                        'original_name' => $file->original_name,
                        'file_url' => $this->resolveChecklistEvidenceFileUrl(
                            (string) ($file->storage_disk ?? ''),
                            (string) ($file->file_path ?? ''),
                        ),
                    ])
                    ->values()
                    ->all();

                return array_merge((array) $incident, ['files' => $files]);
            })
            ->values()
            ->all();
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
