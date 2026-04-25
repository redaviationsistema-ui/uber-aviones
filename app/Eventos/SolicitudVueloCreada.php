<?php

namespace App\Eventos;

class SolicitudVueloCreada
{
    public function __construct(public mixed $payload = null) {}
}
