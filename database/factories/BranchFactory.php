<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company().' Branch',
            'address' => fake()->streetAddress(),
            'phone' => fake()->numerify('0##########'),
            'is_active' => true,
            'code' => strtoupper(fake()->unique()->bothify('BR-###')),
            'isMainBranch' => false,
            'openingTime' => '08:00:00',
            'closingTime' => '22:00:00',
        ];
    }

    public function main(): static
    {
        return $this->state(fn () => [
            'isMainBranch' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
