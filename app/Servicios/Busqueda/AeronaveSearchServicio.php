<?php

namespace App\Servicios\Busqueda;

use App\Modelos\Aeronave;
use App\Modelos\Aeropuerto;
use App\Enumeraciones\EstadoAeronave;

class AeronaveSearchServicio
{
    public function available(string $origin, int $passengers)
    {
        $normalizedOrigin = strtoupper(trim($origin));
        $airportId = Aeropuerto::query()
            ->where(function ($query) use ($normalizedOrigin) {
                $query->where('icao', $normalizedOrigin)
                    ->orWhere('iata', $normalizedOrigin);
            })
            ->value('id');

        return Aeronave::query()
            ->where('capacity', '>=', $passengers)
            ->where('status', EstadoAeronave::Active->value)
            ->where(function ($query) use ($normalizedOrigin, $airportId) {
                $query->where('base_airport', $normalizedOrigin);

                if ($airportId) {
                    $query->orWhere('base_airport_id', $airportId);
                }
            })
            ->get();
    }
}
