<?php

use App\Actions\Orders\OpenOrReuseOrderForTableAction;
use App\Enums\OrderStatus;
use App\Enums\TableStatus;
use App\Models\Order;
use App\Models\Table;
use App\Models\User;

beforeEach(function () {
    tenancy()->initialize(actingInTenant());
});

test('mesa libre crea una Order nueva y marca la mesa ocupada', function () {
    $table = Table::factory()->create(['status' => TableStatus::Libre]);
    $mesero = User::factory()->create();

    $order = (new OpenOrReuseOrderForTableAction)->handle($table, $mesero);

    expect($order->table_id)->toBe($table->id)
        ->and($order->opened_by)->toBe($mesero->id)
        ->and($order->status)->toBe(OrderStatus::Abierta)
        ->and($table->fresh()->status)->toBe(TableStatus::Ocupada);
});

test('mesa ocupada reutiliza la Order abierta existente', function () {
    $table = Table::factory()->create(['status' => TableStatus::Ocupada]);
    $mesero = User::factory()->create();
    $existente = Order::factory()->for($table)->create(['opened_by' => $mesero->id, 'status' => OrderStatus::Abierta]);

    $order = (new OpenOrReuseOrderForTableAction)->handle($table, $mesero);

    expect($order->id)->toBe($existente->id)
        ->and(Order::count())->toBe(1);
});

test('mesa ocupada con orden enviada_cocina reutiliza esa orden (bug: recargar /pedido tras enviar a cocina daba 404)', function (OrderStatus $status) {
    $table = Table::factory()->create(['status' => TableStatus::Ocupada]);
    $mesero = User::factory()->create();
    $existente = Order::factory()->for($table)->create(['opened_by' => $mesero->id, 'status' => $status]);

    $order = (new OpenOrReuseOrderForTableAction)->handle($table, $mesero);

    expect($order->id)->toBe($existente->id)
        ->and(Order::count())->toBe(1);
})->with([OrderStatus::EnviadaCocina, OrderStatus::Lista]);
