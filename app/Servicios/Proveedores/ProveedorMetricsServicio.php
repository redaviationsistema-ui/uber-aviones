<?php

namespace App\Servicios\Proveedores;

use App\Modelos\Proveedor;

class ProveedorMetricsServicio
{
    public function metrics(Proveedor $provider): array
    {
        return [
            'aircraft' => $provider->aircraft()->count(),
            'quotes' => $provider->quotes()->count(),
            'reservations' => $provider->reservations()->count(),
        ];
    }
}
