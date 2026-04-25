<?php

namespace App\Enumeraciones;

enum EstadoComision: string
{
    case Held = 'held';
    case Released = 'released';
    case Cancelled = 'cancelled';
}
