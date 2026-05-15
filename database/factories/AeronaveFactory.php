<?php

namespace Database\Factories;

use App\Modelos\Aeronave;
use App\Modelos\Proveedor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Aeronave> */
class AeronaveFactory extends Factory
{
    protected $model = Aeronave::class;

    private const CATEGORY_CLIMB_DESCENT_MINUTES = [
        'Helicoptero' => 25,
        'Turboprop' => 25,
        'Light Jet' => 30,
        'Mid Jet' => 35,
        'Heavy Jet' => 45,
    ];

    public function definition(): array
    {
        $category = fake()->randomElement(array_keys(self::CATEGORY_CLIMB_DESCENT_MINUTES));

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
            'climb_descent_minutes' => self::CATEGORY_CLIMB_DESCENT_MINUTES[$category],
            'currency' => 'USD',
            'status' => 'active',
        ];
    }
}
