<?php

namespace App\Enumeraciones;

enum EstadoDemo: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
