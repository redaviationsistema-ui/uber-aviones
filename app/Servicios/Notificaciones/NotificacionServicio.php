<?php

namespace App\Servicios\Notificaciones;

use App\Modelos\Usuario;

class NotificacionServicio
{
    public function inApp(Usuario $user, string $type, string $title, string $message)
    {
        return $user->notifications()->create(compact('type', 'title', 'message'));
    }
}
