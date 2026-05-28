<?php

namespace App\Http\Controladores;

use App\Modelos\Aeronave;
use App\Modelos\LineaTiempoOperacion;
use App\Modelos\Operacion;
use App\Modelos\SolicitudVuelo;
use App\Modelos\Comision;
use App\Modelos\DocumentoEmpresa;
use App\Modelos\Pago;
use App\Modelos\PagoProveedor;
use App\Modelos\Perfil;
use App\Modelos\Proveedor;
use App\Modelos\RegistroAuditoria;
use App\Modelos\Reserva;
use App\Modelos\Usuario;
use App\Servicios\ReintentoCoincidenciaSolicitudServicio;
use App\Servicios\RedAviation\VisibilidadServicio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProveedorControlador extends ControladorBase
{
    public function __construct(
        private readonly ReintentoCoincidenciaSolicitudServicio $reintentoServicio,
        private readonly VisibilidadServicio $visibilidadServicio,
    )
    {
    }

    public function dashboard(Request $request)
    {
        $user = $request->user()->loadMissing('provider', 'profile');
        $provider = $user->provider;
        abort_if(! $provider, 404, 'Proveedor no encontrado.');

        return $this->ok([
            'provider' => $this->formatCompanyPayload($user),
            'metrics' => [
                'aircraft' => $provider->aircraft()->count(),
                'active_aircraft' => $provider->aircraft()->whereIn('status', ['active', 'trial_active'])->count(),
                'inactive_aircraft' => $provider->aircraft()->whereIn('status', ['inactive', 'blocked', 'archived', 'suspended'])->count(),
                'pending_requests' => SolicitudVuelo::whereHas('matches', function ($query) use ($provider) {
                    $query->where('provider_id', $provider->id)
                        ->whereIn('status', ['pending', 'sent_to_provider']);
                })->count(),
                'active_operations' => Operacion::where('provider_id', $provider->id)
                    ->whereNotIn('status', ['finalizada', 'cancelada', 'completed', 'cancelled'])
                    ->count(),
                'open_incidents' => LineaTiempoOperacion::whereHas('operacion', fn ($query) => $query->where('provider_id', $provider->id))
                    ->where('status', 'incidencia')
                    ->count(),
                'pending_quotes' => $provider->quotes()->where('status', 'pending')->count(),
                'reservations' => $provider->reservations()->count(),
                'pending_payments' => PagoProveedor::where('provider_id', $provider->id)
                    ->whereIn('status', ['pending', 'held'])
                    ->count(),
            ],
        ]);
    }

    public function company(Request $request)
    {
        return $this->ok([
            'company' => $this->formatCompanyPayload($request),
        ]);
    }

    public function updateCompany(Request $request)
    {
        $user = $request->user()->loadMissing('provider', 'profile');
        $provider = $user->provider;
        abort_if(! $provider, 404, 'Proveedor no encontrado.');

        $data = $request->validate([
            'legal_name' => ['nullable', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'commercial_name' => ['nullable', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'rfc' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'address' => ['nullable', 'string', 'max:255'],
            'legal_representative' => ['nullable', 'string', 'max:255'],
            'jet_a_price' => ['nullable', 'numeric', 'min:0'],
            'margin_percent' => ['nullable', 'numeric', 'min:0'],
            'fixed_fee' => ['nullable', 'numeric', 'min:0'],
            'admin_notes' => ['nullable', 'string'],
            'documents' => ['nullable', 'array'],
            'documents.*.name' => ['nullable', 'string', 'max:255'],
            'documents.*.state' => ['nullable', 'string', 'max:100'],
        ]);

        $profile = $user->profile ?: new Perfil(['user_id' => $user->id]);
        $currentTaxData = $profile->tax_data ?? [];

        $documents = array_key_exists('documents', $data)
            ? array_values(array_map(
                fn ($document, $index) => [
                    'id' => $document['id'] ?? ($index + 1),
                    'name' => $document['name'] ?? ('Documento '.($index + 1)),
                    'state' => $document['state'] ?? 'pendiente',
                ],
                $data['documents'],
                array_keys($data['documents'])
            ))
            : ($currentTaxData['documents'] ?? []);

        $profile->fill([
            'company_name' => $data['legal_name'] ?? $data['company_name'] ?? $provider->company_name,
            'address' => $data['address'] ?? $profile->address,
            'tax_data' => [
                ...$currentTaxData,
                'rfc' => $data['rfc'] ?? ($currentTaxData['rfc'] ?? null),
                'legal_name' => $data['legal_name'] ?? $data['company_name'] ?? ($currentTaxData['legal_name'] ?? $provider->company_name),
                'legal_representative' => $data['legal_representative'] ?? ($currentTaxData['legal_representative'] ?? null),
                'documents' => $documents,
            ],
        ])->save();

        $provider->update([
            'company_name' => $data['legal_name'] ?? $data['company_name'] ?? $provider->company_name,
            'commercial_name' => $data['commercial_name'] ?? $data['trade_name'] ?? $provider->commercial_name,
            'jet_a_price' => $data['jet_a_price'] ?? $provider->jet_a_price,
            'margin_percent' => $data['margin_percent'] ?? $provider->margin_percent,
            'fixed_fee' => $data['fixed_fee'] ?? $provider->fixed_fee,
            'notes' => $data['admin_notes'] ?? $provider->notes,
        ]);

        $user->update(array_filter([
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
        ], fn ($value) => $value !== null));

        $this->writeAudit($request, 'update', 'provider_company', 'Empresa del proveedor actualizada.');

        return $this->ok([
            'company' => $this->formatCompanyPayload($request->user()->fresh(['provider', 'profile'])),
        ]);
    }

    public function submitCompanyReview(Request $request)
    {
        $provider = $request->user()->provider;
        abort_if(! $provider, 404, 'Proveedor no encontrado.');

        $provider->update(['approval_status' => 'pending']);
        $this->writeAudit($request, 'submit_review', 'provider_company', 'Proveedor enviado a revision.');

        return $this->ok([
            'company' => $this->formatCompanyPayload($request->user()->fresh(['provider', 'profile'])),
            'message' => 'Empresa enviada a revision.',
        ]);
    }

    public function storeCompanyDocument(Request $request)
    {
        $user = $request->user()->loadMissing('provider', 'profile');
        $provider = $user->provider;
        abort_if(! $provider, 404, 'Proveedor no encontrado.');

        $data = $request->validate([
            'file' => ['required_without_all:file_url,document_url', 'nullable', 'file', 'max:20480'],
            'file_url' => ['required_without_all:file,document_url', 'nullable', 'string', 'max:255'],
            'document_name' => ['nullable', 'string', 'max:150'],
            'document_url' => ['required_without_all:file,file_url', 'nullable', 'string'],
            'expires_at' => ['nullable', 'date'],
        ]);

        if ($request->hasFile('file')) {
            $path = $request->file('file')->store('company-documents', 's3');
            $documentUrl = Storage::disk('s3')->url($path);
            $data['file_url'] = $documentUrl;
            $data['document_url'] = $documentUrl;
            $data['mime_type'] = $request->file('file')->getClientMimeType();
            $data['file_size_bytes'] = $request->file('file')->getSize();
            $data['document_name'] = $data['document_name'] ?? $request->file('file')->getClientOriginalName();
        }

        $data['file_url'] = $data['file_url'] ?? $data['document_url'];
        $data['document_url'] = $data['document_url'] ?? $data['file_url'];
        $data['status'] = 'pendiente';

        $document = $provider->companyDocuments()->create($data);

        $this->writeAudit($request, 'upload', 'provider_company_document', 'Documento de empresa cargado.');

        return $this->ok([
            'document' => $document,
            'company' => $this->formatCompanyPayload($request->user()->fresh(['provider', 'profile'])),
            'url' => $document->document_url,
        ], 201);
    }

    public function requests(Request $request)
    {
        $providerId = $request->user()->provider_id;
        abort_if(! $providerId, 404, 'Proveedor no encontrado.');

        $perPage = min(max((int) $request->integer('per_page', 20), 1), 100);

        $requestsPaginator = SolicitudVuelo::query()
            ->select([
                'id',
                'client_id',
                'assigned_provider_id',
                'assigned_aircraft_id',
                'assigned_aircraft_model',
                'origin',
                'destination',
                'departure_datetime',
                'passengers',
                'trip_type',
                'aircraft_type',
                'requirements',
                'visibility_payload',
                'base_price',
                'operational_fee',
                'priority_price',
                'final_price',
                'currency',
                'pricing_context',
                'payment_status',
                'status',
                'workflow_status',
                'created_at',
            ])
            ->with([
                'matches' => fn ($query) => $query
                    ->select([
                        'id',
                        'flight_request_id',
                        'aircraft_id',
                        'provider_id',
                        'estimated_price',
                        'status',
                        'response_deadline',
                        'visibility_payload',
                    ])
                    ->where('provider_id', $providerId),
                'matches.aircraft:id,model,category,capacity',
                'assignedAircraft:id,model,category,capacity',
                'legs:id,flight_request_id,leg_order,origin,destination,departure_datetime,arrival_datetime,passengers,distance_km',
                'reservation:id,flight_request_id,status',
                'reservation.contract:id,reservation_id,status',
                'reservation.latestPayment' => fn ($query) => $query->select([
                    'payments.id',
                    'payments.reservation_id',
                    'payments.status',
                ]),
                'latestOperation' => fn ($query) => $query->select([
                    'operations.id',
                    'operations.flight_request_id',
                    'operations.status',
                ]),
            ])
            ->where(function ($query) use ($providerId) {
                $query->where('assigned_provider_id', $providerId)
                    ->orWhereHas('matches', fn ($matchQuery) => $matchQuery->where('provider_id', $providerId));
            })
            ->latest()
            ->paginate($perPage);

        $requests = $requestsPaginator->getCollection()
            ->map(fn ($solicitud) => $this->visibilidadServicio->solicitudParaOperador($solicitud))
            ->values();

        $requestsPaginator->setCollection($requests);

        return $this->ok([
            'requests' => $requests,
            'flight_requests' => $requests,
            'pagination' => [
                'current_page' => $requestsPaginator->currentPage(),
                'last_page' => $requestsPaginator->lastPage(),
                'per_page' => $requestsPaginator->perPage(),
                'total' => $requestsPaginator->total(),
                'has_more_pages' => $requestsPaginator->hasMorePages(),
            ],
        ]);
    }

    public function showRequest(Request $request, SolicitudVuelo $flightRequest)
    {
        $providerId = $request->user()->provider_id;
        $hasAccess = $providerId && (
            (int) $flightRequest->assigned_provider_id === (int) $providerId ||
            $flightRequest->matches()->where('provider_id', $providerId)->exists()
        );
        abort_if(! $hasAccess, 403);

        return $this->ok(['flight_request' => $flightRequest->load(['matches.aircraft', 'quotes'])]);
    }

    public function acceptRequest(Request $request, SolicitudVuelo $flightRequest)
    {
        $providerId = $request->user()->provider_id;
        abort_if(! $providerId, 404);

        $match = $flightRequest->matches()->where('provider_id', $providerId)->first();
        $match?->loadMissing('aircraft');

        $flightRequest->matches()->where('provider_id', $providerId)->update([
            'status' => 'accepted',
            'accepted_at' => now(),
            'rejected_at' => null,
        ]);
        $visibilityPayload = $flightRequest->visibility_payload ?? [];
        $flightRequest->update([
            'status' => 'matched',
            'workflow_status' => 'aceptada',
            'assigned_provider_id' => $providerId,
            'assigned_aircraft_id' => $match?->aircraft_id,
            'assigned_aircraft_model' => $match?->aircraft?->model,
            'visibility_payload' => [
                ...$visibilityPayload,
                'selected_provider_id' => $providerId,
                'selected_aircraft_id' => $match?->aircraft_id,
                'aircraft_model' => $match?->aircraft?->model,
                'aircraft_category' => $match?->aircraft?->category,
                'aircraft_capacity' => $match?->aircraft?->capacity,
            ],
        ]);

        return $this->ok(['message' => 'Solicitud aceptada para cotizar.']);
    }

    public function rejectRequest(Request $request, SolicitudVuelo $flightRequest)
    {
        $providerId = $request->user()->provider_id;
        abort_if(! $providerId, 404);

        $flightRequest->matches()->where('provider_id', $providerId)->update([
            'status' => 'rejected',
            'rejected_at' => now(),
        ]);

        if ((int) $flightRequest->assigned_provider_id === (int) $providerId) {
            $visibilityPayload = $flightRequest->visibility_payload ?? [];
            $flightRequest->update([
                'assigned_provider_id' => null,
                'assigned_aircraft_id' => null,
                'assigned_aircraft_model' => null,
                'visibility_payload' => [
                    ...$visibilityPayload,
                    'selected_provider_id' => null,
                    'selected_aircraft_id' => null,
                    'aircraft_model' => null,
                    'aircraft_category' => null,
                    'aircraft_capacity' => null,
                ],
            ]);
        }

        $retry = $this->reintentoServicio->manejarRechazo($flightRequest);

        return $this->ok([
            'message' => 'Solicitud rechazada.',
            'retry' => $retry,
        ]);
    }

    public function payments(Request $request)
    {
        $providerId = $request->user()->provider_id;
        abort_if(! $providerId, 404);

        return $this->ok([
            'payments' => PagoProveedor::where('provider_id', $providerId)
                ->latest()
                ->paginate(20)
                ->through(fn (PagoProveedor $payment) => [
                    'id' => $payment->id,
                    'flight' => $payment->commission_id ? 'Reserva / comision #'.$payment->commission_id : 'Liquidacion proveedor',
                    'completed_at' => optional($payment->paid_at ?? $payment->released_at ?? $payment->created_at)?->toDateString(),
                    'amount' => number_format((float) $payment->amount, 2).' '.($payment->currency ?: 'USD'),
                    'status' => $payment->status,
                    'receipt' => $payment->transaction_reference,
                    'notes' => $payment->notes,
                ]),
        ]);
    }

    public function crew(Request $request)
    {
        $providerId = $request->user()->provider_id;
        abort_if(! $providerId, 404, 'Proveedor no encontrado.');

        $activeStatuses = ['confirmada', 'confirmed', 'en_preparacion', 'preparacion', 'preparing', 'lista', 'ready', 'en_vuelo', 'in_progress', 'in_flight', 'incidencia'];
        $assignedCrewIds = Operacion::query()
            ->where('provider_id', $providerId)
            ->whereIn('status', $activeStatuses)
            ->whereNotNull('sobrecargo_user_id')
            ->pluck('sobrecargo_user_id')
            ->all();

        $crew = Usuario::query()
            ->with(['roles', 'profile'])
            ->where('provider_id', $providerId)
            ->where(function ($query) {
                $query->whereHas('roles', fn ($roles) => $roles->whereIn('code', ['sobrecargo']))
                    ->orWhere('operational_role', 'sobrecargo');
            })
            ->latest()
            ->get()
            ->map(function (Usuario $user) use ($assignedCrewIds) {
                $hasActiveOperation = in_array($user->id, $assignedCrewIds, true);

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->operational_role ?: $user->effectiveRole(),
                    'base' => $user->profile?->city,
                    'availability' => $user->status !== 'active'
                        ? 'No disponible'
                        : ($hasActiveOperation ? 'Asignado' : 'Disponible'),
                    'state' => $user->status !== 'active'
                        ? 'Suspendido'
                        : ($hasActiveOperation ? 'Asignado' : 'Disponible'),
                    'phone' => $user->phone,
                    'note' => $user->profile?->business_type ?: 'Sin notas',
                    'email' => $user->email,
                ];
            })
            ->values();

        return $this->ok(['crew' => $crew]);
    }

    public function commissions(Request $request)
    {
        $providerId = $request->user()->provider_id;
        abort_if(! $providerId, 404);

        return $this->ok(['commissions' => Comision::where('provider_id', $providerId)->paginate(20)]);
    }

    public function operations(Request $request)
    {
        $providerId = $request->user()->provider_id;
        abort_if(! $providerId, 404, 'Proveedor no encontrado.');

        $operations = Operacion::with(['solicitudVuelo', 'aeronave', 'sobrecargo', 'timeline'])
            ->where('provider_id', $providerId)
            ->latest()
            ->paginate(20)
            ->through(fn (Operacion $operation) => $this->formatOperationPayload($operation));

        return $this->ok(['operations' => $operations]);
    }

    public function updateOperation(Request $request, Operacion $operation)
    {
        $providerId = $request->user()->provider_id;
        abort_if($operation->provider_id !== $providerId, 403, 'No puedes actualizar esta operacion.');

        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:100'],
            'crew_id' => ['nullable', 'exists:users,id'],
            'sobrecargo_id' => ['nullable', 'exists:users,id'],
            'crew_name' => ['nullable', 'string', 'max:255'],
            'crew_label' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
        ]);

        abort_if(
            ! array_key_exists('status', $data)
            && ! array_key_exists('crew_id', $data)
            && ! array_key_exists('sobrecargo_id', $data),
            422,
            'Debes indicar un estado o un sobrecargo para actualizar la operacion.'
        );

        $crewId = $data['crew_id'] ?? $data['sobrecargo_id'] ?? null;
        $assignedCrew = null;

        if ($crewId) {
            $assignedCrew = Usuario::query()
                ->where('provider_id', $providerId)
                ->where(function ($query) {
                    $query->whereHas('roles', fn ($roles) => $roles->whereIn('code', ['sobrecargo']))
                        ->orWhere('operational_role', 'sobrecargo');
                })
                ->find($crewId);

            abort_if(! $assignedCrew, 422, 'El sobrecargo seleccionado no pertenece a este proveedor o no es valido.');
        }

        $nextStatus = $data['status'] ?? $operation->status;
        $assigningCrew = (bool) $crewId;

        $operation->update([
            'sobrecargo_user_id' => $crewId ?? $operation->sobrecargo_user_id,
            'status' => $nextStatus,
            'crew_status' => $assigningCrew ? 'pending_crew_response' : $operation->crew_status,
            'crew_confirmed_at' => $assigningCrew ? null : $operation->crew_confirmed_at,
            'crew_decline_reason' => $assigningCrew ? null : $operation->crew_decline_reason,
            'crew_notes' => $assigningCrew ? ($data['note'] ?? null) : ($data['note'] ?? $operation->crew_notes),
            'crew_checkin_at' => $assigningCrew ? null : $operation->crew_checkin_at,
            'crew_service_started_at' => $assigningCrew ? null : $operation->crew_service_started_at,
            'crew_service_completed_at' => $assigningCrew ? null : $operation->crew_service_completed_at,
            'started_at' => in_array($nextStatus, ['En vuelo', 'en_vuelo', 'in_progress'], true)
                ? ($operation->started_at ?? now())
                : $operation->started_at,
            'completed_at' => in_array($nextStatus, ['Finalizada', 'finalizada', 'completed'], true)
                ? now()
                : $operation->completed_at,
        ]);

        if ($crewId) {
            $operation->timeline()->create([
                'status' => 'sobrecargo_asignado',
                'title' => 'Sobrecargo asignado por proveedor',
                'description' => trim(implode(' | ', array_filter([
                    'Sobrecargo: '.($assignedCrew?->name ?: $data['crew_name'] ?? $data['crew_label'] ?? 'N/D'),
                    $data['note'] ?? null,
                ]))),
                'created_by' => $request->user()->id,
            ]);
        }

        if (array_key_exists('status', $data) && $data['status']) {
            $operation->timeline()->create([
                'status' => $this->normalizeOperationTimelineStatus($data['status']),
                'title' => 'Estado operativo actualizado',
                'description' => 'Proveedor actualizo la operacion a '.$data['status'].'.',
                'created_by' => $request->user()->id,
            ]);
        }

        $this->writeAudit($request, 'update', 'provider_operations', 'Operacion actualizada por proveedor.');

        return $this->ok([
            'operation' => $this->formatOperationPayload($operation->fresh(['solicitudVuelo', 'aeronave', 'sobrecargo', 'timeline'])),
        ]);
    }

    public function updateReleaseProvider(Request $request, SolicitudVuelo $flightRequest)
    {
        $providerId = $request->user()->provider_id;
        abort_if(! $providerId, 404, 'Proveedor no encontrado.');

        $hasAccess = (int) $flightRequest->assigned_provider_id === (int) $providerId
            || $flightRequest->matches()->where('provider_id', $providerId)->exists();
        abort_if(! $hasAccess, 403, 'No puedes actualizar esta liberacion operativa.');

        $data = $request->validate([
            'provider_operational_release' => ['required', 'array'],
            'provider_operational_release.status' => ['required', 'string', 'max:120'],
            'provider_operational_release.aircraft_id' => ['nullable', 'exists:aircraft,id'],
            'provider_operational_release.aircraft_label' => ['nullable', 'string', 'max:255'],
            'provider_operational_release.aircraft_operational_status' => ['nullable', 'string', 'max:120'],
            'provider_operational_release.aircraft_overall_status' => ['nullable', 'string', 'max:120'],
            'provider_operational_release.availability_confirmed' => ['nullable', 'boolean'],
            'provider_operational_release.maintenance_clear' => ['nullable', 'boolean'],
            'provider_operational_release.route_coverage_confirmed' => ['nullable', 'boolean'],
            'provider_operational_release.captain_assigned_status' => ['nullable', 'string', 'max:120'],
            'provider_operational_release.captain_status' => ['nullable', 'string', 'max:120'],
            'provider_operational_release.copilot_assigned_status' => ['nullable', 'string', 'max:120'],
            'provider_operational_release.copilot_status' => ['nullable', 'string', 'max:120'],
            'provider_operational_release.crew_availability_status' => ['nullable', 'string', 'max:120'],
            'provider_operational_release.crew_requirements_confirmed' => ['nullable', 'boolean'],
            'provider_operational_release.crew_requirements_status' => ['nullable', 'string', 'max:120'],
            'provider_operational_release.crew_general_status' => ['nullable', 'string', 'max:120'],
            'provider_operational_release.crew_overall_status' => ['nullable', 'string', 'max:120'],
            'provider_operational_release.crew_schedule_confirmed' => ['nullable', 'boolean'],
            'provider_operational_release.crew_documents_ready' => ['nullable', 'boolean'],
            'provider_operational_release.departure_airport' => ['nullable', 'string', 'max:120'],
            'provider_operational_release.arrival_airport' => ['nullable', 'string', 'max:120'],
            'provider_operational_release.fbo' => ['nullable', 'string', 'max:255'],
            'provider_operational_release.flight_plan_ready' => ['nullable', 'boolean'],
            'provider_operational_release.permits_ready' => ['nullable', 'boolean'],
            'provider_operational_release.handling_ready' => ['nullable', 'boolean'],
            'provider_operational_release.fuel_ready' => ['nullable', 'boolean'],
            'provider_operational_release.cleaning_ready' => ['nullable', 'boolean'],
            'provider_operational_release.documents_ready' => ['nullable', 'boolean'],
            'provider_operational_release.insurance_ready' => ['nullable', 'boolean'],
            'provider_operational_release.registration_ready' => ['nullable', 'boolean'],
            'provider_operational_release.logbook_ready' => ['nullable', 'boolean'],
            'provider_operational_release.aircraft_readiness_status' => ['nullable', 'string', 'max:120'],
            'provider_operational_release.notes' => ['nullable', 'string', 'max:4000'],
            'provider_operational_release.updated_at' => ['nullable', 'date'],
            'operational_status' => ['nullable', 'string', 'max:120'],
            'aircraft_confirmed' => ['nullable', 'boolean'],
            'crew_confirmed' => ['nullable', 'boolean'],
            'operational_ready' => ['nullable', 'boolean'],
            'workflow_status' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
        ]);

        $release = $data['provider_operational_release'];
        $crewRequirementsStatus = Str::lower(trim((string) (
            $release['crew_requirements_status'] ?? ''
        )));
        $crewRequirementsConfirmed = array_key_exists('crew_requirements_confirmed', $release)
            ? (bool) $release['crew_requirements_confirmed']
            : $crewRequirementsStatus === 'confirmed';
        $captainStatus = $release['captain_status'] ?? $release['captain_assigned_status'] ?? null;
        $copilotStatus = $release['copilot_status'] ?? $release['copilot_assigned_status'] ?? null;
        $crewOverallStatus = $release['crew_overall_status'] ?? $release['crew_general_status'] ?? null;
        $aircraftOverallStatus = $release['aircraft_overall_status'] ?? $release['aircraft_operational_status'] ?? null;

        $release = [
            ...$release,
            'aircraft_operational_status' => $aircraftOverallStatus,
            'aircraft_overall_status' => $aircraftOverallStatus,
            'captain_assigned_status' => $captainStatus,
            'captain_status' => $captainStatus,
            'copilot_assigned_status' => $copilotStatus,
            'copilot_status' => $copilotStatus,
            'crew_requirements_confirmed' => $crewRequirementsConfirmed,
            'crew_requirements_status' => $crewRequirementsStatus ?: ($crewRequirementsConfirmed ? 'confirmed' : 'pending'),
            'crew_general_status' => $crewOverallStatus,
            'crew_overall_status' => $crewOverallStatus,
        ];
        $releaseStatus = Str::lower(trim((string) ($release['status'] ?? $data['operational_status'] ?? 'pending')));
        $workflowStatus = $this->normalizeReleaseWorkflowStatus(
            $data['workflow_status'] ?? $data['status'] ?? $data['state'] ?? null,
            $releaseStatus
        );

        $result = DB::transaction(function () use ($request, $flightRequest, $providerId, $release, $releaseStatus, $workflowStatus, $data) {
            $visibilityPayload = is_array($flightRequest->visibility_payload) ? $flightRequest->visibility_payload : [];
            $releasePayload = [
                ...($visibilityPayload['provider_operational_release'] ?? []),
                ...$release,
                'status' => $releaseStatus,
                'provider_id' => $providerId,
                'updated_at' => $release['updated_at'] ?? now()->toIso8601String(),
                'updated_by' => $request->user()->id,
            ];

            $flightRequest->fill([
                'assigned_provider_id' => $flightRequest->assigned_provider_id ?: $providerId,
                'assigned_aircraft_id' => $release['aircraft_id'] ?? $flightRequest->assigned_aircraft_id,
                'assigned_aircraft_model' => $release['aircraft_label'] ?? $flightRequest->assigned_aircraft_model,
                'workflow_status' => $workflowStatus ?: $flightRequest->workflow_status,
                'status' => $workflowStatus
                    ? $this->resolveReleaseRequestStatus($workflowStatus, $flightRequest->status)
                    : $flightRequest->status,
                'visibility_payload' => [
                    ...$visibilityPayload,
                    'provider_operational_release' => $releasePayload,
                    'operational_status' => $releaseStatus,
                    'aircraft_confirmed' => (bool) ($data['aircraft_confirmed'] ?? false),
                    'crew_confirmed' => (bool) ($data['crew_confirmed'] ?? false),
                    'operational_ready' => (bool) ($data['operational_ready'] ?? false),
                ],
            ]);
            $flightRequest->save();

            $reservation = \App\Modelos\Reserva::query()
                ->where('flight_request_id', $flightRequest->id)
                ->latest('id')
                ->first();

            if ($reservation && ! empty($workflowStatus)) {
                $reservation->update([
                    'status' => $this->mapReleaseWorkflowToReservationStatus(
                        $workflowStatus,
                        $reservation->status
                    ),
                ]);
            }

            $operation = Operacion::query()
                ->where('flight_request_id', $flightRequest->id)
                ->where('provider_id', $providerId)
                ->latest('id')
                ->first();

            if (! $operation) {
                $operation = Operacion::create([
                    'flight_request_id' => $flightRequest->id,
                    'provider_id' => $providerId,
                    'aircraft_id' => $release['aircraft_id'] ?? $flightRequest->assigned_aircraft_id,
                    'status' => $this->mapReleaseStatusToOperationStatus($releaseStatus, $workflowStatus),
                    'started_at' => $this->resolveOperationStartedAt($workflowStatus),
                    'completed_at' => $this->resolveOperationCompletedAt($workflowStatus),
                ]);
            } else {
                $operation->update([
                    'aircraft_id' => $release['aircraft_id'] ?? $operation->aircraft_id,
                    'status' => $this->mapReleaseStatusToOperationStatus($releaseStatus, $workflowStatus, $operation->status),
                    'started_at' => $this->resolveOperationStartedAt($workflowStatus, $operation->started_at),
                    'completed_at' => $this->resolveOperationCompletedAt($workflowStatus, $operation->completed_at),
                ]);
            }

            $operation->timeline()->create([
                'status' => $this->normalizeOperationTimelineStatus($workflowStatus ?: $releaseStatus),
                'title' => 'Liberacion operativa del proveedor',
                'description' => trim(implode(' | ', array_filter([
                    'Estado: '.$releaseStatus,
                    ! empty($release['aircraft_label']) ? 'Aeronave: '.$release['aircraft_label'] : null,
                    ! empty($workflowStatus) ? 'Workflow: '.$workflowStatus : null,
                    ! empty($release['notes']) ? 'Notas: '.$release['notes'] : null,
                ]))),
                'created_by' => $request->user()->id,
            ]);

            $this->writeAudit(
                $request,
                'update',
                'provider_release_provider',
                'Liberacion operativa actualizada por proveedor.'
            );

            return [
                'flight_request_id' => $flightRequest->id,
                'operation_id' => $operation->id,
                'reservation_id' => $reservation?->id,
            ];
        });

        $freshRequest = SolicitudVuelo::query()
            ->select([
                'id',
                'client_id',
                'assigned_provider_id',
                'assigned_aircraft_id',
                'assigned_aircraft_model',
                'origin',
                'destination',
                'departure_datetime',
                'passengers',
                'trip_type',
                'aircraft_type',
                'requirements',
                'visibility_payload',
                'base_price',
                'operational_fee',
                'priority_price',
                'final_price',
                'currency',
                'pricing_context',
                'payment_status',
                'status',
                'workflow_status',
            ])
            ->with([
                'matches' => fn ($query) => $query
                    ->select([
                        'id',
                        'flight_request_id',
                        'aircraft_id',
                        'provider_id',
                        'estimated_price',
                        'status',
                        'response_deadline',
                        'visibility_payload',
                    ])
                    ->where('provider_id', $providerId),
                'matches.aircraft:id,model,category,capacity',
                'assignedAircraft:id,model,category,capacity',
                'legs:id,flight_request_id,leg_order,origin,destination,departure_datetime,arrival_datetime,passengers,distance_km',
                'reservation:id,flight_request_id,status',
                'reservation.contract:id,reservation_id,status',
                'reservation.latestPayment' => fn ($query) => $query->select([
                    'payments.id',
                    'payments.reservation_id',
                    'payments.status',
                ]),
                'latestOperation' => fn ($query) => $query->select([
                    'operations.id',
                    'operations.flight_request_id',
                    'operations.status',
                ]),
            ])
            ->findOrFail($result['flight_request_id']);

        $operation = Operacion::query()
            ->with([
                'solicitudVuelo:id,origin,destination,departure_datetime',
                'aeronave:id,model',
                'sobrecargo:id,name',
            ])
            ->findOrFail($result['operation_id']);

        return $this->ok([
            'request' => $this->visibilidadServicio->solicitudParaOperador($freshRequest),
            'operation' => $this->formatOperationPayload($operation),
        ]);
    }

    public function incidents(Request $request)
    {
        $providerId = $request->user()->provider_id;
        abort_if(! $providerId, 404, 'Proveedor no encontrado.');

        $incidents = LineaTiempoOperacion::with(['operacion.solicitudVuelo', 'operacion.aeronave'])
            ->where('status', 'incidencia')
            ->whereHas('operacion', fn ($query) => $query->where('provider_id', $providerId))
            ->latest()
            ->paginate(20)
            ->through(fn (LineaTiempoOperacion $incident) => $this->formatIncidentPayload($incident));

        return $this->ok(['incidents' => $incidents]);
    }

    public function storeIncident(Request $request)
    {
        $providerId = $request->user()->provider_id;
        abort_if(! $providerId, 404, 'Proveedor no encontrado.');

        $data = $request->validate([
            'operation_id' => ['nullable', 'exists:operations,id'],
            'flight' => ['nullable', 'string', 'max:150'],
            'type' => ['required', 'string', 'max:100'],
            'priority' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
            'evidence' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string'],
        ]);

        $operation = null;
        if (! empty($data['operation_id'])) {
            $operation = Operacion::with(['solicitudVuelo', 'aeronave'])
                ->where('provider_id', $providerId)
                ->findOrFail($data['operation_id']);
        } else {
            $operation = Operacion::with(['solicitudVuelo', 'aeronave'])
                ->where('provider_id', $providerId)
                ->latest()
                ->first();
        }

        abort_if(! $operation, 422, 'No hay una operacion del proveedor para vincular la incidencia.');

        $descriptionParts = array_filter([
            'Tipo: '.$data['type'],
            ! empty($data['priority']) ? 'Prioridad: '.$data['priority'] : null,
            ! empty($data['evidence']) ? 'Evidencia: '.$data['evidence'] : null,
            $data['comment'] ?? null,
            ! empty($data['flight']) ? 'Vuelo: '.$data['flight'] : null,
        ]);

        $timeline = $operation->timeline()->create([
            'status' => 'incidencia',
            'title' => $data['type'],
            'description' => implode(' | ', $descriptionParts),
            'created_by' => $request->user()->id,
        ]);

        $operation->update(['status' => 'incidencia']);
        $this->writeAudit($request, 'create', 'provider_incidents', 'Incidencia creada por proveedor.');

        return $this->ok([
            'incident' => $this->formatIncidentPayload($timeline->fresh(['operacion.solicitudVuelo', 'operacion.aeronave'])),
        ], 201);
    }

    public function updateIncident(Request $request, LineaTiempoOperacion $timeline)
    {
        $providerId = $request->user()->provider_id;
        $timeline->loadMissing('operacion');
        abort_if(! $timeline->operacion || $timeline->operacion->provider_id !== $providerId, 403, 'No puedes editar esta incidencia.');

        $data = $request->validate([
            'status' => ['required', 'string', 'max:50'],
        ]);

        $timeline->update([
            'status' => strtolower($data['status']) === 'cerrada' ? 'cerrada' : 'incidencia',
            'description' => trim(($timeline->description ? $timeline->description.' | ' : '').'Estado: '.$data['status']),
        ]);

        if (in_array(strtolower($data['status']), ['resuelta', 'cerrada'], true)) {
            $timeline->operacion->update(['status' => strtolower($data['status']) === 'cerrada' ? 'finalizada' : 'confirmada']);
        }

        $this->writeAudit($request, 'update', 'provider_incidents', 'Incidencia actualizada por proveedor.');

        return $this->ok([
            'incident' => $this->formatIncidentPayload($timeline->fresh(['operacion.solicitudVuelo', 'operacion.aeronave'])),
        ]);
    }

    public function history(Request $request)
    {
        $providerId = $request->user()->provider_id;
        abort_if(! $providerId, 404, 'Proveedor no encontrado.');

        $userIds = Proveedor::findOrFail($providerId)->users()->pluck('id')->push($request->user()->id)->unique();

        $entries = RegistroAuditoria::whereIn('user_id', $userIds)
            ->latest()
            ->limit(80)
            ->get()
            ->map(fn (RegistroAuditoria $entry) => [
                'id' => $entry->id,
                'date' => optional($entry->created_at)?->format('Y-m-d H:i'),
                'module' => $entry->module,
                'action' => $entry->description ?: $entry->action,
                'actor' => 'Usuario #'.$entry->user_id,
            ])
            ->values();

        return $this->ok(['history' => $entries]);
    }

    public function settings(Request $request)
    {
        $profile = $request->user()->loadMissing('profile')->profile;
        $taxData = $profile?->tax_data ?? [];
        $settings = $taxData['provider_settings'] ?? [];

        return $this->ok([
            'settings' => [
                'email_notifications' => (bool) ($settings['email_notifications'] ?? true),
                'payment_alerts' => (bool) ($settings['payment_alerts'] ?? true),
                'ops_alerts' => (bool) ($settings['ops_alerts'] ?? true),
                'crew_approval_mode' => $settings['crew_approval_mode'] ?? 'suggest_only',
            ],
        ]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'email_notifications' => ['required', 'boolean'],
            'payment_alerts' => ['required', 'boolean'],
            'ops_alerts' => ['required', 'boolean'],
            'crew_approval_mode' => ['required', 'string', 'in:suggest_only,provider_confirms'],
        ]);

        $user = $request->user()->loadMissing('profile');
        $profile = $user->profile ?: new Perfil(['user_id' => $user->id]);
        $taxData = $profile->tax_data ?? [];

        $profile->fill([
            'tax_data' => [
                ...$taxData,
                'provider_settings' => [
                    'email_notifications' => (bool) $data['email_notifications'],
                    'payment_alerts' => (bool) $data['payment_alerts'],
                    'ops_alerts' => (bool) $data['ops_alerts'],
                    'crew_approval_mode' => $data['crew_approval_mode'],
                ],
            ],
        ])->save();

        $this->writeAudit($request, 'update', 'provider_settings', 'Configuracion del proveedor actualizada.');

        return $this->ok([
            'settings' => [
                'email_notifications' => (bool) $data['email_notifications'],
                'payment_alerts' => (bool) $data['payment_alerts'],
                'ops_alerts' => (bool) $data['ops_alerts'],
                'crew_approval_mode' => $data['crew_approval_mode'],
            ],
        ]);
    }

    private function formatCompanyPayload(Request|\App\Modelos\Usuario $source): array
    {
        $user = $source instanceof Request
            ? $source->user()->loadMissing('provider.companyDocuments', 'profile')
            : $source->loadMissing('provider.companyDocuments', 'profile');
        $provider = $user->provider;
        $profile = $user->profile;
        $taxData = $profile?->tax_data ?? [];
        $legacyDocuments = collect($taxData['documents'] ?? [])
            ->values()
            ->map(fn ($document, $index) => [
                'id' => $document['id'] ?? ($index + 1),
                'name' => $document['name'] ?? ('Documento '.($index + 1)),
                'state' => $document['state'] ?? 'pendiente',
            ])
            ->all();
        $documents = ($provider && $provider->companyDocuments->isNotEmpty())
            ? $provider->companyDocuments
                ->sortByDesc('id')
                ->values()
                ->map(fn (DocumentoEmpresa $document) => [
                    'id' => $document->id,
                    'name' => $document->document_name ?? 'Documento',
                    'state' => $document->status ?? 'pendiente',
                    'file_url' => $document->file_url,
                    'document_url' => $document->document_url,
                    'expires_at' => optional($document->expires_at)?->toISOString(),
                ])
                ->all()
            : $legacyDocuments;

        return [
            'id' => $provider?->id,
            'legal_name' => $taxData['legal_name'] ?? $provider?->company_name,
            'company_name' => $provider?->company_name,
            'commercial_name' => $provider?->commercial_name,
            'trade_name' => $provider?->commercial_name,
            'jet_a_price' => (float) ($provider?->jet_a_price ?? 0),
            'margin_percent' => (float) ($provider?->margin_percent ?? 0),
            'fixed_fee' => (float) ($provider?->fixed_fee ?? 0),
            'rfc' => $taxData['rfc'] ?? null,
            'phone' => $user->phone,
            'email' => $user->email,
            'address' => $profile?->address,
            'legal_representative' => $taxData['legal_representative'] ?? null,
            'status' => $provider?->approval_status ?? 'pending',
            'validation_status' => $provider?->approval_status ?? 'pending',
            'review_status' => $provider?->approval_status ?? 'pending',
            'admin_notes' => $provider?->notes,
            'documents' => $documents,
        ];
    }

    private function formatOperationPayload(Operacion $operation): array
    {
        $flightRequest = $operation->solicitudVuelo;
        $aircraft = $operation->aeronave;
        $latestTimeline = $operation->relationLoaded('timeline')
            ? $operation->timeline->sortByDesc('id')->first()
            : $operation->timeline()->latest('id')->first();

        return [
            'id' => $operation->id,
            'request_id' => $operation->flight_request_id,
            'route' => trim(($flightRequest?->origin ?? 'N/D').' - '.($flightRequest?->destination ?? 'N/D')),
            'aircraft' => $aircraft?->model,
            'aircraft_model' => $aircraft?->model,
            'crew' => $operation->sobrecargo?->name,
            'crew_id' => $operation->sobrecargo_user_id,
            'crew_status' => $operation->crew_status,
            'crew_status_label' => $this->humanizeCrewStatus($operation->crew_status),
            'crew_confirmed_at' => optional($operation->crew_confirmed_at)?->toISOString(),
            'crew_decline_reason' => $operation->crew_decline_reason,
            'crew_notes' => $operation->crew_notes,
            'crew_checkin_at' => optional($operation->crew_checkin_at)?->toISOString(),
            'crew_service_started_at' => optional($operation->crew_service_started_at)?->toISOString(),
            'crew_service_completed_at' => optional($operation->crew_service_completed_at)?->toISOString(),
            'departure_datetime' => $flightRequest?->departure_datetime,
            'arrival_datetime' => $operation->completed_at,
            'status' => $this->humanizeOperationStatus($operation->status),
            'notes' => $latestTimeline?->description,
        ];
    }

    private function formatIncidentPayload(LineaTiempoOperacion $incident): array
    {
        $operation = $incident->operacion;
        $flightRequest = $operation?->solicitudVuelo;

        return [
            'id' => $incident->id,
            'type' => $incident->title,
            'flight' => trim(($flightRequest?->origin ?? 'N/D').' - '.($flightRequest?->destination ?? 'N/D')),
            'status' => str_contains(strtolower((string) $incident->description), 'estado: cerrada')
                ? 'Cerrada'
                : (str_contains(strtolower((string) $incident->description), 'estado: resuelta') ? 'Resuelta' : 'Abierta'),
            'priority' => $this->extractTaggedValue((string) $incident->description, 'Prioridad'),
            'evidence' => $this->extractTaggedValue((string) $incident->description, 'Evidencia'),
            'comment' => $incident->description,
            'created_at' => optional($incident->created_at)?->format('Y-m-d H:i'),
            'operation_id' => $operation?->id,
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

    private function humanizeOperationStatus(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'confirmada', 'confirmed' => 'Confirmada',
            'en_preparacion', 'preparacion', 'preparing' => 'En preparacion',
            'lista', 'ready' => 'Lista',
            'en_vuelo', 'in_progress', 'in_flight' => 'En vuelo',
            'finalizada', 'completed' => 'Finalizada',
            'incidencia' => 'Con incidencia',
            'cancelada', 'cancelled' => 'Cancelada',
            default => $status ?: 'Confirmada',
        };
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
            default => $status ?: 'Sin tripulacion',
        };
    }

    private function resolveReleaseRequestStatus(string $workflowStatus, ?string $currentStatus): string
    {
        return match (Str::lower(trim($workflowStatus))) {
            'flight_confirmed', 'vuelo confirmado',
            'tracking_live', 'tracking en vivo',
            'completed', 'finalizada' => 'reserved',
            default => $currentStatus ?: 'matched',
        };
    }

    private function normalizeReleaseWorkflowStatus(?string $workflowStatus, string $releaseStatus): ?string
    {
        $normalizedWorkflowStatus = Str::lower(trim((string) $workflowStatus));
        if ($normalizedWorkflowStatus !== '') {
            return $normalizedWorkflowStatus;
        }

        return match ($releaseStatus) {
            'operational_ready' => 'tracking_live',
            default => null,
        };
    }

    private function mapReleaseWorkflowToReservationStatus(string $workflowStatus, ?string $currentStatus): string
    {
        return match (Str::lower(trim($workflowStatus))) {
            'flight_confirmed', 'vuelo confirmado',
            'tracking_live', 'tracking en vivo' => 'confirmed',
            'completed', 'finalizada' => 'completed',
            default => $currentStatus ?: 'pending',
        };
    }

    private function mapReleaseStatusToOperationStatus(
        string $releaseStatus,
        ?string $workflowStatus = null,
        ?string $currentStatus = null
    ): string {
        $normalizedWorkflowStatus = $workflowStatus ? Str::lower(trim($workflowStatus)) : '';

        if (in_array($normalizedWorkflowStatus, ['completed', 'finalizada'], true)) {
            return 'finalizada';
        }

        if (in_array($normalizedWorkflowStatus, ['tracking_live', 'tracking en vivo'], true)) {
            return 'en_vuelo';
        }

        if (in_array($normalizedWorkflowStatus, ['flight_confirmed', 'vuelo confirmado'], true)) {
            return 'confirmada';
        }

        return match ($releaseStatus) {
            'operational_ready' => 'confirmada',
            'crew_confirmed', 'aircraft_confirmed' => 'en_preparacion',
            default => $currentStatus ?: 'pendiente',
        };
    }

    private function resolveOperationStartedAt(?string $workflowStatus, $currentStartedAt = null)
    {
        $normalizedWorkflowStatus = Str::lower(trim((string) $workflowStatus));

        if (in_array($normalizedWorkflowStatus, ['tracking_live', 'tracking en vivo', 'completed', 'finalizada'], true)) {
            return $currentStartedAt ?: now();
        }

        return $currentStartedAt;
    }

    private function resolveOperationCompletedAt(?string $workflowStatus, $currentCompletedAt = null)
    {
        $normalizedWorkflowStatus = Str::lower(trim((string) $workflowStatus));

        if (in_array($normalizedWorkflowStatus, ['completed', 'finalizada'], true)) {
            return $currentCompletedAt ?: now();
        }

        return $currentCompletedAt;
    }

    private function normalizeOperationTimelineStatus(string $status): string
    {
        return match (strtolower($status)) {
            'confirmada' => 'confirmada',
            'flight_confirmed' => 'confirmada',
            'aircraft_confirmed' => 'preparacion',
            'crew_confirmed' => 'preparacion',
            'operational_ready' => 'lista',
            'tracking_live' => 'en_vuelo',
            'tracking en vivo' => 'en_vuelo',
            'completed' => 'finalizada',
            'en preparacion' => 'preparacion',
            'lista' => 'lista',
            'en vuelo' => 'en_vuelo',
            'finalizada' => 'finalizada',
            'con incidencia' => 'incidencia',
            'cancelada' => 'cancelada',
            default => str_replace(' ', '_', strtolower($status)),
        };
    }
}
