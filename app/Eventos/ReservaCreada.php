<?php

namespace App\Eventos;

class ReservaCreada
{
    public function __construct(public mixed $payload = null) {}
}
