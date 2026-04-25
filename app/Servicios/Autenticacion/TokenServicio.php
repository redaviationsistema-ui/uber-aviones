<?php

namespace App\Servicios\Autenticacion;

use App\Modelos\TokenApi;
use App\Modelos\Usuario;

class TokenServicio
{
    public function issue(Usuario $user): string
    {
        return TokenApi::issue($user);
    }
}
