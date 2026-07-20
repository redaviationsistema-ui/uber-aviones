<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsignacionSobrecargo extends Model
{
    protected $table = 'sobrecargo_assignments';

    protected $fillable = [
        'operation_id', 'sobrecargo_user_id', 'role', 'status', 'assigned_by', 'assigned_at',
        'response_deadline', 'presentation_time', 'accepted_at', 'rejected_at',
        'rejection_reason', 'cancelled_at', 'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime', 'response_deadline' => 'datetime',
            'presentation_time' => 'datetime', 'accepted_at' => 'datetime',
            'rejected_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function operacion(): BelongsTo
    {
        return $this->belongsTo(Operacion::class, 'operation_id');
    }

    public function sobrecargo(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'sobrecargo_user_id');
    }
}
