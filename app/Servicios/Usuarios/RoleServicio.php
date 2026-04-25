<?php

namespace App\Servicios\Usuarios;

use App\Modelos\Usuario;

class RoleServicio
{
    public function hasRole(Usuario $user, string $role): bool
    {
        return $user->role === $role;
    }
}
