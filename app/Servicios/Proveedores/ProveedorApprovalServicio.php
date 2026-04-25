<?php

namespace App\Servicios\Proveedores;

use App\Modelos\Proveedor;

class ProveedorApprovalServicio
{
    public function approve(Proveedor $provider): Proveedor
    {
        $provider->update(['approval_status' => 'approved']);

        return $provider;
    }
}
