<?php

namespace Tests;

use App\Modelos\Usuario;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function authenticateAsUsuario(Usuario|string $user): Usuario
    {
        if (is_string($user)) {
            $user = Usuario::query()
                ->where('email', $user)
                ->firstOrFail();
        }

        return $user;
    }
}
