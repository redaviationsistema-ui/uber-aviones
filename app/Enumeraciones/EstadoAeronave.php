<?php

namespace App\Enumeraciones;

enum EstadoAeronave: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Maintenance = 'maintenance';
    case Blocked = 'blocked';
}
