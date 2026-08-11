<?php

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Stancl\Tenancy\Database\Models\Domain;

beforeEach(function () {
    $this->tenant = actingInTenant();
    $this->admin = User::factory()->for($this->tenant, 'tenant')->admin()->create();
});

test('POST /staff con datos válidos y role=mesero crea la cuenta', function () {
    $response = $this->actingAs($this->admin)
        ->post(route('staff.store'), [
            'name' => 'Ana Mesera',
            'email' => 'ana@example.test',
            'password' => 'password-valida-123',
            'role' => 'mesero',
        ]);

    $response->assertRedirect(route('staff.index'));

    $staff = User::where('email', 'ana@example.test')->sole();
    expect($staff->role)->toBe(Role::Mesero)
        ->and($staff->tenant_id)->toBe($this->tenant->getTenantKey());
});

test('POST /staff con role=admin devuelve 422 y no crea la cuenta', function () {
    $response = $this->actingAs($this->admin)
        ->postJson(route('staff.store'), [
            'name' => 'Falso Admin',
            'email' => 'falso-admin@example.test',
            'password' => 'password-valida-123',
            'role' => 'admin',
        ]);

    $response->assertStatus(422);
    expect(User::where('email', 'falso-admin@example.test')->exists())->toBeFalse();
});

test('POST /staff con email duplicado devuelve 422', function () {
    User::factory()->for($this->tenant, 'tenant')->mesero()->create(['email' => 'duplicado@example.test']);

    $response = $this->actingAs($this->admin)
        ->postJson(route('staff.store'), [
            'name' => 'Otra Persona',
            'email' => 'duplicado@example.test',
            'password' => 'password-valida-123',
            'role' => 'mesero',
        ]);

    $response->assertStatus(422);
});

test('usuario con role=mesero o role=cocina accede a /staff → 403', function (string $factoryState) {
    $user = User::factory()->for($this->tenant, 'tenant')->{$factoryState}()->create();

    $response = $this->actingAs($user)->get(route('staff.index'));

    $response->assertForbidden();
})->with([
    'mesero' => 'mesero',
    'cocina' => 'cocina',
]);

test('GET /staff devuelve las cuentas de staff del tenant, sin incluir cuentas admin', function () {
    User::factory()->for($this->tenant, 'tenant')->mesero()->create(['name' => 'Mesero Uno']);
    User::factory()->for($this->tenant, 'tenant')->cocina()->create(['name' => 'Cocinero Uno']);

    // Sin pantalla Vue de /staff todavía (backend only, ver
    // _ai/specs/gestion-staff.spec.md) — inertiaXhrHeaders() (tests/Pest.php)
    // simula la navegación XHR de Inertia.
    $response = $this->actingAs($this->admin)
        ->get(route('staff.index'), inertiaXhrHeaders());

    $response->assertSuccessful();
    $response->assertJsonPath('component', 'staff/Index');
    $names = collect($response->json('props.staff'))->pluck('name');
    expect($names)->toContain('Mesero Uno', 'Cocinero Uno')
        ->and($names)->not->toContain($this->admin->name);
});

test('PATCH /staff/{user} cambia el role de una cuenta existente', function () {
    $user = User::factory()->for($this->tenant, 'tenant')->mesero()->create();

    $response = $this->actingAs($this->admin)
        ->patch(route('staff.update', $user), ['role' => 'cocina']);

    $response->assertRedirect(route('staff.index'));
    expect($user->refresh()->role)->toBe(Role::Cocina);
});

test('PATCH /staff/{user} con role=admin devuelve 422 y no modifica la cuenta', function () {
    $user = User::factory()->for($this->tenant, 'tenant')->mesero()->create();

    $response = $this->actingAs($this->admin)
        ->patchJson(route('staff.update', $user), ['role' => 'admin']);

    $response->assertStatus(422);
    expect($user->refresh()->role)->toBe(Role::Mesero);
});

test('PATCH /staff/{user}/desactivar desactiva la cuenta sin eliminarla', function () {
    $user = User::factory()->for($this->tenant, 'tenant')->mesero()->create();

    $response = $this->actingAs($this->admin)
        ->patch(route('staff.deactivate', $user));

    $response->assertRedirect(route('staff.index'));
    expect($user->refresh()->is_active)->toBeFalse();
    expect(User::find($user->id))->not->toBeNull();
});

test('una cuenta desactivada no puede iniciar sesión', function () {
    $user = User::factory()->for($this->tenant, 'tenant')->mesero()->inactive()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
});

test('F-04: POST /staff con tenant_id inyectado en el payload lo ignora — se crea bajo el tenant del admin autenticado', function () {
    $tenantB = Tenant::create(['name' => 'Restaurante B']);
    Domain::create(['tenant_id' => $tenantB->getTenantKey(), 'domain' => 'restaurante-b.test']);

    $response = $this->actingAs($this->admin)
        ->post(route('staff.store'), [
            'name' => 'Ana Mesera',
            'email' => 'ana-f04@example.test',
            'password' => 'password-valida-123',
            'role' => 'mesero',
            'tenant_id' => $tenantB->getTenantKey(),
        ]);

    $response->assertRedirect(route('staff.index'));

    $staff = User::where('email', 'ana-f04@example.test')->sole();
    expect($staff->tenant_id)->toBe($this->tenant->getTenantKey())
        ->and($staff->tenant_id)->not->toBe($tenantB->getTenantKey());
});

test('F-05: PATCH /staff/{user} sobre una cuenta de otro restaurante → 404', function () {
    // No se usa actingInTenant() aquí porque fuerza el root URL global al
    // dominio del tenant creado — este test necesita que las peticiones
    // sigan aterrizando en el dominio de $this->tenant (forzado en
    // beforeEach), no en el de B.
    $tenantB = Tenant::create(['name' => 'Restaurante B']);
    Domain::create(['tenant_id' => $tenantB->getTenantKey(), 'domain' => 'restaurante-b.test']);
    $userB = User::factory()->for($tenantB, 'tenant')->mesero()->create();

    $updateResponse = $this->actingAs($this->admin)
        ->patch(route('staff.update', $userB), ['role' => 'cocina']);
    $updateResponse->assertNotFound();

    $deactivateResponse = $this->actingAs($this->admin)
        ->patch(route('staff.deactivate', $userB));
    $deactivateResponse->assertNotFound();

    $userB->refresh();
    expect($userB->role)->toBe(Role::Mesero)
        ->and($userB->is_active)->toBeTrue();
});
