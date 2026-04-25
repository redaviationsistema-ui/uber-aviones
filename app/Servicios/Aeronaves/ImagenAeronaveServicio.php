<?php

namespace App\Servicios\Aeronaves;

use App\Modelos\Aeronave;

class ImagenAeronaveServicio
{
    public function attach(Aeronave $aircraft, array $data)
    {
        return $aircraft->images()->create($data);
    }
}
