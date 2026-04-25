<?php

namespace Database\Factories;

use App\Modelos\Proveedor;
use App\Modelos\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Proveedor> */
class ProveedorFactory extends Factory
{
    protected $model = Proveedor::class;

    public function definition(): array
    {
        return [
            'user_id' => Usuario::factory()->create(['role' => 'provider'])->id,
            'company_name' => fake()->company(),
            'commercial_name' => fake()->companySuffix(),
            'approval_status' => 'pending',
        ];
    }
}
