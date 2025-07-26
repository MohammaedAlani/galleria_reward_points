<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => \App\Models\Customer::factory(),
            'add_by' => \App\Models\User::factory(),
            'approved_by' => \App\Models\User::factory(),
            'transaction_type' => $this->faker->randomElement(['credit', 'debit']),
            'transaction_date' => $this->faker->dateTimeThisYear(),
            'transaction_amount' => $this->faker->randomFloat(2, 0, 1000),
            'transaction_number' => $this->faker->unique()->numerify('TR-#####'),
        ];
    }
}
