<?php

namespace App\Http\Intermediarios;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerificarAccesoActivo
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->hasPremiumAccess()) {
            return response()->json([
                'success' => false,
                'message' => 'Necesitas demo activa o suscripcion vigente.',
            ], 402);
        }

        return $next($request);
    }
}
