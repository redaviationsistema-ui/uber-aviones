<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistroAuditoria extends Model
{
    protected $table = 'audit_logs';

    protected $fillable = [
        'admin_user_id',
        'user_id',
        'action',
        'module',
        'entity',
        'entity_id',
        'reason',
        'result',
        'before',
        'after',
        'metadata',
        'description',
        'ip_address',
        'user_agent',
        'request_id',
        'old_values',
        'new_values',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'metadata' => 'array',
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }
}
