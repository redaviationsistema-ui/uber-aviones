<?php

namespace App\Servicios\Busqueda;

class RouteDistanceServicio
{
    public function estimateKm(string $origin, string $destination): int
    {
        return $origin === $destination ? 0 : 1000;
    }
}
