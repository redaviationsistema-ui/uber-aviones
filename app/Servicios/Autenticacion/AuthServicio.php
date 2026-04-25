<?php

namespace App\Servicios\Autenticacion;

use App\Modelos\Usuario;
use Illuminate\Support\Facades\Hash;

class AuthServicio
{
    public function validateCredentials(string $email, string $password): ?Usuario
    {
        $user = Usuario::where('email', $email)->first();

        return $user && Hash::check($password, $user->password) ? $user : null;
    }
}
