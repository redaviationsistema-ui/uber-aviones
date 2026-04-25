<?php

namespace App\Servicios\Aeronaves;

use App\Modelos\Aeronave;

class DocumentoAeronaveServicio
{
    public function attach(Aeronave $aircraft, array $data)
    {
        return $aircraft->documents()->create($data);
    }
}
