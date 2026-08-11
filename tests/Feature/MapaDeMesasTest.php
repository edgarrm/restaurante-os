<?php

use App\Enums\TableStatus;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\User;
use Stancl\Tenancy\Database\Models\Domain;

beforeEach(function () {
    $this->tenant = actingInTenant();
    $this->admin = User::factory()->for($this->tenant, 'tenant')->admin()->create();
});

test('GET /mesas devuelve todas las mesas con su status actual', function (string $factoryState) {
    $user = User::factory()->for($this->tenant, 'tenant')->{$factoryState}()->create();
    $libre = Table::factory()->for($this->tenant, 'tenant')->create(['name' => 'Mesa 1', 'status' => TableStatus::Libre]);
    $ocupada = Table::factory()->for($this->tenant, 'tenant')->create(['name' => 'Mesa 2', 'status' => TableStatus::Ocupada]);

    $response = $this->actingAs($user)
        ->get(route('mesas.index'), inertiaXhrHeaders());

    $response->assertOk();
    $tables = collect($response->json('props.tables'));
    expect($tables)->toHaveCount(2)
        ->and($tables->firstWhere('id', $libre->id)['status'])->toBe(TableStatus::Libre->value)
        ->and($tables->firstWhere('id', $ocupada->id)['status'])->toBe(TableStatus::Ocupada->value);
})->with([
    'admin' => 'admin',
    'mesero' => 'mesero',
]);

test('GET /mesas con cero mesas devuelve lista vacía sin error', function () {
    $mesero = User::factory()->for($this->tenant, 'tenant')->mesero()->create();

    $response = $this->actingAs($mesero)
        ->get(route('mesas.index'), inertiaXhrHeaders());

    $response->assertOk();
    expect($response->json('props.tables'))->toBe([]);
});

test('usuario con role=cocina accede a /mesas → 403', function () {
    $cocina = User::factory()->for($this->tenant, 'tenant')->cocina()->create();

    $response = $this->actingAs($cocina)->get(route('mesas.index'));

    $response->assertForbidden();
});

test('F-05: GET /mesas del restaurante A no incluye mesas del restaurante B', function () {
    // No se usa actingInTenant() aquí por la misma razón documentada en
    // GestionMesasTest.php: forzaría el root URL global al dominio del
    // tenant B, y este test necesita seguir aterrizando en el dominio de
    // $this->tenant.
    $tenantB = Tenant::create(['name' => 'Restaurante B']);
    Domain::create(['tenant_id' => $tenantB->getTenantKey(), 'domain' => 'restaurante-b.test']);
    Table::factory()->for($tenantB, 'tenant')->create(['name' => 'Mesa de B']);

    $mesaA = Table::factory()->for($this->tenant, 'tenant')->create(['name' => 'Mesa de A']);

    $response = $this->actingAs($this->admin)
        ->get(route('mesas.index'), inertiaXhrHeaders());

    $response->assertOk();
    $tables = collect($response->json('props.tables'));
    expect($tables)->toHaveCount(1)
        ->and($tables->first()['id'])->toBe($mesaA->id)
        ->and($tables->pluck('name'))->not->toContain('Mesa de B');
});
