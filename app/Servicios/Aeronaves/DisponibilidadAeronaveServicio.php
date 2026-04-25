<?php

namespace App\Servicios\Aeronaves;

use App\Modelos\Aeronave;

class DisponibilidadAeronaveServicio
{
    public function block(Aeronave $aircraft, array $data)
    {
        return $aircraft->availability()->create($data + ['status' => 'blocked']);
    }
}
