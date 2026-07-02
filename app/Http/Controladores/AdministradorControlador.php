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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class AdministradorControlador extends ControladorBase
{
    public function dashboard()
    {
        return $this->ok([
            'metrics' => [
                'users_registered' => Usuario::count(),
                'active_demos' => Demo::where('status', 'active')->where('expires_at', '>', now())->count(),
                'active_subscriptions' => Suscripcion::where('status', 'active')->where('expires_at', '>', now())->count(),
                'approved_providers' => Proveedor::where('approval_status', 'approved')->count(),
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
        return $this->ok([
            'providers' => Proveedor::with(['user', 'aircraft', 'companyDocuments'])
                ->paginate(25)
                ->through(fn (Proveedor $provider) => $this->serializeProvider($provider)),
        ]);
    }

    public function showProveedor(Proveedor $provider)
    {
        $provider->load(['user', 'aircraft', 'companyDocuments']);

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

    public function providerDocuments(Proveedor $provider)
    {
        $provider->loadMissing('companyDocuments');

        return $this->ok([
            'provider' => $this->serializeProvider($provider),
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

        $document->update($this->filterCompanyDocumentPayload([
            'status' => $nextStatus,
            'notes' => $nextNotes,
        ]));

        if ($nextStatus === 'approved') {
            $provider->update(['approval_status' => 'approved']);
        } elseif ($nextStatus === 'rejected') {
            $provider->update(['approval_status' => 'rejected']);
        } elseif (in_array($nextStatus, ['pending', 'pendiente', 'pending_validation'], true)) {
            $provider->update(['approval_status' => 'pending']);
        }

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

    public function approveProveedor(Proveedor $provider)
    {
        $provider->update(['approval_status' => 'approved']);

        return $this->ok(['provider' => $this->serializeProvider($provider->fresh(['user', 'aircraft', 'companyDocuments']))]);
    }

    public function rejectProveedor(Proveedor $provider)
    {
        $provider->update(['approval_status' => 'rejected']);

        return $this->ok(['provider' => $this->serializeProvider($provider->fresh(['user', 'aircraft', 'companyDocuments']))]);
    }

    public function suspendProveedor(Proveedor $provider)
    {
        $provider->update(['approval_status' => 'suspended']);

        return $this->ok(['provider' => $this->serializeProvider($provider->fresh(['user', 'aircraft', 'companyDocuments']))]);
    }

    public function aircraft()
    {
        return $this->ok(['aircraft' => Aeronave::with(['provider.user', 'availability'])->paginate(25)]);
    }

    public function showAeronave(Aeronave $aircraft)
    {
        return $this->ok(['aircraft' => $aircraft->load(['provider.user', 'images', 'documents', 'availability'])]);
    }

    public function blockAeronave(Aeronave $aircraft)
    {
        $aircraft->update(['status' => 'blocked']);

        return $this->ok(['aircraft' => $aircraft->fresh()]);
    }

    public function activateAeronave(Aeronave $aircraft)
    {
        $aircraft->update(['status' => 'active']);

        return $this->ok(['aircraft' => $aircraft->fresh()]);
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
        $provider->loadMissing(['user', 'aircraft', 'companyDocuments']);
        $documents = $provider->companyDocuments
            ->sortByDesc('id')
            ->values()
            ->map(fn (DocumentoEmpresa $document) => $this->serializeProviderDocument($document))
            ->all();

        return [
            ...$provider->attributesToArray(),
            'provider_id' => $provider->id,
            'documents' => $documents,
            'company_documents' => $documents,
            'legal_documents' => $documents,
            'documents_count' => count($documents),
            'legal_documents_count' => count($documents),
            'company_documents_count' => count($documents),
            'user' => $provider->user,
            'aircraft' => $provider->aircraft,
        ];
    }

    private function serializeProviderDocument(DocumentoEmpresa $document): array
    {
        $downloadUrl = sprintf('/api/v1/admin/proveedores/%d/documentos/%d/descargar', $document->provider_id, $document->id);

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
}
