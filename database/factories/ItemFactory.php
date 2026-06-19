<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    public function definition(): array
    {
        $name = fake()->words(2, true);

        return [
            'department_id' => Department::factory(),
            'name' => ucfirst($name),
            'name_ar' => fake()->words(2, true),
            'code' => strtoupper(fake()->unique()->bothify('ITM-#####')),
            'image' => null,
            'unit' => fake()->randomElement(['pcs', 'kg', 'ltr', 'box', null]),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
