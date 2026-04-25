<?php

namespace App\Http\Intermediarios;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerificarSuscripcion
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->activeSuscripcion) {
            return response()->json([
                'success' => false,
                'message' => 'Suscripcion requerida.',
            ], 402);
        }

        return $next($request);
    }
}
