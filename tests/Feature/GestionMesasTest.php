<?php

use App\Enums\OrderStatus;
use App\Enums\TableStatus;
use App\Models\Order;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\User;
use Stancl\Tenancy\Database\Models\Domain;

beforeEach(function () {
    $this->tenant = actingInTenant();
    $this->admin = User::factory()->for($this->tenant, 'tenant')->admin()->create();
});

test('POST /mesas/gestion con datos válidos crea la mesa con status=libre', function () {
    $response = $this->actingAs($this->admin)
        ->post(route('tables.store'), ['name' => 'Mesa 4', 'capacity' => 4]);

    $response->assertRedirect(route('tables.index'));

    $table = Table::sole();
    expect($table->name)->toBe('Mesa 4')
        ->and($table->capacity)->toBe(4)
        ->and($table->status)->toBe(TableStatus::Libre);
});

test('PATCH /mesas/gestion/{table} actualiza nombre y capacidad', function () {
    $table = Table::factory()->for($this->tenant, 'tenant')->create(['name' => 'Mesa 1', 'capacity' => 2]);

    $response = $this->actingAs($this->admin)
        ->patch(route('tables.update', $table), ['name' => 'Mesa Terraza 1', 'capacity' => 6]);

    $response->assertRedirect(route('tables.index'));

    $table->refresh();
    expect($table->name)->toBe('Mesa Terraza 1')
        ->and($table->capacity)->toBe(6);
});

test('DELETE /mesas/gestion/{table} con orden activa devuelve 422 y no elimina la mesa', function (OrderStatus $status) {
    $table = Table::factory()->for($this->tenant, 'tenant')->create();
    Order::factory()->for($this->tenant, 'tenant')->for($table)->create([
        'opened_by' => $this->admin->id,
        'status' => $status,
    ]);

    // deleteJson fuerza Accept: application/json, así que aquí sí vemos el
    // 422 JSON estándar de ValidationException (no abort() plano) — mismo
    // criterio que OrderController/PaymentController (ver
    // .ai/rules/feature.md).
    $response = $this->actingAs($this->admin)
        ->deleteJson(route('tables.destroy', $table));

    $response->assertStatus(422);
    expect($response->json('errors.name.0'))->toBe('No se puede eliminar una mesa con una cuenta activa.')
        ->and(Table::find($table->id))->not->toBeNull();
})->with([
    'abierta' => OrderStatus::Abierta,
    'enviada_cocina' => OrderStatus::EnviadaCocina,
]);

test('DELETE /mesas/gestion/{table} sin órdenes activas elimina la mesa', function () {
    $table = Table::factory()->for($this->tenant, 'tenant')->create();

    $response = $this->actingAs($this->admin)
        ->delete(route('tables.destroy', $table));

    $response->assertRedirect(route('tables.index'));
    expect(Table::find($table->id))->toBeNull();
});

test('usuario con role=mesero o role=cocina accede a /mesas/gestion → 403', function (string $factoryState) {
    $user = User::factory()->for($this->tenant, 'tenant')->{$factoryState}()->create();

    $response = $this->actingAs($user)->get(route('tables.index'));

    $response->assertForbidden();
})->with([
    'mesero' => 'mesero',
    'cocina' => 'cocina',
]);

test('F-05: admin del restaurante A no puede editar ni eliminar una mesa del restaurante B', function () {
    // No se usa actingInTenant() aquí porque fuerza el root URL global al
    // dominio del tenant creado — este test necesita que las peticiones
    // sigan aterrizando en el dominio de $this->tenant (forzado en
    // beforeEach), no en el de B.
    $tenantB = Tenant::create(['name' => 'Restaurante B']);
    Domain::create(['tenant_id' => $tenantB->getTenantKey(), 'domain' => 'restaurante-b.test']);
    $tableB = Table::factory()->for($tenantB, 'tenant')->create(['name' => 'Mesa de B', 'capacity' => 4]);

    $updateResponse = $this->actingAs($this->admin)
        ->patch(route('tables.update', $tableB), ['name' => 'Hackeada', 'capacity' => 99]);
    $updateResponse->assertNotFound();

    $deleteResponse = $this->actingAs($this->admin)
        ->delete(route('tables.destroy', $tableB));
    $deleteResponse->assertNotFound();

    $tableB->refresh();
    expect($tableB->name)->toBe('Mesa de B')
        ->and($tableB->capacity)->toBe(4);
});
