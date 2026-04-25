<?php

namespace Database\Factories;

use App\Modelos\SolicitudVuelo;
use App\Modelos\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SolicitudVuelo> */
class SolicitudVueloFactory extends Factory
{
    protected $model = SolicitudVuelo::class;

    public function definition(): array
    {
        return [
            'client_id' => Usuario::factory()->create(['role' => 'client'])->id,
            'origin' => 'MMMX',
            'destination' => 'MMUN',
            'departure_datetime' => now()->addDays(3)->setTime(10, 0),
            'departure_date' => now()->addDays(3)->format('Y-m-d'),
            'departure_time' => '10:00',
            'passengers' => 4,
            'trip_type' => 'one_way',
            'status' => 'pending',
        ];
    }
}
