<?php

namespace App\Http\Intermediarios;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerificarProveedorAprobado
{
    public function handle(Request $request, Closure $next): Response
    {
        $provider = $request->user()?->provider;

        if ($request->user()?->role !== 'admin' && (! $provider || $provider->approval_status !== 'approved')) {
            return response()->json([
                'success' => false,
                'message' => 'Proveedor no aprobado.',
            ], 403);
        }

        return $next($request);
    }
}
