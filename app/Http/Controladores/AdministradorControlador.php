<?php

namespace App\Http\Controladores;

use App\Modelos\Aeronave;
use App\Modelos\RegistroAuditoria;
use App\Modelos\Comision;
use App\Modelos\Demo;
use App\Modelos\DocumentoAeronave;
use App\Modelos\DocumentoEmpresa;
use App\Modelos\SolicitudVuelo;
use App\Modelos\Pago;
use App\Modelos\Plan;
use App\Modelos\Proveedor;
use App\Modelos\Cotizacion;
use App\Modelos\Reserva;
use App\Modelos\Suscripcion;
use App\Modelos\ConfiguracionSistema;
use App\Modelos\Usuario;
use App\Servicios\Aeronaves\AircraftStateService;
use App\Servicios\Providers\AdminProviderApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdministradorControlador extends ControladorBase
{
    private const MEXICAN_RFC_PATTERN = '/^([A-Z&Ñ]{3,4})\d{6}[A-Z0-9]{3}$/u';

    private const APPROVED_DOCUMENT_STATUSES = ['approved', 'aprobado', 'aprobada', 'vigente', 'validado'];

    private const APPROVED_AIRCRAFT_STATUSES = ['active', 'trial_active', 'inactive', 'approved', 'aprobada', 'aprobado'];

    private const LEGAL_REQUIREMENT_DOCUMENT_KEYS = [
        'articles_of_incorporation',
        'legal_representative_power',
        'legal_representative_id',
        'tax_address_proof',
        'operational_permit',
    ];

    private const REQUIRED_PROVIDER_DOCUMENT_KEYS = [
        'sat_certificate',
        'articles_of_incorporation',
        'legal_representative_power',
        'legal_representative_id',
        'tax_address_proof',
        'operational_permit',
    ];

    private const RESERVATION_STATUS_ALIASES = [
        'payment_pending' => 'pending_payment',
        'pending_payment' => 'pending_payment',
        'pago pendiente' => 'pending_payment',
        'contract_pending' => 'pending_payment',
        'contrato pendiente' => 'pending_payment',
        'contract_signed' => 'pending_payment',
        'contrato firmado' => 'pending_payment',
        'provider_accepted' => 'pending_payment',
        'proveedor aceptado' => 'pending_payment',
        'provider_pending' => 'pending_payment',
        'reserved' => 'pending_payment',
        'paid' => 'confirmed',
        'payment_confirmed' => 'confirmed',
        'pago confirmado' => 'confirmed',
        'flight_confirmed' => 'confirmed',
        'vuelo confirmado' => 'confirmed',
        'tracking_live' => 'in_progress',
        'tracking en vivo' => 'in_progress',
        'cancelled' => 'cancelled',
        'cancelada' => 'cancelled',
        'completed' => 'completed',
        'finalizada' => 'completed',
    ];

    public function __construct(
        private readonly AdminProviderApprovalService $adminProviderApprovalService,
        private readonly AircraftStateService $aircraftStateService,
    )
    {
    }

    public function dashboard()
    {
        return $this->ok([
            'metrics' => [
                'users_registered' => Usuario::count(),
                'active_demos' => Demo::where('status', 'active')->where('expires_at', '>', now())->count(),
                'active_subscriptions' => Suscripcion::where('status', 'active')->where('expires_at', '>', now())->count(),
                'approved_providers' => Proveedor::approvedForOperations()->count(),
                'aircraft_registered' => Aeronave::count(),
                'flight_requests' => SolicitudVuelo::count(),
                'closed_quotes' => Cotizacion::where('status', 'accepted')->count(),
                'confirmed_reservations' => Reserva::where('status', 'confirmed')->count(),
                'revenue' => Pago::where('status', 'paid')->sum('amount'),
            ],
        ]);
    }

    public function users()
    {
        $users = Usuario::query()
            ->select([
                'id',
                'name',
                'email',
                'phone',
                'created_at',
                'role',
                'operational_role',
                'provider_id',
                'status',
                'access_status',
                'trial_started_at',
                'trial_ends_at',
                'free_quote_limit',
                'free_quotes_used',
                'has_paid_access',
                'paid_access_at',
                'access_payment_id',
                'updated_at',
            ])
            ->with([
                'profile:id,user_id,company_name,city,base_airport,tax_data',
                'provider:id,user_id,company_name,commercial_name,approval_status',
                'ownedProvider:id,user_id,company_name,commercial_name,approval_status',
                'demo:id,user_id,status,started_at,expires_at',
                'activeSuscripcion' => fn ($query) => $query->select([
                    'subscriptions.id',
                    'subscriptions.user_id',
                    'subscriptions.plan_id',
                    'subscriptions.status',
                    'subscriptions.started_at',
                    'subscriptions.expires_at',
                ]),
                'activeSuscripcion.plan:id,name,code,billing_cycle',
                'roles:id,code,name',
            ])
            ->paginate(25);

        return $this->ok([
            'users' => $users->through(fn (Usuario $user) => $this->serializeAdminUserSummary($user)),
        ]);
    }

    public function showUsuario(Usuario $user)
    {
        $user->load([
            'profile',
            'provider',
            'ownedProvider',
            'demo',
            'subscriptions.plan',
            'activeSuscripcion.plan',
            'roles',
            'identityVerifications',
        ]);

        return $this->ok([
            'user' => $this->serializeAdminUserDetail($user),
        ]);
    }

    public function updateUsuario(Request $request, Usuario $user)
    {
        $user->update($request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['sometimes', 'in:client,provider,admin,sobrecargo'],
            'status' => ['sometimes', 'in:active,inactive,blocked'],
        ]));

        if ($request->filled('role')) {
            $selectedRole = $request->string('role')->toString();
            $user->syncRoles(
                $selectedRole === Usuario::ROLE_SOBRECARGO
                    ? [Usuario::ROLE_CLIENT, Usuario::ROLE_SOBRECARGO]
                    : [$selectedRole],
                $selectedRole
            );
        }

        return $this->ok(['user' => $user->fresh()]);
    }

    public function blockUsuario(Usuario $user)
    {
        $user->update(['status' => 'blocked']);

        return $this->ok(['user' => $user->fresh()]);
    }

    public function activateUsuario(Usuario $user)
    {
        $user->update(['status' => 'active']);

        return $this->ok(['user' => $user->fresh()]);
    }

    public function clients()
    {
        $clients = Usuario::query()
            ->select([
                'id',
                'name',
                'email',
                'phone',
                'created_at',
                'role',
                'operational_role',
                'provider_id',
                'status',
                'access_status',
                'trial_started_at',
                'trial_ends_at',
                'free_quote_limit',
                'free_quotes_used',
                'has_paid_access',
                'paid_access_at',
                'access_payment_id',
                'access_expires_at',
                'updated_at',
            ])
            ->where(function ($query) {
                $query
                    ->where('role', Usuario::ROLE_CLIENT)
                    ->orWhere('operational_role', Usuario::ROLE_CLIENT);
            })
            ->with([
                'profile:id,user_id,company_name,city,base_airport',
                'demo:id,user_id,status,started_at,expires_at',
            ])
            ->latest('id')
            ->paginate(20);

        return $this->ok([
            'clients' => $clients->through(fn (Usuario $user) => $this->serializeAdminClientSummary($user)),
        ]);
    }

    public function providers()
    {
        $providers = Proveedor::query()
            ->with([
                'user.profile',
                'companyDocuments:id,provider_id,document_slot,status,document_section',
            ])
            ->withCount([
                'aircraft',
                'companyDocuments',
                'aircraft as active_aircraft_count' => fn ($query) => $query->whereIn('status', ['active', 'approved', 'aprobado', 'aprobada']),
                'aircraft as trial_aircraft_count' => fn ($query) => $query->where('status', 'trial_active'),
                'aircraft as pending_aircraft_count' => fn ($query) => $query->whereNotIn('status', ['active', 'approved', 'aprobado', 'aprobada', 'trial_active']),
            ])
            ->paginate(25);

        return $this->ok([
            'providers' => $providers->through(fn (Proveedor $provider) => $this->serializeProviderSummary($provider)),
        ]);
    }

    public function showProveedor(Proveedor $provider)
    {
        $provider->load(['user.profile', 'aircraft', 'companyDocuments']);

        return $this->ok([
            'provider' => $this->serializeProvider($provider),
            'company' => $this->serializeProvider($provider),
            'documents' => $provider->companyDocuments
                ->sortByDesc('id')
                ->values()
                ->map(fn (DocumentoEmpresa $document) => $this->serializeProviderDocument($document))
                ->all(),
        ]);
    }

    public function providerActivity(Proveedor $provider)
    {
        $provider->loadMissing('users:id,provider_id');
        $providerUserIds = $this->providerUserIds($provider);

        $entries = RegistroAuditoria::query()
            ->select(['id', 'user_id', 'module', 'action', 'description', 'new_values', 'old_values', 'created_at'])
            ->with('user:id,name,role,operational_role')
            ->where(function ($query) use ($providerUserIds) {
                $query->whereIn('user_id', $providerUserIds)
                    ->orWhereIn('module', [
                        'provider_company',
                        'provider_company_document',
                        'provider_requirement_review',
                        'provider_admin_validation',
                        'provider_aircraft',
                        'provider_aircraft_document',
                    ]);
            })
            ->latest()
            ->limit(250)
            ->get()
            ->filter(fn (RegistroAuditoria $entry) => $this->auditEntryMatchesProvider($entry, $provider, $providerUserIds))
            ->map(fn (RegistroAuditoria $entry) => $this->serializeProviderActivityEntry($entry))
            ->values();

        return $this->ok([
            'data' => $entries,
            'activity' => $entries,
        ]);
    }

    public function providerDocuments(Proveedor $provider)
    {
        $provider->loadMissing(['user.profile', 'companyDocuments']);

        return $this->ok([
            'provider' => $this->serializeProviderSummary($provider),
            'documents' => $provider->companyDocuments
                ->sortByDesc('id')
                ->values()
                ->map(fn (DocumentoEmpresa $document) => $this->serializeProviderDocument($document))
                ->all(),
        ]);
    }

    public function downloadProviderDocument(Proveedor $provider, DocumentoEmpresa $document)
    {
        abort_if($document->provider_id !== $provider->id, 404, 'Documento no encontrado para este proveedor.');

        return $this->downloadCompanyDocumentResponse($document);
    }

    public function downloadCompanyDocumentByDocument(DocumentoEmpresa $document)
    {
        return $this->downloadCompanyDocumentResponse($document);
    }

    public function updateProviderDocument(Request $request, Proveedor $provider, DocumentoEmpresa $document)
    {
        abort_if($document->provider_id !== $provider->id, 404, 'Documento no encontrado para este proveedor.');
        $previousState = $document->only(['status', 'notes', 'updated_at']);

        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:100'],
            'validation_status' => ['nullable', 'string', 'max:100'],
            'review_status' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'observation' => ['nullable', 'string', 'max:5000'],
            'observacion' => ['nullable', 'string', 'max:5000'],
        ]);

        $nextStatus = $data['status']
            ?? $data['validation_status']
            ?? $data['review_status']
            ?? $document->status
            ?? 'pendiente';
        $nextNotes = $data['notes'] ?? $data['observation'] ?? $data['observacion'] ?? $document->notes;
        $documentMetadata = $this->companyDocumentMetadataForResponse($document);

        $document->update($this->filterCompanyDocumentPayload([
            'status' => $nextStatus,
            'notes' => $nextNotes,
        ]));

        $this->writeAudit(
            $request,
            in_array(strtolower(trim((string) $nextStatus)), ['approved', 'aprobado', 'validado'], true)
                ? 'admin_requirement_approved'
                : 'admin_requirement_rejected',
            'provider_company_document',
            in_array(strtolower(trim((string) $nextStatus)), ['approved', 'aprobado', 'validado'], true)
                ? 'Documento legal validado por administracion.'
                : 'Documento legal rechazado por administracion.',
            [
                'old_values' => [
                    ...$previousState,
                    'provider_id' => $provider->id,
                    'document_id' => $document->id,
                ],
                'new_values' => [
                    'provider_id' => $provider->id,
                    'document_id' => $document->id,
                    'event_type' => in_array(strtolower(trim((string) $nextStatus)), ['approved', 'aprobado', 'validado'], true)
                        ? 'document_approved'
                        : 'document_rejected',
                    'title' => in_array(strtolower(trim((string) $nextStatus)), ['approved', 'aprobado', 'validado'], true)
                        ? 'Documento legal validado'
                        : 'Documento legal rechazado',
                    'description' => sprintf(
                        '%s · %s%s',
                        $documentMetadata['definition_label'] ?: ($document->document_name ?: $document->original_name ?: 'Documento de empresa'),
                        $documentMetadata['section_label'] ?: 'Documentacion legal del operador',
                        filled($documentMetadata['document_slot'] ?? null) ? ' · document_slot: '.$documentMetadata['document_slot'] : ''
                    ),
                    'status' => $nextStatus,
                    'notes' => $nextNotes,
                    'document_type' => $documentMetadata['document_type'],
                    'document_category' => $documentMetadata['document_category'],
                    'document_slot' => $documentMetadata['document_slot'],
                    'document_section' => $documentMetadata['document_section'],
                    'document_definition_key' => $documentMetadata['definition_key'],
                    'document_definition_label' => $documentMetadata['definition_label'],
                    'document_section_label' => $documentMetadata['section_label'],
                ],
            ]
        );

        return $this->ok([
            'document' => $this->serializeProviderDocument($document->fresh()),
            'provider' => $this->serializeProvider($provider->fresh(['user', 'aircraft', 'companyDocuments'])),
        ]);
    }

    public function approveProviderDocument(Request $request, Proveedor $provider, DocumentoEmpresa $document)
    {
        $request->merge(['status' => 'approved']);

        return $this->updateProviderDocument($request, $provider, $document);
    }

    public function rejectProviderDocument(Request $request, Proveedor $provider, DocumentoEmpresa $document)
    {
        $request->merge(['status' => 'rejected']);

        return $this->updateProviderDocument($request, $provider, $document);
    }

    public function approveCompanyDocumentByDocument(Request $request, DocumentoEmpresa $document)
    {
        return $this->approveProviderDocument($request, $document->provider()->firstOrFail(), $document);
    }

    public function rejectCompanyDocumentByDocument(Request $request, DocumentoEmpresa $document)
    {
        return $this->rejectProviderDocument($request, $document->provider()->firstOrFail(), $document);
    }

    public function validateProveedor(Request $request, Proveedor $provider)
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:5000'],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
            'sat_validation_status' => ['nullable', 'string', 'max:50'],
        ]);

        $result = $this->adminProviderApprovalService->approve($provider, $request->user(), $data, function (Proveedor $approvedProvider, array $context) use ($request) {
            if (($context['noop'] ?? false) === true) {
                return;
            }

            $this->writeAudit(
                $request,
                'admin_operator_approved',
                'provider_admin_validation',
                'Proveedor aprobado por administracion.',
                [
                    'old_values' => [
                        ...($context['previous_state'] ?? []),
                        'provider_id' => $approvedProvider->id,
                    ],
                    'new_values' => [
                        'provider_id' => $approvedProvider->id,
                        'event_type' => 'admin_operator_approved',
                        'title' => 'Proveedor aprobado por administracion',
                        'description' => $approvedProvider->company_name ?: $approvedProvider->legal_name ?: 'Proveedor',
                        'admin_notes' => $context['admin_notes'] ?? null,
                        'access_enabled' => true,
                        'admin_validation_status' => 'approved',
                        'operator_status' => 'active',
                    ],
                ]
            );
        });

        return $this->ok([
            'provider' => $this->serializeProvider($result['provider']),
        ]);
    }

    public function requestProviderChanges(Request $request, Proveedor $provider)
    {
        $data = $request->validate([
            'notes' => ['required', 'string', 'max:5000'],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
            'changes_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $result = $this->adminProviderApprovalService->requestChanges($provider, $request->user(), $data, function (Proveedor $updatedProvider, array $context) use ($request) {
            $this->writeAudit(
                $request,
                'admin_changes_requested',
                'provider_admin_validation',
                'Cambios solicitados por administracion.',
                [
                    'old_values' => [
                        ...($context['previous_state'] ?? []),
                        'provider_id' => $updatedProvider->id,
                    ],
                    'new_values' => [
                        'provider_id' => $updatedProvider->id,
                        'event_type' => 'admin_changes_requested',
                        'title' => 'Cambios solicitados por administracion',
                        'description' => $context['changes_notes'] ?? null,
                        'admin_notes' => $context['admin_notes'] ?? null,
                        'admin_validation_status' => 'changes_requested',
                        'operator_status' => 'incomplete',
                        'access_enabled' => false,
                    ],
                ]
            );
        });

        return $this->ok([
            'provider' => $this->serializeProvider($result['provider']),
        ]);
    }

    public function rejectProviderValidation(Request $request, Proveedor $provider)
    {
        $data = $request->validate([
            'notes' => ['required', 'string', 'max:5000'],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
            'rejection_reason' => ['nullable', 'string', 'max:5000'],
        ]);

        $result = $this->adminProviderApprovalService->reject($provider, $request->user(), $data, function (Proveedor $updatedProvider, array $context) use ($request) {
            $this->writeAudit(
                $request,
                'admin_operator_rejected',
                'provider_admin_validation',
                'Proveedor rechazado por administracion.',
                [
                    'old_values' => [
                        ...($context['previous_state'] ?? []),
                        'provider_id' => $updatedProvider->id,
                    ],
                    'new_values' => [
                        'provider_id' => $updatedProvider->id,
                        'event_type' => 'admin_operator_rejected',
                        'title' => 'Proveedor rechazado por administracion',
                        'description' => $context['rejection_reason'] ?? null,
                        'admin_notes' => $context['admin_notes'] ?? null,
                        'admin_validation_status' => 'rejected',
                        'operator_status' => 'rejected',
                        'access_enabled' => false,
                    ],
                ]
            );
        });

        return $this->ok([
            'provider' => $this->serializeProvider($result['provider']),
        ]);
    }

    public function approveProviderRequirement(Request $request, Proveedor $provider, string $requirement)
    {
        $provider->loadMissing(['user', 'aircraft', 'companyDocuments']);
        $requirementItem = collect($this->providerValidationChecklist($provider))
            ->firstWhere('key', $requirement);

        abort_if(! $requirementItem, 404, 'Requisito no encontrado.');

        if (! ($requirementItem['complete'] ?? false)) {
            throw ValidationException::withMessages([
                'validation' => [
                    'No se puede validar todavia. Faltan requisitos obligatorios: '.$requirementItem['label'].'.',
                ],
            ]);
        }

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:5000'],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $payload = $this->upsertProviderRequirementReview(
            $provider,
            $requirement,
            'approved',
            trim((string) ($data['admin_notes'] ?? $data['notes'] ?? '')),
            $request->user()
        );

        $this->writeAudit(
            $request,
            'admin_requirement_approved',
            'provider_requirement_review',
            'Requisito validado por administracion.',
            [
                'new_values' => [
                    'provider_id' => $provider->id,
                    'requirement_key' => $requirement,
                    'event_type' => 'admin_requirement_approved',
                    'title' => 'Requisito validado por administracion',
                    'description' => $requirementItem['label'],
                    'requirement_status' => 'approved',
                    'admin_note' => $payload['admin_note'],
                ],
            ]
        );

        return $this->ok([
            'provider' => $this->serializeProvider($provider->fresh(['user.profile', 'aircraft', 'companyDocuments'])),
            'requirement' => $payload,
        ]);
    }

    public function rejectProviderRequirement(Request $request, Proveedor $provider, string $requirement)
    {
        $provider->loadMissing(['user', 'aircraft', 'companyDocuments']);
        $requirementItem = collect($this->providerValidationChecklist($provider))
            ->firstWhere('key', $requirement);

        abort_if(! $requirementItem, 404, 'Requisito no encontrado.');

        $data = $request->validate([
            'notes' => ['required', 'string', 'max:5000'],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $payload = $this->upsertProviderRequirementReview(
            $provider,
            $requirement,
            'rejected',
            trim((string) ($data['admin_notes'] ?? $data['notes'] ?? '')),
            $request->user()
        );

        $this->writeAudit(
            $request,
            'admin_requirement_rejected',
            'provider_requirement_review',
            'Requisito rechazado por administracion.',
            [
                'new_values' => [
                    'provider_id' => $provider->id,
                    'requirement_key' => $requirement,
                    'event_type' => 'admin_requirement_rejected',
                    'title' => 'Requisito rechazado por administracion',
                    'description' => $payload['admin_note'] ?: $requirementItem['label'],
                    'requirement_label' => $requirementItem['label'],
                    'requirement_status' => 'rejected',
                    'admin_note' => $payload['admin_note'],
                ],
            ]
        );

        return $this->ok([
            'provider' => $this->serializeProvider($provider->fresh(['user.profile', 'aircraft', 'companyDocuments'])),
            'requirement' => $payload,
        ]);
    }

    public function approveProveedor(Request $request, Proveedor $provider)
    {
        return $this->validateProveedor($request, $provider);
    }

    public function rejectProveedor(Request $request, Proveedor $provider)
    {
        return $this->rejectProviderValidation($request, $provider);
    }

    public function suspendProveedor(Request $request, Proveedor $provider)
    {
        return $this->requestProviderChanges($request, $provider);
    }

    public function aircraft(Request $request)
    {
        $filters = $request->validate([
            'provider_id' => ['nullable', 'integer', 'exists:providers,id'],
        ]);

        $providerId = (int) ($filters['provider_id'] ?? 0);

        $aircraft = Aeronave::query()
            ->with(['provider.user.profile', 'availability', 'images', 'documents'])
            ->when($providerId > 0, fn ($query) => $query->where('provider_id', $providerId))
            ->paginate(25);

        return $this->ok([
            'aircraft' => $aircraft->through(fn (Aeronave $item) => $this->serializeAdminAircraftPayload($item)),
        ]);
    }

    public function showAeronave(Aeronave $aircraft)
    {
        $state = $this->aircraftStateService->evaluateAndSyncAircraftState($aircraft);

        return $this->ok([
            'aircraft' => $this->serializeAdminAircraftPayload(
                $aircraft->load(['provider.user.profile', 'images', 'documents', 'availability']),
                $state,
            ),
        ]);
    }

    public function blockAeronave(Aeronave $aircraft)
    {
        $payload = [
            'status' => 'blocked',
        ];
        if (array_key_exists('is_active', $aircraft->getAttributes())) {
            $payload['is_active'] = false;
        }
        if (array_key_exists('operational_status', $aircraft->getAttributes())) {
            $payload['operational_status'] = 'inactive';
        }
        if (array_key_exists('validation_status', $aircraft->getAttributes())) {
            $payload['validation_status'] = 'rejected';
        }

        $aircraft->update($payload);
        $state = $this->aircraftStateService->evaluateAndSyncAircraftState($aircraft);

        return $this->ok([
            'aircraft' => $this->serializeAdminAircraftPayload(
                $aircraft->fresh(['provider.user.profile', 'images', 'documents', 'availability']),
                $state,
            ),
        ]);
    }

    public function activateAeronave(Aeronave $aircraft)
    {
        $requestStartedAt = microtime(true);
        if (! $aircraft->approved_at) {
            $approvalPayload = ['approved_at' => now()];
            if (array_key_exists('validation_status', $aircraft->getAttributes())) {
                $approvalPayload['validation_status'] = 'approved';
            }
            $aircraft->forceFill($approvalPayload)->save();
            $aircraft->refresh();
        }
        $validationStartedAt = microtime(true);
        $state = $this->aircraftStateService->evaluateAndSyncAircraftState($aircraft);
        $validationMs = round((microtime(true) - $validationStartedAt) * 1000, 2);
        $activationState = is_array($state['activation'] ?? null) ? $state['activation'] : [];
        $canReactivateBlockedAircraft =
            ($activationState['commercial_status'] ?? null) === 'blocked'
            && ($activationState['requirements_complete'] ?? false) === true
            && ($state['review']['approved'] ?? false) === true;

        if (! (($activationState['can_activate'] ?? false) || $canReactivateBlockedAircraft)) {
            Log::info('admin.aircraft_activation.rejected', [
                'aircraft_id' => $aircraft->id,
                'validation_ms' => $validationMs,
                'total_ms' => round((microtime(true) - $requestStartedAt) * 1000, 2),
                'missing_requirements' => $activationState['missing_requirements'] ?? [],
                'commercial_status' => $activationState['commercial_status'] ?? null,
                'commercial_block_reason' => $activationState['commercial_block_reason'] ?? null,
            ]);

            return $this->ok([
                'message' => 'Aeronave aprobada; la activacion queda pendiente de requisitos.',
                'aircraft' => $this->serializeAdminAircraftPayload(
                    $aircraft->fresh(['provider.user.profile', 'images', 'documents', 'availability']),
                    $state,
                ),
                'missing_requirements' => $this->aircraftActivationRequirementPayload($state),
                'state' => $state,
            ]);
        }

        $databaseStartedAt = microtime(true);
        $activatedAircraft = DB::transaction(function () use ($aircraft) {
            $lockedAircraft = Aeronave::query()->lockForUpdate()->findOrFail($aircraft->id);
            $payload = [
                'status' => 'active',
                'approved_at' => $lockedAircraft->approved_at ?: now(),
            ];
            if (array_key_exists('is_active', $lockedAircraft->getAttributes())) {
                $payload['is_active'] = true;
            }
            if (array_key_exists('operational_status', $lockedAircraft->getAttributes())) {
                $payload['operational_status'] = 'active';
            }
            if (array_key_exists('validation_status', $lockedAircraft->getAttributes())) {
                $payload['validation_status'] = 'approved';
            }
            if (array_key_exists('activated_at', $lockedAircraft->getAttributes())) {
                $payload['activated_at'] = now();
            }

            $lockedAircraft->forceFill($payload)->save();

            return $lockedAircraft->fresh();
        });

        $databaseMs = round((microtime(true) - $databaseStartedAt) * 1000, 2);
        $totalMs = round((microtime(true) - $requestStartedAt) * 1000, 2);

        Log::info('admin.aircraft_activation.completed', [
            'aircraft_id' => $activatedAircraft->id,
            'validation_and_readiness_ms' => $validationMs,
            'database_update_ms' => $databaseMs,
            'post_save_snapshot_ms' => 0,
            'events_and_jobs_ms' => 0,
            'total_before_response_ms' => $totalMs,
            'reactivated_from_blocked' => $canReactivateBlockedAircraft,
        ]);

        return $this->ok([
            'message' => 'Aeronave activada correctamente.',
            'aircraft' => $this->serializeAdminAircraftPayload(
                $activatedAircraft->load(['provider.user.profile', 'images', 'documents', 'availability']),
                $this->aircraftStateService->evaluateAndSyncAircraftState($activatedAircraft),
            ),
            'timing' => [
                'validation_and_readiness_ms' => $validationMs,
                'database_update_ms' => $databaseMs,
                'total_before_response_ms' => $totalMs,
            ],
        ]);
    }

    public function deactivateAeronave(Aeronave $aircraft)
    {
        return DB::transaction(function () use ($aircraft) {
            $lockedAircraft = Aeronave::query()->lockForUpdate()->findOrFail($aircraft->id);
            $payload = [
                'status' => 'inactive',
            ];
            if (array_key_exists('is_active', $lockedAircraft->getAttributes())) {
                $payload['is_active'] = false;
            }
            if (array_key_exists('operational_status', $lockedAircraft->getAttributes())) {
                $payload['operational_status'] = 'inactive';
            }
            if (array_key_exists('validation_status', $lockedAircraft->getAttributes()) && $lockedAircraft->approved_at) {
                $payload['validation_status'] = 'approved';
            }

            $lockedAircraft->forceFill($payload)->save();

            $state = $this->aircraftStateService->evaluate($lockedAircraft->fresh(['provider.user.profile', 'images', 'documents', 'availability']));
            $refreshedAircraft = $lockedAircraft->fresh(['provider.user.profile', 'images', 'documents', 'availability']);

            return $this->ok([
                'message' => 'Aeronave desactivada correctamente.',
                'aircraft' => $this->serializeAdminAircraftPayload($refreshedAircraft, $state),
                'state' => $state,
            ]);
        });
    }

    public function approveAeronave(Aeronave $aircraft)
    {
        $aircraft->loadMissing(['provider', 'documents', 'images', 'availability']);

        abort_if(! $aircraft->provider?->isAdministrativelyApproved(), 422, 'El proveedor debe estar aprobado antes de aprobar la aeronave.');

        return DB::transaction(function () use ($aircraft) {
            $lockedAircraft = Aeronave::query()
                ->with(['provider', 'documents', 'images', 'availability'])
                ->lockForUpdate()
                ->findOrFail($aircraft->id);

            abort_if(! $lockedAircraft->provider?->isAdministrativelyApproved(), 422, 'El proveedor debe estar aprobado antes de aprobar la aeronave.');

            $payload = [
                'approved_at' => $lockedAircraft->approved_at ?: now(),
            ];
            if (array_key_exists('validation_status', $lockedAircraft->getAttributes())) {
                $payload['validation_status'] = 'approved';
            }
            if (array_key_exists('is_active', $lockedAircraft->getAttributes())) {
                $payload['is_active'] = false;
            }
            if (array_key_exists('operational_status', $lockedAircraft->getAttributes())) {
                $payload['operational_status'] = 'inactive';
            }
            if (array_key_exists('status', $lockedAircraft->getAttributes()) && $lockedAircraft->status !== 'blocked') {
                $payload['status'] = 'inactive';
            }

            $lockedAircraft->forceFill($payload)->save();

            $refreshedAircraft = $lockedAircraft->fresh(['provider.user.profile', 'images', 'documents', 'availability']);
            $state = $this->aircraftStateService->evaluate($refreshedAircraft);

            return $this->ok([
                'message' => 'Aeronave aprobada correctamente.',
                'aircraft' => $this->serializeAdminAircraftPayload($refreshedAircraft, $state),
                'state' => $state,
            ]);
        });
    }

    public function flightRequests()
    {
        return $this->ok(['flight_requests' => SolicitudVuelo::with(['client', 'matches' => fn ($query) => $query->whereNotNull('aircraft_id'), 'matches.aircraft'])->latest()->paginate(25)]);
    }

    public function updateFlightRequest(Request $request, SolicitudVuelo $flightRequest)
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:50'],
            'workflow_status' => ['nullable', 'string', 'max:100'],
            'payment_status' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'admin_flow_state' => ['nullable', 'string', 'max:50'],
            'flow_control_state' => ['nullable', 'string', 'max:50'],
            'admin_delay_reason' => ['nullable', 'string', 'max:1000'],
            'delay_reason' => ['nullable', 'string', 'max:1000'],
            'hold_reason' => ['nullable', 'string', 'max:1000'],
            'admin_delay_eta' => ['nullable', 'string', 'max:100'],
            'delay_eta' => ['nullable', 'string', 'max:100'],
            'hold_eta' => ['nullable', 'string', 'max:100'],
            'admin_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $previousState = $flightRequest->only(['status', 'workflow_status', 'payment_status', 'admin_flow_state', 'flow_control_state']);

        $updateData = collect($data)->filter()->all();
        if (array_key_exists('status', $updateData)) {
            $updateData['status'] = $this->normalizeAdminFlightRequestStatus(
                $updateData['status'],
                $updateData['workflow_status'] ?? null,
                $flightRequest->status
            );
        }
        $flightRequest->update($updateData);

        $this->writeAudit($request, 'admin_flight_request_updated', 'flight_requests', 'Admin actualizó solicitud de vuelo.', [
            'entity' => 'flight_request',
            'entity_id' => $flightRequest->id,
            'before' => $previousState,
            'after' => $flightRequest->only(['status', 'workflow_status', 'payment_status', 'admin_flow_state', 'flow_control_state']),
        ]);

        return $this->ok(['flight_request' => $flightRequest->fresh(['client', 'matches' => fn ($query) => $query->whereNotNull('aircraft_id'), 'matches.aircraft'])]);
    }

    public function quotes()
    {
        return $this->ok(['quotes' => Cotizacion::with(['flightRequest', 'provider', 'aircraft'])->latest()->paginate(25)]);
    }

    public function reservations()
    {
        return $this->ok(['reservations' => Reserva::with(['client', 'provider', 'aircraft', 'quote'])->latest()->paginate(25)]);
    }

    public function updateReservation(Request $request, Reserva $reservation)
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:50'],
            'workflow_status' => ['nullable', 'string', 'max:100'],
            'payment_status' => ['nullable', 'string', 'max:50'],
            'contract_status' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'admin_flow_state' => ['nullable', 'string', 'max:50'],
            'flow_control_state' => ['nullable', 'string', 'max:50'],
            'admin_delay_reason' => ['nullable', 'string', 'max:1000'],
            'delay_reason' => ['nullable', 'string', 'max:1000'],
            'hold_reason' => ['nullable', 'string', 'max:1000'],
            'admin_delay_eta' => ['nullable', 'string', 'max:100'],
            'delay_eta' => ['nullable', 'string', 'max:100'],
            'hold_eta' => ['nullable', 'string', 'max:100'],
            'admin_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $previousState = $reservation->only(['status', 'workflow_status', 'payment_status', 'contract_status', 'admin_flow_state', 'flow_control_state']);

        $updateData = collect($data)->filter()->all();
        if (array_key_exists('status', $updateData)) {
            $updateData['status'] = $this->normalizeAdminReservationStatus(
                $updateData['status'],
                $updateData['workflow_status'] ?? null,
                $reservation->status
            );
        }
        $reservation->update($updateData);

        $this->writeAudit($request, 'admin_reservation_updated', 'reservations', 'Admin actualizó reserva.', [
            'entity' => 'reservation',
            'entity_id' => $reservation->id,
            'before' => $previousState,
            'after' => $reservation->only(['status', 'workflow_status', 'payment_status', 'contract_status', 'admin_flow_state', 'flow_control_state']),
        ]);

        return $this->ok(['reservation' => $reservation->fresh(['client', 'provider', 'aircraft', 'quote', 'flightRequest'])]);
    }

    private function normalizeAdminFlightRequestStatus(?string $requestedStatus, ?string $workflowStatus, ?string $currentStatus): ?string
    {
        $normalizedRequestedStatus = Str::lower(trim((string) $requestedStatus));
        if (in_array($normalizedRequestedStatus, ['pending', 'matched', 'quoted', 'reserved', 'cancelled', 'expired'], true)) {
            return $normalizedRequestedStatus;
        }

        $normalizedWorkflowStatus = Str::lower(trim((string) $workflowStatus));

        return match ($normalizedWorkflowStatus) {
            'cotizada', 'quoted' => 'quoted',
            'cancelada', 'cancelled' => 'cancelled',
            'expirada', 'expired' => 'expired',
            'proveedor aceptado', 'provider accepted', 'provider_accepted', 'aceptada', 'accepted', 'operador asignado', 'operador_asignado' => 'matched',
            'contrato pendiente', 'contract pending', 'contract_pending',
            'contrato firmado', 'contract signed', 'contract_signed',
            'pago pendiente', 'payment pending', 'payment_pending',
            'pago confirmado', 'payment confirmed', 'payment_confirmed',
            'vuelo confirmado', 'flight confirmed', 'flight_confirmed',
            'tracking en vivo', 'tracking live', 'tracking_live',
            'finalizada', 'completed' => 'reserved',
            default => $currentStatus,
        };
    }

    private function normalizeAdminReservationStatus(?string $requestedStatus, ?string $workflowStatus, ?string $currentStatus): ?string
    {
        $normalizedRequestedStatus = Str::lower(trim((string) $requestedStatus));
        if ($normalizedRequestedStatus !== '' && array_key_exists($normalizedRequestedStatus, self::RESERVATION_STATUS_ALIASES)) {
            return self::RESERVATION_STATUS_ALIASES[$normalizedRequestedStatus];
        }

        $normalizedWorkflowStatus = Str::lower(trim((string) $workflowStatus));
        if ($normalizedWorkflowStatus !== '' && array_key_exists($normalizedWorkflowStatus, self::RESERVATION_STATUS_ALIASES)) {
            return self::RESERVATION_STATUS_ALIASES[$normalizedWorkflowStatus];
        }

        return $currentStatus;
    }

    public function payments()
    {
        return $this->ok(['payments' => Pago::latest()->paginate(25)]);
    }

    public function commissions()
    {
        return $this->ok(['commissions' => Comision::with(['provider', 'reservation'])->latest()->paginate(25)]);
    }

    public function releaseComision(Comision $commission)
    {
        $commission->update(['status' => 'released']);

        return $this->ok(['commission' => $commission->fresh()]);
    }

    public function demos()
    {
        return $this->ok(['demos' => Demo::with('user')->latest()->paginate(25)]);
    }

    public function subscriptions()
    {
        return $this->ok(['subscriptions' => Suscripcion::with(['user', 'plan'])->latest()->paginate(25)]);
    }

    public function plans()
    {
        return $this->ok(['plans' => Plan::latest()->paginate(25)]);
    }

    public function storePlan(Request $request)
    {
        $plan = Plan::create($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:plans,slug'],
            'price' => ['required', 'numeric', 'min:0'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
            'features' => ['nullable', 'array'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]));

        return $this->ok(['plan' => $plan], 201);
    }

    public function updatePlan(Request $request, Plan $plan)
    {
        $plan->update($request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'billing_cycle' => ['sometimes', 'in:monthly,yearly'],
            'features' => ['nullable', 'array'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]));

        return $this->ok(['plan' => $plan->fresh()]);
    }

    public function reports()
    {
        return $this->ok([
            'payments_by_type' => Pago::selectRaw('payment_type, status, count(*) as count, sum(amount) as total')
                ->groupBy('payment_type', 'status')
                ->get(),
            'reservations_by_status' => Reserva::selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->get(),
            'quotes_by_status' => Cotizacion::selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->get(),
        ]);
    }

    public function audit()
    {
        return $this->ok(['audit_logs' => RegistroAuditoria::latest()->paginate(50)]);
    }

    public function settings()
    {
        return $this->ok(['settings' => ConfiguracionSistema::orderBy('group')->orderBy('key')->get()]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*.key' => ['required', 'string', 'max:255'],
            'settings.*.value' => ['nullable'],
            'settings.*.group' => ['nullable', 'string', 'max:100'],
        ]);

        foreach ($data['settings'] as $setting) {
            ConfiguracionSistema::updateOrCreate(
                ['key' => $setting['key']],
                ['value' => $setting['value'] ?? null, 'group' => $setting['group'] ?? 'general']
            );
        }

        return $this->settings();
    }

    private function serializeAdminUserSummary(Usuario $user): array
    {
        $commercialAccess = $this->serializeAdminCommercialAccess($user);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'created_at' => $user->created_at,
            'role' => $user->role,
            'operational_role' => $user->operational_role,
            'effective_role' => $user->effectiveRole(),
            'provider_id' => $user->provider_id,
            'proveedor_id' => $user->provider_id,
            'status' => $user->status,
            'access_status' => $user->access_status,
            'trial_started_at' => $user->trial_started_at,
            'trial_ends_at' => $user->trial_ends_at,
            'free_quote_limit' => (int) ($user->free_quote_limit ?? 1),
            'free_quotes_used' => (int) ($user->free_quotes_used ?? 0),
            'has_paid_access' => (bool) $user->has_paid_access,
            'paid_access_at' => $user->paid_access_at,
            'access_payment_id' => $user->access_payment_id,
            'updated_at' => $user->updated_at,
            'access' => $user->accessStatus(),
            'commercial_access' => $commercialAccess,
            'roles' => $user->roles,
            'profile' => $user->profile ? [
                'company_name' => $user->profile->company_name,
                'city' => $user->profile->city,
                'base_airport' => $user->profile->base_airport,
                'tax_data' => $user->profile->tax_data,
            ] : null,
            'identity_verification_status' => $user->identity_verification_status,
            'identity_verified' => (bool) $user->identity_verified,
            'biometric_image_saved' => (bool) $user->biometric_image_saved,
            'has_biometric_selfie' => (bool) ($user->resolvedBiometricSelfiePath() || $user->biometric_image_saved),
            'provider' => $user->provider ? [
                'id' => $user->provider->id,
                'company_name' => $user->provider->company_name,
                'commercial_name' => $user->provider->commercial_name,
                'approval_status' => $user->provider->approval_status,
            ] : null,
            'ownedProvider' => $user->ownedProvider ? [
                'id' => $user->ownedProvider->id,
                'company_name' => $user->ownedProvider->company_name,
                'commercial_name' => $user->ownedProvider->commercial_name,
                'approval_status' => $user->ownedProvider->approval_status,
            ] : null,
            'demo' => $user->demo,
            'active_suscripcion' => $user->activeSuscripcion,
            'activeSuscripcion' => $user->activeSuscripcion,
        ];
    }

    private function serializeAdminClientSummary(Usuario $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'created_at' => $user->created_at,
            'role' => $user->role,
            'operational_role' => $user->operational_role,
            'provider_id' => $user->provider_id,
            'proveedor_id' => $user->provider_id,
            'status' => $user->status,
            'access_status' => $user->access_status,
            'trial_started_at' => $user->trial_started_at,
            'trial_ends_at' => $user->trial_ends_at,
            'free_quote_limit' => (int) ($user->free_quote_limit ?? 1),
            'free_quotes_used' => (int) ($user->free_quotes_used ?? 0),
            'has_paid_access' => (bool) $user->has_paid_access,
            'paid_access_at' => $user->paid_access_at,
            'access_payment_id' => $user->access_payment_id,
            'access_expires_at' => $user->access_expires_at,
            'updated_at' => $user->updated_at,
            'commercial_access' => $this->serializeAdminCommercialAccess($user),
            'profile' => $user->profile ? [
                'company_name' => $user->profile->company_name,
                'city' => $user->profile->city,
                'base_airport' => $user->profile->base_airport,
            ] : null,
            'identity_verification_status' => $user->identity_verification_status,
            'identity_verified' => (bool) $user->identity_verified,
            'biometric_image_saved' => (bool) $user->biometric_image_saved,
            'has_biometric_selfie' => (bool) ($user->resolvedBiometricSelfiePath() || $user->biometric_image_saved),
            'demo' => $user->demo ? [
                'status' => $user->demo->status,
                'started_at' => $user->demo->started_at,
                'expires_at' => $user->demo->expires_at,
            ] : null,
        ];
    }

    private function serializeAdminUserDetail(Usuario $user): array
    {
        $summary = $this->serializeAdminUserSummary($user);
        $summary['profile'] = $user->profile;
        $summary['provider'] = $user->provider;
        $summary['ownedProvider'] = $user->ownedProvider;
        $summary['subscriptions'] = $user->subscriptions;
        $summary['identity_verification_status'] = $user->identity_verification_status;
        $summary['identity_verification_message'] = $user->identity_verification_message;
        $summary['identity_verified'] = (bool) $user->identity_verified;
        $summary['biometric_image_saved'] = (bool) $user->biometric_image_saved;
        $summary['biometric_selfie_path'] = $user->resolvedBiometricSelfiePath();
        $summary['biometric_selfie_disk'] = $user->resolvedBiometricSelfieDisk();
        $summary['biometric_selfie_uploaded_at'] = $user->biometric_selfie_uploaded_at;
        $summary['biometric_selfie_url'] = $user->biometric_selfie_url;
        $summary['identityVerifications'] = $user->identityVerifications;
        $summary['identity_verifications'] = $user->identityVerifications;

        return $summary;
    }

    private function serializeAdminCommercialAccess(Usuario $user): array
    {
        $status = (string) ($user->access_status ?: 'trial_active');
        $freeQuoteLimit = (int) ($user->free_quote_limit ?? 1);
        $freeQuotesUsed = (int) ($user->free_quotes_used ?? 0);
        $remainingQuotes = max(0, $freeQuoteLimit - $freeQuotesUsed);

        $stage = match (true) {
            (bool) $user->has_paid_access && $user->paid_access_at !== null => 'paid',
            $freeQuotesUsed >= $freeQuoteLimit => 'trial_used',
            $freeQuotesUsed > 0 => 'trial_in_progress',
            in_array($status, ['registered', 'trial_active', 'payment_failed', 'payment_pending'], true) => 'new',
            default => 'blocked',
        };

        $label = match ($stage) {
            'paid' => 'Pago activo',
            'trial_used' => 'Prueba consumida',
            'trial_in_progress' => 'Prueba iniciada',
            'new' => 'Registro nuevo',
            default => 'Acceso bloqueado',
        };

        return [
            'status' => $status,
            'stage' => $stage,
            'label' => $label,
            'has_paid_access' => (bool) $user->has_paid_access,
            'paid_access_at' => $user->paid_access_at,
            'access_payment_id' => $user->access_payment_id,
            'trial_started_at' => $user->trial_started_at,
            'trial_ends_at' => $user->trial_ends_at,
            'free_quote_limit' => $freeQuoteLimit,
            'free_quotes_used' => $freeQuotesUsed,
            'remaining_free_quotes' => $remainingQuotes,
            'trial_consumed' => $freeQuotesUsed >= $freeQuoteLimit,
            'is_new_registration' => $freeQuotesUsed === 0 && ! $user->has_paid_access,
        ];
    }

    private function serializeProvider(Proveedor $provider): array
    {
        $provider->loadMissing(['user.profile', 'aircraft', 'companyDocuments']);
        $documents = $provider->companyDocuments
            ->sortByDesc('id')
            ->values()
            ->map(fn (DocumentoEmpresa $document) => $this->serializeProviderDocument($document))
            ->all();
        $checklist = $this->providerValidationChecklist($provider);
        $adminValidationStatus = $this->resolveAdminValidationStatus($provider);
        $operatorStatus = $this->resolveOperatorStatus($provider, $adminValidationStatus);
        $accessEnabled = $this->resolveProviderAccessEnabled($provider, $adminValidationStatus);
        $aircraftMetrics = $this->providerAircraftMetrics($provider);
        $aircraft = $provider->aircraft
            ->map(fn (Aeronave $item) => $this->serializeProviderAircraftPayload($item))
            ->values()
            ->all();

        return [
            ...$provider->attributesToArray(),
            'provider_id' => $provider->id,
            'admin_validation_status' => $adminValidationStatus,
            'operator_status' => $operatorStatus,
            'sat_validation_status' => $this->resolveSatValidationStatus($provider),
            'admin_notes' => $provider->admin_notes ?: $provider->admin_validation_notes ?: $provider->notes,
            'admin_validation_notes' => $provider->admin_validation_notes ?: $provider->admin_notes ?: $provider->notes,
            'changes_notes' => $provider->changes_notes,
            'rejection_reason' => $provider->rejection_reason,
            'validated_by' => $provider->validated_by ?: $provider->admin_validated_by,
            'validated_at' => optional($provider->validated_at ?: $provider->admin_validated_at)?->toISOString(),
            'rejected_by' => $provider->rejected_by ?: $provider->admin_rejected_by,
            'rejected_at' => optional($provider->rejected_at ?: $provider->admin_rejected_at)?->toISOString(),
            'changes_requested_by' => $provider->changes_requested_by ?: $provider->admin_changes_requested_by,
            'changes_requested_at' => optional($provider->changes_requested_at ?: $provider->admin_changes_requested_at)?->toISOString(),
            'access_enabled' => $accessEnabled,
            'provider_validation_requirements' => $this->providerRequirementReviews($provider),
            'validation_requirements' => $checklist,
            'can_validate' => collect($checklist)->every(fn (array $item) => (bool) ($item['complete'] ?? false)),
            'provider_status_summary' => $this->buildProviderStatusSummary($provider, $checklist, $documents, $aircraftMetrics),
            'documents' => $documents,
            'company_documents' => $documents,
            'legal_documents' => $documents,
            'documents_count' => count($documents),
            'legal_documents_count' => count($documents),
            'company_documents_count' => count($documents),
            'aircraft_metrics' => $aircraftMetrics,
            'user' => $this->serializeProviderUserSummary($provider->user),
            'aircraft' => $aircraft,
        ];
    }

    private function serializeProviderSummary(Proveedor $provider): array
    {
        $provider->loadMissing(['user.profile', 'companyDocuments']);
        $adminValidationStatus = $this->resolveAdminValidationStatus($provider);
        $operatorStatus = $this->resolveOperatorStatus($provider, $adminValidationStatus);
        $checklist = $this->providerValidationChecklist($provider);
        $aircraftMetrics = $this->providerAircraftMetrics($provider);
        $documents = $provider->companyDocuments
            ->sortByDesc('id')
            ->values()
            ->map(fn (DocumentoEmpresa $document) => $this->serializeProviderDocument($document))
            ->all();

        return [
            ...$provider->attributesToArray(),
            'provider_id' => $provider->id,
            'admin_validation_status' => $adminValidationStatus,
            'operator_status' => $operatorStatus,
            'sat_validation_status' => $this->resolveSatValidationStatus($provider),
            'admin_notes' => $provider->admin_notes ?: $provider->admin_validation_notes ?: $provider->notes,
            'admin_validation_notes' => $provider->admin_validation_notes ?: $provider->admin_notes ?: $provider->notes,
            'changes_notes' => $provider->changes_notes,
            'rejection_reason' => $provider->rejection_reason,
            'access_enabled' => $this->resolveProviderAccessEnabled($provider, $adminValidationStatus),
            'provider_validation_requirements' => $this->providerRequirementReviews($provider),
            'validation_requirements' => $checklist,
            'can_validate' => collect($checklist)->every(fn (array $item) => (bool) ($item['complete'] ?? false)),
            'provider_status_summary' => $this->buildProviderStatusSummary($provider, $checklist, $documents, $aircraftMetrics),
            'documents_count' => count($documents),
            'legal_documents_count' => count($documents),
            'company_documents_count' => count($documents),
            'aircraft_metrics' => $aircraftMetrics,
            'user' => $this->serializeProviderUserSummary($provider->user),
        ];
    }

    private function providerAircraftMetrics(Proveedor $provider): array
    {
        $aircraftCount = (int) ($provider->aircraft_count ?? ($provider->relationLoaded('aircraft') ? $provider->aircraft->count() : 0));
        $activeCount = (int) ($provider->active_aircraft_count ?? ($provider->relationLoaded('aircraft')
            ? $provider->aircraft->filter(fn (Aeronave $item) => in_array(strtolower(trim((string) $item->status)), ['active', 'approved', 'aprobado', 'aprobada'], true))->count()
            : 0));
        $trialCount = (int) ($provider->trial_aircraft_count ?? ($provider->relationLoaded('aircraft')
            ? $provider->aircraft->filter(fn (Aeronave $item) => strtolower(trim((string) $item->status)) === 'trial_active')->count()
            : 0));
        $pendingCount = (int) ($provider->pending_aircraft_count ?? max($aircraftCount - $activeCount - $trialCount, 0));

        return [
            'aircraft' => $aircraftCount,
            'active' => $activeCount,
            'trial' => $trialCount,
            'pending' => $pendingCount,
        ];
    }

    private function buildProviderStatusSummary(
        Proveedor $provider,
        array $checklist = [],
        iterable $documents = [],
        ?array $aircraftMetrics = null,
    ): array {
        $documentSummary = $this->providerDocumentSummary($documents, $checklist);
        $fleetSummary = $this->providerFleetSummary($aircraftMetrics ?? $this->providerAircraftMetrics($provider));
        $status = $this->resolveProviderPrimaryStatus($provider, $checklist, $documentSummary);
        $completedChecks = collect($checklist)->filter(fn (array $item) => (bool) ($item['complete'] ?? false))->count();
        $totalChecks = count($checklist);

        return [
            'status' => $status,
            'label' => $this->providerStatusLabel($status),
            'description' => $this->providerStatusDescription($status),
            'progress' => $totalChecks > 0 ? (int) round(($completedChecks / $totalChecks) * 100) : 0,
            'document_summary' => $documentSummary,
            'fleet_summary' => $fleetSummary,
            'missing_requirements' => collect($checklist)
                ->filter(fn (array $item) => ! (bool) ($item['complete'] ?? false))
                ->map(fn (array $item) => (string) ($item['label'] ?? 'Requisito'))
                ->values()
                ->all(),
        ];
    }

    private function providerDocumentSummary(iterable $documents, array $checklist = []): array
    {
        $normalizedDocuments = collect($documents)
            ->map(function ($document) {
                if ($document instanceof DocumentoEmpresa) {
                    return [
                        'slot' => trim((string) $document->document_slot),
                        'status' => strtolower(trim((string) $document->status)),
                    ];
                }

                return [
                    'slot' => trim((string) ($document['document_slot'] ?? $document['definitionKey'] ?? $document['definition_key'] ?? '')),
                    'status' => strtolower(trim((string) ($document['status'] ?? $document['state'] ?? ''))),
                ];
            })
            ->filter(fn (array $item) => $item['slot'] !== '')
            ->unique('slot')
            ->values();

        $total = count(self::REQUIRED_PROVIDER_DOCUMENT_KEYS);

        if ($normalizedDocuments->isEmpty()) {
            $legalRequirement = collect($checklist)->firstWhere('key', 'legal_documents_approved');
            $responseStatus = $this->normalizeProviderRequirementResponseStatus($legalRequirement['response_status'] ?? null);
            $complete = (bool) ($legalRequirement['complete'] ?? false);

            if ($complete && $responseStatus === 'approved') {
                return [
                    'total' => $total,
                    'approved' => $total,
                    'pending' => 0,
                    'rejected' => 0,
                    'missing' => 0,
                ];
            }

            if ($responseStatus === 'rejected') {
                return [
                    'total' => $total,
                    'approved' => 0,
                    'pending' => max($total - 1, 0),
                    'rejected' => 1,
                    'missing' => max($total - 1, 0),
                ];
            }
        }

        $approved = 0;
        $rejected = 0;
        $missing = 0;

        foreach (self::REQUIRED_PROVIDER_DOCUMENT_KEYS as $slot) {
            $document = $normalizedDocuments->firstWhere('slot', $slot);
            $status = strtolower(trim((string) ($document['status'] ?? '')));

            if (in_array($status, self::APPROVED_DOCUMENT_STATUSES, true)) {
                $approved++;
                continue;
            }

            if (in_array($status, ['rejected', 'rechazado', 'rechazada', 'cancelled', 'canceled', 'cancelado', 'cancelada'], true)) {
                $rejected++;
                continue;
            }

            if (! $document) {
                $missing++;
            }
        }

        return [
            'total' => $total,
            'approved' => $approved,
            'pending' => max($total - $approved - $rejected, 0),
            'rejected' => $rejected,
            'missing' => $missing,
        ];
    }

    private function providerFleetSummary(array $aircraftMetrics): array
    {
        $total = (int) ($aircraftMetrics['aircraft'] ?? 0);
        $active = (int) ($aircraftMetrics['active'] ?? 0);
        $pending = (int) ($aircraftMetrics['pending'] ?? 0);
        $trial = (int) ($aircraftMetrics['trial'] ?? 0);

        $status = 'no_aircraft';
        $label = 'Sin aeronaves';

        if ($total > 0 && $active === 0) {
            $status = 'pending_fleet';
            $label = 'Flota pendiente';
        } elseif ($total > 0 && $active === $total && $pending === 0 && $trial === 0) {
            $status = 'active_fleet';
            $label = 'Flota activa';
        } elseif ($active > 0 && ($pending > 0 || $trial > 0 || $active < $total)) {
            $status = 'mixed_fleet';
            $label = 'Flota mixta';
        }

        return [
            'total' => $total,
            'active' => $active,
            'pending' => $pending,
            'trial' => $trial,
            'status' => $status,
            'label' => $label,
        ];
    }

    private function resolveProviderPrimaryStatus(Proveedor $provider, array $checklist, array $documentSummary): string
    {
        $statuses = collect([
            $provider->admin_validation_status,
            $provider->approval_status,
            $provider->status,
            $provider->operator_status,
        ])->map(fn ($value) => $this->normalizeProviderPrimaryStatusValue($value))
            ->filter()
            ->values()
            ->all();

        $hasRejectedRequirements = collect($checklist)
            ->contains(fn (array $item) => $this->normalizeProviderRequirementResponseStatus($item['response_status'] ?? null) === 'rejected');
        $hasRejectedDocuments = (int) ($documentSummary['rejected'] ?? 0) > 0;
        $reviewSubmitted = (bool) $provider->admin_review_submitted_at;
        $startedSignals = count(array_filter([
            $provider->company_name,
            $provider->commercial_name,
            $provider->legal_name,
            $provider->rfc,
            $provider->user?->profile?->address,
            $provider->base_airport,
            $provider->company_phone,
            $provider->company_email,
            $provider->representative_name,
            ((int) ($documentSummary['total'] ?? 0) - (int) ($documentSummary['missing'] ?? 0)) > 0 ? 'documents' : null,
        ], fn ($value) => filled($value)));

        if (in_array('suspended', $statuses, true)) {
            return 'suspended';
        }

        if (in_array('rejected', $statuses, true)) {
            return 'rejected';
        }

        if (in_array('observations', $statuses, true) || $hasRejectedRequirements || $hasRejectedDocuments) {
            return 'observations';
        }

        if (in_array('approved', $statuses, true) || $this->resolveProviderAccessEnabled($provider, $this->resolveAdminValidationStatus($provider))) {
            return 'approved';
        }

        if (in_array('under_review', $statuses, true)) {
            return 'under_review';
        }

        if (in_array('submitted', $statuses, true) || ($reviewSubmitted && $statuses === [])) {
            return 'submitted';
        }

        if ($startedSignals > 1 || collect($checklist)->contains(fn (array $item) => ! (bool) ($item['complete'] ?? false))) {
            return 'incomplete';
        }

        return 'draft';
    }

    private function normalizeProviderPrimaryStatusValue(mixed $value): string
    {
        $normalized = Proveedor::normalizeStatusValue($value);

        return match ($normalized) {
            'approved', 'active', 'validated' => 'approved',
            'rejected' => 'rejected',
            'suspended' => 'suspended',
            'changes_requested', 'changes_required', 'observations', 'needs_changes' => 'observations',
            'pending_review', 'under_review' => 'under_review',
            'pending_validation', 'submitted', 'sent', 'enviado' => 'submitted',
            'incomplete', 'expediente_incompleto' => 'incomplete',
            'draft' => 'draft',
            default => '',
        };
    }

    private function normalizeProviderRequirementResponseStatus(mixed $value): string
    {
        $normalized = Proveedor::normalizeStatusValue($value);

        return match ($normalized) {
            'approved', 'aprobado', 'aprobada', 'validated', 'validado', 'validada' => 'approved',
            'rejected', 'rechazado', 'rechazada', 'cancelled', 'canceled', 'cancelado', 'cancelada' => 'rejected',
            default => 'pending',
        };
    }

    private function providerStatusLabel(string $status): string
    {
        return match ($status) {
            'incomplete' => 'Expediente incompleto',
            'submitted' => 'Enviado a revision',
            'under_review' => 'En revision',
            'observations' => 'Requiere correcciones',
            'approved' => 'Aprobado',
            'rejected' => 'Rechazado',
            'suspended' => 'Suspendido',
            default => 'Registro iniciado',
        };
    }

    private function providerStatusDescription(string $status): string
    {
        return match ($status) {
            'incomplete' => 'Faltan datos, documentos o validaciones obligatorias.',
            'submitted' => 'El proveedor termino la captura y envio el expediente.',
            'under_review' => 'El administrador esta revisando el expediente.',
            'observations' => 'El administrador encontro datos o documentos que deben corregirse.',
            'approved' => 'El expediente fue validado y aprobado por el administrador.',
            'rejected' => 'El proveedor no cumple los requisitos.',
            'suspended' => 'El proveedor aprobado fue suspendido administrativamente.',
            default => 'El proveedor comenzo su registro, pero aun faltan varios datos obligatorios.',
        };
    }

    private function serializeProviderUserSummary(?Usuario $user): ?array
    {
        if (! $user) {
            return null;
        }

        $profile = $user->relationLoaded('profile') && $user->profile
            ? $user->profile->attributesToArray()
            : null;

        return [
            'id' => $user->id,
            'provider_id' => $user->provider_id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'operational_role' => $user->operational_role,
            'status' => $user->status,
            'profile' => $profile,
        ];
    }

    private function serializeProviderInlineSummary(?Proveedor $provider): ?array
    {
        if (! $provider) {
            return null;
        }

        $provider->loadMissing('user.profile');

        return [
            'id' => $provider->id,
            'provider_id' => $provider->id,
            'company_name' => $provider->company_name,
            'commercial_name' => $provider->commercial_name,
            'legal_name' => $provider->legal_name,
            'rfc' => $provider->rfc,
            'company_phone' => $provider->company_phone,
            'company_email' => $provider->company_email,
            'base_airport' => $provider->base_airport,
            'representative_name' => $provider->representative_name,
            'representative_phone' => $provider->representative_phone,
            'name' => $provider->commercial_name ?: $provider->company_name ?: $provider->legal_name,
            'approval_status' => $provider->approval_status,
            'admin_validation_status' => $this->resolveAdminValidationStatus($provider),
            'operator_status' => $this->resolveOperatorStatus($provider),
            'status' => $provider->resolvedApprovalStatus(),
            'raw_status' => $provider->status,
            'approved_at' => $provider->admin_validated_at ?: $provider->validated_at,
            'is_approved' => $provider->isAdministrativelyApproved(),
            'access_enabled' => $provider->access_enabled,
            'user' => $this->serializeProviderUserSummary($provider->user),
        ];
    }

    private function serializeProviderAircraftPayload(Aeronave $aircraft): array
    {
        return [
            ...$aircraft->attributesToArray(),
            'approved_at' => $aircraft->approved_at,
            'approved' => $aircraft->isAdministrativelyApproved(),
            'review_status' => $aircraft->resolvedReviewStatus(),
            'documents' => [],
        ];
    }

    private function serializeAdminAircraftPayload(Aeronave $aircraft, ?array $stateSnapshot = null): array
    {
        $state = $stateSnapshot ?? $this->aircraftStateService->evaluate($aircraft);
        $documents = $aircraft->relationLoaded('documents')
            ? $this->deduplicateAircraftDocuments($aircraft->documents)->map(fn ($item) => $item->attributesToArray())->values()->all()
            : [];
        $availability = $aircraft->relationLoaded('availability')
            ? $aircraft->availability->map(fn ($item) => $item->attributesToArray())->values()->all()
            : [];

        return [
            ...$aircraft->attributesToArray(),
            'approved_at' => $aircraft->approved_at,
            'approved' => $aircraft->isAdministrativelyApproved(),
            'review_status' => $aircraft->resolvedReviewStatus(),
            'approval' => [
                'status' => $aircraft->resolvedApprovalStatus(),
                'label' => $aircraft->isAdministrativelyApproved() ? 'Aprobada' : 'Pendiente',
                'is_approved' => (bool) $aircraft->isAdministrativelyApproved(),
            ],
            'operational' => [
                'status' => $aircraft->resolvedOperationalStatus(),
                'label' => $aircraft->isOperationallyActive() ? 'Activa' : 'Inactiva',
                'is_active' => (bool) $aircraft->isOperationallyActive(),
            ],
            'provider' => $this->serializeProviderInlineSummary($aircraft->provider),
            'documents' => $documents,
            'documents_count' => count($documents),
            'images' => $aircraft->relationLoaded('images')
                ? $aircraft->images->map(fn ($item) => $item->attributesToArray())->values()->all()
                : [],
            'availability' => $availability,
            'documents_state' => $state['documents'] ?? [],
            'review' => $state['review'] ?? [],
            'operation' => $state['operation'] ?? [],
            'pricing' => $state['pricing'] ?? [],
            'activation' => $state['activation'] ?? [],
            'payment' => $state['payment'] ?? [],
            'billing_state' => $state['billing'] ?? [],
            'aircraft_state' => $state,
            'ready_to_quote' => $state['ready_to_quote'] ?? false,
            'ready_to_book' => $state['ready_to_book'] ?? false,
        ];
    }

    private function aircraftActivationRequirementPayload(array $state): array
    {
        return collect($state['activation']['missing_requirements'] ?? [])
            ->filter(fn ($entry) => trim((string) $entry) !== '')
            ->values()
            ->map(fn ($entry) => [
                'code' => (string) $entry,
                'label' => match ((string) $entry) {
                    'provider_not_approved' => 'Proveedor pendiente de aprobación',
                    'aircraft_not_approved' => 'Aeronave pendiente de aprobación',
                    'documents_pending' => 'Documentación obligatoria pendiente',
                    'range_missing' => 'Falta completar el rango de la aeronave',
                    'capacity_missing' => 'Falta completar la capacidad de la aeronave',
                    'base_missing' => 'Falta registrar la base operativa',
                    'commercial_information_incomplete' => 'Información comercial incompleta',
                    'payment_pending' => 'Pago pendiente',
                    default => Str::headline(str_replace('_', ' ', (string) $entry)),
                },
            ])
            ->all();
    }

    private function deduplicateAircraftDocuments(iterable $documents)
    {
        return collect($documents)
            ->filter(fn ($document) => $document instanceof DocumentoAeronave)
            ->sortByDesc(fn (DocumentoAeronave $document) => (int) $document->id)
            ->unique(function (DocumentoAeronave $document) {
                $aircraftId = (int) ($document->aircraft_id ?: 0);
                $type = trim((string) ($document->document_type ?: $document->type ?: ''));
                $storagePath = trim((string) ($document->storage_path ?: ''));
                $documentUrl = trim((string) ($document->document_url ?: $document->file_url ?: ''));
                $documentName = trim((string) ($document->document_name ?: ''));

                if ($storagePath !== '') {
                    return sprintf('storage:%d:%s:%s', $aircraftId, $type, $storagePath);
                }

                if ($documentUrl !== '') {
                    return sprintf('url:%d:%s:%s', $aircraftId, $type, $documentUrl);
                }

                return sprintf('logical:%d:%s:%s', $aircraftId, $type, $documentName);
            })
            ->sortBy('id')
            ->values();
    }

    private function resolveAdminValidationStatus(Proveedor $provider): string
    {
        $normalized = strtolower(trim((string) ($provider->admin_validation_status ?: '')));
        if ($normalized !== '') {
            if (in_array($normalized, ['draft', 'pending_validation', 'pending_review', 'incomplete'], true)) {
                return match (strtolower(trim((string) $provider->approval_status))) {
                    'approved' => 'approved',
                    'rejected' => 'rejected',
                    'changes_requested', 'changes_required', 'suspended' => 'changes_requested',
                    default => $normalized,
                };
            }

            return match ($normalized) {
                'expediente_incompleto' => 'draft',
                'changes_requested', 'changes_required', 'suspended' => 'changes_requested',
                default => $normalized,
            };
        }

        return match (strtolower(trim((string) $provider->approval_status))) {
            'approved' => 'approved',
            'rejected' => 'rejected',
            'changes_requested', 'changes_required', 'suspended' => 'changes_requested',
            default => ($provider->admin_review_submitted_at ? 'pending_review' : 'draft'),
        };
    }

    private function resolveOperatorStatus(Proveedor $provider, ?string $adminValidationStatus = null): string
    {
        $normalized = strtolower(trim((string) ($provider->operator_status ?: '')));
        if ($normalized !== '') {
            return match ($normalized) {
                'validated', 'active' => 'active',
                'changes_requested', 'changes_required' => 'incomplete',
                default => $normalized,
            };
        }

        return match ($adminValidationStatus ?: $this->resolveAdminValidationStatus($provider)) {
            'approved' => 'active',
            'rejected' => 'rejected',
            'changes_requested' => 'incomplete',
            'pending_review', 'pending_validation' => 'pending_review',
            default => 'incomplete',
        };
    }

    private function resolveProviderAccessEnabled(Proveedor $provider, ?string $adminValidationStatus = null): bool
    {
        if ($provider->access_enabled !== null) {
            return (bool) $provider->access_enabled;
        }

        return ($adminValidationStatus ?: $this->resolveAdminValidationStatus($provider)) === 'approved'
            && strtolower(trim((string) $provider->approval_status)) === 'approved';
    }

    private function resolveSatValidationStatus(Proveedor $provider): string
    {
        $profileTaxData = $provider->user?->profile?->tax_data ?? [];
        $explicit = strtolower(trim((string) ($provider->sat_validation_status ?: ($profileTaxData['sat_validation_status'] ?? ''))));

        if ($explicit !== '') {
            return $explicit;
        }

        return filled($provider->rfc) ? 'approved' : 'pending';
    }

    private function providerSatDocuments(iterable $documents): array
    {
        return collect($documents)
            ->filter(function ($document) {
                if (! $document instanceof DocumentoEmpresa) {
                    return false;
                }

                return ($this->companyDocumentMetadataForResponse($document)['definition_key'] ?? '') === 'sat_certificate';
            })
            ->values()
            ->all();
    }

    private function providerLegalDocuments(iterable $documents): array
    {
        return collect($documents)
            ->filter(function ($document) {
                if (! $document instanceof DocumentoEmpresa) {
                    return false;
                }

                $metadata = $this->companyDocumentMetadataForResponse($document);
                $definitionKey = $metadata['definition_key'] ?? '';
                $sectionKey = $metadata['section_key'] ?? '';

                return in_array($definitionKey, self::LEGAL_REQUIREMENT_DOCUMENT_KEYS, true)
                    || ($sectionKey === 'legal' && $definitionKey !== 'sat_certificate');
            })
            ->values()
            ->all();
    }

    private function documentsAreApproved(iterable $documents): bool
    {
        $collection = collect($documents)->filter(fn ($document) => $document instanceof DocumentoEmpresa)->values();

        return $collection->isNotEmpty()
            && $collection->every(
                fn (DocumentoEmpresa $document) => in_array(
                    strtolower(trim((string) $document->status)),
                    self::APPROVED_DOCUMENT_STATUSES,
                    true,
                )
            );
    }

    private function pendingDocumentLabels(iterable $documents): array
    {
        return collect($documents)
            ->filter(fn ($document) => $document instanceof DocumentoEmpresa)
            ->reject(fn (DocumentoEmpresa $document) => in_array(
                strtolower(trim((string) $document->status)),
                self::APPROVED_DOCUMENT_STATUSES,
                true,
            ))
            ->map(function (DocumentoEmpresa $document) {
                $metadata = $this->companyDocumentMetadataForResponse($document);

                return $metadata['definition_label']
                    ?? $document->document_name
                    ?? $document->original_name
                    ?? 'Documento legal';
            })
            ->filter()
            ->values()
            ->all();
    }

    private function providerHasApprovedSatValidation(Proveedor $provider, iterable $documents, array $requirementReviews = []): bool
    {
        if ($this->providerRequirementWasApproved($requirementReviews, 'sat_validation')) {
            return true;
        }

        $satDocuments = $this->providerSatDocuments($documents);
        if ($satDocuments !== []) {
            return $this->documentsAreApproved($satDocuments);
        }

        return in_array($this->resolveSatValidationStatus($provider), ['approved', 'aprobado', 'validated', 'validado'], true);
    }

    private function providerHasApprovedAircraft(Proveedor $provider): bool
    {
        return ($provider->aircraft ?? collect())
            ->contains(fn ($aircraft) => in_array(
                strtolower(trim((string) $aircraft->status)),
                self::APPROVED_AIRCRAFT_STATUSES,
            true,
        ));
    }

    private function normalizeMexicanRfc(?string $value): string
    {
        return Str::of((string) ($value ?? ''))
            ->trim()
            ->upper()
            ->replace(' ', '')
            ->value();
    }

    private function providerRequirementWasApproved(array $reviews, string $key): bool
    {
        $review = $reviews[$key] ?? null;
        $status = strtolower(trim((string) ($review['status'] ?? '')));

        return in_array($status, ['approved', 'aprobado', 'aprobada', 'validated', 'validado', 'validada'], true);
    }

    private function providerHasValidRfc(Proveedor $provider, array $requirementReviews = []): bool
    {
        if ($this->providerRequirementWasApproved($requirementReviews, 'rfc_valid')) {
            return true;
        }

        return preg_match(self::MEXICAN_RFC_PATTERN, $this->normalizeMexicanRfc($provider->rfc)) === 1;
    }

    private function providerValidationChecklist(Proveedor $provider): array
    {
        $documents = $provider->companyDocuments ?? collect();
        $requirementReviews = $this->providerRequirementReviews($provider);
        $address = $provider->user?->profile?->address;
        $companyIdentityComplete = filled($provider->commercial_name) && filled($provider->legal_name ?: $provider->company_name) && filled($address);
        $contactComplete = filled($provider->company_phone) && filled($provider->company_email);
        $representativeName = $provider->representative_name ?: ($provider->user?->profile?->tax_data['legal_representative'] ?? null);
        $representativeComplete = filled($representativeName);
        $legalDocuments = $this->providerLegalDocuments($documents);
        $documentsApproved = $this->documentsAreApproved($legalDocuments);
        $pendingLegalDocumentLabels = $this->pendingDocumentLabels($legalDocuments);
        $satApproved = $this->providerHasApprovedSatValidation($provider, $documents, $requirementReviews);
        $validRfc = $this->providerHasValidRfc($provider, $requirementReviews);

        return array_map(function (array $item) use ($requirementReviews) {
            $review = $requirementReviews[$item['key']] ?? null;

            return [
                ...$item,
                'response_status' => $review['status'] ?? 'pending',
                'admin_note' => $review['admin_note'] ?? null,
                'responded_at' => $review['updated_at'] ?? null,
                'actor_id' => $review['actor_id'] ?? null,
                'actor_name' => $review['actor_name'] ?? null,
                'actor_type' => $review['actor_type'] ?? null,
            ];
        }, [
            [
                'key' => 'company_identity',
                'label' => 'Datos de empresa completos',
                'complete' => $companyIdentityComplete,
                'message' => 'Faltan datos de empresa completos.',
            ],
            [
                'key' => 'rfc_valid',
                'label' => 'RFC valido',
                'complete' => $validRfc,
                'message' => 'Falta RFC valido del operador.',
            ],
            [
                'key' => 'sat_validation',
                'label' => 'Validacion SAT',
                'complete' => $satApproved,
                'message' => 'La validacion SAT sigue pendiente.',
            ],
            [
                'key' => 'legal_documents_approved',
                'label' => 'Documentacion legal aprobada',
                'complete' => $documentsApproved,
                'message' => $pendingLegalDocumentLabels !== []
                    ? 'La documentacion legal aun no esta aprobada. Pendientes: '.implode(', ', $pendingLegalDocumentLabels).'.'
                    : 'La documentacion legal aun no esta aprobada.',
            ],
            [
                'key' => 'base_operativa',
                'label' => 'Base operativa definida',
                'complete' => filled($provider->base_airport ?: $provider->user?->profile?->base_airport),
                'message' => 'Falta base operativa definida.',
            ],
            [
                'key' => 'contact_complete',
                'label' => 'Datos de contacto completos',
                'complete' => $contactComplete,
                'message' => 'Faltan datos de contacto completos.',
            ],
            [
                'key' => 'legal_representative_complete',
                'label' => 'Representante legal completo',
                'complete' => $representativeComplete,
                'message' => 'Falta representante legal completo.',
            ],
            [
                'key' => 'review_submitted',
                'label' => 'Expediente enviado a revision',
                'complete' => (bool) $provider->admin_review_submitted_at,
                'message' => 'El proveedor aun no envia el expediente a revision administrativa.',
            ],
        ]);
    }

    private function providerRequirementReviews(Proveedor $provider): array
    {
        return is_array($provider->provider_validation_requirements)
            ? $provider->provider_validation_requirements
            : [];
    }

    private function upsertProviderRequirementReview(
        Proveedor $provider,
        string $requirement,
        string $status,
        string $adminNote,
        ?Usuario $actor
    ): array
    {
        $reviews = $this->providerRequirementReviews($provider);
        $payload = [
            'status' => $status,
            'admin_note' => $adminNote ?: null,
            'updated_at' => now()->toISOString(),
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_type' => 'admin',
        ];

        $reviews[$requirement] = $payload;

        $provider->forceFill([
            'provider_validation_requirements' => $reviews,
        ])->save();

        return $payload;
    }

    private function providerUserIds(Proveedor $provider): array
    {
        return collect([$provider->user_id])
            ->merge($provider->users->pluck('id'))
            ->filter()
            ->unique()
            ->map(fn ($value) => (int) $value)
            ->values()
            ->all();
    }

    private function auditEntryMatchesProvider(RegistroAuditoria $entry, Proveedor $provider, array $providerUserIds): bool
    {
        if (in_array((int) $entry->user_id, $providerUserIds, true)) {
            return true;
        }

        $metadataCandidates = [
            $entry->new_values,
            $entry->old_values,
        ];

        foreach ($metadataCandidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $providerId = (int) ($candidate['provider_id'] ?? data_get($candidate, 'provider.id') ?? 0);
            if ($providerId === (int) $provider->id) {
                return true;
            }
        }

        return false;
    }

    private function serializeProviderActivityEntry(RegistroAuditoria $entry): array
    {
        $metadata = is_array($entry->new_values) ? $entry->new_values : [];
        $eventType = $metadata['event_type'] ?? $this->inferProviderActivityEventType($entry);
        $title = $metadata['title'] ?? $this->humanizeProviderActivityTitle($entry, $eventType);
        $description = $metadata['description'] ?? ($entry->description ?: $title);

        if (($metadata['document_definition_label'] ?? '') !== '' && ! str_contains($title, (string) $metadata['document_definition_label'])) {
            $title .= ': '.$metadata['document_definition_label'];
        }

        return [
            'id' => $entry->id,
            'event_type' => $eventType,
            'title' => $title,
            'description' => $description,
            'actor_type' => $metadata['actor_type'] ?? $this->resolveProviderActivityActorType($entry),
            'actor_name' => $metadata['actor_name'] ?? $entry->user?->name ?? 'Proveedor',
            'created_at' => optional($entry->created_at)?->toISOString(),
            'metadata' => $metadata,
        ];
    }

    private function resolveProviderActivityActorType(RegistroAuditoria $entry): string
    {
        $role = strtolower(trim((string) ($entry->user?->role ?: '')));
        $operationalRole = strtolower(trim((string) ($entry->user?->operational_role ?: '')));

        return in_array('admin', [$role, $operationalRole], true) ? 'admin' : 'provider';
    }

    private function inferProviderActivityEventType(RegistroAuditoria $entry): string
    {
        return match ($entry->module) {
            'provider_company_document' => str_contains((string) $entry->action, 'reject')
                ? 'document_rejected'
                : (str_contains((string) $entry->action, 'approv') ? 'document_approved' : 'document_uploaded'),
            'provider_aircraft_document' => 'aircraft_document_uploaded',
            'provider_aircraft' => str_contains((string) $entry->action, 'update') ? 'aircraft_updated' : 'aircraft_created',
            'provider_admin_validation' => $entry->action,
            'provider_requirement_review' => $entry->action,
            default => 'company_updated',
        };
    }

    private function humanizeProviderActivityTitle(RegistroAuditoria $entry, string $eventType): string
    {
        return match ($eventType) {
            'document_uploaded' => 'Documento legal cargado',
            'document_approved' => 'Documento legal validado',
            'document_rejected' => 'Documento legal rechazado',
            'aircraft_created' => 'Aeronave registrada',
            'aircraft_updated' => 'Aeronave actualizada',
            'aircraft_document_uploaded' => 'Documento de aeronave cargado',
            'admin_requirement_approved' => 'Requisito validado por administracion',
            'admin_requirement_rejected' => 'Requisito rechazado por administracion',
            'admin_operator_approved' => 'Operador validado por administracion',
            'admin_operator_rejected' => 'Validacion cancelada por administracion',
            'admin_changes_requested' => 'Cambios solicitados por administracion',
            default => $entry->description ?: 'Actividad del expediente actualizada',
        };
    }

    private function serializeProviderDocument(DocumentoEmpresa $document): array
    {
        $downloadUrl = sprintf('/api/v1/admin/proveedores/%d/documentos/%d/descargar', $document->provider_id, $document->id);
        $metadata = $this->companyDocumentMetadataForResponse($document);

        return [
            'id' => $document->id,
            'provider_id' => $document->provider_id,
            'document_name' => $document->document_name ?? 'Documento',
            'name' => $document->document_name ?? 'Documento',
            'original_name' => $document->original_name ?? $document->document_name ?? 'Documento',
            'file_name' => $document->file_name ?? basename((string) ($document->storage_path ?: $document->document_url ?: $document->file_url ?: '')),
            'path' => $document->storage_path,
            'storage_path' => $document->storage_path,
            'storage_disk' => $document->storage_disk,
            'file_url' => $document->file_url,
            'document_url' => $document->document_url,
            'url' => $document->document_url ?: $document->file_url,
            'download_url' => $downloadUrl,
            'downloadUrl' => $downloadUrl,
            'mime_type' => $document->mime_type,
            'size' => (int) ($document->file_size_bytes ?? 0),
            'file_size_bytes' => (int) ($document->file_size_bytes ?? 0),
            'status' => $document->status ?? 'pendiente',
            'notes' => $document->notes,
            'expires_at' => optional($document->expires_at)?->toISOString(),
            'created_at' => optional($document->created_at)?->toISOString(),
            'updated_at' => optional($document->updated_at)?->toISOString(),
            'document_type' => $metadata['document_type'],
            'document_category' => $metadata['document_category'],
            'document_slot' => $metadata['document_slot'],
            'document_section' => $metadata['document_section'],
            'definition_key' => $metadata['definition_key'],
            'definition_label' => $metadata['definition_label'],
            'section_key' => $metadata['section_key'],
            'section_label' => $metadata['section_label'],
            'field_map' => $metadata['field_map'],
        ];
    }

    private function downloadCompanyDocumentResponse(DocumentoEmpresa $document)
    {
        $disk = trim((string) ($document->storage_disk ?: 's3')) ?: 's3';
        $path = trim((string) ($document->storage_path ?: ''));

        if ($path !== '' && config("filesystems.disks.{$disk}") !== null) {
            $storage = Storage::disk($disk);
            abort_unless($storage->exists($path), 404, 'Archivo no encontrado en almacenamiento.');

            return $storage->response(
                $path,
                $document->original_name ?: $document->document_name ?: basename($path),
                ['Content-Type' => $document->mime_type ?: 'application/octet-stream'],
                'inline'
            );
        }

        $fallbackUrl = trim((string) ($document->document_url ?: $document->file_url ?: ''));
        abort_if($fallbackUrl === '', 404, 'Documento sin URL disponible.');

        return redirect()->away($fallbackUrl);
    }

    private function filterCompanyDocumentPayload(array $payload): array
    {
        return array_intersect_key($payload, array_flip($this->companyDocumentAvailableColumns()));
    }

    private function companyDocumentAvailableColumns(): array
    {
        static $columns = null;

        if (is_array($columns)) {
            return $columns;
        }

        $columns = Schema::getColumnListing('company_documents');

        return $columns;
    }

    private function companyDocumentMetadataForResponse(DocumentoEmpresa $document): array
    {
        $definition = $this->resolveCompanyDocumentDefinitionFromCandidates([
            $document->document_slot,
            $document->document_type,
            $document->document_category,
            $document->document_name,
            $document->original_name,
            $document->file_name,
        ]);

        $documentType = trim((string) ($document->document_type ?: '')) ?: ($definition['id'] ?? '');
        $documentCategory = trim((string) ($document->document_category ?: '')) ?: ($definition['id'] ?? '');
        $documentSlot = trim((string) ($document->document_slot ?: '')) ?: ($definition['id'] ?? '');
        $documentSection = trim((string) ($document->document_section ?: '')) ?: ($definition['section_key'] ?? '');

        return [
            'document_type' => $documentType,
            'document_category' => $documentCategory,
            'document_slot' => $documentSlot,
            'document_section' => $documentSection,
            'definition_key' => $definition['id'] ?? ($documentSlot ?: $documentType ?: $documentCategory),
            'definition_label' => $definition['label'] ?? ($document->document_name ?: $document->original_name ?: 'Documento de empresa'),
            'section_key' => $definition['section_key'] ?? ($documentSection ?: 'legal'),
            'section_label' => $definition['section_label'] ?? 'Documentacion legal del operador',
            'field_map' => [
                ['column' => 'document_slot', 'value' => $documentSlot],
                ['column' => 'document_type', 'value' => $documentType],
                ['column' => 'document_category', 'value' => $documentCategory],
                ['column' => 'document_section', 'value' => $documentSection],
            ],
        ];
    }

    private function resolveCompanyDocumentDefinitionFromCandidates(array $candidates): ?array
    {
        $catalog = $this->companyDocumentDefinitionCatalog();

        foreach ($candidates as $candidate) {
            $normalized = Str::of((string) $candidate)->trim()->lower()->replace(['-', '_'], ' ')->value();
            if ($normalized === '') {
                continue;
            }

            foreach ($catalog as $definition) {
                foreach ($definition['matchers'] as $matcher) {
                    $normalizedMatcher = Str::of((string) $matcher)->trim()->lower()->replace(['-', '_'], ' ')->value();
                    if ($normalized === $normalizedMatcher || str_contains($normalized, $normalizedMatcher)) {
                        return $definition;
                    }
                }
            }
        }

        return null;
    }

    private function companyDocumentDefinitionCatalog(): array
    {
        return [
            [
                'id' => 'sat_certificate',
                'label' => 'Constancia de situacion fiscal',
                'section_key' => 'sat',
                'section_label' => 'Validacion SAT / Constancia fiscal',
                'matchers' => ['sat_certificate', 'constancia fiscal', 'constancia_sat', 'situacion fiscal', 'sat'],
            ],
            [
                'id' => 'articles_of_incorporation',
                'label' => 'Acta constitutiva',
                'section_key' => 'legal',
                'section_label' => 'Carga legal y respaldo',
                'matchers' => ['articles_of_incorporation', 'acta_constitutiva', 'acta constitutiva'],
            ],
            [
                'id' => 'legal_representative_power',
                'label' => 'Poder del representante legal',
                'section_key' => 'legal',
                'section_label' => 'Carga legal y respaldo',
                'matchers' => ['legal_representative_power', 'poder_representante', 'poder del representante', 'power'],
            ],
            [
                'id' => 'legal_representative_id',
                'label' => 'Identificacion oficial del representante',
                'section_key' => 'legal',
                'section_label' => 'Carga legal y respaldo',
                'matchers' => ['legal_representative_id', 'identificacion_representante', 'identificacion oficial', 'ine', 'pasaporte'],
            ],
            [
                'id' => 'tax_address_proof',
                'label' => 'Comprobante de domicilio fiscal',
                'section_key' => 'legal',
                'section_label' => 'Carga legal y respaldo',
                'matchers' => ['tax_address_proof', 'domicilio_fiscal', 'comprobante_domicilio', 'domicilio fiscal'],
            ],
            [
                'id' => 'operational_permit',
                'label' => 'Permiso operativo o documentacion aeronautica',
                'section_key' => 'legal',
                'section_label' => 'Carga legal y respaldo',
                'matchers' => ['operational_permit', 'permiso_operativo', 'documentacion_aeronautica', 'permiso operativo'],
            ],
        ];
    }
}
