<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Proveedor extends Model
{
    private const ADMIN_VALIDATION_DRAFT_STATUS = 'expediente_incompleto';

    protected $table = 'providers';

    use HasFactory;

    protected static function newFactory()
    {
        return \Database\Factories\ProveedorFactory::new();
    }

    protected $fillable = [
        'user_id',
        'company_name',
        'commercial_name',
        'legal_name',
        'rfc',
        'company_phone',
        'company_email',
        'base_airport',
        'status',
        'representative_name',
        'representative_phone',
        'birth_date',
        'curp',
        'nationality',
        'document_type',
        'document_number',
        'document_expiration',
        'jet_a_price',
        'margin_percent',
        'fixed_fee',
        'approval_status',
        'admin_validation_status',
        'operator_status',
        'access_enabled',
        'provider_validation_requirements',
        'sat_validation_status',
        'admin_notes',
        'admin_validation_notes',
        'admin_review_submitted_at',
        'validated_by',
        'validated_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'changes_requested_by',
        'changes_requested_at',
        'changes_notes',
        'admin_validated_by',
        'admin_validated_at',
        'admin_rejected_by',
        'admin_rejected_at',
        'admin_changes_requested_by',
        'admin_changes_requested_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'jet_a_price' => 'decimal:2',
            'margin_percent' => 'decimal:4',
            'fixed_fee' => 'decimal:2',
            'access_enabled' => 'boolean',
            'provider_validation_requirements' => 'array',
            'birth_date' => 'date',
            'document_expiration' => 'date',
            'admin_review_submitted_at' => 'datetime',
            'validated_at' => 'datetime',
            'rejected_at' => 'datetime',
            'changes_requested_at' => 'datetime',
            'admin_validated_at' => 'datetime',
            'admin_rejected_at' => 'datetime',
            'admin_changes_requested_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(Usuario::class, 'provider_id');
    }

    public function aircraft(): HasMany
    {
        return $this->hasMany(Aeronave::class, 'provider_id');
    }

    public function companyDocuments(): HasMany
    {
        return $this->hasMany(DocumentoEmpresa::class, 'provider_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Cotizacion::class, 'provider_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reserva::class, 'provider_id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Comision::class, 'provider_id');
    }

    public static function normalizeStatusValue(mixed $value): string
    {
        $normalized = Str::of((string) ($value ?? ''))
            ->trim()
            ->lower()
            ->ascii()
            ->replaceMatches('/[\s-]+/', '_')
            ->value();

        return preg_replace('/_+/', '_', $normalized) ?: '';
    }

    public function resolvedApprovalStatus(): string
    {
        $adminValidationStatus = self::normalizeStatusValue($this->admin_validation_status);
        if ($adminValidationStatus !== '' && $adminValidationStatus !== self::ADMIN_VALIDATION_DRAFT_STATUS) {
            return $adminValidationStatus;
        }

        $approvalStatus = self::normalizeStatusValue($this->approval_status);
        if ($approvalStatus !== '') {
            return $approvalStatus;
        }

        return self::normalizeStatusValue($this->status);
    }

    public function isAdministrativelyApproved(): bool
    {
        return self::normalizeStatusValue($this->resolvedApprovalStatus()) === 'approved';
    }

    public function isApprovedForOperations(): bool
    {
        $resolvedStatus = self::normalizeStatusValue($this->resolvedApprovalStatus());

        if (in_array($resolvedStatus, ['rejected', 'changes_requested', 'changes_required', 'suspended', 'pending_review', 'pending_validation', self::ADMIN_VALIDATION_DRAFT_STATUS, 'incomplete'], true)) {
            return false;
        }

        return $this->isAdministrativelyApproved();
    }

    public function scopeApprovedForOperations(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query
                ->whereRaw("LOWER(TRIM(COALESCE(admin_validation_status, ''))) = ?", ['approved'])
                ->orWhere(function (Builder $fallback) {
                    $fallback
                        ->where(function (Builder $adminBlank) {
                            $adminBlank
                                ->whereNull('admin_validation_status')
                                ->orWhereRaw("TRIM(COALESCE(admin_validation_status, '')) = ''")
                                ->orWhereRaw("LOWER(TRIM(COALESCE(admin_validation_status, ''))) = ?", [self::ADMIN_VALIDATION_DRAFT_STATUS]);
                        })
                        ->where(function (Builder $resolved) {
                            $resolved
                                ->whereRaw("LOWER(TRIM(COALESCE(approval_status, ''))) = ?", ['approved'])
                                ->orWhere(function (Builder $statusFallback) {
                                    $statusFallback
                                        ->where(function (Builder $approvalBlank) {
                                            $approvalBlank
                                                ->whereNull('approval_status')
                                                ->orWhereRaw("TRIM(COALESCE(approval_status, '')) = ''");
                                        })
                                        ->whereRaw("LOWER(TRIM(COALESCE(status, ''))) = ?", ['approved']);
                                });
                        });
                });
        });
    }
}
