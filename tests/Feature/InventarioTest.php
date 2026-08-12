<?php

use App\Enums\InventoryMovementType;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Tenant;
use App\Models\User;
use Stancl\Tenancy\Database\Models\Domain;

beforeEach(function () {
    $this->tenant = actingInTenant();
    $this->admin = User::factory()->for($this->tenant, 'tenant')->admin()->create();
});

test('GET /inventario devuelve la lista de insumos del tenant actual', function () {
    InventoryItem::factory()->for($this->tenant, 'tenant')->create(['name' => 'Tomate']);

    $response = $this->actingAs($this->admin)
        ->get(route('inventario.index'), inertiaXhrHeaders());

    $response->assertOk();
    expect(collect($response->json('props.items'))->pluck('name'))->toContain('Tomate');
});

test('POST /inventario con datos válidos crea el insumo', function () {
    $response = $this->actingAs($this->admin)->post(route('inventario.store'), [
        'name' => 'Tomate',
        'unit' => 'kg',
        'low_stock_threshold' => 5,
        'quantity_on_hand' => 20,
    ]);

    $response->assertRedirect(route('inventario.index'));
    $item = InventoryItem::sole();
    expect($item->name)->toBe('Tomate')
        ->and((float) $item->quantity_on_hand)->toBe(20.0);
});

test('POST /inventario/{item}/ajustar con entrada incrementa el stock', function () {
    $item = InventoryItem::factory()->for($this->tenant, 'tenant')->create(['quantity_on_hand' => 10]);

    $response = $this->actingAs($this->admin)->post(route('inventario.adjust', $item), [
        'type' => InventoryMovementType::Entrada->value,
        'quantity' => 5,
    ]);

    $response->assertRedirect(route('inventario.index'));
    expect((float) $item->fresh()->quantity_on_hand)->toBe(15.0)
        ->and(InventoryMovement::count())->toBe(1);
});

test('POST /inventario/{item}/ajustar con salida que excede el stock devuelve 422 y no muta el insumo', function () {
    $item = InventoryItem::factory()->for($this->tenant, 'tenant')->create(['quantity_on_hand' => 3, 'name' => 'Tomate', 'unit' => 'kg']);

    $response = $this->actingAs($this->admin)->postJson(route('inventario.adjust', $item), [
        'type' => InventoryMovementType::Salida->value,
        'quantity' => 5,
    ]);

    $response->assertStatus(422);
    expect($response->json('errors.quantity.0'))
        ->toBe("No hay stock suficiente de 'Tomate' para esta salida (disponible: 3.000 kg).")
        ->and((float) $item->fresh()->quantity_on_hand)->toBe(3.0)
        ->and(InventoryMovement::count())->toBe(0);
});

test('usuario con role=mesero accede a /inventario → 403', function () {
    $mesero = User::factory()->for($this->tenant, 'tenant')->mesero()->create();

    $response = $this->actingAs($mesero)->get(route('inventario.index'));

    $response->assertForbidden();
});

test('usuario con role=cocina accede a /inventario → 403', function () {
    $cocina = User::factory()->for($this->tenant, 'tenant')->cocina()->create();

    $response = $this->actingAs($cocina)->get(route('inventario.index'));

    $response->assertForbidden();
});

test('un created_by enviado en el body es ignorado — se usa el usuario autenticado', function () {
    $item = InventoryItem::factory()->for($this->tenant, 'tenant')->create(['quantity_on_hand' => 10]);
    $otroAdmin = User::factory()->for($this->tenant, 'tenant')->admin()->create();

    $this->actingAs($this->admin)->post(route('inventario.adjust', $item), [
        'type' => InventoryMovementType::Entrada->value,
        'quantity' => 1,
        'created_by' => $otroAdmin->id,
    ]);

    expect(InventoryMovement::sole()->created_by)->toBe($this->admin->id);
});

test('F-05: admin del tenant A no puede crear ni ajustar insumos del tenant B', function () {
    $tenantB = Tenant::create(['name' => 'Restaurante B']);
    Domain::create(['tenant_id' => $tenantB->getTenantKey(), 'domain' => 'restaurante-b.test']);
    $itemB = InventoryItem::factory()->for($tenantB, 'tenant')->create();

    $response = $this->actingAs($this->admin)
        ->get(route('inventario.index'), inertiaXhrHeaders());
    $response->assertOk();
    expect(collect($response->json('props.items'))->pluck('id'))->not->toContain($itemB->id);

    $ajustar = $this->actingAs($this->admin)->post(route('inventario.adjust', $itemB), [
        'type' => InventoryMovementType::Entrada->value,
        'quantity' => 1,
    ]);
    $ajustar->assertNotFound();

    expect((float) $itemB->fresh()->quantity_on_hand)->toBe((float) $itemB->quantity_on_hand);
});
