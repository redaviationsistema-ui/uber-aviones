<?php

namespace App\Servicios\RedAviation;

use App\Modelos\BanderaAntiBroker;
use App\Modelos\Operacion;
use App\Modelos\SolicitudVuelo;
use App\Modelos\Suscripcion;
use App\Modelos\Usuario;

class KpiSaasServicio
{
    public function resumen(): array
    {
        return [
            'usuarios_totales' => Usuario::count(),
            'clientes' => Usuario::whereHas('roles', fn ($query) => $query->where('code', Usuario::ROLE_CLIENT))
                ->whereDoesntHave('roles', fn ($query) => $query->where('code', Usuario::ROLE_SOBRECARGO))
                ->count(),
            'operadores' => Usuario::whereHas('roles', fn ($query) => $query->where('code', Usuario::ROLE_PROVIDER))->count(),
            'sobrecargos' => Usuario::whereHas('roles', fn ($query) => $query->where('code', Usuario::ROLE_SOBRECARGO))->count(),
            'solicitudes' => SolicitudVuelo::count(),
            'operaciones_activas' => Operacion::whereNotIn('status', ['finalizada', 'cancelada'])->count(),
            'suscripciones_activas' => Suscripcion::where('status', 'active')->count(),
            'alertas_anti_broker_abiertas' => BanderaAntiBroker::where('status', 'abierta')->count(),
        ];
    }
}
