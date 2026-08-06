<?php

namespace Database\Factories;

use App\Modelos\Aeronave;
use App\Modelos\Proveedor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Aeronave> */
class AeronaveFactory extends Factory
{
    protected $model = Aeronave::class;

    public function definition(): array
    {
        $defaults = (array) config('vuelos.climb_descent_defaults', []);
        $category = fake()->randomElement(array_keys($defaults));

        return [
            'provider_id' => Proveedor::factory(),
            'model' => 'Citation '.fake()->word(),
            'category' => $category,
            'registration' => fake()->unique()->bothify('XA-???'),
            'capacity' => fake()->numberBetween(4, 14),
            'base_airport' => 'MMMX',
            'range_km' => fake()->numberBetween(1500, 7000),
            'speed_kmh' => fake()->numberBetween(600, 950),
            'hourly_rate' => fake()->numberBetween(3000, 9000),
            'climb_descent_minutes' => (int) ($defaults[$category] ?? 30),
            'currency' => 'USD',
            'status' => 'active',
        ];
    }
}
