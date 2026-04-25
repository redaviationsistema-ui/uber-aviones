<?php

namespace App\Http\Intermediarios;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AsegurarAccesoPremium
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasPremiumAccess()) {
            return response()->json([
                'success' => false,
                'message' => 'Necesitas una demo activa o una suscripcion vigente para usar esta funcion.',
                'access' => $user?->accessStatus(),
            ], 402);
        }

        return $next($request);
    }
}
