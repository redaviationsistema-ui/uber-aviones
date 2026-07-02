<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proveedor extends Model
{
    protected $table = 'providers';

    use HasFactory;

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
}
