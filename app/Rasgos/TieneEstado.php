<?php

namespace App\Rasgos;

trait TieneEstado
{
    public function hasStatus(string $status): bool
    {
        return $this->status === $status;
    }
}
