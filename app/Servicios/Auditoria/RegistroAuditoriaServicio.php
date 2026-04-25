<?php

namespace App\Servicios\Auditoria;

use App\Modelos\RegistroAuditoria;
use App\Modelos\Usuario;

class RegistroAuditoriaServicio
{
    public function write(?Usuario $user, string $action, string $module, ?string $description = null): RegistroAuditoria
    {
        return RegistroAuditoria::create([
            'user_id' => $user?->id,
            'action' => $action,
            'module' => $module,
            'description' => $description,
        ]);
    }
}
