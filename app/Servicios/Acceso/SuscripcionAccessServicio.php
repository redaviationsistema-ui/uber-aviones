<?php

namespace App\Servicios\Acceso;

use App\Modelos\Usuario;

class SuscripcionAccessServicio
{
    public function isActive(Usuario $user): bool
    {
        return $user->activeSuscripcion !== null;
    }
}
