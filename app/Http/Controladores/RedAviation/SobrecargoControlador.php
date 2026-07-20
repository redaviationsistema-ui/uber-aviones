<?php

namespace App\Http\Controladores\RedAviation;

use App\Dominio\Sobrecargo\CrewAssignmentStatus;
use App\Http\Controladores\ControladorBase;
use App\Modelos\AsignacionSobrecargo;
use App\Modelos\CatalogoDisponibilidadEstatus;
use App\Modelos\ChecklistItem;
use App\Modelos\ChecklistOperacion;
use App\Modelos\LineaTiempoOperacion;
use App\Modelos\Operacion;
use App\Modelos\RegistroAuditoria;
use App\Modelos\SobrecargoDisponibilidad;
use App\Modelos\Usuario;
use App\Servicios\Operaciones\ReservationLifecycleService;
use App\Servicios\RedAviation\VisibilidadServicio;
use App\Servicios\Sobrecargo\CrewOperationalAuditService;
use App\Servicios\Sobrecargo\CrewOperationalNotificationService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SobrecargoControlador extends ControladorBase
{
    public function __construct(
        private readonly VisibilidadServicio $visibilidadServicio,
        private readonly ReservationLifecycleService $reservationLifecycleService,
        private readonly CrewOperationalAuditService $crewAudit,
        private readonly CrewOperationalNotificationService $crewNotifications,
    ) {}

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
            'checklists.items',
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
        $data = $request->validate([
            'response' => ['required', Rule::in(['Confirmado', 'Rechazado', 'Solicitar revision', 'confirmed', 'rejected', 'clarification_requested'])],
            'reject_reason' => ['nullable', Rule::in(['schedule_conflict', 'not_available', 'documentation_issue', 'medical', 'distance', 'personal', 'other'])],
            'comment' => ['nullable', 'string'],
        ]);
        $response = match ($data['response']) {
            'Confirmado', 'confirmed' => CrewAssignmentStatus::CONFIRMED,
            'Rechazado', 'rejected' => CrewAssignmentStatus::REJECTED,
            default => 'clarification_requested',
        };
        if ($response === CrewAssignmentStatus::REJECTED && empty($data['reject_reason'])) {
            throw ValidationException::withMessages(['reject_reason' => 'Selecciona un motivo de rechazo.']);
        }

        [$assignment, $timeline] = DB::transaction(function () use ($operation, $request, $data, $response) {
            $lockedOperation = Operacion::query()->with('solicitudVuelo')->lockForUpdate()->findOrFail($operation->id);
            abort_if($lockedOperation->sobrecargo_user_id !== $request->user()->id, 403);
            $assignment = AsignacionSobrecargo::query()->lockForUpdate()->firstOrCreate(
                ['operation_id' => $lockedOperation->id, 'sobrecargo_user_id' => $request->user()->id],
                ['status' => CrewAssignmentStatus::PENDING_CONFIRMATION, 'assigned_at' => now()]
            );
            $current = CrewAssignmentStatus::normalize($assignment->status);
            abort_if(in_array($current, [CrewAssignmentStatus::CONFIRMED, CrewAssignmentStatus::REJECTED, CrewAssignmentStatus::CANCELLED], true), 409, 'La asignacion ya fue respondida o cancelada.');
            abort_if($response === CrewAssignmentStatus::CONFIRMED && $assignment->response_deadline?->isPast(), 409, 'La fecha limite para aceptar esta asignacion ya vencio.');

            if ($response === CrewAssignmentStatus::CONFIRMED) {
                $departure = $lockedOperation->solicitudVuelo?->departure_datetime;
                $end = $lockedOperation->solicitudVuelo?->return_datetime ?: optional($departure)->copy()?->addHours(8);
                if ($departure && $end && $this->reservationLifecycleService->crewHasConflict((int) $request->user()->id, Carbon::parse($departure), Carbon::parse($end), (int) $lockedOperation->id)) {
                    throw ValidationException::withMessages(['response' => 'Existe otra operacion activa en el mismo horario.']);
                }
                $blocked = $departure && SobrecargoDisponibilidad::query()
                    ->where('sobrecargo_id', $request->user()->id)
                    ->whereDate('fecha', Carbon::parse($departure)->toDateString())
                    ->whereHas('estatus', fn ($query) => $query->where('permite_asignacion', false))->exists();
                throw_if($blocked, ValidationException::withMessages(['response' => 'Tu disponibilidad actual bloquea esta operacion.']));
            }

            $assignment->update([
                'status' => $response,
                'accepted_at' => $response === CrewAssignmentStatus::CONFIRMED ? now() : null,
                'rejected_at' => $response === CrewAssignmentStatus::REJECTED ? now() : null,
                'rejection_reason' => $response === CrewAssignmentStatus::REJECTED ? $data['reject_reason'] : null,
            ]);
            $this->crewAudit->record(
                $request,
                $request->user(),
                $lockedOperation,
                $response === CrewAssignmentStatus::CONFIRMED ? 'assignment_accepted' : ($response === CrewAssignmentStatus::REJECTED ? 'assignment_rejected' : 'assignment_clarification_requested'),
                $current,
                $response,
                $data['reject_reason'] ?? $data['comment'] ?? null,
                ['assignment_id' => $assignment->id],
            );
            DB::afterCommit(function () use ($lockedOperation, $assignment, $response) {
                Usuario::query()
                    ->where(fn ($query) => $query->where('role', Usuario::ROLE_ADMIN)->orWhere('operational_role', Usuario::ROLE_ADMIN))
                    ->each(fn (Usuario $admin) => $this->crewNotifications->send(
                        $admin,
                        $lockedOperation,
                        $response === CrewAssignmentStatus::CONFIRMED ? 'assignment_accepted' : 'assignment_rejected',
                        $response === CrewAssignmentStatus::CONFIRMED ? 'Asignacion aceptada' : 'Asignacion rechazada',
                        $response === CrewAssignmentStatus::CONFIRMED ? 'La sobrecargo acepto la asignacion.' : 'La sobrecargo rechazo la asignacion.',
                        $response === CrewAssignmentStatus::CONFIRMED ? 'success' : 'warning',
                        $assignment->id,
                    ));
            });
            $lockedOperation->update([
                'status' => $response === 'clarification_requested' ? 'revision_requested' : $lockedOperation->status,
                'crew_status' => $response,
                'crew_confirmed_at' => $response === CrewAssignmentStatus::CONFIRMED ? now() : null,
                'crew_decline_reason' => $response === CrewAssignmentStatus::REJECTED ? $data['reject_reason'] : null,
                'crew_notes' => $data['comment'] ?? $data['reject_reason'] ?? $lockedOperation->crew_notes,
            ]);
            if ($response === CrewAssignmentStatus::CONFIRMED) {
                $this->ensureOperationChecklist($lockedOperation, $request->user()->id, 'preparation');
            }
            $timeline = LineaTiempoOperacion::create([
                'operation_id' => $lockedOperation->id, 'status' => $response,
                'title' => $response === CrewAssignmentStatus::CONFIRMED ? 'Sobrecargo acepta asignacion' : ($response === CrewAssignmentStatus::REJECTED ? 'Sobrecargo rechaza asignacion' : 'Sobrecargo solicita aclaracion'),
                'description' => $data['comment'] ?? $data['reject_reason'] ?? 'Respuesta registrada.', 'created_by' => $request->user()->id,
            ]);

            return [$assignment->fresh(), $timeline];
        }, 3);

        $operation = $operation->fresh(['solicitudVuelo', 'aeronave', 'proveedor', 'timeline']);

        return $this->ok([
            'assignment' => $assignment,
            'operation' => $this->formatAssignmentPayload($operation, $request->user()->id),
            'timeline_item' => $timeline,
        ]);
    }

    public function checkinOperation(Request $request, Operacion $operation)
    {
        abort_if($operation->sobrecargo_user_id !== $request->user()->id, 403);
        $data = $request->validate([
            'note' => ['nullable', 'string'],
            'base' => ['nullable', 'string', 'max:120'],
            'fit_to_operate' => ['required', 'boolean'],
        ]);
        abort_unless($data['fit_to_operate'], 422, 'Debes confirmar que estas apta para operar.');

        return DB::transaction(function () use ($operation, $request, $data) {
            $locked = Operacion::query()->lockForUpdate()->findOrFail($operation->id);
            abort_if($locked->crew_checkin_at, 409, 'El check-in ya fue registrado.');
            abort_unless(CrewAssignmentStatus::normalize($locked->crew_status) === CrewAssignmentStatus::READY_FOR_OPERATION, 409, 'Completa primero la preparacion previa.');
            $assignment = AsignacionSobrecargo::query()->where('operation_id', $locked->id)
                ->where('sobrecargo_user_id', $request->user()->id)->latest('id')->first();
            $presentation = $assignment?->presentation_time ? Carbon::parse($assignment->presentation_time) : null;
            $minutesLate = $presentation ? max(0, $presentation->diffInMinutes(now(), false)) : 0;
            $punctuality = $minutesLate >= 30 ? 'very_late' : ($minutesLate > 0 ? 'late' : 'on_time');
            $previous = CrewAssignmentStatus::normalize($locked->crew_status);
            $locked->update([
                'crew_status' => CrewAssignmentStatus::CHECKED_IN, 'crew_notes' => $data['note'] ?? $locked->crew_notes,
                'crew_checkin_at' => now(), 'crew_checkin_base' => $data['base'] ?? null,
                'crew_checkin_status' => $punctuality, 'crew_checkin_notes' => $data['note'] ?? null, 'crew_fit_to_operate' => true,
            ]);
            $this->ensureOperationChecklist($locked, $request->user()->id, 'preflight');
            $timeline = LineaTiempoOperacion::create([
                'operation_id' => $locked->id, 'status' => 'crew_checkin', 'title' => 'Sobrecargo confirma check-in operativo',
                'description' => $data['note'] ?: 'El sobrecargo ya reporto llegada a FBO / aeropuerto.', 'created_by' => $request->user()->id,
            ]);
            $event = $punctuality === 'on_time' ? 'check_in_completed' : 'check_in_late';
            $this->crewAudit->record($request, $request->user(), $locked, $event, $previous, CrewAssignmentStatus::CHECKED_IN, $data['note'] ?? null, ['assignment_id' => $assignment?->id, 'minutes_late' => $minutesLate]);
            if ($punctuality !== 'on_time') {
                DB::afterCommit(fn () => $this->notifyAdmins($locked, 'check_in_late', 'Check-in tardio', "El check-in se registro con {$minutesLate} minutos de retraso.", 'warning', $assignment?->id));
            }

            return $this->ok(['operation' => $this->formatAssignmentPayload($locked->fresh(['solicitudVuelo', 'aeronave', 'proveedor', 'timeline', 'checklists.items']), $request->user()->id), 'timeline_item' => $timeline]);
        }, 3);
    }

    public function markCabinReady(Request $request, Operacion $operation)
    {
        abort_if($operation->sobrecargo_user_id !== $request->user()->id, 403);
        abort_unless(CrewAssignmentStatus::normalize($operation->crew_status) === CrewAssignmentStatus::CABIN_READY, 409, 'Completa primero el checklist prevuelo sin fallas criticas pendientes.');
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

        abort_unless(CrewAssignmentStatus::normalize($operation->crew_status) === CrewAssignmentStatus::BOARDING, 409, 'Primero inicia el abordaje desde el estado cabina lista.');
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
            'crew_status' => CrewAssignmentStatus::BOARDING_COMPLETED,
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

        abort(403, 'El inicio de vuelo debe ser confirmado por administracion u operaciones.');
    }

    public function completeService(Request $request, Operacion $operation)
    {
        abort_if($operation->sobrecargo_user_id !== $request->user()->id, 403);

        abort(409, 'Completa el checklist postvuelo y envia el reporte final para cerrar tu participacion.');
    }

    public function completeChecklist(Request $request, ChecklistOperacion $checklist)
    {
        abort_if($checklist->sobrecargo_user_id !== $request->user()->id, 403);
        abort(409, 'Completa cada elemento mediante el flujo de checklist para conservar validaciones y trazabilidad.');
    }

    public function workflow(Request $request, Operacion $operation)
    {
        abort_if($operation->sobrecargo_user_id !== $request->user()->id, 403);
        $operation->loadMissing(['checklists.items', 'timeline' => fn ($query) => $query->oldest('id')]);

        return $this->ok([
            'status' => CrewAssignmentStatus::normalize($operation->crew_status),
            'checklists' => $operation->checklists,
            'report' => $operation->crew_final_report,
            'timeline' => $operation->timeline,
        ]);
    }

    public function updateChecklistItem(Request $request, Operacion $operation, string $type, ChecklistItem $item)
    {
        abort_if($operation->sobrecargo_user_id !== $request->user()->id, 403);
        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'completed', 'not_applicable', 'failed'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $checklist = $this->ensureOperationChecklist($operation, $request->user()->id, $type);
        abort_if($item->checklist_id !== $checklist->id, 404);
        if ($data['status'] === 'failed' && $item->is_critical && empty($data['notes'])) {
            throw ValidationException::withMessages(['notes' => 'Un elemento critico fallido requiere observaciones.']);
        }

        DB::transaction(function () use ($item, $data, $request, $checklist, $operation, $type) {
            $lockedOperation = Operacion::query()->lockForUpdate()->findOrFail($operation->id);
            $lockedItem = ChecklistItem::query()->lockForUpdate()->findOrFail($item->id);
            abort_if(in_array(CrewAssignmentStatus::normalize($lockedOperation->crew_status), [CrewAssignmentStatus::CREW_COMPLETED, CrewAssignmentStatus::ADMINISTRATIVELY_CLOSED, CrewAssignmentStatus::CANCELLED], true), 409, 'La operacion ya no admite cambios en checklists.');
            $lockedItem->update([
                'status' => $data['status'], 'notes' => $data['notes'] ?? null,
                'is_completed' => in_array($data['status'], ['completed', 'not_applicable'], true),
                'completed_at' => in_array($data['status'], ['completed', 'not_applicable'], true) ? now() : null,
                'completed_by' => $request->user()->id,
            ]);
            if ($data['status'] === 'failed' && $lockedItem->is_critical) {
                $assignmentId = AsignacionSobrecargo::query()->where('operation_id', $lockedOperation->id)->latest('id')->value('id');
                $this->crewAudit->record($request, $request->user(), $lockedOperation, 'critical_item_failed', CrewAssignmentStatus::normalize($lockedOperation->crew_status), CrewAssignmentStatus::normalize($lockedOperation->crew_status), $data['notes'] ?? null, ['assignment_id' => $assignmentId, 'checklist_item_id' => $lockedItem->id]);
                DB::afterCommit(fn () => $this->notifyAdmins($lockedOperation, 'critical_checklist_failed', 'Falla critica en checklist', $data['notes'] ?? 'Un elemento critico fue marcado como fallido.', 'critical', $assignmentId, ['idempotency_context' => 'item_'.$lockedItem->id]));
            }
            $items = $checklist->items()->get();
            $hasBlockingItem = $items->contains(fn ($entry) => $entry->is_required && ! in_array($entry->status, ['completed', 'not_applicable'], true));
            if (! $hasBlockingItem) {
                $checklist->update(['status' => 'completed', 'submitted_at' => now()]);
                $nextStatus = match ($type) {
                    'preparation' => CrewAssignmentStatus::READY_FOR_OPERATION,
                    'preflight' => CrewAssignmentStatus::CABIN_READY,
                    'postflight' => CrewAssignmentStatus::REPORT_PENDING,
                    default => CrewAssignmentStatus::normalize($operation->crew_status),
                };
                $lockedOperation->update(['crew_status' => $nextStatus]);
                LineaTiempoOperacion::firstOrCreate(
                    ['operation_id' => $operation->id, 'status' => $nextStatus],
                    ['title' => 'Checklist '.str_replace('_', ' ', $type).' completado', 'description' => 'Todos los elementos obligatorios fueron respondidos.', 'created_by' => $request->user()->id]
                );
            }
        });

        return $this->ok(['checklist' => $checklist->fresh('items'), 'operation' => $operation->fresh()]);
    }

    public function transitionOperation(Request $request, Operacion $operation)
    {
        abort_if($operation->sobrecargo_user_id !== $request->user()->id, 403);
        $data = $request->validate(['status' => ['required', 'string'], 'notes' => ['nullable', 'string']]);
        $target = CrewAssignmentStatus::normalize($data['status']);
        $allowedForCrew = [CrewAssignmentStatus::PREPARATION_PENDING, CrewAssignmentStatus::PREFLIGHT_IN_PROGRESS, CrewAssignmentStatus::BOARDING, CrewAssignmentStatus::BOARDING_COMPLETED, CrewAssignmentStatus::POSTFLIGHT_PENDING];
        abort_unless(in_array($target, $allowedForCrew, true), 403, 'Esta transicion corresponde a operaciones o administracion.');

        return DB::transaction(function () use ($operation, $target, $data, $request) {
            $locked = Operacion::query()->lockForUpdate()->findOrFail($operation->id);
            $current = CrewAssignmentStatus::normalize($locked->crew_status);
            abort_unless(CrewAssignmentStatus::canTransition($current, $target), 409, 'La transicion solicitada no corresponde al estado actual.');
            $locked->update(['crew_status' => $target]);
            if ($target === CrewAssignmentStatus::PREFLIGHT_IN_PROGRESS) {
                $this->ensureOperationChecklist($locked, $request->user()->id, 'preflight');
            }
            if ($target === CrewAssignmentStatus::POSTFLIGHT_PENDING) {
                $this->ensureOperationChecklist($locked, $request->user()->id, 'postflight');
            }
            $timeline = LineaTiempoOperacion::create(['operation_id' => $locked->id, 'status' => $target, 'title' => 'Avance operativo: '.str_replace('_', ' ', $target), 'description' => $data['notes'] ?? null, 'created_by' => $request->user()->id]);

            return $this->ok(['operation' => $locked->fresh(), 'timeline_item' => $timeline]);
        });
    }

    public function submitFinalReport(Request $request, Operacion $operation)
    {
        abort_if($operation->sobrecargo_user_id !== $request->user()->id, 403);
        $data = $request->validate([
            'service_rating' => ['required', 'integer', 'between:1,5'],
            'cabin_condition' => ['required', 'string', 'max:100'],
            'catering_condition' => ['required', 'string', 'max:100'],
            'passenger_observations' => ['nullable', 'string'], 'forgotten_items' => ['nullable', 'string'],
            'damages' => ['nullable', 'string'], 'cleaning_required' => ['required', 'boolean'],
            'restocking_required' => ['required', 'boolean'], 'general_notes' => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($operation, $data, $request) {
            $locked = Operacion::query()->lockForUpdate()->findOrFail($operation->id);
            abort_if($locked->crew_report_submitted_at, 409, 'El reporte final ya fue enviado.');
            abort_unless(CrewAssignmentStatus::normalize($locked->crew_status) === CrewAssignmentStatus::REPORT_PENDING, 409, 'Completa primero el checklist posterior al vuelo.');
            $locked->update(['crew_final_report' => $data, 'crew_report_submitted_at' => now(), 'crew_service_completed_at' => now(), 'crew_status' => CrewAssignmentStatus::CREW_COMPLETED]);
            $timeline = LineaTiempoOperacion::create(['operation_id' => $locked->id, 'status' => CrewAssignmentStatus::CREW_COMPLETED, 'title' => 'Reporte final entregado', 'description' => $data['general_notes'] ?? 'Reporte posterior recibido.', 'created_by' => $request->user()->id]);
            $assignmentId = AsignacionSobrecargo::query()->where('operation_id', $locked->id)->latest('id')->value('id');
            $this->crewAudit->record($request, $request->user(), $locked, 'report_submitted', CrewAssignmentStatus::REPORT_PENDING, CrewAssignmentStatus::CREW_COMPLETED, $data['general_notes'] ?? null, ['assignment_id' => $assignmentId]);
            DB::afterCommit(fn () => $this->notifyAdmins($locked, 'report_submitted', 'Reporte final enviado', 'La sobrecargo envio el reporte final de la operacion.', 'success', $assignmentId));

            return $this->ok(['operation' => $locked->fresh(), 'report' => $locked->crew_final_report, 'timeline_item' => $timeline], 201);
        });
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
            'dias.*.hora_inicio' => ['nullable', 'date_format:H:i'],
            'dias.*.hora_fin' => ['nullable', 'date_format:H:i'],
            'dias.*.tipo' => ['nullable', Rule::in(['available', 'blocked', 'partial'])],
            'dias.*.base' => ['nullable', 'string', 'max:100'],
            'dias.*.inmediata' => ['nullable', 'boolean'],
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
            'hora_inicio' => ['nullable', 'date_format:H:i'],
            'hora_fin' => ['nullable', 'date_format:H:i', 'after:hora_inicio'],
            'tipo' => ['nullable', Rule::in(['available', 'blocked', 'partial'])],
            'inmediata' => ['nullable', 'boolean'],
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

    private function ensureOperationChecklist(Operacion $operation, int $crewUserId, string $type): ChecklistOperacion
    {
        $templates = [
            'preparation' => [
                ['personal_documents', 'personal', 'Documentacion personal revisada', true],
                ['uniform_ready', 'personal', 'Uniforme listo', true],
                ['transfer_confirmed', 'logistics', 'Traslado confirmado', true],
                ['presentation_reviewed', 'logistics', 'Hora de presentacion revisada', true],
                ['route_reviewed', 'operation', 'Ruta revisada', true],
                ['aircraft_reviewed', 'operation', 'Aeronave revisada', true],
                ['manifest_reviewed', 'passengers', 'Manifiesto consultado', true],
                ['special_requirements', 'passengers', 'Requerimientos especiales revisados', true],
                ['catering_reviewed', 'service', 'Catering revisado', false],
                ['service_material_ready', 'service', 'Material de servicio preparado', false],
            ],
            'preflight' => [
                ['cabin_cleaning', 'cabin', 'Limpieza de cabina', false], ['seatbelts', 'cabin', 'Asientos y cinturones', true],
                ['baggage_secured', 'cabin', 'Equipaje asegurado', true], ['restroom', 'cabin', 'Baño revisado', false],
                ['fire_extinguishers', 'safety', 'Extintores', true], ['first_aid', 'safety', 'Botiquin', true],
                ['emergency_equipment', 'safety', 'Equipo de emergencia', true], ['emergency_exits', 'safety', 'Salidas libres', true],
                ['oxygen', 'safety', 'Oxigeno', true], ['catering_received', 'service', 'Catering recibido', false],
                ['special_food', 'service', 'Alimentos especiales', false], ['passenger_manifest', 'passengers', 'Manifiesto revisado', true],
                ['special_passengers', 'passengers', 'Pasajeros especiales identificados', true], ['pets', 'passengers', 'Mascotas confirmadas', false],
            ],
            'postflight' => [
                ['disembark_confirmed', 'passengers', 'Desembarque confirmado', true], ['forgotten_items', 'cabin', 'Objetos olvidados revisados', true],
                ['damage_review', 'cabin', 'Daños revisados', true], ['cabin_condition', 'cabin', 'Condicion final de cabina', true],
                ['leftover_catering', 'service', 'Catering sobrante registrado', false], ['missing_inventory', 'service', 'Faltantes registrados', false],
                ['cabin_handover', 'operation', 'Entrega de cabina confirmada', true],
            ],
        ];
        abort_unless(array_key_exists($type, $templates), 422, 'Tipo de checklist no soportado.');
        $checklist = ChecklistOperacion::firstOrCreate(
            ['operation_id' => $operation->id, 'sobrecargo_user_id' => $crewUserId, 'type' => $type],
            ['status' => 'pending']
        );
        foreach ($templates[$type] as [$code, $category, $label, $critical]) {
            $checklist->items()->firstOrCreate(['code' => $code], [
                'category' => $category, 'label' => $label, 'status' => 'pending',
                'is_required' => true, 'is_critical' => $critical, 'is_completed' => false,
            ]);
        }

        return $checklist->load('items');
    }

    private function notifyAdmins(Operacion $operation, string $type, string $title, string $message, string $level, ?int $assignmentId = null, array $extra = []): void
    {
        Usuario::query()->where(fn ($query) => $query->where('role', Usuario::ROLE_ADMIN)->orWhere('operational_role', Usuario::ROLE_ADMIN))
            ->each(fn (Usuario $admin) => $this->crewNotifications->send($admin, $operation, $type, $title, $message, $level, $assignmentId, $extra));
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
            'hora_inicio' => $availability->hora_inicio,
            'hora_fin' => $availability->hora_fin,
            'tipo' => $availability->tipo,
            'base' => $availability->base,
            'inmediata' => (bool) $availability->inmediata,
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
        if (! empty($data['hora_inicio']) && ! empty($data['hora_fin'])) {
            abort_if($data['hora_inicio'] >= $data['hora_fin'], 422, 'La hora final debe ser posterior a la hora inicial.');
        }
        if (! $status->permite_asignacion) {
            $confirmedOperationExists = Operacion::query()
                ->where('sobrecargo_user_id', $request->user()->id)
                ->whereNotIn('crew_status', [CrewAssignmentStatus::REJECTED, CrewAssignmentStatus::CANCELLED])
                ->whereHas('solicitudVuelo', fn ($query) => $query
                    ->whereDate('departure_datetime', '<=', $date)
                    ->where(fn ($range) => $range->whereNull('return_datetime')->whereDate('departure_datetime', $date)->orWhereDate('return_datetime', '>=', $date)))
                ->exists();
            abort_if($confirmedOperationExists, 409, 'No puedes bloquear disponibilidad sobre una operacion asignada o confirmada.');
        }
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
                'hora_inicio' => $data['hora_inicio'] ?? null,
                'hora_fin' => $data['hora_fin'] ?? null,
                'tipo' => $data['tipo'] ?? (($data['hora_inicio'] ?? null) ? 'partial' : ($status->permite_asignacion ? 'available' : 'blocked')),
                'base' => $data['base'] ?? null,
                'inmediata' => (bool) ($data['inmediata'] ?? false),
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
        $auditTimeline = RegistroAuditoria::query()->with('user:id,name,role,operational_role')
            ->where('module', 'crew_operations')->where('entity', 'operations')->where('entity_id', (string) $operation->id)
            ->oldest('id')->get();

        return [
            'id' => $operation->id,
            'status' => $operation->status,
            'crew_status' => CrewAssignmentStatus::normalize($operation->crew_status),
            'workflow_status' => CrewAssignmentStatus::normalize($operation->crew_status),
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
            'assignment' => $assignment ? [
                'id' => $assignment->id,
                'role' => $assignment->role,
                'status' => CrewAssignmentStatus::normalize($assignment->status),
                'assigned_at' => optional($assignment->assigned_at)?->toISOString(),
                'response_deadline' => optional($assignment->response_deadline)?->toISOString(),
                'presentation_time' => optional($assignment->presentation_time)?->toISOString(),
                'accepted_at' => optional($assignment->accepted_at)?->toISOString(),
                'rejected_at' => optional($assignment->rejected_at)?->toISOString(),
                'rejection_reason' => $assignment->rejection_reason,
                'cancelled_at' => optional($assignment->cancelled_at)?->toISOString(),
                'cancellation_reason' => $assignment->cancellation_reason,
            ] : null,
            'checklists' => $operation->checklists->map(fn (ChecklistOperacion $checklist) => [
                'id' => $checklist->id,
                'type' => $checklist->type,
                'status' => $checklist->status,
                'submitted_at' => optional($checklist->submitted_at)?->toISOString(),
                'items' => $checklist->items->map(fn (ChecklistItem $item) => [
                    'id' => $item->id, 'code' => $item->code, 'category' => $item->category,
                    'description' => $item->description, 'status' => $item->status,
                    'is_required' => $item->is_required, 'is_critical' => $item->is_critical,
                    'notes' => $item->notes,
                ])->values(),
            ])->values(),
            'final_report' => $operation->crew_final_report,
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
                    'id' => 'timeline-'.$item->id,
                    'status' => $item->status,
                    'title' => $item->title,
                    'description' => $item->description,
                    'created_at' => optional($item->created_at)?->toISOString(),
                ])
                ->concat($auditTimeline->map(fn (RegistroAuditoria $item) => [
                    'id' => 'audit-'.$item->id,
                    'status' => data_get($item->after, 'status') ?: $item->action,
                    'title' => str_replace('_', ' ', $item->action),
                    'description' => $item->reason ?: $item->description,
                    'actor' => $item->user?->name ?: 'Sistema',
                    'actor_role' => data_get($item->metadata, 'actor_role') ?: $item->user?->effectiveRole(),
                    'previous_status' => data_get($item->before, 'status'),
                    'new_status' => data_get($item->after, 'status'),
                    'reason' => $item->reason,
                    'created_at' => optional($item->created_at)?->toISOString(),
                ]))
                ->sortBy('created_at')
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
