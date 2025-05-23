<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'address' => fake()->address(),
            'phone' => fake()->phoneNumber(),
            'card_number' => fake()->unique()->creditCardNumber(),
            'total_points' => fake()->numberBetween(0, 1000),
            'total_spent' => fake()->numberBetween(0, 10000),
            'last_transaction' => fake()->dateTimeThisYear(),
            'last_transaction_date' => fake()->date(),
            'last_transaction_amount' => fake()->randomFloat(2, 0, 1000),
        ];
    }
}
