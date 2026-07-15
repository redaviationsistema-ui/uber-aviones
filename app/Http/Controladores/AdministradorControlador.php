<?php

namespace App\Http\Controladores;

use App\Modelos\Aeronave;
use App\Modelos\RegistroAuditoria;
use App\Modelos\Comision;
use App\Modelos\Demo;
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
use App\Servicios\Providers\AdminProviderApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdministradorControlador extends ControladorBase
{
    private const APPROVED_DOCUMENT_STATUSES = ['approved', 'aprobado', 'aprobada', 'vigente', 'validado'];

    private const APPROVED_AIRCRAFT_STATUSES = ['active', 'trial_active', 'inactive', 'approved', 'aprobada', 'aprobado'];

    private const LEGAL_REQUIREMENT_DOCUMENT_KEYS = [
        'articles_of_incorporation',
        'legal_representative_power',
        'legal_representative_id',
        'tax_address_proof',
        'operational_permit',
    ];

    public function __construct(
        private readonly AdminProviderApprovalService $adminProviderApprovalService,
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
            ->with(['user.profile'])
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

    public function aircraft()
    {
        $aircraft = Aeronave::query()
            ->with(['provider.user.profile', 'availability'])
            ->paginate(25);

        return $this->ok([
            'aircraft' => $aircraft->through(fn (Aeronave $item) => $this->serializeAdminAircraftPayload($item)),
        ]);
    }

    public function showAeronave(Aeronave $aircraft)
    {
        return $this->ok([
            'aircraft' => $this->serializeAdminAircraftPayload(
                $aircraft->load(['provider.user.profile', 'images', 'documents', 'availability'])
            ),
        ]);
    }

    public function blockAeronave(Aeronave $aircraft)
    {
        $aircraft->update(['status' => 'blocked']);

        return $this->ok([
            'aircraft' => $this->serializeAdminAircraftPayload(
                $aircraft->fresh(['provider.user.profile', 'images', 'documents', 'availability'])
            ),
        ]);
    }

    public function activateAeronave(Aeronave $aircraft)
    {
        $provider = $aircraft->provider;
        if (! $provider) {
            return response()->json([
                'success' => false,
                'code' => 'PROVIDER_NOT_FOUND',
                'message' => 'No se encontró el proveedor asociado a la aeronave.',
            ], 404);
        }

        if (! $provider->isApprovedForOperations()) {
            return response()->json([
                'success' => false,
                'code' => 'PROVIDER_NOT_APPROVED',
                'message' => 'No se puede activar la aeronave porque el proveedor aún no está aprobado.',
            ], 403);
        }

        $aircraftStatus = Proveedor::normalizeStatusValue($aircraft->status ?? 'inactive');
        if ($aircraftStatus !== 'inactive') {
            return response()->json([
                'success' => false,
                'code' => 'AIRCRAFT_NOT_APPROVED',
                'message' => 'No se puede activar la aeronave porque todavía no ha sido aprobada.',
            ], 403);
        }

        $aircraft->update([
            'approved_at' => $aircraft->approved_at ?: now(),
            'status' => $aircraft->hasActiveBillingSubscription() ? 'active' : 'inactive',
        ]);

        return $this->ok([
            'aircraft' => $this->serializeAdminAircraftPayload(
                $aircraft->fresh(['provider.user.profile', 'images', 'documents', 'availability'])
            ),
        ]);
    }

    public function flightRequests()
    {
        return $this->ok(['flight_requests' => SolicitudVuelo::with(['client', 'matches.aircraft'])->latest()->paginate(25)]);
    }

    public function quotes()
    {
        return $this->ok(['quotes' => Cotizacion::with(['flightRequest', 'provider', 'aircraft'])->latest()->paginate(25)]);
    }

    public function reservations()
    {
        return $this->ok(['reservations' => Reserva::with(['client', 'provider', 'aircraft', 'quote'])->latest()->paginate(25)]);
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
            'documents' => $documents,
            'company_documents' => $documents,
            'legal_documents' => $documents,
            'documents_count' => count($documents),
            'legal_documents_count' => count($documents),
            'company_documents_count' => count($documents),
            'aircraft_metrics' => $this->providerAircraftMetrics($provider),
            'user' => $this->serializeProviderUserSummary($provider->user),
            'aircraft' => $aircraft,
        ];
    }

    private function serializeProviderSummary(Proveedor $provider): array
    {
        $provider->loadMissing('user.profile');
        $adminValidationStatus = $this->resolveAdminValidationStatus($provider);
        $operatorStatus = $this->resolveOperatorStatus($provider, $adminValidationStatus);

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
            'validation_requirements' => [],
            'can_validate' => false,
            'documents_count' => (int) ($provider->company_documents_count ?? $provider->documents_count ?? 0),
            'legal_documents_count' => (int) ($provider->company_documents_count ?? $provider->legal_documents_count ?? 0),
            'company_documents_count' => (int) ($provider->company_documents_count ?? 0),
            'aircraft_metrics' => $this->providerAircraftMetrics($provider),
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
            'approval_status' => $provider->approval_status,
            'admin_validation_status' => $this->resolveAdminValidationStatus($provider),
            'operator_status' => $this->resolveOperatorStatus($provider),
            'status' => $provider->status,
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

    private function serializeAdminAircraftPayload(Aeronave $aircraft): array
    {
        $availability = $aircraft->relationLoaded('availability')
            ? $aircraft->availability->map(fn ($item) => $item->attributesToArray())->values()->all()
            : [];

        return [
            ...$aircraft->attributesToArray(),
            'approved_at' => $aircraft->approved_at,
            'approved' => $aircraft->isAdministrativelyApproved(),
            'review_status' => $aircraft->resolvedReviewStatus(),
            'provider' => $this->serializeProviderInlineSummary($aircraft->provider),
            'documents' => $aircraft->relationLoaded('documents')
                ? $aircraft->documents->map(fn ($item) => $item->attributesToArray())->values()->all()
                : [],
            'images' => $aircraft->relationLoaded('images')
                ? $aircraft->images->map(fn ($item) => $item->attributesToArray())->values()->all()
                : [],
            'availability' => $availability,
        ];
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

    private function providerHasApprovedSatValidation(Proveedor $provider, iterable $documents): bool
    {
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

    private function providerValidationChecklist(Proveedor $provider): array
    {
        $documents = $provider->companyDocuments ?? collect();
        $companyIdentityComplete = filled($provider->company_name) && filled($provider->commercial_name) && filled($provider->legal_name);
        $contactComplete = filled($provider->company_phone) && filled($provider->company_email);
        $representativeComplete = filled($provider->representative_name) && filled($provider->representative_phone);
        $legalDocuments = $this->providerLegalDocuments($documents);
        $documentsApproved = $this->documentsAreApproved($legalDocuments);
        $pendingLegalDocumentLabels = $this->pendingDocumentLabels($legalDocuments);
        $satApproved = $this->providerHasApprovedSatValidation($provider, $documents);
        $requirementReviews = $this->providerRequirementReviews($provider);

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
                'complete' => filled($provider->rfc),
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
                'complete' => filled($provider->base_airport),
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
                'complete' => filled($provider->representative_name),
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
