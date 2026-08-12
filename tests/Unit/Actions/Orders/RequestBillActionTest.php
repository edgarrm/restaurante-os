<?php

use App\Actions\Orders\RequestBillAction;
use App\Enums\OrderStatus;
use App\Enums\TableStatus;
use App\Models\Order;
use App\Models\Table;
use App\Models\User;

beforeEach(function () {
    tenancy()->initialize(actingInTenant());
});

test('marca la orden y la mesa como por_cobrar', function (OrderStatus $from) {
    $table = Table::factory()->create(['status' => TableStatus::Ocupada]);
    $mesero = User::factory()->create();
    $order = Order::factory()->for($table)->create(['opened_by' => $mesero->id, 'status' => $from]);

    $result = (new RequestBillAction)->handle($order);

    expect($result->status)->toBe(OrderStatus::PorCobrar)
        ->and($order->fresh()->status)->toBe(OrderStatus::PorCobrar)
        ->and($table->fresh()->status)->toBe(TableStatus::PorCobrar);
})->with([OrderStatus::Abierta, OrderStatus::EnviadaCocina, OrderStatus::Lista]);

test('es idempotente: una orden ya por_cobrar no cambia', function () {
    $table = Table::factory()->create(['status' => TableStatus::PorCobrar]);
    $mesero = User::factory()->create();
    $order = Order::factory()->for($table)->create(['opened_by' => $mesero->id, 'status' => OrderStatus::PorCobrar]);

    $result = (new RequestBillAction)->handle($order);

    expect($result->status)->toBe(OrderStatus::PorCobrar);
});

test('no reabre una orden ya pagada', function () {
    $table = Table::factory()->create(['status' => TableStatus::Libre]);
    $mesero = User::factory()->create();
    $order = Order::factory()->for($table)->create(['opened_by' => $mesero->id, 'status' => OrderStatus::Pagada]);

    (new RequestBillAction)->handle($order);

    expect($order->fresh()->status)->toBe(OrderStatus::Pagada)
        ->and($table->fresh()->status)->toBe(TableStatus::Libre);
});
