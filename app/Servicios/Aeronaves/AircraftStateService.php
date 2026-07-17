<?php

namespace App\Servicios\Aeronaves;

use App\Modelos\Aeronave;
use App\Modelos\DocumentoAeronave;
use App\Servicios\Billing\ProviderAircraftSubscriptionService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AircraftStateService
{
    private array $evaluationCache = [];

    private const REQUIRED_DOCUMENTS = [
        [
            'key' => 'airworthiness',
            'label' => 'Certificado de aeronavegabilidad',
            'aliases' => [
                'airworthiness',
                'aeronavegabilidad',
                'airworthiness_certificate',
                'certificate_airworthiness',
                'certificado_aeronavegabilidad',
                'certificado_de_aeronavegabilidad',
            ],
        ],
        [
            'key' => 'registration',
            'label' => 'Matricula',
            'aliases' => [
                'registration',
                'registro',
                'matricula',
                'aircraft_registration',
                'registro_aeronave',
                'matricula_aeronave',
            ],
        ],
        [
            'key' => 'insurance',
            'label' => 'Seguro',
            'aliases' => [
                'insurance',
                'seguro',
                'insurance_policy',
                'policy',
                'poliza',
                'poliza_seguro',
                'aircraft_insurance',
            ],
        ],
        [
            'key' => 'maintenance',
            'label' => 'Mantenimiento',
            'aliases' => [
                'maintenance',
                'mantenimiento',
                'maintenance_sticker',
                'sticker_mantenimiento',
                'maintenance_sticker_document',
                'flight_logbook',
                'logbook',
                'bitacora_vuelo',
                'bitacora',
                'maintenance_program',
                'maintenance_log',
                'maintenance_records',
            ],
        ],
    ];

    public function __construct(
        private readonly ProviderAircraftSubscriptionService $providerAircraftSubscriptionService,
    ) {
    }

    public function evaluate(Aeronave|int $aircraft, ?array $billingSnapshot = null): array
    {
        $resolvedAircraft = $aircraft instanceof Aeronave
            ? $aircraft->loadMissing(['provider', 'documents', 'images', 'availability', 'baseAirport'])
            : Aeronave::query()->with(['provider', 'documents', 'images', 'availability', 'baseAirport'])->findOrFail($aircraft);

        $cacheKey = $this->evaluationCacheKey($resolvedAircraft, $billingSnapshot);

        if (array_key_exists($cacheKey, $this->evaluationCache)) {
            return $this->evaluationCache[$cacheKey];
        }

        $documents = $this->deduplicateAircraftDocuments($resolvedAircraft->documents ?? collect());
        $billing = $billingSnapshot ?? $this->providerAircraftSubscriptionService->buildAircraftBillingSnapshot(
            $resolvedAircraft->loadMissing(['provider', 'documents'])
        );
        $documentsState = $this->buildDocumentsState($resolvedAircraft, $documents);
        $operationState = $this->buildOperationState($resolvedAircraft);
        $pricingState = $this->buildPricingState($resolvedAircraft);
        $reviewState = $this->buildReviewState($resolvedAircraft, $documentsState);
        $activationState = $this->buildActivationState($resolvedAircraft, $billing, $reviewState, $documentsState, $operationState, $pricingState);
        $readyToQuote = (bool) ($activationState['can_activate'] ?? false) && ($activationState['commercial_status'] ?? '') === 'active';
        $readyToBook = $readyToQuote;
        $paymentState = $this->buildPaymentState($billing);

        return $this->evaluationCache[$cacheKey] = [
            'review' => $reviewState,
            'documents' => $documentsState,
            'payment' => $paymentState,
            'billing' => [
                ...$billing,
                ...$paymentState,
                'payment_confirmed' => $paymentState['is_active'],
                'subscription_active' => $paymentState['is_active'],
            ],
            'operation' => $operationState,
            'pricing' => $pricingState,
            'activation' => $activationState,
            'ready_to_quote' => $readyToQuote,
            'ready_to_book' => $readyToBook,
        ];
    }

    public function evaluateAndSyncAircraftState(Aeronave|int $aircraft, ?array $billingSnapshot = null): array
    {
        $aircraftId = $aircraft instanceof Aeronave ? (int) $aircraft->id : (int) $aircraft;

        if ($aircraftId <= 0) {
            abort(404, 'Aeronave no encontrada para sincronizar estado.');
        }

        return DB::transaction(function () use ($aircraftId, $billingSnapshot) {
            $aircraft = Aeronave::query()
                ->with(['provider', 'documents', 'images', 'availability', 'baseAirport'])
                ->lockForUpdate()
                ->findOrFail($aircraftId);

            $this->providerAircraftSubscriptionService->syncAircraftStateIfExpired($aircraft);
            $aircraft->refresh();
            $aircraft->load(['provider', 'documents', 'images', 'availability', 'baseAirport']);

            $snapshot = $this->evaluate($aircraft, $billingSnapshot);
            $normalizedStatus = $this->normalizeValue($aircraft->status);

            $nextApprovedAt = ($snapshot['review']['approved'] ?? false) ? ($aircraft->approved_at ?: now()) : null;
            $nextStatus = $normalizedStatus === 'blocked'
                ? 'blocked'
                : (($snapshot['activation']['can_activate'] ?? false) ? 'active' : 'inactive');

            $changes = [];

            if (($aircraft->approved_at?->toISOString() ?? null) !== ($nextApprovedAt?->toISOString() ?? null)) {
                $changes['approved_at'] = $nextApprovedAt;
            }

            if ($normalizedStatus !== $nextStatus && $normalizedStatus !== 'blocked') {
                $changes['status'] = $nextStatus;
            }

            if ($changes !== []) {
                $this->forgetEvaluationCacheForAircraft($aircraftId);
                $aircraft->forceFill($changes)->save();
                $aircraft->refresh();
                $aircraft->load(['provider', 'documents', 'images', 'availability', 'baseAirport']);
            }

            $this->forgetEvaluationCacheForAircraft($aircraftId);

            return $this->evaluate($aircraft);
        });
    }

    public function deduplicateAircraftDocuments(iterable $documents): Collection
    {
        return collect($documents)
            ->filter(fn ($document) => $document instanceof DocumentoAeronave)
            ->sortByDesc(fn (DocumentoAeronave $document) => (int) $document->id)
            ->unique(fn (DocumentoAeronave $document) => $this->documentSignature($document))
            ->sortBy('id')
            ->values();
    }

    private function buildReviewState(Aeronave $aircraft, array $documentsState): array
    {
        $providerApproved = (bool) $aircraft->provider?->isAdministrativelyApproved();
        $documentsRejected = (int) ($documentsState['rejected'] ?? 0) > 0;
        $documentsExpired = (int) ($documentsState['expired'] ?? 0) > 0;
        $documentsValid = (bool) ($documentsState['valid'] ?? false);

        if ($providerApproved && $documentsValid) {
            return [
                'status' => 'approved',
                'approved' => true,
                'provider_approved' => true,
                'aircraft_approved' => true,
            ];
        }

        if ($documentsRejected || $documentsExpired) {
            return [
                'status' => 'correction_required',
                'approved' => false,
                'provider_approved' => $providerApproved,
                'aircraft_approved' => false,
            ];
        }

        return [
            'status' => $providerApproved ? 'pending_review' : 'provider_pending',
            'approved' => false,
            'provider_approved' => $providerApproved,
            'aircraft_approved' => false,
        ];
    }

    private function buildDocumentsState(Aeronave $aircraft, Collection $documents): array
    {
        $required = count(self::REQUIRED_DOCUMENTS);
        $uploaded = 0;
        $approved = 0;
        $pending = 0;
        $rejected = 0;
        $expired = 0;
        $missing = 0;
        $statusByRequirement = [];

        foreach (self::REQUIRED_DOCUMENTS as $requirement) {
            $matched = $documents
                ->filter(fn (DocumentoAeronave $document) => $this->documentMatchesRequirement($document, $requirement))
                ->values();

            $status = 'missing';

            if ($matched->isNotEmpty()) {
                $uploaded++;

                $hasApprovedCurrent = $matched->contains(fn (DocumentoAeronave $document) => $this->documentStatus($document) === 'approved' && ! $this->isDocumentExpired($document));
                $hasExpired = $matched->contains(fn (DocumentoAeronave $document) => $this->documentStatus($document) === 'expired' || $this->isDocumentExpired($document));
                $hasRejected = $matched->contains(fn (DocumentoAeronave $document) => in_array($this->documentStatus($document), ['rejected', 'correction_required'], true));

                if ($hasApprovedCurrent) {
                    $status = 'approved';
                    $approved++;
                } elseif ($hasExpired) {
                    $status = 'expired';
                    $expired++;
                } elseif ($hasRejected) {
                    $status = 'rejected';
                    $rejected++;
                } else {
                    $status = 'pending';
                    $pending++;
                }
            } else {
                $missing++;
            }

            $statusByRequirement[$requirement['key']] = [
                'key' => $requirement['key'],
                'label' => $requirement['label'],
                'status' => $status,
                'documents' => $matched->map(fn (DocumentoAeronave $document) => [
                    'id' => $document->id,
                    'status' => $this->documentStatus($document),
                    'document_type' => $document->document_type,
                    'document_name' => $document->document_name,
                    'expires_at' => optional($document->expires_at)->toIso8601String(),
                ])->values()->all(),
            ];
        }

        return [
            'required' => $required,
            'uploaded' => $uploaded,
            'approved' => $approved,
            'pending' => $pending,
            'rejected' => $rejected,
            'expired' => $expired,
            'missing' => $missing,
            'uploaded_percentage' => $required > 0 ? (int) round(($uploaded / $required) * 100) : 0,
            'approved_percentage' => $required > 0 ? (int) round(($approved / $required) * 100) : 0,
            'complete' => $approved === $required,
            'valid' => $approved === $required && $expired === 0,
            'files_count' => $documents->count(),
            'status' => $approved === 0
                ? ($uploaded === 0 ? 'missing' : 'pending')
                : ($approved < $required ? 'incomplete' : 'complete'),
            'requirements' => array_values($statusByRequirement),
            'last_updated_at' => optional($documents->max('updated_at'))->toIso8601String(),
        ];
    }

    private function buildOperationState(Aeronave $aircraft): array
    {
        $rangeKm = (int) ($aircraft->range_km ?? 0);
        $rangeNm = $rangeKm > 0 ? (int) round($rangeKm / 1.852) : null;
        $capacityConfigured = (int) ($aircraft->capacity ?? 0) > 0;
        $baseRegistered = trim((string) ($aircraft->resolvedBaseAirportCode() ?? $aircraft->base_airport ?? '')) !== '';
        $rangeConfigured = $rangeKm > 0;
        $operationalDataComplete = $rangeConfigured && $capacityConfigured && $baseRegistered;

        return [
            'range_nm' => $rangeNm,
            'range_km' => $rangeKm > 0 ? $rangeKm : null,
            'range_configured' => $rangeConfigured,
            'capacity' => $aircraft->capacity,
            'capacity_configured' => $capacityConfigured,
            'base' => $aircraft->resolvedBaseAirportCode() ?? $aircraft->base_airport,
            'base_registered' => $baseRegistered,
            'operational_data_complete' => $operationalDataComplete,
        ];
    }

    private function buildPricingState(Aeronave $aircraft): array
    {
        $hourlyRate = (float) ($aircraft->hourly_rate ?? 0);
        $minimumHours = (float) ($aircraft->minimum_hours ?? 0);

        return [
            'hourly_rate' => $hourlyRate > 0 ? $hourlyRate : null,
            'minimum_hours' => $minimumHours > 0 ? $minimumHours : null,
            'currency' => $aircraft->currency ?: 'USD',
            'complete' => $hourlyRate > 0 && $minimumHours > 0,
        ];
    }

    private function buildActivationState(Aeronave $aircraft, array $billing, array $reviewState, array $documentsState, array $operationState, array $pricingState): array
    {
        $providerApproved = (bool) ($reviewState['provider_approved'] ?? false);
        $paymentActive = $this->buildPaymentState($billing)['is_active'] ?? false;
        $missingRequirements = [];
        $normalizedStatus = $this->normalizeValue($aircraft->status);

        if (! $providerApproved) {
            $missingRequirements[] = 'provider';
        }
        if (! ($documentsState['valid'] ?? false)) {
            $missingRequirements[] = 'documents';
        }
        if (! ($operationState['range_configured'] ?? false)) {
            $missingRequirements[] = 'range';
        }
        if (! ($operationState['capacity_configured'] ?? false)) {
            $missingRequirements[] = 'capacity';
        }
        if (! ($operationState['base_registered'] ?? false)) {
            $missingRequirements[] = 'base';
        }
        if (! ($pricingState['complete'] ?? false)) {
            $missingRequirements[] = 'pricing';
        }
        if (! $paymentActive) {
            $missingRequirements[] = 'payment_pending';
        }

        $commercialStatus = 'pending_requirements';
        if ($normalizedStatus === 'blocked') {
            $commercialStatus = 'blocked';
        } elseif ($missingRequirements === []) {
            $commercialStatus = 'active';
        } elseif (! $paymentActive) {
            $commercialStatus = 'pending_payment';
        }

        return [
            'is_active' => $normalizedStatus === 'active' && $missingRequirements === [],
            'can_activate' => $normalizedStatus !== 'blocked' && $missingRequirements === [],
            'commercial_status' => $commercialStatus,
            'missing_requirements' => array_values(array_unique($missingRequirements)),
            'provider_approved' => $providerApproved,
        ];
    }

    private function evaluationCacheKey(Aeronave $aircraft, ?array $billingSnapshot): string
    {
        $billingKey = $billingSnapshot === null ? 'auto' : md5(json_encode($billingSnapshot));

        return implode(':', [
            (string) $aircraft->getKey(),
            (string) optional($aircraft->updated_at)->getTimestamp(),
            (string) optional($aircraft->approved_at)->getTimestamp(),
            $this->normalizeValue($aircraft->status),
            $this->normalizeValue($aircraft->billing_status),
            $this->normalizeValue($aircraft->subscription_status),
            $billingKey,
        ]);
    }

    private function forgetEvaluationCacheForAircraft(int $aircraftId): void
    {
        $prefix = $aircraftId.':';

        foreach (array_keys($this->evaluationCache) as $cacheKey) {
            if (str_starts_with($cacheKey, $prefix)) {
                unset($this->evaluationCache[$cacheKey]);
            }
        }
    }

    private function buildPaymentState(array $billing): array
    {
        $subscriptionStatus = $this->normalizeValue($billing['subscription_status'] ?? '');
        $paymentStatus = $this->normalizeValue($billing['payment_status'] ?? '');
        $billingStatus = $this->normalizeValue($billing['billing_status'] ?? '');
        $isActive = in_array($subscriptionStatus, ProviderAircraftSubscriptionService::ACTIVE_STRIPE_STATUSES, true)
            || in_array($paymentStatus, ['paid', 'active'], true)
            || $billingStatus === 'active';

        return [
            'status' => $isActive ? 'active' : 'pending',
            'label' => $isActive ? 'Pago activo' : 'Pago pendiente',
            'is_active' => $isActive,
            'verified_at' => $isActive ? optional($billing['last_payment_at'] ?? null)->toIso8601String() ?? $billing['last_payment_at'] ?? null : null,
        ];
    }

    private function documentMatchesRequirement(DocumentoAeronave $document, array $requirement): bool
    {
        $key = $this->normalizeValue($document->document_type ?: $document->type ?: data_get($document->metadata, 'definition_key'));

        if ($key !== '' && in_array($key, $requirement['aliases'], true)) {
            return true;
        }

        $name = $this->normalizeValue($document->document_name);

        return collect($requirement['aliases'])->contains(fn ($alias) => $name !== '' && str_contains($name, $alias));
    }

    private function documentStatus(DocumentoAeronave $document): string
    {
        $status = $this->normalizeValue($document->status);

        return match (true) {
            $this->isDocumentExpired($document) => 'expired',
            $document->verified_by_admin === true => 'approved',
            in_array($status, ['approved', 'aprobado', 'aprobada', 'validated', 'vigente'], true) => 'approved',
            in_array($status, ['rejected', 'rechazado', 'rechazada'], true) => 'rejected',
            in_array($status, ['changes_requested', 'changes_required', 'correction_required', 'needs_changes'], true) => 'correction_required',
            $status === '' => 'pending',
            default => $status,
        };
    }

    private function isDocumentExpired(DocumentoAeronave $document): bool
    {
        return $document->expires_at instanceof Carbon && $document->expires_at->isPast();
    }

    private function documentSignature(DocumentoAeronave $document): string
    {
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
    }

    private function normalizeValue(mixed $value): string
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
