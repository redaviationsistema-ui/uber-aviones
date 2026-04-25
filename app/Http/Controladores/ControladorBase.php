<?php

namespace App\Http\Controladores;

use App\Modelos\RegistroAuditoria;
use Illuminate\Http\Request;

abstract class ControladorBase
{
    protected function ok(array $data = [], int $status = 200)
    {
        return response()->json(['success' => true] + $data, $status);
    }

    protected function writeAudit(Request $request, string $action, string $module, ?string $description = null): void
    {
        RegistroAuditoria::create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'module' => $module,
            'description' => $description,
            'ip_address' => $request->ip(),
        ]);
    }
}
