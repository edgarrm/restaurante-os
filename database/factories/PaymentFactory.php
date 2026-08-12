<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'collected_by' => User::factory(),
            'amount' => fake()->randomFloat(2, 20, 400),
            'method' => fake()->randomElement(PaymentMethod::cases()),
            'paid_at' => now(),
        ];
    }
}
