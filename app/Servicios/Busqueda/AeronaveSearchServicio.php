<?php

namespace App\Servicios\Busqueda;

use App\Modelos\Aeronave;

class AeronaveSearchServicio
{
    public function available(string $origin, int $passengers)
    {
        return Aeronave::where('base_airport', $origin)->where('capacity', '>=', $passengers)->where('status', 'active')->get();
    }
}
