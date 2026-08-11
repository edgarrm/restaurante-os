<?php

use App\Actions\MenuItems\UpdateMenuItemAction;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Table;
use App\Models\User;

beforeEach(function () {
    tenancy()->initialize(actingInTenant());
});

test('cambiar el precio no afecta el snapshot de OrderItem existentes', function () {
    $menuItem = MenuItem::factory()->create(['price' => 100.00]);
    $table = Table::factory()->create();
    $user = User::factory()->create();
    $order = Order::factory()->for($table)->create(['opened_by' => $user->id]);
    $orderItem = OrderItem::factory()->for($order)->for($menuItem)->create(['unit_price' => 100.00]);

    (new UpdateMenuItemAction)->handle($menuItem, [
        'name' => $menuItem->name,
        'category' => $menuItem->category,
        'price' => 250.00,
    ]);

    expect((float) $orderItem->fresh()->unit_price)->toBe(100.00)
        ->and((float) $menuItem->fresh()->price)->toBe(250.00);
});

test('actualiza nombre, categoría y precio sin afectar available', function () {
    $menuItem = MenuItem::factory()->create(['name' => 'Tacos', 'category' => 'Platos fuertes', 'price' => 50.00]);
    $menuItem->forceFill(['available' => false])->save();

    $updated = (new UpdateMenuItemAction)->handle($menuItem, [
        'name' => 'Tacos al pastor',
        'category' => 'Antojitos',
        'price' => 65.00,
    ]);

    expect($updated->name)->toBe('Tacos al pastor')
        ->and($updated->category)->toBe('Antojitos')
        ->and((float) $updated->price)->toBe(65.00)
        ->and($updated->available)->toBeFalse();
});
