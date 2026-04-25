<?php

namespace App\Enumeraciones;

enum RolUsuario: string
{
    case Client = 'client';
    case Proveedor = 'provider';
    case Admin = 'admin';
}
