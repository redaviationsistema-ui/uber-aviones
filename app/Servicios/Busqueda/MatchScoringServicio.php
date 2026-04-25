<?php

namespace App\Servicios\Busqueda;

class MatchScoringServicio
{
    public function score(int $capacity, int $passengers): int
    {
        return max(1, 100 - abs($capacity - $passengers) * 5);
    }
}
