<?php

namespace App\Servicios\Comisiones;

use App\Modelos\Comision;
use App\Modelos\PagoProveedor;

class PagoProveedorServicio
{
    public function release(Comision $commission): PagoProveedor
    {
        return PagoProveedor::create([
            'provider_id' => $commission->provider_id,
            'commission_id' => $commission->id,
            'amount' => $commission->provider_amount,
            'currency' => 'USD',
            'status' => 'released',
            'released_at' => now(),
        ]);
    }
}
