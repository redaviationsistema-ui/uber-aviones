<?php

namespace App\Servicios\Acceso;

use App\Modelos\Usuario;

class DemoAccessServicio
{
    public function isActive(Usuario $user): bool
    {
        return $user->demo?->status === 'active' && $user->demo?->expires_at?->isFuture();
    }
}
