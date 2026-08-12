<?php

use App\Actions\Inventory\RegisterInventoryMovementAction;
use App\Enums\InventoryMovementType;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->tenant = actingInTenant();
    tenancy()->initialize($this->tenant);
    $this->admin = User::factory()->for($this->tenant, 'tenant')->admin()->create();
});

test('entrada incrementa quantity_on_hand y crea el movimiento', function () {
    $item = InventoryItem::factory()->create(['quantity_on_hand' => 10]);

    $result = (new RegisterInventoryMovementAction)->handle($item, [
        'type' => InventoryMovementType::Entrada->value,
        'quantity' => 5,
        'note' => 'Compra a proveedor',
    ], $this->admin);

    expect((float) $result->quantity_on_hand)->toBe(15.0)
        ->and(InventoryMovement::count())->toBe(1);

    $movement = InventoryMovement::sole();
    expect($movement->type)->toBe(InventoryMovementType::Entrada)
        ->and((float) $movement->quantity)->toBe(5.0)
        ->and($movement->note)->toBe('Compra a proveedor')
        ->and($movement->created_by)->toBe($this->admin->id);
});

test('salida decrementa quantity_on_hand', function () {
    $item = InventoryItem::factory()->create(['quantity_on_hand' => 10]);

    $result = (new RegisterInventoryMovementAction)->handle($item, [
        'type' => InventoryMovementType::Salida->value,
        'quantity' => 4,
    ], $this->admin);

    expect((float) $result->quantity_on_hand)->toBe(6.0);
});

test('salida que dejaría stock negativo lanza InsufficientStockException y no muta el insumo', function () {
    $item = InventoryItem::factory()->create(['quantity_on_hand' => 3]);

    expect(fn () => (new RegisterInventoryMovementAction)->handle($item, [
        'type' => InventoryMovementType::Salida->value,
        'quantity' => 5,
    ], $this->admin))->toThrow(InsufficientStockException::class);

    expect((float) $item->fresh()->quantity_on_hand)->toBe(3.0)
        ->and(InventoryMovement::count())->toBe(0);
});

test('salida que deja el stock exactamente en 0 está permitida', function () {
    $item = InventoryItem::factory()->create(['quantity_on_hand' => 5]);

    $result = (new RegisterInventoryMovementAction)->handle($item, [
        'type' => InventoryMovementType::Salida->value,
        'quantity' => 5,
    ], $this->admin);

    expect((float) $result->quantity_on_hand)->toBe(0.0);
});

test('created_by es siempre el usuario pasado explícitamente, nunca de un array de datos', function () {
    $item = InventoryItem::factory()->create(['quantity_on_hand' => 10]);
    $otroUsuario = User::factory()->for($this->tenant, 'tenant')->admin()->create();

    (new RegisterInventoryMovementAction)->handle($item, [
        'type' => InventoryMovementType::Entrada->value,
        'quantity' => 1,
        'created_by' => $otroUsuario->id,
    ], $this->admin);

    expect(InventoryMovement::sole()->created_by)->toBe($this->admin->id);
});

test('cantidad menor o igual a 0 lanza error de validación', function (float $quantity) {
    $item = InventoryItem::factory()->create(['quantity_on_hand' => 10]);

    expect(fn () => (new RegisterInventoryMovementAction)->handle($item, [
        'type' => InventoryMovementType::Entrada->value,
        'quantity' => $quantity,
    ], $this->admin))->toThrow(ValidationException::class);

    expect((float) $item->fresh()->quantity_on_hand)->toBe(10.0);
})->with([0.0, -1.0]);

test('type inválido lanza error de validación', function () {
    $item = InventoryItem::factory()->create(['quantity_on_hand' => 10]);

    expect(fn () => (new RegisterInventoryMovementAction)->handle($item, [
        'type' => 'invalido',
        'quantity' => 1,
    ], $this->admin))->toThrow(ValidationException::class);
});
