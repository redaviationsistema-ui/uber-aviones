<?php

namespace App\Servicios\Acceso;

use App\Modelos\Usuario;

class AccessServicio
{
    public function hasAccess(Usuario $user): bool
    {
        return $user->hasPremiumAccess();
    }
}
