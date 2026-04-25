<?php

namespace App\Rasgos;

trait TieneRol
{
    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }
}
