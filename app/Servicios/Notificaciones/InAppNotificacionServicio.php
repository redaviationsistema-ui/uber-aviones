<?php

namespace App\Servicios\Notificaciones;

use App\Modelos\Usuario;

class InAppNotificacionServicio
{
    public function create(Usuario $user, array $data)
    {
        return $user->notifications()->create($data);
    }
}
