<?php

namespace App\Http\Intermediarios;

use App\Modelos\RegistroAuditoria;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditoriaIntermediario
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->user() && in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            RegistroAuditoria::create([
                'user_id' => $request->user()->id,
                'action' => strtolower($request->method()),
                'module' => $request->path(),
                'description' => 'Operacion API ejecutada.',
                'ip_address' => $request->ip(),
            ]);
        }

        return $response;
    }
}
