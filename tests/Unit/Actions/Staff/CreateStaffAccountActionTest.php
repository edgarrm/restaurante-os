<?php

use App\Actions\Staff\CreateStaffAccountAction;
use App\Enums\Role;
use App\Exceptions\Staff\InvalidStaffRoleException;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Stancl\Tenancy\Database\Models\Domain;

beforeEach(function () {
    tenancy()->initialize(actingInTenant());
});

test('crea una cuenta de staff con el role indicado', function (string $role) {
    $user = (new CreateStaffAccountAction)->handle([
        'name' => 'Ana Mesera',
        'email' => 'ana@example.test',
        'password' => 'password-valida-123',
        'role' => $role,
    ]);

    expect($user->name)->toBe('Ana Mesera')
        ->and($user->email)->toBe('ana@example.test')
        ->and($user->role->value)->toBe($role)
        ->and(Hash::check('password-valida-123', $user->password))->toBeTrue();
})->with([
    'mesero' => 'mesero',
    'cocina' => 'cocina',
]);

test('intentar crear una cuenta con role=admin lanza excepción de dominio y no crea nada', function () {
    expect(fn () => (new CreateStaffAccountAction)->handle([
        'name' => 'Falso Admin',
        'email' => 'falso-admin@example.test',
        'password' => 'password-valida-123',
        'role' => 'admin',
    ]))->toThrow(InvalidStaffRoleException::class);

    expect(User::where('email', 'falso-admin@example.test')->exists())->toBeFalse();
});

test('email duplicado lanza excepción de validación', function () {
    User::factory()->mesero()->create(['email' => 'ya-existe@example.test']);

    expect(fn () => (new CreateStaffAccountAction)->handle([
        'name' => 'Otra Persona',
        'email' => 'ya-existe@example.test',
        'password' => 'password-valida-123',
        'role' => 'mesero',
    ]))->toThrow(ValidationException::class);
});

test('F-04: un tenant_id inyectado en el payload se ignora — gana el tenant actual, no el del request', function () {
    $tenantB = Tenant::create(['name' => 'Restaurante B']);
    Domain::create(['tenant_id' => $tenantB->getTenantKey(), 'domain' => 'restaurante-b.test']);
    $tenantActual = tenancy()->tenant->getTenantKey();

    $user = (new CreateStaffAccountAction)->handle([
        'name' => 'Ana Mesera',
        'email' => 'ana-f04@example.test',
        'password' => 'password-valida-123',
        'role' => 'mesero',
        // Vector de F-04 (_ai/docs/threat-model.md): un tenant_id ajeno
        // inyectado en el array de datos. La Action nunca debe leer esta
        // clave — tenant_id lo rellena BelongsToTenant desde el contexto
        // real, no desde $data.
        'tenant_id' => $tenantB->getTenantKey(),
    ]);

    expect($user->tenant_id)->toBe($tenantActual)
        ->and($user->tenant_id)->not->toBe($tenantB->getTenantKey())
        ->and($user->role)->toBe(Role::Mesero);
});
