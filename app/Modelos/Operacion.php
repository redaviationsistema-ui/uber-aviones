<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Operacion extends Model
{
    protected $table = 'operations';

    protected $fillable = [
        'flight_request_id',
        'provider_id',
        'aircraft_id',
        'sobrecargo_user_id',
        'status',
        'crew_status',
        'crew_confirmed_at',
        'crew_decline_reason',
        'crew_notes',
        'crew_checkin_at',
        'crew_service_started_at',
        'crew_service_completed_at',
        'crew_checkin_status',
        'crew_checkin_base',
        'crew_checkin_notes',
        'crew_fit_to_operate',
        'crew_landed_at',
        'crew_final_report',
        'crew_report_submitted_at',
        'crew_administratively_closed_at',
        'crew_administratively_closed_by',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'crew_confirmed_at' => 'datetime',
            'crew_checkin_at' => 'datetime',
            'crew_service_started_at' => 'datetime',
            'crew_service_completed_at' => 'datetime',
            'crew_landed_at' => 'datetime',
            'crew_fit_to_operate' => 'boolean',
            'crew_final_report' => 'array',
            'crew_report_submitted_at' => 'datetime',
            'crew_administratively_closed_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function solicitudVuelo(): BelongsTo
    {
        return $this->belongsTo(SolicitudVuelo::class, 'flight_request_id');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'provider_id');
    }

    public function aeronave(): BelongsTo
    {
        return $this->belongsTo(Aeronave::class, 'aircraft_id');
    }

    public function sobrecargo(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'sobrecargo_user_id');
    }

    public function timeline(): HasMany
    {
        return $this->hasMany(LineaTiempoOperacion::class, 'operation_id');
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(ChecklistOperacion::class, 'operation_id');
    }

    public function latestCrewAssignment(): HasOne
    {
        return $this->hasOne(AsignacionSobrecargo::class, 'operation_id')
            ->latestOfMany()
            ->select([
                'sobrecargo_assignments.id',
                'sobrecargo_assignments.operation_id',
                'sobrecargo_assignments.sobrecargo_user_id',
                'sobrecargo_assignments.role',
                'sobrecargo_assignments.status',
                'sobrecargo_assignments.assigned_at',
                'sobrecargo_assignments.response_deadline',
                'sobrecargo_assignments.presentation_time',
                'sobrecargo_assignments.accepted_at',
                'sobrecargo_assignments.rejected_at',
                'sobrecargo_assignments.rejection_reason',
                'sobrecargo_assignments.cancelled_at',
                'sobrecargo_assignments.cancellation_reason',
                'sobrecargo_assignments.created_at',
                'sobrecargo_assignments.updated_at',
            ]);
    }
}
