<?php

use App\Actions\Orders\MarkOrderItemReadyAction;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Table;
use App\Models\User;

beforeEach(function () {
    tenancy()->initialize(actingInTenant());
});

test('cambia status de pendiente/preparando a listo', function (OrderItemStatus $from) {
    $order = Order::factory()->for(Table::factory())->create([
        'opened_by' => User::factory(),
        'status' => OrderStatus::EnviadaCocina,
    ]);
    $orderItem = OrderItem::factory()->for($order)->for(MenuItem::factory())->create(['status' => $from]);

    $result = (new MarkOrderItemReadyAction)->handle($orderItem);

    expect($result->status)->toBe(OrderItemStatus::Listo)
        ->and($orderItem->fresh()->status)->toBe(OrderItemStatus::Listo);
})->with([OrderItemStatus::Pendiente, OrderItemStatus::Preparando]);

test('es idempotente: marcar un ítem ya listo no lanza error', function () {
    $order = Order::factory()->for(Table::factory())->create([
        'opened_by' => User::factory(),
        'status' => OrderStatus::EnviadaCocina,
    ]);
    $orderItem = OrderItem::factory()->for($order)->for(MenuItem::factory())->create(['status' => OrderItemStatus::Listo]);

    $result = (new MarkOrderItemReadyAction)->handle($orderItem);

    expect($result->status)->toBe(OrderItemStatus::Listo)
        ->and($orderItem->fresh()->status)->toBe(OrderItemStatus::Listo);
});

test('al marcar el último ítem pendiente de una orden, la Order pasa a lista', function () {
    $order = Order::factory()->for(Table::factory())->create([
        'opened_by' => User::factory(),
        'status' => OrderStatus::EnviadaCocina,
    ]);
    OrderItem::factory()->for($order)->for(MenuItem::factory())->create(['status' => OrderItemStatus::Listo]);
    $lastPending = OrderItem::factory()->for($order)->for(MenuItem::factory())->create(['status' => OrderItemStatus::Pendiente]);

    (new MarkOrderItemReadyAction)->handle($lastPending);

    expect($order->fresh()->status)->toBe(OrderStatus::Lista);
});

test('marcar un ítem listo mientras otros de la orden siguen pendientes no cambia la Order', function () {
    $order = Order::factory()->for(Table::factory())->create([
        'opened_by' => User::factory(),
        'status' => OrderStatus::EnviadaCocina,
    ]);
    $item1 = OrderItem::factory()->for($order)->for(MenuItem::factory())->create(['status' => OrderItemStatus::Pendiente]);
    OrderItem::factory()->for($order)->for(MenuItem::factory())->create(['status' => OrderItemStatus::Pendiente]);

    (new MarkOrderItemReadyAction)->handle($item1);

    expect($order->fresh()->status)->toBe(OrderStatus::EnviadaCocina);
});
