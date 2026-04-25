<?php

namespace App\Modelos;

use Illuminate\Database\Eloquent\Model;

class RegistroAuditoria extends Model
{
    protected $table = 'audit_logs';

    protected $fillable = ['user_id', 'action', 'module', 'description', 'ip_address', 'user_agent', 'old_values', 'new_values'];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }
}
