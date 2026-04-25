<?php

namespace App\Servicios\Usuarios;

use App\Modelos\Usuario;

class PerfilServicio
{
    public function update(Usuario $user, array $data)
    {
        return $user->profile()->updateOrCreate(['user_id' => $user->id], $data);
    }
}
