<?php

namespace App\Enumeraciones;

enum EstadoUsuario: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Blocked = 'blocked';
}
