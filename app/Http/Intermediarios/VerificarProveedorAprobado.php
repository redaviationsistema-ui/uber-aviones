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
        $user = $request->user();
        $provider = $user?->provider ?: $user?->ownedProvider;

        if (! $user?->hasRole('admin') && ! $provider) {
            return response()->json([
                'success' => false,
                'code' => 'PROVIDER_NOT_LINKED',
                'message' => 'La sesion no tiene un proveedor vinculado.',
            ], 403);
        }

        if (! $user?->hasRole('admin') && ! $this->proveedorServicio->isApproved($provider)) {
            return response()->json([
                'success' => false,
                'code' => 'PROVIDER_NOT_APPROVED',
                'message' => 'Proveedor no aprobado.',
            ], 403);
        }

        return $next($request);
    }
}
