<?php

namespace App\Servicios\Administracion;

use App\Modelos\Usuario;

class AdminDashboardServicio
{
    public function metrics(): array
    {
        return ['users' => Usuario::count()];
    }
}
