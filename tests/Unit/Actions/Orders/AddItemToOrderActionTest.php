<?php

use App\Actions\Orders\AddItemToOrderAction;
use App\Enums\TableStatus;
use App\Exceptions\Orders\MenuItemNotAvailableException;
use App\Exceptions\Orders\TableNotAcceptingOrdersException;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Table;
use App\Models\User;

beforeEach(function () {
    tenancy()->initialize(actingInTenant());
});

test('agregar un ítem nuevo crea un OrderItem con unit_price snapshot del MenuItem', function () {
    $table = Table::factory()->create(['status' => TableStatus::Ocupada]);
    $mesero = User::factory()->create();
    Order::factory()->for($table)->create(['opened_by' => $mesero->id]);
    $menuItem = MenuItem::factory()->create(['price' => 85.50]);

    $orderItem = (new AddItemToOrderAction)->handle($table, $menuItem, 1, $mesero);

    expect((float) $orderItem->unit_price)->toBe(85.50)
        ->and($orderItem->quantity)->toBe(1)
        ->and($orderItem->menu_item_id)->toBe($menuItem->id);
});

test('agregar un ítem ya presente incrementa quantity en vez de duplicar el renglón', function () {
    $table = Table::factory()->create(['status' => TableStatus::Ocupada]);
    $mesero = User::factory()->create();
    $order = Order::factory()->for($table)->create(['opened_by' => $mesero->id]);
    $menuItem = MenuItem::factory()->create(['price' => 50]);
    OrderItem::factory()->for($order)->for($menuItem)->create(['quantity' => 1, 'unit_price' => 50]);

    (new AddItemToOrderAction)->handle($table, $menuItem, 1, $mesero);

    expect(OrderItem::count())->toBe(1)
        ->and(OrderItem::sole()->quantity)->toBe(2);
});

test('agregar un ítem con available=false lanza excepción de dominio', function () {
    $table = Table::factory()->create(['status' => TableStatus::Ocupada]);
    $mesero = User::factory()->create();
    Order::factory()->for($table)->create(['opened_by' => $mesero->id]);
    $menuItem = MenuItem::factory()->create();
    $menuItem->forceFill(['available' => false])->save();

    expect(fn () => (new AddItemToOrderAction)->handle($table, $menuItem, 1, $mesero))
        ->toThrow(MenuItemNotAvailableException::class);

    expect(OrderItem::count())->toBe(0);
});

test('agregar un ítem a una mesa en por_cobrar lanza excepción de dominio', function () {
    $table = Table::factory()->create(['status' => TableStatus::PorCobrar]);
    $mesero = User::factory()->create();
    $menuItem = MenuItem::factory()->create();

    expect(fn () => (new AddItemToOrderAction)->handle($table, $menuItem, 1, $mesero))
        ->toThrow(TableNotAcceptingOrdersException::class);

    expect(OrderItem::count())->toBe(0);
});
