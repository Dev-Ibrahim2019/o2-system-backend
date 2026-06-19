<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'employeeId' => 'EMP-'.fake()->unique()->numerify('######'),
            'name' => fake()->name(),
            'phone' => fake()->numerify('0##########'),
            'email' => fake()->unique()->safeEmail(),
            'address' => fake()->optional()->streetAddress(),
            'nationalId' => fake()->optional()->numerify('##############'),
            'dob' => fake()->optional()->dateTimeBetween('-50 years', '-18 years'),
            'image' => null,
            'branch_id' => null,
            'department_id' => null,
            'jobTitleId' => null,
            'typeId' => null,
            'managerId' => null,
            'hireDate' => fake()->dateTimeBetween('-8 years', 'now'),
            'salary' => fake()->randomFloat(2, 2500, 12000),
            'role' => 'EMPLOYEE',
            'status' => 'ACTIVE',
            'username' => fake()->unique()->userName(),
            'password' => Hash::make('password'),
            'pin' => fake()->numerify('####'),
            'permissions' => null,
            'notes' => null,
            'rating' => fake()->randomFloat(1, 3, 5),
            'performance' => null,
        ];
    }
}
