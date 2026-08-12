<?php

use App\Actions\Inventory\CreateInventoryItemAction;
use App\Models\InventoryItem;
use Illuminate\Validation\ValidationException;

/**
 * CreateInventoryItemAction rellena tenant_id vía BelongsToTenant, que solo
 * autorrellena cuando tenancy está inicializada — mismo patrón que
 * CreateTableActionTest.
 */
beforeEach(function () {
    tenancy()->initialize(actingInTenant());
});

test('crea un insumo con la cantidad inicial dada', function () {
    $item = (new CreateInventoryItemAction)->handle([
        'name' => 'Tomate',
        'unit' => 'kg',
        'low_stock_threshold' => 5,
        'quantity_on_hand' => 20,
    ]);

    expect($item->name)->toBe('Tomate')
        ->and($item->unit)->toBe('kg')
        ->and((float) $item->low_stock_threshold)->toBe(5.0)
        ->and((float) $item->quantity_on_hand)->toBe(20.0);
});

test('sin cantidad inicial, quantity_on_hand queda en 0', function () {
    $item = (new CreateInventoryItemAction)->handle([
        'name' => 'Cebolla',
        'unit' => 'kg',
        'low_stock_threshold' => 3,
    ]);

    expect((float) $item->quantity_on_hand)->toBe(0.0);
});

test('nombre o unidad vacíos lanzan error de validación', function (array $data) {
    expect(fn () => (new CreateInventoryItemAction)->handle($data))
        ->toThrow(ValidationException::class);

    expect(InventoryItem::count())->toBe(0);
})->with([
    'sin nombre' => [['name' => '', 'unit' => 'kg']],
    'sin unidad' => [['name' => 'Tomate', 'unit' => '']],
]);

test('low_stock_threshold o quantity_on_hand negativos lanzan error de validación', function (array $data) {
    expect(fn () => (new CreateInventoryItemAction)->handle($data))
        ->toThrow(ValidationException::class);

    expect(InventoryItem::count())->toBe(0);
})->with([
    'threshold negativo' => [['name' => 'Tomate', 'unit' => 'kg', 'low_stock_threshold' => -1]],
    'cantidad inicial negativa' => [['name' => 'Tomate', 'unit' => 'kg', 'quantity_on_hand' => -1]],
]);
