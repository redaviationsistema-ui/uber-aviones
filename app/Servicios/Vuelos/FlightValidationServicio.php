<?php

namespace App\Servicios\Vuelos;

class FlightValidationServicio
{
    public function validatePassengers(int $passengers): bool
    {
        return $passengers > 0;
    }
}
