<?php

namespace App\Http\Controladores;

use App\Modelos\LineaTiempoOperacion;
use App\Modelos\Operacion;
use App\Modelos\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CrewOperationIncidentController extends ControladorBase
{
    private const STATUSES = ['open', 'in_review', 'resolved', 'closed'];
    private const CATEGORIES = ['catering', 'cabina', 'cliente', 'seguridad', 'horario', 'coordinacion', 'otro'];
    private const PRIORITIES = ['baja', 'media', 'alta', 'critica'];
    private const PHASES = ['Pre-vuelo', 'En vuelo', 'Post-vuelo'];

    public function index(Request $request)
    {
        $user = $request->user();
        $query = DB::table('crew_operation_incidents')
            ->when($request->filled('crew_operation_id'), fn ($builder) => $builder->where('crew_operation_id', $request->integer('crew_operation_id')))
            ->when($request->filled('crew_id'), fn ($builder) => $builder->where('crew_id', $request->integer('crew_id')))
            ->latest('reported_at')
            ->latest('id');

        if ($user && $user->hasRole(Usuario::ROLE_SOBRECARGO)) {
            $query->where('crew_id', $user->id);
        }

        $incidents = $query->get()->map(fn ($incident) => $this->withFiles($incident))->values();

        return $this->ok(['incidents' => $incidents]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'crew_operation_id' => ['required', 'integer', 'exists:operations,id'],
            'crew_id' => ['required', 'integer', 'exists:users,id'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'priority' => ['required', Rule::in(self::PRIORITIES)],
            'phase' => ['nullable', Rule::in(self::PHASES)],
            'description' => ['required', 'string'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:10240'],
        ]);

        $user = $request->user();
        if ($user && $user->hasRole(Usuario::ROLE_SOBRECARGO)) {
            abort_if((int) $data['crew_id'] !== (int) $user->id, 403);
        }

        $operation = Operacion::with(['solicitudVuelo', 'proveedor', 'sobrecargo'])->findOrFail($data['crew_operation_id']);
        if ($user && $user->hasRole(Usuario::ROLE_SOBRECARGO)) {
            abort_if((int) $operation->sobrecargo_user_id !== (int) $user->id, 403);
        }

        $incidentId = DB::table('crew_operation_incidents')->insertGetId([
            'crew_operation_id' => $data['crew_operation_id'],
            'crew_id' => $data['crew_id'],
            'reported_by' => $user?->id,
            'phase' => $data['phase'] ?? null,
            'category' => $data['category'],
            'priority' => $data['priority'],
            'status' => 'open',
            'description' => $data['description'],
            'reported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($request->file('files', []) as $file) {
            $disk = $this->resolveIncidentUploadDisk();
            $storedPath = $file->store('crew-operation-incidents', $disk);

            DB::table('crew_operation_incident_files')->insert([
                'incident_id' => $incidentId,
                'storage_disk' => $disk,
                'file_path' => $storedPath,
                'file_type' => $file->getClientMimeType(),
                'original_name' => $file->getClientOriginalName(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $timeline = $this->syncProviderTimeline(
            (object) [
                'id' => $incidentId,
                'crew_operation_id' => $data['crew_operation_id'],
                'crew_id' => $data['crew_id'],
                'provider_timeline_id' => null,
                'phase' => $data['phase'] ?? null,
                'category' => $data['category'],
                'priority' => $data['priority'],
                'status' => 'open',
                'description' => $data['description'],
                'admin_response' => null,
            ],
            $operation,
        );

        if ($timeline) {
            DB::table('crew_operation_incidents')
                ->where('id', $incidentId)
                ->update([
                    'provider_timeline_id' => $timeline->id,
                    'updated_at' => now(),
                ]);
        }

        $incident = DB::table('crew_operation_incidents')->where('id', $incidentId)->first();

        return $this->ok(['incident' => $this->withFiles($incident)], 201);
    }

    public function show(Request $request, int $id)
    {
        $incident = DB::table('crew_operation_incidents')->where('id', $id)->first();
        abort_if(! $incident, 404);

        $user = $request->user();
        if ($user && $user->hasRole(Usuario::ROLE_SOBRECARGO)) {
            abort_if((int) $incident->crew_id !== (int) $user->id, 403);
        }

        return $this->ok(['incident' => $this->withFiles($incident)]);
    }

    public function update(Request $request, int $id)
    {
        abort_if(! $request->user()?->hasRole(Usuario::ROLE_ADMIN), 403);

        $incident = DB::table('crew_operation_incidents')->where('id', $id)->first();
        abort_if(! $incident, 404);

        $data = $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'admin_response' => ['nullable', 'string'],
        ]);

        $status = $data['status'] ?? $incident->status;
        $patch = [
            'status' => $status,
            'admin_response' => array_key_exists('admin_response', $data) ? $data['admin_response'] : $incident->admin_response,
            'updated_at' => now(),
        ];

        if ($status === 'resolved' && ! $incident->resolved_at) {
            $patch['resolved_at'] = now();
        }

        if ($status === 'closed' && ! $incident->closed_at) {
            $patch['closed_at'] = now();
            $patch['resolved_at'] = $incident->resolved_at ?: now();
        }

        DB::table('crew_operation_incidents')->where('id', $id)->update($patch);

        $updated = DB::table('crew_operation_incidents')->where('id', $id)->first();
        $operation = Operacion::with(['solicitudVuelo', 'proveedor', 'sobrecargo'])->find($updated->crew_operation_id);
        if ($operation) {
            $timeline = $this->syncProviderTimeline($updated, $operation);

            if ($timeline && (int) ($updated->provider_timeline_id ?? 0) !== (int) $timeline->id) {
                DB::table('crew_operation_incidents')
                    ->where('id', $id)
                    ->update([
                        'provider_timeline_id' => $timeline->id,
                        'updated_at' => now(),
                    ]);
                $updated = DB::table('crew_operation_incidents')->where('id', $id)->first();
            }
        }

        return $this->ok(['incident' => $this->withFiles($updated)]);
    }

    private function withFiles(object $incident): array
    {
        $operation = DB::table('operations')
            ->leftJoin('flight_requests', 'flight_requests.id', '=', 'operations.flight_request_id')
            ->where('operations.id', $incident->crew_operation_id)
            ->select([
                'operations.id',
                'operations.provider_id',
                'operations.sobrecargo_user_id',
                'flight_requests.origin',
                'flight_requests.destination',
                'flight_requests.departure_datetime',
                'providers.company_name as provider_company_name',
                'providers.commercial_name as provider_commercial_name',
            ])
            ->leftJoin('providers', 'providers.id', '=', 'operations.provider_id')
            ->first();
        $crew = DB::table('users')
            ->leftJoin('providers as crew_providers', 'crew_providers.id', '=', 'users.provider_id')
            ->where('users.id', $incident->crew_id)
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.provider_id',
                'crew_providers.company_name as crew_provider_company_name',
                'crew_providers.commercial_name as crew_provider_commercial_name',
            ])
            ->first();
        $route = trim(implode(' - ', array_filter([
            $operation?->origin,
            $operation?->destination,
        ])));
        $files = DB::table('crew_operation_incident_files')
            ->where('incident_id', $incident->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($file) => [
                'id' => $file->id,
                'storage_disk' => $file->storage_disk ?: 'public',
                'file_path' => $file->file_path,
                'file_url' => $this->resolveIncidentFileUrl($file),
                'file_type' => $file->file_type,
                'original_name' => $file->original_name,
                'created_at' => $file->created_at,
                'updated_at' => $file->updated_at,
            ])
            ->values();

        return [
            'id' => $incident->id,
            'crew_operation_id' => $incident->crew_operation_id,
            'operation_route' => $route ?: 'Ruta por definir',
            'operation_departure_datetime' => $operation?->departure_datetime,
            'crew_id' => $incident->crew_id,
            'crew_name' => $crew?->name ?: 'Sobrecargo por definir',
            'crew_email' => $crew?->email,
            'crew_provider_id' => $crew?->provider_id,
            'crew_provider_name' => $crew?->crew_provider_company_name ?: $crew?->crew_provider_commercial_name,
            'crew_provider_company_name' => $crew?->crew_provider_company_name,
            'crew_provider_commercial_name' => $crew?->crew_provider_commercial_name,
            'provider_id' => $operation?->provider_id,
            'provider_name' => $operation?->provider_company_name ?: $operation?->provider_commercial_name,
            'provider_company_name' => $operation?->provider_company_name,
            'provider_commercial_name' => $operation?->provider_commercial_name,
            'provider_timeline_id' => $incident->provider_timeline_id,
            'reported_by' => $incident->reported_by,
            'phase' => $incident->phase,
            'category' => $incident->category,
            'priority' => $incident->priority,
            'status' => $incident->status,
            'description' => $incident->description,
            'admin_response' => $incident->admin_response,
            'reported_at' => $incident->reported_at,
            'resolved_at' => $incident->resolved_at,
            'closed_at' => $incident->closed_at,
            'created_at' => $incident->created_at,
            'updated_at' => $incident->updated_at,
            'files' => $files,
        ];
    }

    private function resolveIncidentUploadDisk(): string
    {
        $missingVariables = $this->missingS3UploadConfigurationVariables();

        abort_if(
            $missingVariables !== [],
            500,
            'La evidencia de incidencias de sobrecargo solo puede guardarse en AWS S3. '
            .'Faltan variables: '.implode(', ', $missingVariables).'.'
        );

        return 's3';
    }

    private function resolveIncidentFileUrl(object $file): ?string
    {
        $path = trim((string) ($file->file_path ?? ''));
        if ($path === '') {
            return null;
        }

        $disk = trim((string) ($file->storage_disk ?? 'public')) ?: 'public';

        // Solo exponemos evidencias si realmente viven en AWS S3.
        if ($disk !== 's3') {
            return null;
        }

        if ($this->canGenerateTemporaryS3Urls()) {
            try {
                return Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(30));
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function canGenerateTemporaryS3Urls(): bool
    {
        $key = trim((string) config('filesystems.disks.s3.key', ''));
        $secret = trim((string) config('filesystems.disks.s3.secret', ''));
        $bucket = trim((string) config('filesystems.disks.s3.bucket', ''));
        $region = trim((string) config('filesystems.disks.s3.region', ''));

        return $key !== '' && $secret !== '' && $bucket !== '' && $region !== '';
    }

    private function missingS3UploadConfigurationVariables(): array
    {
        $required = [
            'AWS_ACCESS_KEY_ID' => config('filesystems.disks.s3.key'),
            'AWS_SECRET_ACCESS_KEY' => config('filesystems.disks.s3.secret'),
            'AWS_BUCKET' => config('filesystems.disks.s3.bucket'),
            'AWS_DEFAULT_REGION' => config('filesystems.disks.s3.region'),
        ];

        return array_keys(array_filter(
            $required,
            fn ($value) => $this->isMissingS3ConfigValue($value)
        ));
    }

    private function isMissingS3ConfigValue(mixed $value): bool
    {
        $normalized = trim((string) $value);

        return $normalized === ''
            || str_starts_with($normalized, 'TU_NUEVA_')
            || str_starts_with($normalized, 'your_')
            || str_starts_with($normalized, 'YOUR_');
    }

    private function syncProviderTimeline(object $incident, Operacion $operation): ?LineaTiempoOperacion
    {
        if (! $operation->provider_id) {
            return null;
        }

        $timeline = null;
        $timelineId = (int) ($incident->provider_timeline_id ?? 0);
        if ($timelineId > 0) {
            $timeline = LineaTiempoOperacion::query()
                ->where('operation_id', $operation->id)
                ->find($timelineId);
        }

        if (! $timeline) {
            $timeline = new LineaTiempoOperacion([
                'operation_id' => $operation->id,
                'created_by' => $incident->reported_by ?? $incident->crew_id,
            ]);
        }

        $statusLabel = $this->humanizeIncidentStatus($incident->status ?? 'open');
        $categoryLabel = $this->humanizeCategory($incident->category ?? '');
        $priorityLabel = $this->humanizePriority($incident->priority ?? '');
        $providerName = $operation->proveedor?->commercial_name ?: $operation->proveedor?->company_name;
        $crewName = $operation->sobrecargo?->name;
        $route = trim(implode(' - ', array_filter([
            $operation->solicitudVuelo?->origin,
            $operation->solicitudVuelo?->destination,
        ])));
        $filesLabel = $this->incidentFilesLabel((int) $incident->id);

        $description = (string) ($timeline->description ?? '');
        $description = $this->replaceTaggedValue($description, 'Origen', 'Sobrecargo');
        $description = $this->replaceTaggedValue($description, 'Categoria', $categoryLabel);
        $description = $this->replaceTaggedValue($description, 'Prioridad', $priorityLabel);
        $description = $this->replaceTaggedValue($description, 'Fase', $incident->phase ?: null);
        $description = $this->replaceTaggedValue($description, 'Ruta', $route ?: null);
        $description = $this->replaceTaggedValue($description, 'Empresa', $providerName ?: null);
        $description = $this->replaceTaggedValue($description, 'Sobrecargo', $crewName ?: null);
        $description = $this->replaceTaggedValue($description, 'Descripcion', $incident->description ?? null);
        $description = $this->replaceTaggedValue($description, 'Evidencia', $filesLabel ?: null);
        $description = $this->replaceTaggedValue($description, 'Estado', $statusLabel);
        $description = $this->replaceTaggedValue($description, 'Respuesta Admin', $incident->admin_response ?: null);

        $timeline->fill([
            'status' => in_array($incident->status, ['resolved', 'closed'], true) ? 'cerrada' : 'incidencia',
            'title' => 'Incidencia de sobrecargo · '.$categoryLabel,
            'description' => $description,
        ]);
        $timeline->save();

        $this->syncOperationStatusFromIncident($operation, $incident->status ?? 'open');

        return $timeline->fresh(['operacion.solicitudVuelo', 'operacion.proveedor', 'operacion.sobrecargo']);
    }

    private function syncOperationStatusFromIncident(Operacion $operation, string $incidentStatus): void
    {
        $normalized = strtolower(trim($incidentStatus));
        $currentStatus = strtolower((string) $operation->status);

        if (in_array($normalized, ['open', 'in_review'], true)) {
            if (! in_array($currentStatus, ['cancelada', 'cancelled', 'finalizada', 'completed'], true)) {
                $operation->forceFill([
                    'status' => 'incidencia',
                    'crew_status' => 'crew_incident_reported',
                ])->save();
            }

            return;
        }

        if (! in_array($currentStatus, ['incidencia', 'issue', 'incident'], true)) {
            return;
        }

        $isCompleted = ! empty($operation->crew_service_completed_at) || ! empty($operation->completed_at);
        $operation->forceFill([
            'status' => $isCompleted ? 'completed' : 'confirmed',
            'crew_status' => $isCompleted ? 'crew_completed' : 'crew_confirmed',
        ])->save();
    }

    private function incidentFilesLabel(int $incidentId): ?string
    {
        $files = DB::table('crew_operation_incident_files')
            ->where('incident_id', $incidentId)
            ->orderBy('id')
            ->pluck('original_name')
            ->filter()
            ->values()
            ->all();

        return $files ? implode(', ', $files) : null;
    }

    private function humanizeCategory(string $category): string
    {
        return match ($category) {
            'catering' => 'Catering',
            'cabina' => 'Cabina',
            'cliente' => 'Cliente',
            'seguridad' => 'Seguridad',
            'horario' => 'Horario',
            'coordinacion' => 'Coordinacion',
            'otro' => 'Otro',
            default => $category ?: 'Incidencia',
        };
    }

    private function humanizePriority(string $priority): string
    {
        return match ($priority) {
            'baja' => 'Baja',
            'media' => 'Media',
            'alta' => 'Alta',
            'critica' => 'Critica',
            default => $priority ?: 'Media',
        };
    }

    private function humanizeIncidentStatus(string $status): string
    {
        return match ($status) {
            'open' => 'Abierta',
            'in_review' => 'En revision',
            'resolved' => 'Resuelta',
            'closed' => 'Cerrada',
            default => $status ?: 'Abierta',
        };
    }

    private function replaceTaggedValue(string $description, string $label, ?string $value): string
    {
        $segments = collect(explode('|', $description))
            ->map(fn ($segment) => trim($segment))
            ->filter()
            ->reject(fn ($segment) => str_starts_with($segment, $label.':'))
            ->values()
            ->all();

        if ($value !== null && $value !== '') {
            $segments[] = $label.': '.$value;
        }

        return implode(' | ', $segments);
    }
}
