<?php

namespace App\Rasgos;

use App\Modelos\RegistroAuditoria;

trait Auditable
{
    public function audit(string $action, string $module, ?string $description = null): void
    {
        RegistroAuditoria::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'module' => $module,
            'description' => $description,
            'ip_address' => request()?->ip(),
        ]);
    }
}
