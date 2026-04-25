<?php

namespace App\Servicios\Vuelos;

use App\Modelos\SolicitudVuelo;
use App\Modelos\Usuario;

class SolicitudVueloServicio
{
    public function create(Usuario $client, array $data): SolicitudVuelo
    {
        return SolicitudVuelo::create($data + ['client_id' => $client->id, 'status' => 'pending']);
    }
}
