<?php

namespace App\Servicios\Providers;

use App\Modelos\DocumentoEmpresa;
use App\Modelos\Proveedor;
use App\Modelos\Usuario;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminProviderApprovalService
{
    private const APPROVED_DOCUMENT_STATUSES = ['approved', 'aprobado', 'aprobada', 'vigente', 'validado'];

    private const LEGAL_REQUIREMENT_DOCUMENT_KEYS = [
        'articles_of_incorporation',
        'legal_representative_power',
        'legal_representative_id',
        'tax_address_proof',
        'operational_permit',
    ];

    public function approve(Proveedor $provider, ?Usuario $admin, array $payload = [], ?callable $afterDecision = null): array
    {
        $this->assertAuthenticatedAdmin($admin);
        $adminNotes = $this->resolveDecisionNotes($payload, ['admin_notes', 'notes']);

        return DB::transaction(function () use ($provider, $admin, $adminNotes, $afterDecision) {
            $lockedProvider = $this->lockProvider($provider->id);
            $this->assertManageableProvider($lockedProvider);

            $readiness = $this->buildApprovalReadiness($lockedProvider);
            $missingItems = $this->missingApprovalItems($readiness);

            if ($missingItems !== []) {
                throw ValidationException::withMessages([
                    'validation' => [
                        'No se puede aprobar todavia. Faltan requisitos obligatorios: '.implode(', ', array_column($missingItems, 'label')).'.',
                    ],
                    'missing_items' => array_map(
                        fn (array $item) => $item['message'] ?: $item['label'],
                        $missingItems,
                    ),
                ]);
            }

            $previousState = $this->capturePreviousState($lockedProvider);
            $alreadyApproved = $this->providerAlreadyApproved($lockedProvider);

            if (! $alreadyApproved) {
                $lockedProvider->forceFill([
                    'approval_status' => 'approved',
                    'admin_validation_status' => 'approved',
                    'operator_status' => 'active',
                    'status' => 'approved',
                    'access_enabled' => true,
                    'sat_validation_status' => $payload['sat_validation_status'] ?? ($lockedProvider->sat_validation_status ?: 'approved'),
                    'admin_notes' => $adminNotes,
                    'admin_validation_notes' => $adminNotes,
                    'validated_by' => $admin?->id,
                    'validated_at' => now(),
                    'admin_validated_by' => $admin?->id,
                    'admin_validated_at' => now(),
                    'rejected_by' => null,
                    'rejected_at' => null,
                    'rejection_reason' => null,
                    'changes_requested_by' => null,
                    'changes_requested_at' => null,
                    'changes_notes' => null,
                    'admin_rejected_by' => null,
                    'admin_rejected_at' => null,
                    'admin_changes_requested_by' => null,
                    'admin_changes_requested_at' => null,
                ])->save();
            }

            $freshProvider = $lockedProvider->fresh(['user.profile', 'aircraft', 'companyDocuments', 'users']);
            $context = [
                'action' => 'approve',
                'noop' => $alreadyApproved,
                'admin_notes' => $adminNotes,
                'previous_state' => $previousState,
                'readiness' => $readiness,
            ];

            $this->afterDecisionPersisted('approve', $freshProvider, $admin, $context);
            if ($afterDecision) {
                $afterDecision($freshProvider, $context);
            }

            return [
                'provider' => $freshProvider,
                ...$context,
            ];
        });
    }

    public function requestChanges(Proveedor $provider, ?Usuario $admin, array $payload = [], ?callable $afterDecision = null): array
    {
        $this->assertAuthenticatedAdmin($admin);
        $changesNotes = $this->requireDecisionNotes($payload, ['changes_notes', 'notes']);
        $adminNotes = $this->resolveDecisionNotes($payload, ['admin_notes', 'notes']) ?: $changesNotes;

        return DB::transaction(function () use ($provider, $admin, $changesNotes, $adminNotes, $afterDecision) {
            $lockedProvider = $this->lockProvider($provider->id);
            $this->assertManageableProvider($lockedProvider);

            $previousState = $this->capturePreviousState($lockedProvider);

            $lockedProvider->forceFill([
                'approval_status' => 'changes_requested',
                'admin_validation_status' => 'changes_requested',
                'operator_status' => 'incomplete',
                'status' => 'changes_requested',
                'access_enabled' => false,
                'changes_requested_by' => $admin?->id,
                'changes_requested_at' => now(),
                'changes_notes' => $changesNotes,
                'admin_notes' => $adminNotes,
                'admin_validation_notes' => $adminNotes,
                'validated_by' => null,
                'validated_at' => null,
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
                'admin_changes_requested_by' => $admin?->id,
                'admin_changes_requested_at' => now(),
                'admin_validated_by' => null,
                'admin_validated_at' => null,
                'admin_rejected_by' => null,
                'admin_rejected_at' => null,
            ])->save();

            $freshProvider = $lockedProvider->fresh(['user.profile', 'aircraft', 'companyDocuments', 'users']);
            $context = [
                'action' => 'request_changes',
                'noop' => false,
                'admin_notes' => $adminNotes,
                'changes_notes' => $changesNotes,
                'previous_state' => $previousState,
            ];

            $this->afterDecisionPersisted('request_changes', $freshProvider, $admin, $context);
            if ($afterDecision) {
                $afterDecision($freshProvider, $context);
            }

            return [
                'provider' => $freshProvider,
                ...$context,
            ];
        });
    }

    public function reject(Proveedor $provider, ?Usuario $admin, array $payload = [], ?callable $afterDecision = null): array
    {
        $this->assertAuthenticatedAdmin($admin);
        $rejectionReason = $this->requireDecisionNotes($payload, ['rejection_reason', 'notes']);
        $adminNotes = $this->resolveDecisionNotes($payload, ['admin_notes', 'notes']) ?: $rejectionReason;

        return DB::transaction(function () use ($provider, $admin, $rejectionReason, $adminNotes, $afterDecision) {
            $lockedProvider = $this->lockProvider($provider->id);
            $this->assertManageableProvider($lockedProvider);

            $previousState = $this->capturePreviousState($lockedProvider);

            $lockedProvider->forceFill([
                'approval_status' => 'rejected',
                'admin_validation_status' => 'rejected',
                'operator_status' => 'rejected',
                'status' => 'rejected',
                'access_enabled' => false,
                'rejected_by' => $admin?->id,
                'rejected_at' => now(),
                'rejection_reason' => $rejectionReason,
                'admin_notes' => $adminNotes,
                'admin_validation_notes' => $adminNotes,
                'validated_by' => null,
                'validated_at' => null,
                'changes_requested_by' => null,
                'changes_requested_at' => null,
                'changes_notes' => null,
                'admin_rejected_by' => $admin?->id,
                'admin_rejected_at' => now(),
                'admin_validated_by' => null,
                'admin_validated_at' => null,
                'admin_changes_requested_by' => null,
                'admin_changes_requested_at' => null,
            ])->save();

            $freshProvider = $lockedProvider->fresh(['user.profile', 'aircraft', 'companyDocuments', 'users']);
            $context = [
                'action' => 'reject',
                'noop' => false,
                'admin_notes' => $adminNotes,
                'rejection_reason' => $rejectionReason,
                'previous_state' => $previousState,
            ];

            $this->afterDecisionPersisted('reject', $freshProvider, $admin, $context);
            if ($afterDecision) {
                $afterDecision($freshProvider, $context);
            }

            return [
                'provider' => $freshProvider,
                ...$context,
            ];
        });
    }

    public function buildApprovalReadiness(Proveedor $provider): array
    {
        $provider->loadMissing(['user.profile', 'companyDocuments']);

        $documents = $provider->companyDocuments ?? collect();
        $legalDocuments = $this->providerLegalDocuments($documents);
        $pendingLegalDocumentLabels = $this->pendingDocumentLabels($legalDocuments);

        return [
            [
                'key' => 'provider_exists',
                'label' => 'Proveedor existente',
                'complete' => (bool) $provider->exists,
                'message' => 'No encontramos el proveedor solicitado.',
            ],
            [
                'key' => 'provider_user_active',
                'label' => 'Usuario proveedor activo',
                'complete' => strtolower(trim((string) ($provider->user?->status ?: ''))) === 'active',
                'message' => 'El usuario del proveedor no esta activo.',
            ],
            [
                'key' => 'company_identity',
                'label' => 'Datos generales completos',
                'complete' => filled($provider->company_name) && filled($provider->commercial_name) && filled($provider->legal_name),
                'message' => 'Faltan datos generales de la empresa.',
            ],
            [
                'key' => 'legal_representative_complete',
                'label' => 'Representante legal completo',
                'complete' => filled($provider->representative_name) && filled($provider->representative_phone),
                'message' => 'Faltan datos del representante legal.',
            ],
            [
                'key' => 'legal_documents_approved',
                'label' => 'Documentos requeridos aprobados',
                'complete' => $this->documentsAreApproved($legalDocuments),
                'message' => $pendingLegalDocumentLabels !== []
                    ? 'Siguen pendientes: '.implode(', ', $pendingLegalDocumentLabels).'.'
                    : 'La documentacion legal obligatoria sigue incompleta o pendiente de aprobacion.',
            ],
            [
                'key' => 'rfc_valid',
                'label' => 'Informacion fiscal completa',
                'complete' => filled($provider->rfc),
                'message' => 'Falta RFC valido del proveedor.',
            ],
            [
                'key' => 'sat_validation',
                'label' => 'Validacion fiscal aprobada',
                'complete' => $this->providerHasApprovedSatValidation($provider, $documents),
                'message' => 'La validacion SAT sigue pendiente.',
            ],
            [
                'key' => 'contact_complete',
                'label' => 'Contacto completo',
                'complete' => filled($provider->company_phone) && filled($provider->company_email),
                'message' => 'Faltan datos de contacto de la empresa.',
            ],
            [
                'key' => 'base_operativa',
                'label' => 'Base operativa definida',
                'complete' => filled($provider->base_airport),
                'message' => 'Falta base operativa definida.',
            ],
            [
                'key' => 'review_submitted',
                'label' => 'Expediente enviado a revision',
                'complete' => (bool) $provider->admin_review_submitted_at,
                'message' => 'El proveedor aun no envia el expediente a revision administrativa.',
            ],
        ];
    }

    protected function afterDecisionPersisted(string $action, Proveedor $provider, ?Usuario $admin, array $context): void
    {
    }

    private function assertAuthenticatedAdmin(?Usuario $admin): void
    {
        if (! $admin || ! $admin->hasRole('admin')) {
            throw new AuthorizationException('Solo un administrador autenticado puede gestionar la aprobacion del proveedor.');
        }
    }

    private function assertManageableProvider(Proveedor $provider): void
    {
        if (! $provider->exists) {
            throw ValidationException::withMessages([
                'provider' => ['No encontramos el proveedor solicitado.'],
            ]);
        }

        if (! $provider->user) {
            throw ValidationException::withMessages([
                'provider' => ['El proveedor no tiene un usuario asociado.'],
            ]);
        }
    }

    private function lockProvider(int $providerId): Proveedor
    {
        return Proveedor::query()
            ->with(['user.profile', 'companyDocuments', 'aircraft', 'users'])
            ->lockForUpdate()
            ->findOrFail($providerId);
    }

    private function capturePreviousState(Proveedor $provider): array
    {
        return $provider->only([
            'approval_status',
            'admin_validation_status',
            'operator_status',
            'access_enabled',
            'validated_by',
            'validated_at',
            'rejected_by',
            'rejected_at',
            'rejection_reason',
            'changes_requested_by',
            'changes_requested_at',
            'changes_notes',
            'admin_notes',
            'admin_validation_notes',
        ]);
    }

    private function providerAlreadyApproved(Proveedor $provider): bool
    {
        return $this->normalizeStatus($provider->approval_status) === 'approved'
            && $this->normalizeStatus($provider->admin_validation_status) === 'approved'
            && (bool) $provider->access_enabled === true;
    }

    private function missingApprovalItems(array $readiness): array
    {
        return array_values(array_filter($readiness, fn (array $item) => ($item['complete'] ?? false) !== true));
    }

    private function resolveDecisionNotes(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function requireDecisionNotes(array $payload, array $keys): string
    {
        $value = $this->resolveDecisionNotes($payload, $keys);
        if ($value !== null) {
            return $value;
        }

        throw ValidationException::withMessages([
            'notes' => ['Debes capturar una observacion administrativa.'],
        ]);
    }

    private function providerLegalDocuments(iterable $documents): array
    {
        return collect($documents)
            ->filter(function ($document) {
                if (! $document instanceof DocumentoEmpresa) {
                    return false;
                }

                $definitionKey = $this->documentDefinitionKey($document);
                $sectionKey = $this->documentSectionKey($document);

                return in_array($definitionKey, self::LEGAL_REQUIREMENT_DOCUMENT_KEYS, true)
                    || ($sectionKey === 'legal' && $definitionKey !== 'sat_certificate');
            })
            ->values()
            ->all();
    }

    private function providerSatDocuments(iterable $documents): array
    {
        return collect($documents)
            ->filter(fn ($document) => $document instanceof DocumentoEmpresa && $this->documentDefinitionKey($document) === 'sat_certificate')
            ->values()
            ->all();
    }

    private function documentsAreApproved(iterable $documents): bool
    {
        $collection = collect($documents)->filter(fn ($document) => $document instanceof DocumentoEmpresa)->values();

        return $collection->isNotEmpty()
            && $collection->every(
                fn (DocumentoEmpresa $document) => in_array(
                    $this->normalizeStatus($document->status),
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
                $this->normalizeStatus($document->status),
                self::APPROVED_DOCUMENT_STATUSES,
                true,
            ))
            ->map(fn (DocumentoEmpresa $document) => $this->documentLabel($document))
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

        return in_array($this->normalizeStatus($provider->sat_validation_status ?: (filled($provider->rfc) ? 'approved' : 'pending')), ['approved', 'aprobado', 'validated', 'validado'], true);
    }

    private function documentDefinitionKey(DocumentoEmpresa $document): string
    {
        return $this->normalizeStatus(
            data_get($document->metadata, 'definition_key')
                ?: data_get($document->metadata, 'document_definition.id')
                ?: $document->document_slot
                ?: $document->document_type
                ?: $document->type
        );
    }

    private function documentSectionKey(DocumentoEmpresa $document): string
    {
        return $this->normalizeStatus(
            data_get($document->metadata, 'section_key')
                ?: data_get($document->metadata, 'document_definition.section')
                ?: data_get($document->metadata, 'document_section')
        );
    }

    private function documentLabel(DocumentoEmpresa $document): string
    {
        return (string) (
            data_get($document->metadata, 'definition_label')
            ?: data_get($document->metadata, 'document_definition.label')
            ?: $document->document_name
            ?: $document->original_name
            ?: 'Documento legal'
        );
    }

    private function normalizeStatus(mixed $value): string
    {
        return Str::of((string) ($value ?? ''))
            ->trim()
            ->lower()
            ->ascii()
            ->replaceMatches('/[\s-]+/', '_')
            ->replaceMatches('/_+/', '_')
            ->value();
    }
}
