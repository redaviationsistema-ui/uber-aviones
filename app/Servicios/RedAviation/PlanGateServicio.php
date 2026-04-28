<?php

namespace App\Servicios\RedAviation;

use App\Modelos\Plan;
use App\Modelos\SolicitudVuelo;
use App\Modelos\Usuario;

class PlanGateServicio
{
    public function accesoActivo(Usuario $usuario): bool
    {
        return $usuario->hasPremiumAccess();
    }

    public function planActual(Usuario $usuario): ?Plan
    {
        return $usuario->activeSuscripcion?->plan;
    }

    public function puedeCrearSolicitud(Usuario $usuario): bool
    {
        if ($usuario->role === Usuario::ROLE_ADMIN) {
            return true;
        }

        if (! $this->accesoActivo($usuario)) {
            return false;
        }

        $plan = $this->planActual($usuario);
        if (! $plan || ! $plan->max_requests) {
            return true;
        }

        $conteo = SolicitudVuelo::where('client_id', $usuario->id)->count();

        return $conteo < $plan->max_requests;
    }
}
