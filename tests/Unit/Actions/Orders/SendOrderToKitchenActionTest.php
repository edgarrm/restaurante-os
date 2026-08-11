<?php

use App\Actions\Orders\SendOrderToKitchenAction;
use App\Enums\OrderStatus;
use App\Exceptions\Orders\EmptyOrderException;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Table;
use App\Models\User;

beforeEach(function () {
    tenancy()->initialize(actingInTenant());
});

test('una orden con ítems cambia su status a enviada_cocina', function () {
    $table = Table::factory()->create();
    $mesero = User::factory()->create();
    $order = Order::factory()->for($table)->create(['opened_by' => $mesero->id]);
    OrderItem::factory()->for($order)->for(MenuItem::factory())->create();

    $result = (new SendOrderToKitchenAction)->handle($order);

    expect($result->status)->toBe(OrderStatus::EnviadaCocina)
        ->and($order->fresh()->status)->toBe(OrderStatus::EnviadaCocina);
});

test('una orden sin ítems lanza excepción de dominio', function () {
    $table = Table::factory()->create();
    $mesero = User::factory()->create();
    $order = Order::factory()->for($table)->create(['opened_by' => $mesero->id]);

    expect(fn () => (new SendOrderToKitchenAction)->handle($order))
        ->toThrow(EmptyOrderException::class);

    expect($order->fresh()->status)->toBe(OrderStatus::Abierta);
});
