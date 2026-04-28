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
            'clientes' => Usuario::where('role', 'client')->whereNull('operational_role')->count(),
            'operadores' => Usuario::where('role', 'provider')->count(),
            'sobrecargos' => Usuario::where('operational_role', 'sobrecargo')->count(),
            'solicitudes' => SolicitudVuelo::count(),
            'operaciones_activas' => Operacion::whereNotIn('status', ['finalizada', 'cancelada'])->count(),
            'suscripciones_activas' => Suscripcion::where('status', 'active')->count(),
            'alertas_anti_broker_abiertas' => BanderaAntiBroker::where('status', 'abierta')->count(),
        ];
    }
}
