<?php

namespace Database\Factories;

use App\Enums\OrderItemStatus;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
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
            'menu_item_id' => MenuItem::factory(),
            'quantity' => fake()->numberBetween(1, 4),
            'unit_price' => fake()->randomFloat(2, 20, 400),
            'status' => OrderItemStatus::Pendiente,
        ];
    }
}
