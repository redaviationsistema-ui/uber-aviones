<?php

namespace App\Http\Intermediarios;

use App\Servicios\Proveedores\ProveedorServicio;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerificarProveedorAprobado
{
    public function __construct(private readonly ProveedorServicio $proveedorServicio)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $provider = $request->user()?->provider;

        if (! $request->user()?->hasRole('admin') && ! $this->proveedorServicio->isApproved($provider)) {
            return response()->json([
                'success' => false,
                'code' => 'PROVIDER_NOT_APPROVED',
                'message' => 'Proveedor no aprobado.',
            ], 403);
        }

        return $next($request);
    }
}
