<?php

namespace App\Servicios\Administracion;

use App\Modelos\Usuario;

class AdminUsuarioServicio
{
    public function block(Usuario $user): Usuario
    {
        $user->update(['status' => 'blocked']);

        return $user;
    }
}
