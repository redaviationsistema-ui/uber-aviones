<?php

namespace App\Http\Controladores\RedAviation;

use App\Http\Controladores\ControladorBase;
use App\Modelos\AsignacionSobrecargo;
use App\Modelos\ChecklistItem;
use App\Modelos\ChecklistOperacion;
use App\Modelos\LineaTiempoOperacion;
use App\Modelos\Operacion;
use App\Modelos\Usuario;
use App\Servicios\RedAviation\VisibilidadServicio;
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
        $user = $request->user()->loadMissing(['profile', 'provider', 'roles']);

        return $this->ok([
            'profile' => $this->formatProfilePayload($user),
            'user' => $user,
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

        $profile->fill([
            'city' => $data['base'] ?? $profile->city,
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

        if ($data['response'] === 'Confirmado') {
            $operation->update(['status' => 'confirmed']);
        } elseif ($data['response'] === 'Solicitar revision') {
            $operation->update(['status' => 'revision_requested']);
        }

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

        $operation->update([
            'status' => 'in_progress',
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
        $profile = $request->user()->loadMissing('profile')->profile;
        $availability = collect($profile?->tax_data['availability'] ?? [])->values();

        return $this->ok([
            'availability' => $availability,
        ]);
    }

    public function storeAvailability(Request $request)
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'base' => ['nullable', 'string', 'max:100'],
            'coverage' => ['nullable', 'string', 'max:150'],
            'restriction' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $profile = $request->user()->loadMissing('profile')->profile ?: $request->user()->profile()->make(['user_id' => $request->user()->id]);
        $taxData = $profile->tax_data ?? [];
        $availability = collect($taxData['availability'] ?? []);

        $block = [
            'id' => now()->timestamp.$availability->count(),
            'from' => $data['from'] ?? $data['starts_at'] ?? null,
            'to' => $data['to'] ?? $data['ends_at'] ?? null,
            'state' => $data['state'] ?? $data['status'] ?? 'Disponible',
            'base' => $data['base'] ?? ($profile->city ?: null),
            'coverage' => $data['coverage'] ?? null,
            'restriction' => $data['restriction'] ?? $data['notes'] ?? null,
            'created_at' => now()->toISOString(),
        ];

        $profile->fill([
            'tax_data' => array_merge($taxData, [
                'availability' => $availability->push($block)->values()->all(),
            ]),
        ]);
        $profile->save();

        return $this->ok([
            'availability' => $block,
        ], 201);
    }

    public function destroyAvailability(Request $request, string $availabilityId)
    {
        $profile = $request->user()->loadMissing('profile')->profile;
        abort_if(! $profile, 404, 'No existe perfil operativo para este sobrecargo.');

        $taxData = $profile->tax_data ?? [];
        $availability = collect($taxData['availability'] ?? []);
        $filtered = $availability->reject(fn (array $item) => (string) ($item['id'] ?? '') === $availabilityId)->values();

        abort_if($filtered->count() === $availability->count(), 404, 'No existe el bloqueo solicitado.');

        $profile->fill([
            'tax_data' => array_merge($taxData, [
                'availability' => $filtered->all(),
            ]),
        ]);
        $profile->save();

        return $this->ok([
            'message' => 'Disponibilidad eliminada correctamente.',
        ]);
    }

    public function incidents(Request $request)
    {
        $data = $request->validate([
            'operation_id' => ['required', 'exists:operations,id'],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', 'string', 'max:100'],
            'phase' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:100'],
            'evidence' => ['nullable', 'string', 'max:255'],
            'action_taken' => ['nullable', 'string', 'max:255'],
        ]);

        $operacion = Operacion::findOrFail($data['operation_id']);
        abort_if($operacion->sobrecargo_user_id !== $request->user()->id, 403);

        $timeline = LineaTiempoOperacion::create([
            'operation_id' => $operacion->id,
            'status' => 'incidencia',
            'title' => $data['title'],
            'description' => trim(implode(' | ', array_filter([
                $data['description'] ?? null,
                $data['priority'] ? 'Prioridad: '.$data['priority'] : null,
                $data['phase'] ? 'Fase: '.$data['phase'] : null,
                $data['evidence'] ? 'Evidencia: '.$data['evidence'] : null,
                $data['action_taken'] ? 'Accion: '.$data['action_taken'] : null,
            ]))) ?: null,
            'created_by' => $request->user()->id,
        ]);

        $operacion->update(['status' => 'incidencia']);

        return $this->ok(['incident' => $timeline], 201);
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
                $timeline->operacion->update(['status' => $normalizedStatus === 'cerrada' ? 'completed' : 'confirmed']);
            } else {
                $timeline->operacion->update(['status' => 'incidencia']);
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

        return [
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'base' => $profile?->city,
            'languages' => $taxData['languages'] ?? [],
            'certifications' => $taxData['certifications'] ?? [],
            'experience' => $taxData['experience'] ?? null,
            'bank_data' => $taxData['bank_data'] ?? null,
            'weekly_availability' => $taxData['weekly_availability'] ?? null,
            'profile_state' => $taxData['profile_state'] ?? 'Pendiente',
            'current_status' => $taxData['current_status'] ?? 'Disponible',
            'preferences' => array_merge([
                'notify_assignments' => true,
                'notify_incidents' => true,
                'notify_schedule_changes' => true,
                'personal_coverage' => 'Centro / Bajio',
                'escalation_mode' => 'Operador primero',
            ], $taxData['preferences'] ?? []),
            'documents' => $taxData['documents'] ?? [],
            'availability' => $taxData['availability'] ?? [],
            'photo' => $profile?->avatar_url ?: $profile?->avatar,
            'provider' => $user->provider,
        ];
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
            'response_status' => $assignment?->status
                ?? match ($latestResponse?->status) {
                    'confirmada' => 'Confirmado',
                    'rechazada' => 'Rechazado',
                    'revision_solicitada' => 'Solicitar revision',
                    default => null,
                },
            'departure_datetime' => $operation->solicitudVuelo?->departure_datetime,
            'origin' => $operation->solicitudVuelo?->origin,
            'destination' => $operation->solicitudVuelo?->destination,
            'passengers' => $operation->solicitudVuelo?->passengers,
            'notes' => $operation->solicitudVuelo?->notes,
            'requirements' => $operation->solicitudVuelo?->requirements,
            'aircraft' => $operation->aeronave?->model,
            'aircraft_model' => $operation->aeronave?->model,
            'provider_name' => $operation->proveedor?->commercial_name ?: $operation->proveedor?->company_name,
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
}
