<?php

namespace App\Servicios\Comisiones;

use App\Modelos\Comision;

class ComisionServicio
{
    public function release(Comision $commission): Comision
    {
        $commission->update(['status' => 'released']);

        return $commission;
    }
}
