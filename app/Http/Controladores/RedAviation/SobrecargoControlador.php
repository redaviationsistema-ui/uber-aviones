<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
use App\Modelos\AsignacionSobrecargo;
use App\Modelos\CatalogoDisponibilidadEstatus;
use App\Modelos\ChecklistItem;
use App\Modelos\ChecklistOperacion;
use App\Modelos\LineaTiempoOperacion;
use App\Modelos\Operacion;
use App\Modelos\SobrecargoDisponibilidad;
use App\Modelos\Usuario;
use App\Servicios\RedAviation\VisibilidadServicio;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SobrecargoControlador extends ControladorBase
{
    public function __construct(private readonly VisibilidadServicio $visibilidadServicio)
    {
    }

    public function dashboard(Request $request)
    {
        return $this->ok([
            'metrics' => [
                'asignaciones' => Operacion::where('sobrecargo_user_id', $request->user()->id)->count(),
                'servicios_activos' => Operacion::where('sobrecargo_user_id', $request->user()->id)
                    ->whereNotIn('status', ['finalizada', 'completed', 'cancelled'])
                    ->count(),
            ],
        ]);
    }

    public function profile(Request $request)
    {
        $user = $request->user()->loadMissing([
            'profile:id,user_id,avatar,avatar_url,city,base_airport,birth_date,nationality,document_type,document_number,document_expiration,identity_validation_required,tax_data',
            'provider:id,user_id,company_name,commercial_name',
            'roles:id,code,name',
        ]);

        return $this->ok([
            'profile' => $this->formatProfilePayload($user),
            'user' => $this->serializeCrewUser($user),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user()->loadMissing('profile');
        $profile = $user->profile ?: $user->profile()->make(['user_id' => $user->id]);
        $taxData = $profile->tax_data ?? [];

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'base' => ['nullable', 'string', 'max:100'],
            'base_airport' => ['nullable', 'string', 'max:20'],
            'languages' => ['nullable', 'array'],
            'languages.*' => ['nullable', 'string', 'max:100'],
            'certifications' => ['nullable', 'array'],
            'certifications.*' => ['nullable', 'string', 'max:150'],
            'experience' => ['nullable', 'string', 'max:255'],
            'bank_data' => ['nullable', 'string', 'max:255'],
            'weekly_availability' => ['nullable', 'string', 'max:255'],
            'profile_state' => ['nullable', 'string', 'max:100'],
            'current_status' => ['nullable', 'string', 'max:100'],
            'preferences' => ['nullable', 'array'],
            'preferences.notify_assignments' => ['nullable', 'boolean'],
            'preferences.notify_incidents' => ['nullable', 'boolean'],
            'preferences.notify_schedule_changes' => ['nullable', 'boolean'],
            'preferences.personal_coverage' => ['nullable', 'string', 'max:150'],
            'preferences.escalation_mode' => ['nullable', 'string', 'max:150'],
        ]);

        $user->fill([
            'name' => $data['name'] ?? $user->name,
            'phone' => $data['phone'] ?? $user->phone,
            'email' => $data['email'] ?? $user->email,
        ])->save();

        $resolvedBase = $data['base_airport'] ?? $data['base'] ?? null;

        $profile->fill([
            'city' => array_key_exists('base', $data) ? $data['base'] : $profile->city,
            'base_airport' => array_key_exists('base_airport', $data) || array_key_exists('base', $data)
                ? $resolvedBase
                : $profile->base_airport,
            'tax_data' => array_merge($taxData, [
                'languages' => $data['languages'] ?? ($taxData['languages'] ?? []),
                'certifications' => $data['certifications'] ?? ($taxData['certifications'] ?? []),
                'experience' => $data['experience'] ?? ($taxData['experience'] ?? null),
                'bank_data' => $data['bank_data'] ?? ($taxData['bank_data'] ?? null),
                'weekly_availability' => $data['weekly_availability'] ?? ($taxData['weekly_availability'] ?? null),
                'profile_state' => $data['profile_state'] ?? ($taxData['profile_state'] ?? 'Pendiente'),
                'current_status' => $data['current_status'] ?? ($taxData['current_status'] ?? 'Disponible'),
                'preferences' => array_merge($taxData['preferences'] ?? [], $data['preferences'] ?? []),
                'documents' => $taxData['documents'] ?? [],
                'availability' => $taxData['availability'] ?? [],
            ]),
        ]);

        $profile->save();

        return $this->ok([
            'profile' => $this->formatProfilePayload($user->fresh(['profile', 'provider', 'roles'])),
        ]);
    }

    public function assignments(Request $request)
    {
        $operations = Operacion::with([
            'solicitudVuelo',
            'aeronave',
            'proveedor',
            'timeline' => fn ($query) => $query->latest('id'),
        ])
            ->where('sobrecargo_user_id', $request->user()->id)
            ->latest()
            ->get()
            ->map(fn (Operacion $operation) => $this->formatAssignmentPayload($operation, $request->user()->id))
            ->values();

        return $this->ok([
            'assignments' => $operations,
        ]);
    }

    public function respondAssignment(Request $request, Operacion $operation)
    {
        abort_if($operation->sobrecargo_user_id !== $request->user()->id, 403);

        $data = $request->validate([
            'response' => ['required', Rule::in(['Confirmado', 'Rechazado', 'Solicitar revision'])],
            'reject_reason' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string'],
        ]);

        $assignment = AsignacionSobrecargo::query()->updateOrCreate(
            [
                'operation_id' => $operation->id,
                'sobrecargo_user_id' => $request->user()->id,
            ],
            [
                'status' => $data['response'],
            ],
        );

        $crewStatus = match ($data['response']) {
            'Confirmado' => 'crew_confirmed',
            'Rechazado' => 'crew_declined',
            default => 'crew_change_requested',
        };

        $operation->update([
            'status' => match ($data['response']) {
                'Confirmado' => 'confirmed',
                'Solicitar revision' => 'revision_requested',
                default => $operation->status,
            },
            'crew_status' => $crewStatus,
            'crew_confirmed_at' => $data['response'] === 'Confirmado' ? now() : null,
            'crew_decline_reason' => $data['response'] === 'Rechazado' ? ($data['reject_reason'] ?? null) : null,
            'crew_notes' => $data['comment'] ?? $data['reject_reason'] ?? $operation->crew_notes,
            'crew_checkin_at' => $data['response'] === 'Confirmado' ? $operation->crew_checkin_at : null,
            'crew_service_started_at' => $data['response'] === 'Confirmado' ? $operation->crew_service_started_at : null,
            'crew_service_completed_at' => $data['response'] === 'Confirmado' ? $operation->crew_service_completed_at : null,
        ]);

        $timeline = LineaTiempoOperacion::create([
            'operation_id' => $operation->id,
            'status' => match ($data['response']) {
                'Confirmado' => 'confirmada',
                'Rechazado' => 'rechazada',
                default => 'revision_solicitada',
            },
            'title' => match ($data['response']) {
                'Confirmado' => 'Sobrecargo confirma asignacion',
                'Rechazado' => 'Sobrecargo rechaza asignacion',
                default => 'Sobrecargo solicita revision',
            },
            'description' => $data['comment']
                ?: $data['reject_reason']
                ?: 'Respuesta de asignacion registrada por el sobrecargo.',
            'created_by' => $request->user()->id,
        ]);

        return $this->ok([
            'assignment' => $assignment,
            'operation' => $this->formatAssignmentPayload($operation->fresh(['solicitudVuelo', 'aeronave', 'proveedor', 'timeline']), $request->user()->id),
            'timeline_item' => $timeline,
        ]);
    }

    public function checkinOperation(Request $request, Operacion $operation)
    {
        abort_if($operation->sobrecargo_user_id !== $request->user()->id, 403);

        $data = $request->validate([
            'note' => ['nullable', 'string'],
        ]);

        $operation->update([
            'crew_status' => 'crew_enroute',
            'crew_notes' => $data['note'] ?? $operation->crew_notes,
            'crew_checkin_at' => $operation->crew_checkin_at ?? now(),
        ]);

        $timeline = LineaTiempoOperacion::create([
            'operation_id' => $operation->id,
            'status' => 'crew_checkin',
            'title' => 'Sobrecargo confirma check-in operativo',
            'description' => $data['note'] ?: 'El sobrecargo ya reporto llegada a FBO / aeropuerto.',
            'created_by' => $request->user()->id,
        ]);

        return $this->ok([
            'operation' => $this->formatAssignmentPayload($operation->fresh(['solicitudVuelo', 'aeronave', 'proveedor', 'timeline']), $request->user()->id),
            'timeline_item' => $timeline,
        ]);
    }

    public function markCabinReady(Request $request, Operacion $operation)
    {
        abort_if($operation->sobrecargo_user_id !== $request->user()->id, 403);

        abort_if(! $operation->crew_checkin_at, 422, 'Primero confirma tu llegada a aeropuerto o base.');
        abort_if($operation->crew_service_started_at, 422, 'La cabina ya no puede marcarse porque el servicio ya inicio.');

        $data = $request->validate([
            'note' => ['nullable', 'string'],
        ]);

        $existingTimeline = $operation->timeline()
            ->where('status', 'cabina_lista')
            ->latest('id')
            ->first();

        if (! $existingTimeline) {
            $existingTimeline = LineaTiempoOperacion::create([
                'operation_id' => $operation->id,
                'status' => 'cabina_lista',
                'title' => 'Sobrecargo revisa cabina, catering e insumos',
                'description' => $data['note'] ?: 'Cabina, catering e insumos revisados por el sobrecargo.',
                'created_by' => $request->user()->id,
            ]);
        }

        $operation->update([
            'status' => 'cabina_lista',
            'crew_status' => 'crew_enroute',
            'crew_notes' => $data['note'] ?? $operation->crew_notes,
        ]);

        return $this->ok([
            'operation' => $this->formatAssignmentPayload($operation->fresh(['solicitudVuelo', 'aeronave', 'proveedor', 'timeline']), $request->user()->id),
            'timeline_item' => $existingTimeline,
        ]);
    }

    public function markPassengersReady(Request $request, Operacion $operation)
    {
        abort_if($operation->sobrecargo_user_id !== $request->user()->id, 403);

        abort_if(! $operation->crew_checkin_at, 422, 'Primero confirma tu llegada a aeropuerto o base.');
        abort_if($operation->crew_service_started_at, 422, 'Los pasajeros ya no pueden marcarse porque el servicio ya inicio.');

        $hasCabinReady = $operation->timeline()
            ->where('status', 'cabina_lista')
            ->exists();

        abort_if(! $hasCabinReady, 422, 'Primero registra que la cabina, catering e insumos estan listos.');

        $data = $request->validate([
            'note' => ['nullable', 'string'],
        ]);

        $existingTimeline = $operation->timeline()
            ->where('status', 'pasajeros_recibidos')
            ->latest('id')
            ->first();

        if (! $existingTimeline) {
            $existingTimeline = LineaTiempoOperacion::create([
                'operation_id' => $operation->id,
                'status' => 'pasajeros_recibidos',
                'title' => 'Sobrecargo recibe pasajeros',
                'description' => $data['note'] ?: 'Los pasajeros ya fueron recibidos para el servicio.',
                'created_by' => $request->user()->id,
            ]);
        }

        $operation->update([
            'status' => 'pasajeros_recibidos',
            'crew_status' => 'crew_enroute',
            'crew_notes' => $data['note'] ?? $operation->crew_notes,
        ]);

        return $this->ok([
            'operation' => $this->formatAssignmentPayload($operation->fresh(['solicitudVuelo', 'aeronave', 'proveedor', 'timeline']), $request->user()->id),
            'timeline_item' => $existingTimeline,
        ]);
    }

    public function operation(Request $request, Operacion $operation)
    {
        abort_if($operation->sobrecargo_user_id !== $request->user()->id, 403);

        return $this->ok([
            'operation' => $this->visibilidadServicio->operacionParaSobrecargo($operation->loadMissing(['solicitudVuelo', 'timeline'])),
        ]);
    }

    public function startService(Request $request, Operacion $operation)
    {
        abort_if($operation->sobrecargo_user_id !== $request->user()->id, 403);

        abort_if(! $operation->crew_checkin_at, 422, 'Primero confirma tu llegada a aeropuerto o base.');

        $hasPassengersReady = $operation->timeline()
            ->where('status', 'pasajeros_recibidos')
            ->exists();

        abort_if(! $hasPassengersReady, 422, 'Primero registra que los pasajeros ya fueron recibidos.');
        abort_if($operation->crew_service_started_at, 422, 'El servicio ya fue iniciado.');

        $operation->update([
            'status' => 'in_progress',
            'crew_status' => 'crew_active',
            'crew_service_started_at' => $operation->crew_service_started_at ?? now(),
            'started_at' => now(),
        ]);

        $timeline = LineaTiempoOperacion::create([
            'operation_id' => $operation->id,
            'status' => 'servicio_iniciado',
            'title' => 'Sobrecargo inicia servicio',
            'description' => 'La atencion al cliente comenzo en el dia del vuelo.',
            'created_by' => $request->user()->id,
        ]);

        return $this->ok([
            'operation' => $operation->fresh(),
            'timeline_item' => $timeline,
        ]);
    }

    public function completeService(Request $request, Operacion $operation)
    {
        abort_if($operation->sobrecargo_user_id !== $request->user()->id, 403);

        $operation->update([
            'status' => 'completed',
            'crew_status' => 'crew_completed',
            'crew_service_completed_at' => now(),
            'completed_at' => now(),
        ]);

        $timeline = LineaTiempoOperacion::create([
            'operation_id' => $operation->id,
            'status' => 'servicio_finalizado',
            'title' => 'Vuelo finalizado',
            'description' => 'El sobrecargo cerro la atencion y dejo el servicio completado.',
            'created_by' => $request->user()->id,
        ]);

        return $this->ok([
            'operation' => $operation->fresh(),
            'timeline_item' => $timeline,
        ]);
    }

    public function completeChecklist(Request $request, ChecklistOperacion $checklist)
    {
        abort_if($checklist->sobrecargo_user_id !== $request->user()->id, 403);

        ChecklistItem::where('checklist_id', $checklist->id)->update([
            'is_completed' => true,
            'completed_at' => now(),
        ]);

        $checklist->update(['status' => 'completado']);

        return $this->ok(['checklist' => $checklist->load('items')]);
    }

    public function documents(Request $request)
    {
        $profile = $request->user()->loadMissing('profile')->profile;
        $documents = collect($profile?->tax_data['documents'] ?? [])->values();

        return $this->ok([
            'documents' => $documents,
        ]);
    }

    public function storeDocument(Request $request)
    {
        $data = $request->validate([
            'document_name' => ['required', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:100'],
            'expires_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
        ]);

        $profile = $request->user()->loadMissing('profile')->profile ?: $request->user()->profile()->make(['user_id' => $request->user()->id]);
        $taxData = $profile->tax_data ?? [];
        $documents = collect($taxData['documents'] ?? []);

        $document = [
            'id' => now()->timestamp.$documents->count(),
            'name' => $data['document_name'],
            'category' => $data['category'] ?? 'Certificacion',
            'state' => 'Pendiente',
            'expires_at' => $data['expires_at'] ?? null,
            'note' => $data['note'] ?? 'Pendiente de validacion administrativa',
            'created_at' => now()->toISOString(),
        ];

        $profile->fill([
            'tax_data' => array_merge($taxData, [
                'documents' => $documents->push($document)->values()->all(),
            ]),
        ]);
        $profile->save();

        return $this->ok([
            'document' => $document,
        ], 201);
    }

    public function updateDocument(Request $request, string $documentId)
    {
        $data = $request->validate([
            'state' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string'],
            'expires_at' => ['nullable', 'date'],
            'category' => ['nullable', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:150'],
        ]);

        $profile = $request->user()->loadMissing('profile')->profile;
        abort_if(! $profile, 404, 'No existe perfil operativo para este sobrecargo.');

        $taxData = $profile->tax_data ?? [];
        $documents = collect($taxData['documents'] ?? []);
        $updatedDocument = null;

        $mapped = $documents->map(function (array $document) use ($documentId, $data, &$updatedDocument) {
            if ((string) ($document['id'] ?? '') !== $documentId) {
                return $document;
            }

            $updatedDocument = array_merge($document, array_filter([
                'name' => $data['name'] ?? null,
                'category' => $data['category'] ?? null,
                'state' => $data['state'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
                'note' => $data['note'] ?? null,
            ], fn ($value) => $value !== null));

            return $updatedDocument;
        })->values();

        abort_if(! $updatedDocument, 404, 'No existe el documento solicitado.');

        $profile->fill([
            'tax_data' => array_merge($taxData, [
                'documents' => $mapped->all(),
            ]),
        ]);
        $profile->save();

        return $this->ok([
            'document' => $updatedDocument,
        ]);
    }

    public function availability(Request $request)
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $from = $data['from'] ?? now()->toDateString();
        $to = $data['to'] ?? now()->addDays(30)->toDateString();

        $storedAvailability = SobrecargoDisponibilidad::query()
            ->with(['estatus', 'aprobadoPor:id,name', 'createdBy:id,name'])
            ->where('sobrecargo_id', $request->user()->id)
            ->whereDate('fecha', '>=', $from)
            ->whereDate('fecha', '<=', $to)
            ->orderBy('fecha')
            ->get()
            ->keyBy(fn (SobrecargoDisponibilidad $item) => $item->fecha?->toDateString());

        $availability = $this->buildAvailabilityRangePayload($storedAvailability, $from, $to);

        return $this->ok([
            'availability' => $availability,
            'statuses' => $this->availabilityStatusesPayload($request->user()),
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function availabilityLog(Request $request, string $availabilityId)
    {
        $availability = SobrecargoDisponibilidad::query()
            ->with(['estatus', 'createdBy:id,name', 'aprobadoPor:id,name'])
            ->where('sobrecargo_id', $request->user()->id)
            ->find($availabilityId);

        abort_if(! $availability, 404, 'No existe el registro de disponibilidad solicitado.');

        return $this->ok([
            'availability' => $this->formatAvailabilityPayload($availability),
            'bitacora' => collect($availability->bitacora ?? [])->values(),
        ]);
    }

    public function availabilityStatuses(Request $request)
    {
        $statuses = CatalogoDisponibilidadEstatus::query()
            ->where('activo', true)
            ->orderBy('orden')
            ->get()
            ->filter(function (CatalogoDisponibilidadEstatus $status) use ($request) {
                if ($request->user()->hasRole(Usuario::ROLE_ADMIN)) {
                    return $status->seleccionable_admin;
                }

                return $status->seleccionable_sobrecargo;
            })
            ->map(fn (CatalogoDisponibilidadEstatus $status) => [
                'id' => $status->id,
                'clave' => $status->clave,
                'nombre' => $status->nombre,
                'descripcion' => $status->descripcion,
                'color' => $status->color,
                'icono' => $status->icono,
                'orden' => $status->orden,
                'seleccionable_sobrecargo' => $status->seleccionable_sobrecargo,
                'seleccionable_admin' => $status->seleccionable_admin,
                'permite_asignacion' => $status->permite_asignacion,
            ])
            ->values();

        return $this->ok([
            'statuses' => $statuses,
        ]);
    }

    public function storeAvailability(Request $request)
    {
        $data = $request->validate([
            'dias' => ['nullable', 'array'],
            'dias.*.fecha' => ['required_with:dias', 'date'],
            'dias.*.estatus_id' => ['nullable', 'integer'],
            'dias.*.status_id' => ['nullable', 'integer'],
            'dias.*.status_key' => ['nullable', 'string', 'max:50'],
            'dias.*.clave' => ['nullable', 'string', 'max:50'],
            'dias.*.status' => ['nullable', 'string', 'max:100'],
            'dias.*.state' => ['nullable', 'string', 'max:100'],
            'dias.*.motivo' => ['nullable', 'string', 'max:255'],
            'dias.*.comentario' => ['nullable', 'string'],
            'fecha' => ['nullable', 'date'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'estatus_id' => ['nullable', 'integer'],
            'status_id' => ['nullable', 'integer'],
            'status_key' => ['nullable', 'string', 'max:50'],
            'clave' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'motivo' => ['nullable', 'string', 'max:255'],
            'comentario' => ['nullable', 'string'],
            'base' => ['nullable', 'string', 'max:100'],
            'coverage' => ['nullable', 'string', 'max:150'],
            'restriction' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
            'operacion_id' => ['nullable', 'exists:operations,id'],
        ]);

        $saved = collect();

        if (! empty($data['dias'])) {
            foreach ($data['dias'] as $day) {
                $status = $this->resolveAvailabilityStatus($request, $day);
                $saved->push(
                    $this->upsertAvailabilityRecord(
                        $request,
                        $status,
                        Carbon::parse($day['fecha'])->toDateString(),
                        $day
                    )
                );
            }

            return $this->ok([
                'availability' => $saved->map(fn (SobrecargoDisponibilidad $item) => $this->formatAvailabilityPayload($item))->values(),
            ], 201);
        }

        $status = $this->resolveAvailabilityStatus($request, $data);
        $startDate = $data['fecha'] ?? $data['from'] ?? $data['starts_at'] ?? null;
        $endDate = $data['to'] ?? $data['ends_at'] ?? $startDate;

        abort_if(! $startDate || ! $endDate, 422, 'Debes indicar una fecha o rango de fechas.');

        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();
        abort_if($start->gt($end), 422, 'La fecha inicial no puede ser mayor a la final.');

        foreach (CarbonPeriod::create($start, $end) as $date) {
            $saved->push(
                $this->upsertAvailabilityRecord($request, $status, $date->toDateString(), $data)
            );
        }

        return $this->ok([
            'availability' => $saved->map(fn (SobrecargoDisponibilidad $item) => $this->formatAvailabilityPayload($item))->values(),
        ], 201);
    }

    public function destroyAvailability(Request $request, string $availabilityId)
    {
        $availability = SobrecargoDisponibilidad::query()
            ->where('sobrecargo_id', $request->user()->id)
            ->find($availabilityId);

        abort_if(! $availability, 404, 'No existe el bloqueo solicitado.');

        $availability->delete();

        return $this->ok([
            'message' => 'Disponibilidad eliminada correctamente.',
        ]);
    }

    public function incidents(Request $request)
    {
        $data = $request->validate([
            'operation_id' => ['required', 'exists:operations,id'],
            'title' => ['nullable', 'string', 'max:150'],
            'type' => ['nullable', 'string', 'max:150'],
            'flight' => ['nullable', 'string', 'max:150'],
            'reference' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'comment' => ['nullable', 'string'],
            'priority' => ['nullable', 'string', 'max:100'],
            'phase' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:100'],
            'evidence' => ['nullable', 'string', 'max:255'],
            'action_taken' => ['nullable', 'string', 'max:255'],
        ]);

        $title = trim((string) ($data['title'] ?? $data['type'] ?? ''));
        abort_if($title === '', 422, 'El tipo o titulo de la incidencia es obligatorio.');

        $description = trim((string) ($data['description'] ?? $data['comment'] ?? ''));
        $flightReference = trim((string) ($data['flight'] ?? $data['reference'] ?? ''));

        $operacion = Operacion::findOrFail($data['operation_id']);
        abort_if($operacion->sobrecargo_user_id !== $request->user()->id, 403);

        $timeline = LineaTiempoOperacion::create([
            'operation_id' => $operacion->id,
            'status' => 'incidencia',
            'title' => $title,
            'description' => trim(implode(' | ', array_filter([
                $description ?: null,
                ! empty($data['priority']) ? 'Prioridad: '.$data['priority'] : null,
                ! empty($data['phase']) ? 'Fase: '.$data['phase'] : null,
                ! empty($data['status']) ? 'Estado: '.$data['status'] : null,
                ! empty($data['evidence']) ? 'Evidencia: '.$data['evidence'] : null,
                ! empty($data['action_taken']) ? 'Accion: '.$data['action_taken'] : null,
                $flightReference !== '' ? 'Vuelo: '.$flightReference : null,
            ]))) ?: null,
            'created_by' => $request->user()->id,
        ]);

        $operacion->update([
            'status' => 'incidencia',
            'crew_status' => 'crew_incident_reported',
            'crew_notes' => $description ?: $operacion->crew_notes,
        ]);

        return $this->ok([
            'incident' => $this->formatIncidentPayload($timeline->fresh(['operacion.solicitudVuelo', 'operacion.aeronave'])),
        ], 201);
    }

    public function storeOperationIncident(Request $request, Operacion $operation)
    {
        abort_if($operation->sobrecargo_user_id !== $request->user()->id, 403);

        $payload = new Request(array_merge($request->all(), [
            'operation_id' => $operation->id,
        ]));
        $payload->setUserResolver(fn () => $request->user());

        return $this->incidents($payload);
    }

    public function listIncidents(Request $request)
    {
        $incidents = LineaTiempoOperacion::with(['operacion.solicitudVuelo', 'operacion.aeronave'])
            ->whereIn('status', ['incidencia', 'cerrada'])
            ->whereHas('operacion', fn ($query) => $query->where('sobrecargo_user_id', $request->user()->id))
            ->latest()
            ->get()
            ->map(fn (LineaTiempoOperacion $incident) => $this->formatIncidentPayload($incident))
            ->values();

        return $this->ok(['incidents' => $incidents]);
    }

    public function updateIncident(Request $request, LineaTiempoOperacion $timeline)
    {
        $timeline->loadMissing('operacion.solicitudVuelo');
        abort_if(! $timeline->operacion || $timeline->operacion->sobrecargo_user_id !== $request->user()->id, 403, 'No puedes editar esta incidencia.');

        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:50'],
            'comment' => ['nullable', 'string'],
            'evidence' => ['nullable', 'string', 'max:255'],
            'action_taken' => ['nullable', 'string', 'max:255'],
        ]);

        $description = (string) ($timeline->description ?? '');
        $description = $this->replaceTaggedValue($description, 'Prioridad', $this->extractTaggedValue($description, 'Prioridad'));
        $description = $this->replaceTaggedValue($description, 'Fase', $this->extractTaggedValue($description, 'Fase'));
        $description = $this->replaceTaggedValue($description, 'Evidencia', $data['evidence'] ?? $this->extractTaggedValue($description, 'Evidencia'));
        $description = $this->replaceTaggedValue($description, 'Accion', $data['action_taken'] ?? $this->extractTaggedValue($description, 'Accion'));
        $description = $this->replaceTaggedValue($description, 'Estado', $data['status'] ?? $this->extractTaggedValue($description, 'Estado'));

        if (! empty($data['comment'])) {
            $description = trim($description ? $description.' | '.$data['comment'] : $data['comment']);
        }

        $timeline->update([
            'status' => strtolower((string) ($data['status'] ?? '')) === 'cerrada' ? 'cerrada' : 'incidencia',
            'description' => $description,
        ]);

        if (! empty($data['status'])) {
            $normalizedStatus = strtolower($data['status']);
            if (in_array($normalizedStatus, ['resuelta por operador', 'cerrada'], true)) {
                $timeline->operacion->update([
                    'status' => $normalizedStatus === 'cerrada' ? 'completed' : 'confirmed',
                    'crew_status' => $normalizedStatus === 'cerrada' ? 'crew_completed' : 'crew_confirmed',
                ]);
            } else {
                $timeline->operacion->update([
                    'status' => 'incidencia',
                    'crew_status' => 'crew_incident_reported',
                ]);
            }
        }

        return $this->ok([
            'incident' => $this->formatIncidentPayload($timeline->fresh(['operacion.solicitudVuelo', 'operacion.aeronave'])),
        ]);
    }

    private function formatProfilePayload(Usuario $user): array
    {
        $profile = $user->profile;
        $taxData = $profile?->tax_data ?? [];
        $documents = is_array($taxData['documents'] ?? null) ? $taxData['documents'] : [];
        $approvedDocuments = collect($documents)
            ->filter(fn ($item) => is_array($item) && in_array(strtolower((string) ($item['status'] ?? '')), ['aprobado', 'approved', 'vigente', 'validado'], true))
            ->count();
        $pendingDocuments = max(count($documents) - $approvedDocuments, 0);

        return [
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'base' => $profile?->base_airport ?: $profile?->city,
            'base_airport' => $profile?->base_airport,
            'languages' => $taxData['languages'] ?? [],
            'certifications' => $taxData['certifications'] ?? [],
            'experience' => $taxData['experience'] ?? null,
            'bank_data' => $taxData['bank_data'] ?? null,
            'weekly_availability' => $taxData['weekly_availability'] ?? null,
            'profile_state' => $taxData['profile_state'] ?? null,
            'current_status' => $taxData['current_status'] ?? null,
            'preferences' => array_merge([
                'notify_assignments' => true,
                'notify_incidents' => true,
                'notify_schedule_changes' => true,
                'personal_coverage' => 'Centro / Bajio',
                'escalation_mode' => 'Operador primero',
            ], $taxData['preferences'] ?? []),
            'documents' => $documents,
            'documents_summary' => count($documents)
                ? sprintf('%d aprobados / %d pendientes', $approvedDocuments, $pendingDocuments)
                : '',
            'photo' => $profile?->avatar_url ?: $profile?->avatar,
            'provider' => $user->provider ? [
                'id' => $user->provider->id,
                'company_name' => $user->provider->company_name,
                'commercial_name' => $user->provider->commercial_name,
            ] : null,
        ];
    }

    private function formatAvailabilityPayload(SobrecargoDisponibilidad $availability): array
    {
        $status = $availability->estatus;

        return [
            'id' => $availability->id,
            'fecha' => $availability->fecha?->toDateString(),
            'from' => $availability->fecha?->toDateString(),
            'to' => $availability->fecha?->toDateString(),
            'estatus_id' => $availability->estatus_id,
            'status_id' => $availability->estatus_id,
            'clave' => $status?->clave,
            'status' => $status?->clave,
            'nombre' => $status?->nombre,
            'state' => $status?->nombre,
            'descripcion' => $status?->descripcion,
            'color' => $status?->color,
            'icono' => $status?->icono,
            'permite_asignacion' => (bool) $status?->permite_asignacion,
            'motivo' => $availability->motivo,
            'comentario' => $availability->comentario,
            'restriction' => $availability->comentario,
            'origen' => $availability->origen,
            'created_by' => $availability->created_by,
            'created_by_nombre' => $availability->createdBy?->name,
            'operacion_id' => $availability->operacion_id,
            'aprobado_por' => $availability->aprobado_por,
            'aprobado_por_nombre' => $availability->aprobadoPor?->name,
            'aprobado_at' => optional($availability->aprobado_at)?->toISOString(),
            'created_at' => optional($availability->created_at)?->toISOString(),
            'updated_at' => optional($availability->updated_at)?->toISOString(),
        ];
    }

    private function formatSyntheticAvailabilityPayload(string $date, CatalogoDisponibilidadEstatus $status): array
    {
        return [
            'id' => null,
            'fecha' => $date,
            'from' => $date,
            'to' => $date,
            'estatus_id' => $status->id,
            'status_id' => $status->id,
            'clave' => $status->clave,
            'status' => $status->clave,
            'nombre' => $status->nombre,
            'state' => $status->nombre,
            'descripcion' => $status->descripcion,
            'color' => $status->color,
            'icono' => $status->icono,
            'permite_asignacion' => (bool) $status->permite_asignacion,
            'motivo' => null,
            'comentario' => null,
            'restriction' => null,
            'origen' => 'SISTEMA',
            'created_by' => null,
            'created_by_nombre' => null,
            'operacion_id' => null,
            'aprobado_por' => null,
            'aprobado_por_nombre' => null,
            'aprobado_at' => null,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    private function resolveAvailabilityStatus(Request $request, array $data): CatalogoDisponibilidadEstatus
    {
        $statusId = $data['estatus_id'] ?? $data['status_id'] ?? null;
        $statusKey = $data['status_key'] ?? $data['clave'] ?? null;
        $statusLabel = $data['state'] ?? $data['status'] ?? null;

        $query = CatalogoDisponibilidadEstatus::query()->where('activo', true);

        if ($statusId) {
            $query->whereKey($statusId);
        } elseif ($statusKey) {
            $query->where('clave', strtoupper(trim($statusKey)));
        } elseif ($statusLabel) {
            $query->where('clave', $this->normalizeAvailabilityStatusKey($statusLabel));
        } else {
            abort(422, 'Debes indicar un estatus para registrar la disponibilidad.');
        }

        $status = $query->first();
        abort_if(! $status, 422, 'El estatus de disponibilidad no existe o no esta activo.');

        $canSelect = $request->user()->hasRole(Usuario::ROLE_ADMIN)
            ? $status->seleccionable_admin
            : $status->seleccionable_sobrecargo;

        abort_if(! $canSelect, 422, 'No puedes seleccionar ese estatus desde este perfil.');

        return $status;
    }

    private function normalizeAvailabilityStatusKey(string $value): string
    {
        return match (strtoupper(trim($value))) {
            'DISPONIBLE' => 'DISPONIBLE',
            'NO DISPONIBLE', 'NO_DISPONIBLE' => 'NO_DISPONIBLE',
            'DESCANSO' => 'DESCANSO',
            'EN OPERACION', 'EN_OPERACION' => 'EN_OPERACION',
            'BLOQUEO SOLICITADO', 'BLOQUEO_SOLICITADO' => 'BLOQUEO_SOLICITADO',
            'BLOQUEO APROBADO', 'BLOQUEO_APROBADO' => 'BLOQUEO_APROBADO',
            'BLOQUEO RECHAZADO', 'BLOQUEO_RECHAZADO' => 'BLOQUEO_RECHAZADO',
            'POR CONFIRMAR', 'POR_CONFIRMAR' => 'POR_CONFIRMAR',
            default => strtoupper(str_replace(' ', '_', trim($value))),
        };
    }

    private function buildAvailabilityLog(?SobrecargoDisponibilidad $existing, CatalogoDisponibilidadEstatus $status, int $userId, array $data): array
    {
        $log = collect($existing?->bitacora ?? []);

        $log->push([
            'timestamp' => now()->toISOString(),
            'user_id' => $userId,
            'previous_estatus_id' => $existing?->estatus_id,
            'new_estatus_id' => $status->id,
            'motivo' => $data['motivo'] ?? null,
            'comentario' => $data['comentario'] ?? $data['restriction'] ?? $data['notes'] ?? null,
        ]);

        return $log->values()->all();
    }

    private function upsertAvailabilityRecord(
        Request $request,
        CatalogoDisponibilidadEstatus $status,
        string $date,
        array $data
    ): SobrecargoDisponibilidad {
        $existing = SobrecargoDisponibilidad::query()
            ->where('sobrecargo_id', $request->user()->id)
            ->whereDate('fecha', $date)
            ->first();

        return SobrecargoDisponibilidad::query()->updateOrCreate(
            [
                'sobrecargo_id' => $request->user()->id,
                'fecha' => $date,
            ],
            [
                'estatus_id' => $status->id,
                'motivo' => $data['motivo'] ?? null,
                'comentario' => $data['comentario'] ?? $data['restriction'] ?? $data['notes'] ?? null,
                'origen' => $request->user()->hasRole(Usuario::ROLE_ADMIN) ? 'ADMIN' : 'SOBRECARGO',
                'operacion_id' => $data['operacion_id'] ?? null,
                'created_by' => $existing?->created_by ?? $request->user()->id,
                'updated_by' => $request->user()->id,
                'bitacora' => $this->buildAvailabilityLog($existing, $status, $request->user()->id, $data),
            ],
        )->loadMissing(['estatus', 'aprobadoPor:id,name', 'createdBy:id,name']);
    }

    private function buildAvailabilityRangePayload($storedAvailability, string $from, string $to)
    {
        $defaultStatus = $this->porConfirmarStatus();
        $days = collect();

        foreach (CarbonPeriod::create(Carbon::parse($from)->startOfDay(), Carbon::parse($to)->startOfDay()) as $date) {
            $dateKey = $date->toDateString();
            $stored = $storedAvailability->get($dateKey);
            $days->push(
                $stored
                    ? $this->formatAvailabilityPayload($stored)
                    : $this->formatSyntheticAvailabilityPayload($dateKey, $defaultStatus)
            );
        }

        return $days->values();
    }

    private function availabilityStatusesPayload(Usuario $user)
    {
        return CatalogoDisponibilidadEstatus::query()
            ->where('activo', true)
            ->orderBy('orden')
            ->get()
            ->filter(function (CatalogoDisponibilidadEstatus $status) use ($user) {
                if ($user->hasRole(Usuario::ROLE_ADMIN)) {
                    return $status->seleccionable_admin;
                }

                return $status->seleccionable_sobrecargo;
            })
            ->map(fn (CatalogoDisponibilidadEstatus $status) => [
                'id' => $status->id,
                'clave' => $status->clave,
                'nombre' => $status->nombre,
                'descripcion' => $status->descripcion,
                'color' => $status->color,
                'icono' => $status->icono,
                'orden' => $status->orden,
                'seleccionable_sobrecargo' => $status->seleccionable_sobrecargo,
                'seleccionable_admin' => $status->seleccionable_admin,
                'permite_asignacion' => $status->permite_asignacion,
            ])
            ->values();
    }

    private function serializeCrewUser(Usuario $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'operational_role' => $user->operational_role,
            'effective_role' => $user->effectiveRole(),
            'status' => $user->status,
            'roles' => $user->roles->map(fn ($role) => [
                'id' => $role->id,
                'code' => $role->code,
                'name' => $role->name,
            ])->values(),
            'profile' => $user->profile ? [
                'city' => $user->profile->city,
                'base_airport' => $user->profile->base_airport,
                'birth_date' => $user->profile->birth_date,
                'nationality' => $user->profile->nationality,
                'document_type' => $user->profile->document_type,
                'document_number' => $user->profile->document_number,
                'document_expiration' => $user->profile->document_expiration,
                'identity_validation_required' => $user->profile->identity_validation_required,
                'avatar' => $user->profile->avatar,
                'avatar_url' => $user->profile->avatar_url,
            ] : null,
            'provider' => $user->provider ? [
                'id' => $user->provider->id,
                'company_name' => $user->provider->company_name,
                'commercial_name' => $user->provider->commercial_name,
            ] : null,
        ];
    }

    private function porConfirmarStatus(): CatalogoDisponibilidadEstatus
    {
        return CatalogoDisponibilidadEstatus::query()
            ->where('clave', 'POR_CONFIRMAR')
            ->firstOrFail();
    }

    private function formatAssignmentPayload(Operacion $operation, int $userId): array
    {
        $assignment = AsignacionSobrecargo::query()
            ->where('operation_id', $operation->id)
            ->where('sobrecargo_user_id', $userId)
            ->latest('id')
            ->first();

        $latestResponse = $operation->timeline
            ->first(fn (LineaTiempoOperacion $item) => in_array($item->status, ['confirmada', 'rechazada', 'revision_solicitada'], true));

        return [
            'id' => $operation->id,
            'status' => $operation->status,
            'crew_status' => $operation->crew_status,
            'crew_status_label' => $this->humanizeCrewStatus($operation->crew_status),
            'response_status' => $assignment?->status
                ?? match ($latestResponse?->status) {
                    'confirmada' => 'Confirmado',
                    'rechazada' => 'Rechazado',
                    'revision_solicitada' => 'Solicitar revision',
                    default => $this->responseStatusFromCrewStatus($operation->crew_status),
                },
            'crew_confirmed_at' => optional($operation->crew_confirmed_at)?->toISOString(),
            'crew_decline_reason' => $operation->crew_decline_reason,
            'crew_notes' => $operation->crew_notes,
            'crew_checkin_at' => optional($operation->crew_checkin_at)?->toISOString(),
            'crew_service_started_at' => optional($operation->crew_service_started_at)?->toISOString(),
            'crew_service_completed_at' => optional($operation->crew_service_completed_at)?->toISOString(),
            'departure_datetime' => $operation->solicitudVuelo?->departure_datetime,
            'origin' => $operation->solicitudVuelo?->origin,
            'destination' => $operation->solicitudVuelo?->destination,
            'passengers' => $operation->solicitudVuelo?->passengers,
            'notes' => $operation->solicitudVuelo?->notes,
            'requirements' => $operation->solicitudVuelo?->requirements,
            'aircraft' => $operation->aeronave?->model,
            'aircraft_model' => $operation->aeronave?->model,
            'provider_name' => $operation->proveedor?->commercial_name ?: $operation->proveedor?->company_name,
            'timeline' => $operation->timeline
                ->map(fn (LineaTiempoOperacion $item) => [
                    'id' => $item->id,
                    'status' => $item->status,
                    'title' => $item->title,
                    'description' => $item->description,
                    'created_at' => optional($item->created_at)?->toISOString(),
                ])
                ->values(),
        ];
    }

    private function formatIncidentPayload(LineaTiempoOperacion $incident): array
    {
        $operation = $incident->operacion;
        $flightRequest = $operation?->solicitudVuelo;
        $status = $this->extractTaggedValue((string) $incident->description, 'Estado');

        return [
            'id' => $incident->id,
            'type' => $incident->title ?: 'Incidencia operativa',
            'flight' => 'OPS-'.($operation?->id ?: 'N/D'),
            'route' => trim(($flightRequest?->origin ?? 'N/D').' - '.($flightRequest?->destination ?? 'N/D')),
            'status' => $status ?: ($incident->status === 'cerrada' ? 'Cerrada' : 'Nueva'),
            'priority' => $this->extractTaggedValue((string) $incident->description, 'Prioridad') ?: 'Media',
            'phase' => $this->extractTaggedValue((string) $incident->description, 'Fase') ?: 'Pre-vuelo',
            'evidence' => $this->extractTaggedValue((string) $incident->description, 'Evidencia'),
            'action_taken' => $this->extractTaggedValue((string) $incident->description, 'Accion') ?: 'Reportado',
            'description' => $incident->description,
            'comment' => $incident->description,
            'created_at' => optional($incident->created_at)?->format('H:i'),
            'operation_id' => $operation?->id,
            'timeline' => array_values(array_filter([
                [
                    'id' => $incident->id.'-1',
                    'time' => optional($incident->created_at)?->format('H:i') ?: now()->format('H:i'),
                    'label' => 'Reportada',
                ],
                $this->extractTaggedValue((string) $incident->description, 'Evidencia')
                    ? [
                        'id' => $incident->id.'-2',
                        'time' => optional($incident->updated_at ?: $incident->created_at)?->format('H:i') ?: now()->format('H:i'),
                        'label' => 'Evidencia subida',
                    ]
                    : null,
                $status
                    ? [
                        'id' => $incident->id.'-3',
                        'time' => optional($incident->updated_at ?: $incident->created_at)?->format('H:i') ?: now()->format('H:i'),
                        'label' => $status,
                    ]
                    : null,
            ])),
        ];
    }

    private function extractTaggedValue(string $description, string $label): ?string
    {
        foreach (explode('|', $description) as $segment) {
            $segment = trim($segment);
            if (str_starts_with($segment, $label.':')) {
                return trim(substr($segment, strlen($label) + 1));
            }
        }

        return null;
    }

    private function replaceTaggedValue(string $description, string $label, ?string $value): string
    {
        $segments = collect(explode('|', $description))
            ->map(fn ($segment) => trim($segment))
            ->filter()
            ->reject(fn ($segment) => str_starts_with($segment, $label.':'))
            ->values();

        if ($value !== null && $value !== '') {
            $segments->push($label.': '.$value);
        }

        return $segments->implode(' | ');
    }

    private function humanizeCrewStatus(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'pending_crew_response' => 'Sin responder',
            'crew_confirmed' => 'Confirmado',
            'crew_declined' => 'Rechazado',
            'crew_change_requested' => 'Solicita cambio',
            'crew_enroute' => 'En traslado',
            'crew_active' => 'En servicio',
            'crew_completed' => 'Finalizado',
            'crew_incident_reported' => 'Con incidencia',
            default => $status ?: 'Pendiente',
        };
    }

    private function responseStatusFromCrewStatus(?string $crewStatus): ?string
    {
        return match (strtolower((string) $crewStatus)) {
            'crew_confirmed', 'crew_enroute', 'crew_active', 'crew_completed' => 'Confirmado',
            'crew_declined' => 'Rechazado',
            'crew_change_requested' => 'Solicitar revision',
            default => null,
        };
    }
}
