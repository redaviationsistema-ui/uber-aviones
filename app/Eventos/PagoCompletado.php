<?php

namespace App\Eventos;

class PagoCompletado
{
    public function __construct(public mixed $payload = null) {}
}
