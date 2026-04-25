<?php

namespace App\Eventos;

class SuscripcionActivada
{
    public function __construct(public mixed $payload = null) {}
}
