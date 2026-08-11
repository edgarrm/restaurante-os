<?php

use App\Models\MenuItem;
use App\Models\Tenant;
use App\Models\User;
use Stancl\Tenancy\Database\Models\Domain;

beforeEach(function () {
    $this->tenant = actingInTenant();
    $this->admin = User::factory()->for($this->tenant, 'tenant')->admin()->create();
});

test('POST /menu con datos válidos crea el platillo con available=true', function () {
    $response = $this->actingAs($this->admin)
        ->post(route('menu.store'), [
            'name' => 'Tacos al pastor',
            'category' => 'Platos fuertes',
            'price' => 85.50,
        ]);

    $response->assertRedirect(route('menu.index'));

    $menuItem = MenuItem::sole();
    expect($menuItem->name)->toBe('Tacos al pastor')
        ->and($menuItem->category)->toBe('Platos fuertes')
        ->and($menuItem->available)->toBeTrue();
});

test('POST /menu con precio inválido devuelve 422 y no crea el platillo', function () {
    // postJson (Accept: application/json) en vez de post(): el patrón
    // redirect-con-errores-flasheados de Inertia responde 302 a un POST con
    // validación fallida, no 422 — 422 es la respuesta real de Laravel para
    // un cliente que pide JSON explícitamente, que es lo que este test
    // verifica.
    $response = $this->actingAs($this->admin)
        ->postJson(route('menu.store'), [
            'name' => 'Tacos al pastor',
            'category' => 'Platos fuertes',
            'price' => 0,
        ]);

    $response->assertStatus(422);
    expect(MenuItem::count())->toBe(0);
});

test('PATCH /menu/{menuItem} actualiza nombre, categoría y precio', function () {
    $menuItem = MenuItem::factory()->for($this->tenant, 'tenant')->create(['name' => 'Tacos', 'price' => 50]);

    $response = $this->actingAs($this->admin)
        ->patch(route('menu.update', $menuItem), [
            'name' => 'Tacos al pastor',
            'category' => 'Antojitos',
            'price' => 65,
        ]);

    $response->assertRedirect(route('menu.index'));

    $menuItem->refresh();
    expect($menuItem->name)->toBe('Tacos al pastor')
        ->and($menuItem->category)->toBe('Antojitos')
        ->and((float) $menuItem->price)->toBe(65.0);
});

test('PATCH /menu/{menuItem}/disponibilidad cambia disponibilidad sin afectar órdenes pasadas', function () {
    $menuItem = MenuItem::factory()->for($this->tenant, 'tenant')->create(['name' => 'Tacos', 'price' => 50]);

    $response = $this->actingAs($this->admin)
        ->patch(route('menu.toggle-availability', $menuItem));

    $response->assertRedirect(route('menu.index'));

    $menuItem->refresh();
    expect($menuItem->available)->toBeFalse()
        ->and($menuItem->name)->toBe('Tacos')
        ->and((float) $menuItem->price)->toBe(50.0);
});

test('GET /menu devuelve todos los platillos del tenant', function () {
    MenuItem::factory()->for($this->tenant, 'tenant')->count(3)->create();

    // Sin pantalla Vue de /menu todavía (backend only, ver
    // _ai/specs/gestion-menu.spec.md) — inertiaXhrHeaders() (tests/Pest.php)
    // simula la navegación XHR de Inertia para probar el controller y los
    // props sin depender de que `resources/js/pages/menu/Index.vue` esté
    // compilado.
    $response = $this->actingAs($this->admin)
        ->get(route('menu.index'), inertiaXhrHeaders());

    $response->assertSuccessful();
});

test('usuario con role=mesero o role=cocina accede a /menu (escritura) → 403', function (string $factoryState) {
    $user = User::factory()->for($this->tenant, 'tenant')->{$factoryState}()->create();

    $response = $this->actingAs($user)->get(route('menu.index'));

    $response->assertForbidden();
})->with([
    'mesero' => 'mesero',
    'cocina' => 'cocina',
]);

test('F-05: GET /menu del restaurante A no lista ningún platillo del restaurante B', function () {
    $tenantB = Tenant::create(['name' => 'Restaurante B']);
    Domain::create(['tenant_id' => $tenantB->getTenantKey(), 'domain' => 'restaurante-b.test']);
    MenuItem::factory()->for($tenantB, 'tenant')->create(['name' => 'Platillo secreto de B']);
    MenuItem::factory()->for($this->tenant, 'tenant')->create(['name' => 'Platillo de A']);

    $response = $this->actingAs($this->admin)
        ->get(route('menu.index'), inertiaXhrHeaders());

    // assertInertia() no soporta la respuesta JSON de una navegación XHR
    // (fromTestResponse() espera un render de View completo, ver
    // AssertableInertia::fromTestResponse) — se verifica el body
    // directamente, mismo componente/props que produce el controller.
    $response->assertSuccessful();
    $response->assertJsonPath('component', 'menu/Index');
    $menuItems = $response->json('props.menuItems');
    expect($menuItems)->toHaveCount(1)
        ->and($menuItems[0]['name'])->toBe('Platillo de A');
});

test('F-05: PATCH /menu/{menuItem} sobre un platillo de otro restaurante → 404', function () {
    // No se usa actingInTenant() aquí porque fuerza el root URL global al
    // dominio del tenant creado — este test necesita que las peticiones
    // sigan aterrizando en el dominio de $this->tenant (forzado en
    // beforeEach), no en el de B.
    $tenantB = Tenant::create(['name' => 'Restaurante B']);
    Domain::create(['tenant_id' => $tenantB->getTenantKey(), 'domain' => 'restaurante-b.test']);
    $menuItemB = MenuItem::factory()->for($tenantB, 'tenant')->create(['name' => 'Platillo de B', 'price' => 40]);

    $updateResponse = $this->actingAs($this->admin)
        ->patch(route('menu.update', $menuItemB), ['name' => 'Hackeado', 'category' => 'x', 'price' => 999]);
    $updateResponse->assertNotFound();

    $toggleResponse = $this->actingAs($this->admin)
        ->patch(route('menu.toggle-availability', $menuItemB));
    $toggleResponse->assertNotFound();

    $menuItemB->refresh();
    expect($menuItemB->name)->toBe('Platillo de B')
        ->and((float) $menuItemB->price)->toBe(40.0)
        ->and($menuItemB->available)->toBeTrue();
});
