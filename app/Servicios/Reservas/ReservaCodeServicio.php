<?php

namespace App\Servicios\Reservas;

use Illuminate\Support\Str;

class ReservaCodeServicio
{
    public function generate(): string
    {
        return 'SG-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
    }
}
