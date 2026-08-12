<?php

use App\Enums\OrderStatus;
use App\Enums\TableStatus;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\User;
use Stancl\Tenancy\Database\Models\Domain;

beforeEach(function () {
    $this->tenant = actingInTenant();
    $this->mesero = User::factory()->for($this->tenant, 'tenant')->mesero()->create();
});

test('mesero visita /mesas/{mesa_libre}/pedido → la mesa queda ocupada y existe una orden abierta', function () {
    $table = Table::factory()->for($this->tenant, 'tenant')->create(['status' => TableStatus::Libre]);

    $response = $this->actingAs($this->mesero)
        ->get(route('pedido.show', $table), inertiaXhrHeaders());

    $response->assertOk();
    expect($table->fresh()->status)->toBe(TableStatus::Ocupada)
        ->and(Order::where('table_id', $table->id)->where('status', OrderStatus::Abierta)->exists())->toBeTrue();
});

test('mesero visita /mesas/{mesa_por_cobrar}/pedido → redirige a /cobro con un aviso', function () {
    $table = Table::factory()->for($this->tenant, 'tenant')->create(['status' => TableStatus::PorCobrar]);
    $order = Order::factory()->for($this->tenant, 'tenant')->for($table)->create(['opened_by' => $this->mesero->id, 'status' => OrderStatus::PorCobrar]);
    OrderItem::factory()->for($order)->for(MenuItem::factory()->for($this->tenant, 'tenant'))->create();

    $response = $this->actingAs($this->mesero)->get(route('pedido.show', $table));

    $response->assertRedirect(route('cobro.show', $table));
    $response->assertInertiaFlash('notice', 'Esta mesa ya solicitó la cuenta — no se pueden agregar más platillos.');
});

test('POST /mesas/{table}/pedido/items con un ítem disponible agrega el ítem con la cantidad correcta', function () {
    $table = Table::factory()->for($this->tenant, 'tenant')->create(['status' => TableStatus::Libre]);
    $menuItem = MenuItem::factory()->for($this->tenant, 'tenant')->create(['price' => 65.00]);

    $response = $this->actingAs($this->mesero)
        ->post(route('pedido.add-item', $table), ['menu_item_id' => $menuItem->id, 'quantity' => 2]);

    $response->assertRedirect(route('pedido.show', $table));

    $orderItem = OrderItem::sole();
    expect($orderItem->menu_item_id)->toBe($menuItem->id)
        ->and($orderItem->quantity)->toBe(2)
        ->and((float) $orderItem->unit_price)->toBe(65.00);
});

test('POST /mesas/{table}/pedido/items con un ítem no disponible devuelve 422 con el mensaje del spec', function () {
    $table = Table::factory()->for($this->tenant, 'tenant')->create(['status' => TableStatus::Ocupada]);
    Order::factory()->for($this->tenant, 'tenant')->for($table)->create(['opened_by' => $this->mesero->id]);
    $menuItem = MenuItem::factory()->for($this->tenant, 'tenant')->create();
    $menuItem->forceFill(['available' => false])->save();

    $response = $this->actingAs($this->mesero)
        ->postJson(route('pedido.add-item', $table), ['menu_item_id' => $menuItem->id, 'quantity' => 1]);

    // ValidationException (no abort() plano) — así el cliente Inertia real
    // recibe el redirect 302 + errores flasheados que espera en vez de un
    // modal con HTML crudo (ver .ai/rules/feature.md y OrderController).
    // postJson fuerza Accept: application/json, así que aquí sí vemos el
    // 422 JSON estándar de ValidationException.
    $response->assertStatus(422);
    expect($response->json('errors.menu_item_id.0'))->toBe('Este platillo ya no está disponible.')
        ->and(OrderItem::count())->toBe(0);
});

test('POST /mesas/{table}/pedido/enviar con ítems marca la orden enviada_cocina', function () {
    $table = Table::factory()->for($this->tenant, 'tenant')->create(['status' => TableStatus::Ocupada]);
    $order = Order::factory()->for($this->tenant, 'tenant')->for($table)->create(['opened_by' => $this->mesero->id]);
    OrderItem::factory()->for($order)->for(MenuItem::factory()->for($this->tenant, 'tenant'))->create();

    $response = $this->actingAs($this->mesero)
        ->post(route('pedido.send', $table));

    $response->assertRedirect(route('pedido.show', $table));
    expect($order->fresh()->status)->toBe(OrderStatus::EnviadaCocina);
});

test('POST /mesas/{table}/pedido/enviar sin ítems devuelve 422', function () {
    $table = Table::factory()->for($this->tenant, 'tenant')->create(['status' => TableStatus::Ocupada]);
    $order = Order::factory()->for($this->tenant, 'tenant')->for($table)->create(['opened_by' => $this->mesero->id]);

    $response = $this->actingAs($this->mesero)
        ->postJson(route('pedido.send', $table));

    $response->assertStatus(422);
    expect($response->json('errors.items.0'))->toBe('Agrega al menos un platillo antes de enviar a cocina.')
        ->and($order->fresh()->status)->toBe(OrderStatus::Abierta);
});

test('PATCH /mesas/{table}/pedido/items/{orderItem} ajusta la cantidad', function () {
    $table = Table::factory()->for($this->tenant, 'tenant')->create(['status' => TableStatus::Ocupada]);
    $order = Order::factory()->for($this->tenant, 'tenant')->for($table)->create(['opened_by' => $this->mesero->id]);
    $orderItem = OrderItem::factory()->for($order)->for(MenuItem::factory()->for($this->tenant, 'tenant'))->create(['quantity' => 1]);

    $response = $this->actingAs($this->mesero)
        ->patch(route('pedido.update-item', [$table, $orderItem]), ['quantity' => 4]);

    $response->assertRedirect(route('pedido.show', $table));
    expect($orderItem->fresh()->quantity)->toBe(4);
});

test('PATCH /mesas/{table}/pedido/items/{orderItem} con quantity=0 elimina el renglón', function () {
    $table = Table::factory()->for($this->tenant, 'tenant')->create(['status' => TableStatus::Ocupada]);
    $order = Order::factory()->for($this->tenant, 'tenant')->for($table)->create(['opened_by' => $this->mesero->id]);
    $orderItem = OrderItem::factory()->for($order)->for(MenuItem::factory()->for($this->tenant, 'tenant'))->create(['quantity' => 1]);

    $response = $this->actingAs($this->mesero)
        ->patch(route('pedido.update-item', [$table, $orderItem]), ['quantity' => 0]);

    $response->assertRedirect(route('pedido.show', $table));
    expect(OrderItem::count())->toBe(0);
});

test('PATCH /mesas/{table}/pedido/items/{orderItem} sobre una orden ya enviada a cocina devuelve 422', function () {
    $table = Table::factory()->for($this->tenant, 'tenant')->create(['status' => TableStatus::Ocupada]);
    $order = Order::factory()->for($this->tenant, 'tenant')->for($table)->create(['opened_by' => $this->mesero->id, 'status' => OrderStatus::EnviadaCocina]);
    $orderItem = OrderItem::factory()->for($order)->for(MenuItem::factory()->for($this->tenant, 'tenant'))->create(['quantity' => 1]);

    $response = $this->actingAs($this->mesero)
        ->patchJson(route('pedido.update-item', [$table, $orderItem]), ['quantity' => 2]);

    $response->assertStatus(422);
    expect($response->json('errors.quantity.0'))->toBe('Esta orden ya fue enviada a cocina — no se pueden modificar sus cantidades.')
        ->and($orderItem->fresh()->quantity)->toBe(1);
});

test('F-05: ajustar un OrderItem de una mesa de otro restaurante devuelve 404', function () {
    $tenantB = Tenant::create(['name' => 'Restaurante B']);
    Domain::create(['tenant_id' => $tenantB->getTenantKey(), 'domain' => 'restaurante-b.test']);
    $tableB = Table::factory()->for($tenantB, 'tenant')->create(['status' => TableStatus::Ocupada]);
    $meseroB = User::factory()->for($tenantB, 'tenant')->mesero()->create();
    $orderB = Order::factory()->for($tenantB, 'tenant')->for($tableB)->create(['opened_by' => $meseroB->id]);
    $orderItemB = OrderItem::factory()->for($orderB)->for(MenuItem::factory()->for($tenantB, 'tenant'))->create();

    $table = Table::factory()->for($this->tenant, 'tenant')->create(['status' => TableStatus::Ocupada]);

    $response = $this->actingAs($this->mesero)
        ->patch(route('pedido.update-item', [$table, $orderItemB]), ['quantity' => 2]);

    $response->assertNotFound();
    expect($orderItemB->fresh()->quantity)->toBe($orderItemB->quantity);
});

test('usuario con role=cocina accede a /mesas/{table}/pedido → 403', function () {
    $cocina = User::factory()->for($this->tenant, 'tenant')->cocina()->create();
    $table = Table::factory()->for($this->tenant, 'tenant')->create();

    $response = $this->actingAs($cocina)->get(route('pedido.show', $table));

    $response->assertForbidden();
});

test('F-05: mesero del restaurante A pide /mesas/{mesa_del_restaurante_B}/pedido → 404', function () {
    // No se usa actingInTenant() aquí porque fuerza el root URL global al
    // dominio del tenant creado — este test necesita que las peticiones
    // sigan aterrizando en el dominio de $this->tenant (forzado en
    // beforeEach), no en el de B.
    $tenantB = Tenant::create(['name' => 'Restaurante B']);
    Domain::create(['tenant_id' => $tenantB->getTenantKey(), 'domain' => 'restaurante-b.test']);
    $tableB = Table::factory()->for($tenantB, 'tenant')->create(['name' => 'Mesa de B']);

    $response = $this->actingAs($this->mesero)
        ->get(route('pedido.show', $tableB), inertiaXhrHeaders());

    $response->assertNotFound();
});

test('F-05: agregar un menu_item_id de otro restaurante falla y no se agrega a la orden', function () {
    $tenantB = Tenant::create(['name' => 'Restaurante B']);
    Domain::create(['tenant_id' => $tenantB->getTenantKey(), 'domain' => 'restaurante-b.test']);
    $menuItemB = MenuItem::factory()->for($tenantB, 'tenant')->create(['name' => 'Platillo de B']);

    $table = Table::factory()->for($this->tenant, 'tenant')->create(['status' => TableStatus::Libre]);

    $response = $this->actingAs($this->mesero)
        ->post(route('pedido.add-item', $table), ['menu_item_id' => $menuItemB->id, 'quantity' => 1]);

    $response->assertNotFound();
    expect(OrderItem::count())->toBe(0);
});
