<?php

namespace App\Servicios\Proveedores;

use App\Modelos\Proveedor;

class ProveedorServicio
{
    public function isApproved(?Proveedor $provider): bool
    {
        return $provider?->isApprovedForOperations() ?? false;
    }
}
