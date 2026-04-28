<?php

namespace App\Http\Intermediarios;

use App\Servicios\RedAviation\PlanGateServicio;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerificarLimitePlanIntermediario
{
    public function __construct(private readonly PlanGateServicio $planGateServicio)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->planGateServicio->puedeCrearSolicitud($request->user())) {
            return response()->json([
                'success' => false,
                'message' => 'Tu paquete actual ya alcanzo el limite de solicitudes.',
            ], 402);
        }

        return $next($request);
    }
}
