<?php

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\User;
use Stancl\Tenancy\Database\Models\Domain;

beforeEach(function () {
    $this->tenant = actingInTenant();
    $this->cocina = User::factory()->for($this->tenant, 'tenant')->cocina()->create();
    $this->mesero = User::factory()->for($this->tenant, 'tenant')->mesero()->create();
});

test('GET /cocina devuelve solo ítems de órdenes en enviada_cocina', function () {
    $table = Table::factory()->for($this->tenant, 'tenant')->create();

    $orderEnviada = Order::factory()->for($this->tenant, 'tenant')->for($table)->create([
        'opened_by' => $this->mesero->id,
        'status' => OrderStatus::EnviadaCocina,
    ]);
    OrderItem::factory()->for($orderEnviada)->for(MenuItem::factory()->for($this->tenant, 'tenant'))->create();

    $orderAbierta = Order::factory()->for($this->tenant, 'tenant')->for($table)->create([
        'opened_by' => $this->mesero->id,
        'status' => OrderStatus::Abierta,
    ]);
    OrderItem::factory()->for($orderAbierta)->for(MenuItem::factory()->for($this->tenant, 'tenant'))->create();

    $response = $this->actingAs($this->cocina)
        ->get(route('cocina.index'), inertiaXhrHeaders());

    $response->assertOk();
    $orderIds = collect($response->json('props.orders'))->pluck('id');
    expect($orderIds)->toContain($orderEnviada->id)
        ->and($orderIds)->not->toContain($orderAbierta->id);
});

test('PATCH /cocina/items/{orderItem}/listo marca el ítem como listo', function () {
    $table = Table::factory()->for($this->tenant, 'tenant')->create();
    $order = Order::factory()->for($this->tenant, 'tenant')->for($table)->create([
        'opened_by' => $this->mesero->id,
        'status' => OrderStatus::EnviadaCocina,
    ]);
    $orderItem = OrderItem::factory()->for($order)->for(MenuItem::factory()->for($this->tenant, 'tenant'))
        ->create(['status' => OrderItemStatus::Pendiente]);

    $response = $this->actingAs($this->cocina)
        ->patch(route('cocina.items.mark-ready', $orderItem));

    $response->assertRedirect(route('cocina.index'));
    expect($orderItem->fresh()->status)->toBe(OrderItemStatus::Listo);
});

test('PATCH /cocina/items/{orderItem}/listo sobre un ítem ya listo es idempotente', function () {
    $table = Table::factory()->for($this->tenant, 'tenant')->create();
    $order = Order::factory()->for($this->tenant, 'tenant')->for($table)->create([
        'opened_by' => $this->mesero->id,
        'status' => OrderStatus::EnviadaCocina,
    ]);
    $orderItem = OrderItem::factory()->for($order)->for(MenuItem::factory()->for($this->tenant, 'tenant'))
        ->create(['status' => OrderItemStatus::Listo]);

    $response = $this->actingAs($this->cocina)
        ->patch(route('cocina.items.mark-ready', $orderItem));

    $response->assertRedirect(route('cocina.index'));
    expect($orderItem->fresh()->status)->toBe(OrderItemStatus::Listo);
});

test('GET /cocina incluye órdenes en lista dentro de completedOrders, no de orders', function () {
    $table = Table::factory()->for($this->tenant, 'tenant')->create();

    $orderLista = Order::factory()->for($this->tenant, 'tenant')->for($table)->create([
        'opened_by' => $this->mesero->id,
        'status' => OrderStatus::Lista,
    ]);
    OrderItem::factory()->for($orderLista)->for(MenuItem::factory()->for($this->tenant, 'tenant'))
        ->create(['status' => OrderItemStatus::Listo]);

    $response = $this->actingAs($this->cocina)
        ->get(route('cocina.index'), inertiaXhrHeaders());

    $response->assertOk();
    $orderIds = collect($response->json('props.orders'))->pluck('id');
    $completedIds = collect($response->json('props.completedOrders'))->pluck('id');
    expect($orderIds)->not->toContain($orderLista->id)
        ->and($completedIds)->toContain($orderLista->id);
});

test('usuario con role=mesero accede a /cocina → 403', function () {
    $response = $this->actingAs($this->mesero)->get(route('cocina.index'));

    $response->assertForbidden();
});

test('F-05: PATCH /cocina/items/{orderItem}/listo sobre un ítem de otro restaurante → 404 y no lo modifica', function () {
    $tenantB = Tenant::create(['name' => 'Restaurante B']);
    Domain::create(['tenant_id' => $tenantB->getTenantKey(), 'domain' => 'restaurante-b.test']);
    $tableB = Table::factory()->for($tenantB, 'tenant')->create();
    $meseroB = User::factory()->for($tenantB, 'tenant')->mesero()->create();
    $orderB = Order::factory()->for($tenantB, 'tenant')->for($tableB)->create([
        'opened_by' => $meseroB->id,
        'status' => OrderStatus::EnviadaCocina,
    ]);
    $itemB = OrderItem::factory()->for($orderB)->for(MenuItem::factory()->for($tenantB, 'tenant'))
        ->create(['status' => OrderItemStatus::Pendiente]);

    $response = $this->actingAs($this->cocina)
        ->patch(route('cocina.items.mark-ready', $itemB));

    $response->assertNotFound();
    expect($itemB->fresh()->status)->toBe(OrderItemStatus::Pendiente);
});

test('F-05: GET /cocina del restaurante A no incluye pedidos del restaurante B', function () {
    $tenantB = Tenant::create(['name' => 'Restaurante B']);
    Domain::create(['tenant_id' => $tenantB->getTenantKey(), 'domain' => 'restaurante-b.test']);
    $tableB = Table::factory()->for($tenantB, 'tenant')->create();
    $meseroB = User::factory()->for($tenantB, 'tenant')->mesero()->create();
    $orderB = Order::factory()->for($tenantB, 'tenant')->for($tableB)->create([
        'opened_by' => $meseroB->id,
        'status' => OrderStatus::EnviadaCocina,
    ]);
    OrderItem::factory()->for($orderB)->for(MenuItem::factory()->for($tenantB, 'tenant'))->create();

    $orderListaB = Order::factory()->for($tenantB, 'tenant')->for($tableB)->create([
        'opened_by' => $meseroB->id,
        'status' => OrderStatus::Lista,
    ]);
    OrderItem::factory()->for($orderListaB)->for(MenuItem::factory()->for($tenantB, 'tenant'))
        ->create(['status' => OrderItemStatus::Listo]);

    $response = $this->actingAs($this->cocina)
        ->get(route('cocina.index'), inertiaXhrHeaders());

    $response->assertOk();
    $orderIds = collect($response->json('props.orders'))->pluck('id');
    $completedIds = collect($response->json('props.completedOrders'))->pluck('id');
    expect($orderIds)->not->toContain($orderB->id)
        ->and($completedIds)->not->toContain($orderListaB->id);
});
