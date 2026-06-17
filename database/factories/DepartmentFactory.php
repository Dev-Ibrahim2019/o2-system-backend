<?php

namespace Database\Factories;

use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        $name = fake()->randomElement(['Kitchen', 'Bar', 'Service', 'Bakery', 'Grill', 'Pastry']);

        return [
            'name' => $name,
            'nameAr' => null,
            'code' => (string) fake()->unique()->numberBetween(1000, 9999),
            'parent_id' => null,
            'type' => fake()->randomElement(['department', 'section', 'unit']),
            'status' => 'ACTIVE',
            'is_central' => false,
            'is_active' => true,
            'shortName' => substr($name, 0, 3),
            'icon' => null,
            'color' => fake()->hexColor(),
            'location' => fake()->optional()->word(),
            'stationNumber' => fake()->optional()->numerify('ST-##'),
            'defaultPrepTime' => fake()->numberBetween(5, 45),
            'maxConcurrentOrders' => fake()->numberBetween(5, 30),
            'hasKds' => fake()->boolean(40),
            'autoPrintTicket' => fake()->boolean(30),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
            'status' => 'INACTIVE',
        ]);
    }
}
