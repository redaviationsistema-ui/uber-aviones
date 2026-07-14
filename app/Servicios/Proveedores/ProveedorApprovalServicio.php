<?php

namespace App\Servicios\Proveedores;

use App\Modelos\Proveedor;

class ProveedorApprovalServicio
{
    public function approve(Proveedor $provider): Proveedor
    {
        $provider->update([
            'approval_status' => 'approved',
            'admin_validation_status' => 'approved',
            'status' => 'approved',
            'access_enabled' => true,
        ]);

        return $provider;
    }
}
