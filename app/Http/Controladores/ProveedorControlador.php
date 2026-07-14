<?php

namespace App\Http\Controladores;

use App\Modelos\Aeronave;
use App\Modelos\AircraftBillingPayment;
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
use App\Servicios\Aeronaves\AircraftAvailabilityService;
use App\Servicios\ReintentoCoincidenciaSolicitudServicio;
use App\Servicios\RedAviation\VisibilidadServicio;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProveedorControlador extends ControladorBase
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
        $providerId = $user->resolvedProviderId();
        $aircraftCounts = Aeronave::query()
            ->where('provider_id', $providerId)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return $this->ok([
            'provider' => $this->formatCompanyPayload($user),
            'metrics' => [
                'aircraft' => (int) $aircraftCounts->sum(),
                'active_aircraft' => (int) $aircraftCounts->only(['active', 'trial_active'])->sum(),
                'inactive_aircraft' => (int) $aircraftCounts->only(['inactive', 'blocked', 'archived', 'suspended'])->sum(),
                'pending_requests' => (int) DB::table('request_matches')
                    ->where('provider_id', $providerId)
                    ->whereIn('status', ['pending', 'sent_to_provider'])
                    ->distinct()
                    ->count('flight_request_id'),
                'active_operations' => Operacion::where('provider_id', $providerId)
                    ->whereNotIn('status', ['finalizada', 'cancelada', 'completed', 'cancelled'])
                    ->count(),
                'open_incidents' => (int) DB::table('operation_timeline')
                    ->join('operations', 'operations.id', '=', 'operation_timeline.operation_id')
                    ->where('operations.provider_id', $providerId)
                    ->where('operation_timeline.status', 'incidencia')
                    ->count(),
                'pending_quotes' => (int) DB::table('quotes')
                    ->where('provider_id', $providerId)
                    ->where('status', 'pending')
                    ->count(),
                'reservations' => Reserva::where('provider_id', $providerId)->count(),
                'pending_payments' => PagoProveedor::where('provider_id', $providerId)
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

    public function profileStatus(Request $request)
    {
        $company = $this->formatCompanyPayload($request);
        $approvalStatus = (string) ($company['approval_status'] ?? 'pending');
        $providerStatus = (string) ($company['admin_validation_status'] ?? 'draft');
        $accessEnabled = (bool) ($company['access_enabled'] ?? false);

        return $this->ok([
            'provider_status' => $providerStatus,
            'approval_status' => $approvalStatus,
            'operator_status' => (string) ($company['operator_status'] ?? 'incomplete'),
            'access_enabled' => $accessEnabled,
            'can_register_aircraft' => $accessEnabled,
            'company' => $company,
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
            'company_phone' => ['nullable', 'string', 'max:50'],
            'company_email' => ['nullable', 'email', 'max:255'],
            'base_airport' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'address' => ['nullable', 'string', 'max:255'],
            'representative_name' => ['nullable', 'string', 'max:255'],
            'representative_phone' => ['nullable', 'string', 'max:50'],
            'legal_representative' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date'],
            'curp' => ['nullable', 'string', 'max:32'],
            'nationality' => ['nullable', 'string', 'max:120'],
            'document_type' => ['nullable', 'string', 'max:50'],
            'document_number' => ['nullable', 'string', 'max:120'],
            'document_expiration' => ['nullable', 'date'],
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

        $previousProvider = $provider->only([
            'company_name',
            'commercial_name',
            'legal_name',
            'rfc',
            'company_phone',
            'company_email',
            'base_airport',
            'representative_name',
            'representative_phone',
        ]);

        $provider->update([
            'company_name' => $data['company_name'] ?? $data['commercial_name'] ?? $data['trade_name'] ?? $provider->company_name,
            'commercial_name' => $data['commercial_name'] ?? $data['company_name'] ?? $data['trade_name'] ?? $provider->commercial_name,
            'legal_name' => $data['legal_name'] ?? $provider->legal_name,
            'rfc' => $data['rfc'] ?? $provider->rfc,
            'company_phone' => $data['company_phone'] ?? $data['phone'] ?? $provider->company_phone,
            'company_email' => $data['company_email'] ?? $data['email'] ?? $provider->company_email,
            'base_airport' => $data['base_airport'] ?? $profile->base_airport ?? $provider->base_airport,
            'status' => $data['status'] ?? $provider->status ?? $provider->approval_status,
            'representative_name' => $data['representative_name'] ?? $data['legal_representative'] ?? $provider->representative_name,
            'representative_phone' => $data['representative_phone'] ?? $provider->representative_phone,
            'birth_date' => $data['birth_date'] ?? $profile->birth_date ?? $provider->birth_date,
            'curp' => $data['curp'] ?? $profile->ine_curp ?? $provider->curp,
            'nationality' => $data['nationality'] ?? $profile->nationality ?? $provider->nationality,
            'document_type' => $data['document_type'] ?? $profile->document_type ?? $provider->document_type,
            'document_number' => $data['document_number'] ?? $profile->document_number ?? $provider->document_number,
            'document_expiration' => $data['document_expiration'] ?? $profile->document_expiration ?? $provider->document_expiration,
            'jet_a_price' => $data['jet_a_price'] ?? $provider->jet_a_price,
            'margin_percent' => $data['margin_percent'] ?? $provider->margin_percent,
            'fixed_fee' => $data['fixed_fee'] ?? $provider->fixed_fee,
            'notes' => $data['admin_notes'] ?? $provider->notes,
        ]);

        $user->update(array_filter([
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
        ], fn ($value) => $value !== null));

        $this->writeAudit($request, 'update', 'provider_company', 'Empresa del proveedor actualizada.', [
            'old_values' => [
                ...$previousProvider,
                'provider_id' => $provider->id,
            ],
            'new_values' => [
                'provider_id' => $provider->id,
                'event_type' => 'company_updated',
                'title' => 'Datos de empresa actualizados',
                'description' => 'El proveedor actualizo razon social, RFC, contacto o base operativa.',
                'company_name' => $provider->fresh()->company_name,
                'commercial_name' => $provider->fresh()->commercial_name,
                'legal_name' => $provider->fresh()->legal_name,
                'rfc' => $provider->fresh()->rfc,
            ],
        ]);

        return $this->ok([
            'company' => $this->formatCompanyPayload($request->user()->fresh(['provider', 'profile'])),
        ]);
    }

    public function submitCompanyReview(Request $request)
    {
        Log::info('INPUT', $request->all());
        Log::info('FILES', $request->allFiles());

        $user = $request->user()->loadMissing('provider', 'profile');
        $provider = $user->provider;
        abort_if(! $provider, 404, 'Proveedor no encontrado.');

        $data = $request->validate([
            'file' => ['nullable', 'file', 'max:20480'],
            'document' => ['nullable', 'file', 'max:20480'],
            'legal_document' => ['nullable', 'file', 'max:20480'],
            'documents' => ['nullable', 'array', 'max:10'],
            'documents.*' => ['file', 'max:20480'],
            'documents.*.*' => ['file', 'max:20480'],
            'document_name' => ['nullable', 'string', 'max:150'],
            'document_type' => ['nullable', 'string', 'max:100'],
            'document_category' => ['nullable', 'string', 'max:100'],
            'document_slot' => ['nullable', 'string', 'max:100'],
            'document_section' => ['nullable', 'string', 'max:100'],
            'original_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', 'string', 'max:100'],
            'validation_status' => ['nullable', 'string', 'max:100'],
            'review_status' => ['nullable', 'string', 'max:100'],
        ]);

        $document = $this->extractCompanyDocumentUploadFromRequest($request);
        Log::info('COMPANY_REVIEW_HAS_FILE', [
            'has_file' => $request->hasFile('file'),
            'has_document' => $request->hasFile('document'),
            'has_legal_document' => $request->hasFile('legal_document'),
            'has_documents' => $request->hasFile('documents'),
            'resolved_original_name' => $document?->getClientOriginalName(),
        ]);

        $createdDocument = null;

        if ($document instanceof UploadedFile) {
            $createdDocument = $this->createCompanyDocumentRecord($provider, $document, [
                'document_name' => $data['document_name'] ?? $data['original_name'] ?? $document->getClientOriginalName(),
                ...$this->normalizeCompanyDocumentMetadata($data),
                'notes' => $data['notes'] ?? null,
                'status' => 'pending',
            ]);
        }

        $provider->update([
            'approval_status' => 'pending',
            'status' => 'pending_review',
            'admin_validation_status' => 'pending_review',
            'operator_status' => 'pending_review',
            'access_enabled' => false,
            'admin_review_submitted_at' => now(),
            'admin_notes' => $data['notes'] ?? $provider->admin_notes,
            'admin_validation_notes' => $data['notes'] ?? $provider->admin_validation_notes,
            'changes_notes' => null,
            'rejection_reason' => null,
            'validated_by' => null,
            'validated_at' => null,
            'rejected_by' => null,
            'rejected_at' => null,
            'changes_requested_by' => null,
            'changes_requested_at' => null,
            'admin_validated_by' => null,
            'admin_validated_at' => null,
            'admin_rejected_by' => null,
            'admin_rejected_at' => null,
            'admin_changes_requested_by' => null,
            'admin_changes_requested_at' => null,
        ]);
        $this->writeAudit($request, 'submit_review', 'provider_company', 'Proveedor enviado a revision administrativa.', [
            'new_values' => [
                'provider_id' => $provider->id,
                'event_type' => 'sent_to_review',
                'title' => 'Expediente enviado a revision',
                'description' => $data['notes'] ?? 'El proveedor envio su expediente a revision administrativa.',
                'document_id' => $createdDocument?->id,
            ],
        ]);

        return $this->ok([
            'company' => $this->formatCompanyPayload($user->fresh(['provider.companyDocuments', 'profile'])),
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
            'document_type' => ['nullable', 'string', 'max:100'],
            'document_category' => ['nullable', 'string', 'max:100'],
            'document_slot' => ['nullable', 'string', 'max:100'],
            'document_section' => ['nullable', 'string', 'max:100'],
            'replace_document_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $existingDocument = $this->findCompanyDocumentTargetForWrite($provider, $data);
        $document = $request->hasFile('file')
            ? $this->createCompanyDocumentRecord($provider, $request->file('file'), $data, $existingDocument)
            : $this->upsertCompanyDocumentRecord($provider, [
                ...$this->normalizeCompanyDocumentMetadata($data),
                'document_name' => $data['document_name'] ?? basename((string) parse_url((string) ($data['document_url'] ?? $data['file_url'] ?? ''), PHP_URL_PATH)),
                'original_name' => $data['document_name'] ?? basename((string) parse_url((string) ($data['document_url'] ?? $data['file_url'] ?? ''), PHP_URL_PATH)),
                'file_name' => basename((string) parse_url((string) ($data['document_url'] ?? $data['file_url'] ?? ''), PHP_URL_PATH)),
                'file_url' => $data['file_url'] ?? $data['document_url'],
                'document_url' => $data['document_url'] ?? $data['file_url'],
                'mime_type' => $data['mime_type'] ?? null,
                'file_size_bytes' => $data['file_size_bytes'] ?? null,
                'status' => 'pendiente',
                'notes' => $data['notes'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
            ], $existingDocument);
        $metadata = $this->companyDocumentMetadataForResponse($document);

        $this->writeAudit($request, 'upload', 'provider_company_document', 'Documento de empresa cargado.', [
            'new_values' => [
                'provider_id' => $provider->id,
                'document_id' => $document->id,
                'event_type' => 'document_uploaded',
                'title' => 'Documento legal cargado',
                'description' => $this->buildCompanyDocumentAuditDescription($metadata, $document),
                'document_name' => $document->document_name ?? $document->original_name ?? 'Documento de empresa',
                'document_type' => $metadata['document_type'],
                'document_category' => $metadata['document_category'],
                'document_slot' => $metadata['document_slot'],
                'document_section' => $metadata['document_section'],
                'document_definition_key' => $metadata['definition_key'],
                'document_definition_label' => $metadata['definition_label'],
                'document_section_label' => $metadata['section_label'],
            ],
        ]);

        return $this->ok([
            'document' => $this->formatCompanyDocumentPayload($document),
            'company' => $this->formatCompanyPayload($request->user()->fresh(['provider', 'profile'])),
            'url' => $document->document_url,
        ], 201);
    }

    public function requests(Request $request)
    {
        $providerId = $request->user()->resolvedProviderId();
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
        $providerId = $this->resolvedProviderIdOrAbort($request, 403);
        $hasAccess = (
            (int) $flightRequest->assigned_provider_id === (int) $providerId ||
            $flightRequest->matches()->where('provider_id', $providerId)->exists()
        );
        abort_if(! $hasAccess, 403);

        return $this->ok(['flight_request' => $flightRequest->load(['matches.aircraft', 'quotes'])]);
    }

    public function acceptRequest(Request $request, SolicitudVuelo $flightRequest)
    {
        $providerId = $this->resolvedProviderIdOrAbort($request, 404);

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
        $providerId = $this->resolvedProviderIdOrAbort($request, 404);

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
        $providerId = $this->resolvedProviderIdOrAbort($request, 404);

        $aircraftPayments = AircraftBillingPayment::query()
            ->with(['aircraft:id,registration,model,base_airport', 'billingPlan:id,code,name,amount,currency'])
            ->where('provider_id', $providerId)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (AircraftBillingPayment $payment) => [
                'id' => 'aircraft-'.$payment->id,
                'aircraft_payment_id' => $payment->id,
                'aircraft_id' => $payment->aircraft_id,
                'aircraft_name' => $payment->aircraft?->model,
                'aircraft_model' => $payment->aircraft?->model,
                'aircraft' => $payment->aircraft?->model,
                'aircraft_registration' => $payment->aircraft?->registration,
                'base_airport' => $payment->aircraft?->base_airport,
                'completed_at' => optional($payment->paid_at ?? $payment->updated_at ?? $payment->created_at)?->toDateTimeString(),
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency ?: 'USD',
                'status' => $payment->status,
                'receipt' => $payment->provider_payment_id ?: $payment->provider_subscription_id ?: $payment->provider_checkout_id,
                'reference' => $payment->provider_subscription_id ?: $payment->provider_payment_id ?: $payment->provider_checkout_id,
                'description' => 'Suscripcion mensual aeronave '.trim(($payment->aircraft?->model ?: 'Aeronave').' '.($payment->aircraft?->registration ?: '')),
                'payment_method' => 'Stripe',
                'payment_provider' => $payment->provider,
                'payment_type' => 'aircraft_subscription',
                'billing_plan_name' => $payment->billingPlan?->name,
                'billing_period_start' => optional($payment->billing_period_start)?->toDateString(),
                'billing_period_end' => optional($payment->billing_period_end)?->toDateString(),
                'provider_checkout_id' => $payment->provider_checkout_id,
                'provider_subscription_id' => $payment->provider_subscription_id,
                'provider_payment_id' => $payment->provider_payment_id,
            ])
            ->values();

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
            'aircraft_payments' => $aircraftPayments,
        ]);
    }

    public function crew(Request $request)
    {
        $providerId = $this->resolvedProviderIdOrAbort($request, 404);
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 50);

        $activeStatuses = ['confirmada', 'confirmed', 'en_preparacion', 'preparacion', 'preparing', 'lista', 'ready', 'en_vuelo', 'in_progress', 'in_flight', 'incidencia'];
        $assignedCrewIds = Operacion::query()
            ->where('provider_id', $providerId)
            ->whereIn('status', $activeStatuses)
            ->whereNotNull('sobrecargo_user_id')
            ->pluck('sobrecargo_user_id')
            ->all();

        $crew = Usuario::query()
            ->select([
                'id',
                'provider_id',
                'name',
                'email',
                'phone',
                'status',
                'role',
                'operational_role',
                'created_at',
                'updated_at',
            ])
            ->with(['profile:id,user_id,city,business_type'])
            ->where('provider_id', $providerId)
            ->where(function ($query) {
                $query->whereHas('roles', fn ($roles) => $roles->whereIn('code', ['sobrecargo']))
                    ->orWhere('operational_role', 'sobrecargo');
            })
            ->latest()
            ->paginate($perPage);

        $collection = $crew->getCollection()->map(function (Usuario $user) use ($assignedCrewIds) {
                $hasActiveOperation = in_array($user->id, $assignedCrewIds, true);

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->operational_role ?: $user->role ?: 'sobrecargo',
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

        return $this->ok([
            'crew' => $collection,
            'meta' => [
                'current_page' => $crew->currentPage(),
                'per_page' => $crew->perPage(),
                'total' => $crew->total(),
                'last_page' => $crew->lastPage(),
            ],
        ]);
    }

    public function commissions(Request $request)
    {
        $providerId = $this->resolvedProviderIdOrAbort($request, 404);

        return $this->ok(['commissions' => Comision::where('provider_id', $providerId)->paginate(20)]);
    }

    public function operations(Request $request)
    {
        $providerId = $this->resolvedProviderIdOrAbort($request, 404);

        $operations = Operacion::with(['solicitudVuelo', 'aeronave', 'sobrecargo', 'timeline'])
            ->where('provider_id', $providerId)
            ->latest()
            ->paginate(20)
            ->through(fn (Operacion $operation) => $this->formatOperationPayload($operation));

        return $this->ok(['operations' => $operations]);
    }

    public function updateOperation(Request $request, Operacion $operation)
    {
        $providerId = $this->resolvedProviderIdOrAbort($request, 403);
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
        $providerId = $this->resolvedProviderIdOrAbort($request, 404);

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
        $currentWorkflowStatus = $flightRequest->workflow_status;
        $existingOperation = Operacion::query()
            ->where('flight_request_id', $flightRequest->id)
            ->where('provider_id', $providerId)
            ->latest('id')
            ->first();
        $operationHasAssignedCrew = (bool) $existingOperation?->sobrecargo_user_id;
        $workflowStatus = $this->normalizeReleaseWorkflowStatus(
            $data['workflow_status'] ?? $data['status'] ?? $data['state'] ?? null,
            $releaseStatus,
            $currentWorkflowStatus,
            $operationHasAssignedCrew,
        );

        if (
            Str::lower(trim((string) $workflowStatus)) === 'tracking_live'
            && ! $operationHasAssignedCrew
        ) {
            throw ValidationException::withMessages([
                'workflow_status' => 'Asigna primero la sobrecargo antes de mover el vuelo a tracking en vivo.',
            ]);
        }

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
                    'operational_ready' => (bool) ($data['operational_ready'] ?? ($releaseStatus === 'operational_ready')),
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

            $releaseAircraftId = (int) ($release['aircraft_id'] ?? 0);
            if ($reservation && $releaseAircraftId > 0) {
                $availabilityService = app(AircraftAvailabilityService::class);
                [$requestedStart, $requestedEnd] = $availabilityService->resolveWindowFromPayload([
                    'departure_datetime' => $flightRequest->departure_datetime,
                    'return_datetime' => $flightRequest->return_datetime,
                    'legs' => $flightRequest->legs()->get(['departure_datetime', 'arrival_datetime'])->map(
                        fn ($leg) => [
                            'departure_datetime' => $leg->departure_datetime,
                            'arrival_datetime' => $leg->arrival_datetime,
                        ]
                    )->values()->all(),
                ]);

                $availabilityService->ensureAircraftAvailable(
                    $releaseAircraftId,
                    $requestedStart,
                    $requestedEnd,
                    (int) $reservation->id,
                );

                $reservation->update([
                    'provider_id' => $providerId,
                    'aircraft_id' => $releaseAircraftId,
                ]);

                $resolvedPaymentStatus = Str::lower(trim((string) ($flightRequest->payment_status ?: $reservation->latestPayment?->status ?: '')));
                if ($resolvedPaymentStatus === 'paid') {
                    $availabilityService->blockAircraftForPaidReservation($reservation->fresh(['flightRequest.legs', 'legs']));
                } else {
                    $availabilityService->releaseReservationBlock(
                        $reservation->fresh(['flightRequest', 'latestPayment']),
                        'Bloqueo anterior liberado por cambio operativo de aeronave.'
                    );
                }
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
        $providerId = $this->resolvedProviderIdOrAbort($request, 404);

        $incidents = LineaTiempoOperacion::with(['operacion.solicitudVuelo', 'operacion.aeronave', 'operacion.sobrecargo', 'operacion.proveedor'])
            ->whereIn('status', ['incidencia', 'cerrada'])
            ->whereHas('operacion', fn ($query) => $query->where('provider_id', $providerId))
            ->latest()
            ->paginate(20)
            ->through(fn (LineaTiempoOperacion $incident) => $this->formatIncidentPayload($incident));

        return $this->ok(['incidents' => $incidents]);
    }

    public function storeIncident(Request $request)
    {
        $providerId = $this->resolvedProviderIdOrAbort($request, 404);

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
            'incident' => $this->formatIncidentPayload($timeline->fresh(['operacion.solicitudVuelo', 'operacion.aeronave', 'operacion.sobrecargo', 'operacion.proveedor'])),
        ], 201);
    }

    public function updateIncident(Request $request, LineaTiempoOperacion $timeline)
    {
        $providerId = $this->resolvedProviderIdOrAbort($request, 403);
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
            'incident' => $this->formatIncidentPayload($timeline->fresh(['operacion.solicitudVuelo', 'operacion.aeronave', 'operacion.sobrecargo', 'operacion.proveedor'])),
        ]);
    }

    public function history(Request $request)
    {
        $providerId = $this->resolvedProviderIdOrAbort($request, 404);

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
            ? $source->user()->loadMissing('provider.companyDocuments', 'provider.aircraft', 'profile')
            : $source->loadMissing('provider.companyDocuments', 'provider.aircraft', 'profile');
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
                ->map(fn (DocumentoEmpresa $document) => $this->formatCompanyDocumentPayload($document))
                ->all()
            : $legacyDocuments;

        $adminValidationStatus = $provider ? $this->resolveAdminValidationStatus($provider, $taxData) : 'draft';
        $operatorStatus = $provider ? $this->resolveOperatorStatus($provider, $adminValidationStatus) : 'incomplete';
        $satValidationStatus = $provider ? $this->resolveSatValidationStatus($provider, $taxData) : (filled($taxData['rfc'] ?? null) ? 'approved' : 'pending');
        $checklist = $provider ? $this->companyValidationChecklist($provider, $documents, $taxData) : [];
        $accessEnabled = $provider ? $this->resolveProviderAccessEnabled($provider, $adminValidationStatus) : false;

        return [
            'id' => $provider?->id,
            'legal_name' => $provider?->legal_name ?? $taxData['legal_name'] ?? $provider?->company_name,
            'company_name' => $provider?->company_name,
            'commercial_name' => $provider?->commercial_name,
            'trade_name' => $provider?->commercial_name,
            'representative_name' => $provider?->representative_name ?? $taxData['legal_representative'] ?? null,
            'representative_phone' => $provider?->representative_phone,
            'birth_date' => optional($provider?->birth_date ?? $profile?->birth_date)?->toDateString(),
            'curp' => $provider?->curp ?? $profile?->ine_curp,
            'nationality' => $provider?->nationality ?? $profile?->nationality,
            'document_type' => $provider?->document_type ?? $profile?->document_type,
            'document_number' => $provider?->document_number ?? $profile?->document_number,
            'document_expiration' => optional($provider?->document_expiration ?? $profile?->document_expiration)?->toDateString(),
            'jet_a_price' => (float) ($provider?->jet_a_price ?? 0),
            'margin_percent' => (float) ($provider?->margin_percent ?? 0),
            'fixed_fee' => (float) ($provider?->fixed_fee ?? 0),
            'rfc' => $provider?->rfc ?? $taxData['rfc'] ?? null,
            'company_phone' => $provider?->company_phone ?? $user->phone,
            'company_email' => $provider?->company_email ?? $user->email,
            'phone' => $provider?->company_phone ?? $user->phone,
            'email' => $provider?->company_email ?? $user->email,
            'base_airport' => $provider?->base_airport ?? $profile?->base_airport,
            'address' => $profile?->address,
            'legal_representative' => $provider?->representative_name ?? $taxData['legal_representative'] ?? null,
            'status' => $provider?->status ?? $operatorStatus,
            'approval_status' => $provider?->approval_status ?? 'pending',
            'validation_status' => $provider?->approval_status ?? 'pending',
            'review_status' => $adminValidationStatus,
            'admin_validation_status' => $adminValidationStatus,
            'operator_status' => $operatorStatus,
            'sat_validation_status' => $satValidationStatus,
            'admin_notes' => $provider?->admin_notes ?? $provider?->admin_validation_notes ?? $provider?->notes,
            'admin_validation_notes' => $provider?->admin_validation_notes ?? $provider?->admin_notes ?? $provider?->notes,
            'changes_notes' => $provider?->changes_notes,
            'rejection_reason' => $provider?->rejection_reason,
            'validated_by' => $provider?->validated_by ?? $provider?->admin_validated_by,
            'validated_at' => optional($provider?->validated_at ?? $provider?->admin_validated_at)?->toISOString(),
            'rejected_by' => $provider?->rejected_by ?? $provider?->admin_rejected_by,
            'rejected_at' => optional($provider?->rejected_at ?? $provider?->admin_rejected_at)?->toISOString(),
            'changes_requested_by' => $provider?->changes_requested_by ?? $provider?->admin_changes_requested_by,
            'changes_requested_at' => optional($provider?->changes_requested_at ?? $provider?->admin_changes_requested_at)?->toISOString(),
            'admin_review_submitted_at' => optional($provider?->admin_review_submitted_at)?->toISOString(),
            'admin_validated_at' => optional($provider?->admin_validated_at)?->toISOString(),
            'admin_rejected_at' => optional($provider?->admin_rejected_at)?->toISOString(),
            'admin_changes_requested_at' => optional($provider?->admin_changes_requested_at)?->toISOString(),
            'access_enabled' => $accessEnabled,
            'provider_validation_requirements' => $provider ? $this->providerRequirementReviews($provider) : [],
            'validation_requirements' => $checklist,
            'can_validate' => collect($checklist)->every(fn (array $item) => (bool) ($item['complete'] ?? false)),
            'documents' => $documents,
            'company_documents' => $documents,
            'legal_documents' => $documents,
            'documents_count' => count($documents),
        ];
    }

    private function formatCompanyDocumentPayload(DocumentoEmpresa $document): array
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

    private function createCompanyDocumentRecord(
        Proveedor $provider,
        UploadedFile $file,
        array $data = [],
        ?DocumentoEmpresa $existingDocument = null
    ): DocumentoEmpresa
    {
        $missingVariables = $this->missingS3UploadConfigurationVariables();

        abort_if(
            $missingVariables !== [],
            500,
            'Falta configuracion de AWS S3 en el servidor. Variables faltantes o vacias: '.implode(', ', $missingVariables).'.'
        );

        $safeBaseName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), '_') ?: 'documento_empresa';
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $path = $file->storeAs(
            'provider/'.$provider->id.'/company-documents',
            $safeBaseName.'_'.Str::lower(Str::random(8)).'.'.$extension,
            's3'
        );

        abort_if(! $path, 500, 'No se pudo subir el documento de empresa al almacenamiento S3.');

        $documentUrl = Storage::disk('s3')->url($path);

        if ($existingDocument) {
            $this->deleteCompanyDocumentStoredFile($existingDocument);
        }

        return $this->upsertCompanyDocumentRecord($provider, [
            ...$this->normalizeCompanyDocumentMetadata($data),
            'document_name' => $data['document_name'] ?? $file->getClientOriginalName(),
            'original_name' => $data['original_name'] ?? $file->getClientOriginalName(),
            'file_name' => basename($path),
            'file_url' => $documentUrl,
            'document_url' => $documentUrl,
            'storage_disk' => 's3',
            'storage_path' => $path,
            'mime_type' => $file->getClientMimeType() ?: $file->getMimeType(),
            'file_size_bytes' => $file->getSize(),
            'status' => $data['status'] ?? 'pendiente',
            'notes' => $data['notes'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
        ], $existingDocument);
    }

    private function extractCompanyDocumentUploadFromRequest(Request $request): ?UploadedFile
    {
        foreach (['file', 'document', 'legal_document'] as $key) {
            if ($request->hasFile($key)) {
                $candidate = $request->file($key);
                if ($candidate instanceof UploadedFile) {
                    return $candidate;
                }
            }
        }

        $documents = $request->file('documents', []);
        foreach ((array) $documents as $candidate) {
            if ($candidate instanceof UploadedFile) {
                return $candidate;
            }
            if (is_array($candidate)) {
                foreach ($candidate as $nestedCandidate) {
                    if ($nestedCandidate instanceof UploadedFile) {
                        return $nestedCandidate;
                    }
                }
            }
        }

        return null;
    }

    private function filterCompanyDocumentPayload(array $payload): array
    {
        return array_intersect_key($payload, array_flip($this->companyDocumentAvailableColumns()));
    }

    private function upsertCompanyDocumentRecord(
        Proveedor $provider,
        array $payload,
        ?DocumentoEmpresa $existingDocument = null
    ): DocumentoEmpresa {
        $filteredPayload = $this->filterCompanyDocumentPayload($payload);

        if ($existingDocument) {
            $existingDocument->update($filteredPayload);

            return $existingDocument->fresh();
        }

        return $provider->companyDocuments()->create($filteredPayload);
    }

    private function findCompanyDocumentTargetForWrite(Proveedor $provider, array $data): ?DocumentoEmpresa
    {
        $replaceDocumentId = (int) ($data['replace_document_id'] ?? 0);
        if ($replaceDocumentId > 0) {
            return $provider->companyDocuments()->whereKey($replaceDocumentId)->first();
        }

        $normalizedMetadata = $this->normalizeCompanyDocumentMetadata($data);
        $documentSlot = $normalizedMetadata['document_slot'] ?? null;
        if (! filled($documentSlot)) {
            return null;
        }

        return $provider->companyDocuments()
            ->where(function ($query) use ($documentSlot, $normalizedMetadata) {
                $query->where('document_slot', $documentSlot);

                if (filled($normalizedMetadata['document_type'] ?? null)) {
                    $query->orWhere('document_type', $normalizedMetadata['document_type']);
                }

                if (filled($normalizedMetadata['document_category'] ?? null)) {
                    $query->orWhere('document_category', $normalizedMetadata['document_category']);
                }
            })
            ->latest('id')
            ->first();
    }

    private function deleteCompanyDocumentStoredFile(?DocumentoEmpresa $document): void
    {
        if (! $document) {
            return;
        }

        $disk = trim((string) ($document->storage_disk ?: ''));
        $path = trim((string) ($document->storage_path ?: ''));
        if ($disk === '' || $path === '' || config("filesystems.disks.{$disk}") === null) {
            return;
        }

        $storage = Storage::disk($disk);
        if ($storage->exists($path)) {
            $storage->delete($path);
        }
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

    private function normalizeCompanyDocumentMetadata(array $data): array
    {
        $definition = $this->resolveCompanyDocumentDefinitionFromCandidates([
            $data['document_slot'] ?? null,
            $data['document_type'] ?? null,
            $data['document_category'] ?? null,
            $data['document_name'] ?? null,
            $data['original_name'] ?? null,
        ]);

        $documentType = trim((string) ($data['document_type'] ?? ''));
        $documentCategory = trim((string) ($data['document_category'] ?? ''));
        $documentSlot = trim((string) ($data['document_slot'] ?? ''));
        $documentSection = trim((string) ($data['document_section'] ?? ''));

        return [
            'document_type' => $documentType !== '' ? $documentType : ($definition['id'] ?? null),
            'document_category' => $documentCategory !== '' ? $documentCategory : ($definition['id'] ?? null),
            'document_slot' => $documentSlot !== '' ? $documentSlot : ($definition['id'] ?? null),
            'document_section' => $documentSection !== '' ? $documentSection : ($definition['section_key'] ?? null),
        ];
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

    private function buildCompanyDocumentAuditDescription(array $metadata, DocumentoEmpresa $document): string
    {
        $label = $metadata['definition_label'] ?? $document->document_name ?? $document->original_name ?? 'Documento de empresa';
        $section = $metadata['section_label'] ?? 'Documentacion legal del operador';
        $slot = $metadata['document_slot'] ?? '';

        return trim(sprintf('%s · %s%s', $label, $section, $slot !== '' ? " · document_slot: {$slot}" : ''));
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

    private function normalizeProviderWorkflowStatus(string $approvalStatus): string
    {
        return $approvalStatus === 'pending' ? 'pending_validation' : $approvalStatus;
    }

    private function resolveAdminValidationStatus(Proveedor $provider, array $taxData = []): string
    {
        $normalized = strtolower(trim((string) ($provider->admin_validation_status ?: ($taxData['admin_validation_status'] ?? ''))));
        $approvalStatus = strtolower(trim((string) $provider->approval_status));

        if ($normalized !== '') {
            if ($normalized === 'expediente_incompleto') {
                $normalized = 'draft';
            }

            if (in_array($normalized, ['draft', 'pending_validation', 'pending_review', 'incomplete'], true)) {
                return match ($approvalStatus) {
                    'approved' => 'approved',
                    'rejected' => 'rejected',
                    'suspended' => 'changes_required',
                    default => $normalized,
                };
            }

            return $normalized;
        }

        return match ($approvalStatus) {
            'approved' => 'approved',
            'rejected' => 'rejected',
            'suspended' => 'changes_required',
            default => ($provider->admin_review_submitted_at ? 'pending_review' : 'draft'),
        };
    }

    private function resolveOperatorStatus(Proveedor $provider, ?string $adminValidationStatus = null): string
    {
        $normalized = strtolower(trim((string) ($provider->operator_status ?: '')));
        if ($normalized !== '') {
            return $normalized;
        }

        return match ($adminValidationStatus ?: $this->resolveAdminValidationStatus($provider)) {
            'approved' => 'validated',
            'rejected' => 'rejected',
            'changes_required' => 'changes_required',
            'pending_review', 'pending_validation' => 'pending_review',
            default => 'incomplete',
        };
    }

    private function resolveProviderAccessEnabled(Proveedor $provider, ?string $adminValidationStatus = null): bool
    {
        $approvalStatus = strtolower(trim((string) $provider->approval_status));
        $resolvedAdminStatus = $adminValidationStatus ?: $this->resolveAdminValidationStatus($provider);

        if ($approvalStatus === 'approved' && $resolvedAdminStatus === 'approved') {
            return true;
        }

        if ($provider->access_enabled !== null) {
            return (bool) $provider->access_enabled;
        }

        return false;
    }

    private function resolveSatValidationStatus(Proveedor $provider, array $taxData = []): string
    {
        $normalized = strtolower(trim((string) ($provider->sat_validation_status ?: ($taxData['sat_validation_status'] ?? ''))));
        if ($normalized !== '') {
            return $normalized;
        }

        return filled($provider->rfc ?: ($taxData['rfc'] ?? null)) ? 'approved' : 'pending';
    }

    private function providerSatDocuments(array $documents): array
    {
        return array_values(array_filter($documents, fn (array $document) => ($document['definition_key'] ?? '') === 'sat_certificate'));
    }

    private function providerLegalDocuments(array $documents): array
    {
        return array_values(array_filter($documents, function (array $document) {
            $definitionKey = $document['definition_key'] ?? '';
            $sectionKey = $document['section_key'] ?? '';

            return in_array($definitionKey, self::LEGAL_REQUIREMENT_DOCUMENT_KEYS, true)
                || ($sectionKey === 'legal' && $definitionKey !== 'sat_certificate');
        }));
    }

    private function documentsAreApproved(array $documents): bool
    {
        return $documents !== []
            && collect($documents)->every(fn (array $document) => in_array(
                strtolower(trim((string) ($document['status'] ?? $document['state'] ?? ''))),
                self::APPROVED_DOCUMENT_STATUSES,
                true,
            ));
    }

    private function pendingDocumentLabels(array $documents): array
    {
        return collect($documents)
            ->reject(fn (array $document) => in_array(
                strtolower(trim((string) ($document['status'] ?? $document['state'] ?? ''))),
                self::APPROVED_DOCUMENT_STATUSES,
                true,
            ))
            ->map(fn (array $document) => $document['definition_label'] ?? $document['name'] ?? $document['document_name'] ?? 'Documento legal')
            ->filter()
            ->values()
            ->all();
    }

    private function providerHasApprovedSatValidation(Proveedor $provider, array $documents, array $taxData = []): bool
    {
        $satDocuments = $this->providerSatDocuments($documents);
        if ($satDocuments !== []) {
            return $this->documentsAreApproved($satDocuments);
        }

        return in_array($this->resolveSatValidationStatus($provider, $taxData), ['approved', 'aprobado', 'validated', 'validado'], true);
    }

    private function providerHasApprovedAircraft(Proveedor $provider): bool
    {
        return $provider->aircraft->contains(fn ($aircraft) => in_array(
            strtolower(trim((string) $aircraft->status)),
            self::APPROVED_AIRCRAFT_STATUSES,
            true,
        ));
    }

    private function companyValidationChecklist(Proveedor $provider, array $documents, array $taxData = []): array
    {
        $companyIdentityComplete = filled($provider->company_name) && filled($provider->commercial_name) && filled($provider->legal_name);
        $legalDocuments = $this->providerLegalDocuments($documents);
        $documentsApproved = $this->documentsAreApproved($legalDocuments);
        $pendingLegalDocumentLabels = $this->pendingDocumentLabels($legalDocuments);
        $activeAircraft = $this->providerHasApprovedAircraft($provider);
        $satApproved = $this->providerHasApprovedSatValidation($provider, $documents, $taxData);
        $contactComplete = filled($provider->company_phone) && filled($provider->company_email);
        $representativeComplete = filled($provider->representative_name) && filled($provider->representative_phone);
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
                'complete' => filled($provider->rfc ?: ($taxData['rfc'] ?? null)),
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
                'key' => 'aircraft_active',
                'label' => 'Aeronave activa o aprobada',
                'complete' => $activeAircraft,
                'message' => 'Se requiere al menos una aeronave activa o aprobada.',
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
        ]);
    }

    private function providerRequirementReviews(Proveedor $provider): array
    {
        return is_array($provider->provider_validation_requirements)
            ? $provider->provider_validation_requirements
            : [];
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
        $files = $this->resolveCrewIncidentFilesForTimeline($incident);

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
            'operation_id' => $operation?->id,
            'request_id' => $flightRequest?->id,
            'route' => trim(($flightRequest?->origin ?? 'N/D').' - '.($flightRequest?->destination ?? 'N/D')),
            'source' => $this->extractTaggedValue((string) $incident->description, 'Origen') ?: 'Proveedor',
            'crew_name' => $this->extractTaggedValue((string) $incident->description, 'Sobrecargo') ?: $operation?->sobrecargo?->name,
            'provider_id' => $operation?->provider_id,
            'provider_name' => $this->extractTaggedValue((string) $incident->description, 'Empresa')
                ?: $operation?->proveedor?->commercial_name
                ?: $operation?->proveedor?->company_name,
            'responsible' => $this->extractTaggedValue((string) $incident->description, 'Origen') === 'Sobrecargo'
                ? ($this->extractTaggedValue((string) $incident->description, 'Sobrecargo') ?: 'Sobrecargo')
                : 'Proveedor',
            'files' => $files,
            'created_at' => optional($incident->created_at)?->format('Y-m-d H:i'),
        ];
    }

    private function resolveCrewIncidentFilesForTimeline(LineaTiempoOperacion $incident): array
    {
        $crewIncidentId = DB::table('crew_operation_incidents')
            ->where('provider_timeline_id', $incident->id)
            ->value('id');

        if (! $crewIncidentId) {
            return [];
        }

        return DB::table('crew_operation_incident_files')
            ->where('incident_id', $crewIncidentId)
            ->orderBy('id')
            ->get()
            ->map(fn ($file) => [
                'id' => $file->id,
                'storage_disk' => $file->storage_disk ?: 'public',
                'file_path' => $file->file_path,
                'file_url' => $this->resolveCrewIncidentFileUrl($file),
                'file_type' => $file->file_type,
                'original_name' => $file->original_name,
                'created_at' => $file->created_at,
                'updated_at' => $file->updated_at,
            ])
            ->values()
            ->all();
    }

    private function resolveCrewIncidentFileUrl(object $file): ?string
    {
        $path = trim((string) ($file->file_path ?? ''));
        if ($path === '') {
            return null;
        }

        $disk = trim((string) ($file->storage_disk ?? 'public')) ?: 'public';

        if ($disk === 's3') {
            if ($this->canGenerateTemporaryS3Urls()) {
                try {
                    return Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(30));
                } catch (\Throwable) {
                    return null;
                }
            }

            return null;
        }

        try {
            return Storage::disk($disk)->url($path);
        } catch (\Throwable) {
            return null;
        }
    }

    private function canGenerateTemporaryS3Urls(): bool
    {
        $key = trim((string) config('filesystems.disks.s3.key', ''));
        $secret = trim((string) config('filesystems.disks.s3.secret', ''));
        $bucket = trim((string) config('filesystems.disks.s3.bucket', ''));
        $region = trim((string) config('filesystems.disks.s3.region', ''));

        return $key !== '' && $secret !== '' && $bucket !== '' && $region !== '';
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

    private function normalizeReleaseWorkflowStatus(
        ?string $workflowStatus,
        string $releaseStatus,
        ?string $currentWorkflowStatus = null,
        bool $operationHasAssignedCrew = false
    ): ?string
    {
        $normalizedWorkflowStatus = Str::lower(trim((string) $workflowStatus));
        if ($normalizedWorkflowStatus !== '') {
            return $normalizedWorkflowStatus;
        }

        $normalizedCurrentWorkflowStatus = Str::lower(trim((string) $currentWorkflowStatus));

        return match ($releaseStatus) {
            'operational_ready' => $operationHasAssignedCrew
                && in_array($normalizedCurrentWorkflowStatus, ['flight_confirmed', 'vuelo confirmado', 'tracking_live', 'tracking en vivo'], true)
                    ? 'tracking_live'
                    : 'flight_confirmed',
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
}
