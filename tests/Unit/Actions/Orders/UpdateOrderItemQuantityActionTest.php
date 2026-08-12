<?php

use App\Actions\Orders\UpdateOrderItemQuantityAction;
use App\Enums\OrderStatus;
use App\Enums\TableStatus;
use App\Exceptions\Orders\OrderNotEditableException;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Table;
use App\Models\User;

beforeEach(function () {
    tenancy()->initialize(actingInTenant());
});

test('ajustar la cantidad de un OrderItem la actualiza', function () {
    $table = Table::factory()->create(['status' => TableStatus::Ocupada]);
    $mesero = User::factory()->create();
    $order = Order::factory()->for($table)->create(['opened_by' => $mesero->id, 'status' => OrderStatus::Abierta]);
    $orderItem = OrderItem::factory()->for($order)->for(MenuItem::factory())->create(['quantity' => 1]);

    $updated = (new UpdateOrderItemQuantityAction)->handle($orderItem, 3);

    expect($updated->quantity)->toBe(3)
        ->and($orderItem->fresh()->quantity)->toBe(3);
});

test('ajustar la cantidad a 0 elimina el renglón', function () {
    $table = Table::factory()->create(['status' => TableStatus::Ocupada]);
    $mesero = User::factory()->create();
    $order = Order::factory()->for($table)->create(['opened_by' => $mesero->id, 'status' => OrderStatus::Abierta]);
    $orderItem = OrderItem::factory()->for($order)->for(MenuItem::factory())->create(['quantity' => 1]);

    $result = (new UpdateOrderItemQuantityAction)->handle($orderItem, 0);

    expect($result)->toBeNull()
        ->and(OrderItem::count())->toBe(0);
});

test('ajustar un OrderItem de una orden que ya no está abierta lanza excepción de dominio', function (OrderStatus $status) {
    $table = Table::factory()->create(['status' => TableStatus::Ocupada]);
    $mesero = User::factory()->create();
    $order = Order::factory()->for($table)->create(['opened_by' => $mesero->id, 'status' => $status]);
    $orderItem = OrderItem::factory()->for($order)->for(MenuItem::factory())->create(['quantity' => 1]);

    expect(fn () => (new UpdateOrderItemQuantityAction)->handle($orderItem, 2))
        ->toThrow(OrderNotEditableException::class);

    expect($orderItem->fresh()->quantity)->toBe(1);
})->with([OrderStatus::EnviadaCocina, OrderStatus::Lista, OrderStatus::PorCobrar]);
