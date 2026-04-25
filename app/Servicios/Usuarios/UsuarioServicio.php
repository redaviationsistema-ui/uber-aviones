<?php

namespace App\Servicios\Usuarios;

use App\Modelos\Usuario;

class UsuarioServicio
{
    public function activate(Usuario $user): Usuario
    {
        $user->update(['status' => 'active']);

        return $user;
    }
}
