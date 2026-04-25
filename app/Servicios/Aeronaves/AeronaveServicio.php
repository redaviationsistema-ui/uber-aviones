<?php

namespace App\Servicios\Aeronaves;

use App\Modelos\Aeronave;
use App\Modelos\Proveedor;

class AeronaveServicio
{
    public function create(Proveedor $provider, array $data): Aeronave
    {
        return $provider->aircraft()->create($data);
    }
}
