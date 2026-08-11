<?php

use App\Actions\Tenants\OnboardTenantAction;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * @return array{restaurant_name: string, subdomain: string, admin_name: string, admin_email: string, admin_password: string}
 */
function validOnboardingData(array $overrides = []): array
{
    return array_merge([
        'restaurant_name' => 'El Ancla',
        'subdomain' => 'elancla',
        'admin_name' => 'Ada Admin',
        'admin_email' => 'ada@elancla.test',
        'admin_password' => 'password-segura-123',
    ], $overrides);
}

test('crea el Tenant, Domain y User admin con los datos correctos', function () {
    $user = (new OnboardTenantAction)->handle(validOnboardingData());

    expect(Tenant::count())->toBe(1)
        ->and(Domain::count())->toBe(1)
        ->and(User::count())->toBe(1);

    $domain = Domain::sole();
    expect($domain->domain)->toBe('elancla')
        ->and($domain->tenant_id)->toBe($user->tenant_id);

    expect($user->name)->toBe('Ada Admin')
        ->and($user->email)->toBe('ada@elancla.test')
        ->and(Hash::check('password-segura-123', $user->password))->toBeTrue();
});

test('el User creado tiene role=admin y el tenant_id del tenant recién creado', function () {
    $otroTenant = Tenant::create(['name' => 'Otro restaurante ya existente']);

    $user = (new OnboardTenantAction)->handle(validOnboardingData());

    expect($user->role)->toBe(Role::Admin)
        ->and($user->tenant_id)->toBe(Domain::sole()->tenant_id)
        ->and($user->tenant_id)->not->toBe($otroTenant->id);
});

test('normaliza el subdominio a minúsculas', function () {
    (new OnboardTenantAction)->handle(validOnboardingData(['subdomain' => 'ElAncla']));

    expect(Domain::sole()->domain)->toBe('elancla');
});

test('rechaza subdominio con caracteres inválidos para DNS y no crea nada', function () {
    expect(fn () => (new OnboardTenantAction)->handle(validOnboardingData(['subdomain' => 'el ancla!'])))
        ->toThrow(ValidationException::class);

    expect(Tenant::count())->toBe(0)
        ->and(Domain::count())->toBe(0)
        ->and(User::count())->toBe(0);
});

test('rechaza nombre de restaurante vacío', function () {
    expect(fn () => (new OnboardTenantAction)->handle(validOnboardingData(['restaurant_name' => ''])))
        ->toThrow(ValidationException::class);

    expect(Tenant::count())->toBe(0);
});

test('subdominio duplicado no crea ninguna entidad', function () {
    (new OnboardTenantAction)->handle(validOnboardingData());

    expect(fn () => (new OnboardTenantAction)->handle(validOnboardingData([
        'admin_email' => 'otro-admin@elancla.test',
    ])))->toThrow(ValidationException::class);

    expect(Tenant::count())->toBe(1)
        ->and(Domain::count())->toBe(1)
        ->and(User::count())->toBe(1);
});

test('falla a mitad de la operación no deja registros huérfanos', function () {
    $tenantExistente = Tenant::create(['name' => 'Restaurante existente']);
    User::factory()->for($tenantExistente, 'tenant')->create([
        'email' => 'ya-existe@elancla.test',
    ]);

    expect(fn () => (new OnboardTenantAction)->handle(validOnboardingData([
        'subdomain' => 'restaurantenuevo',
        'admin_email' => 'ya-existe@elancla.test',
    ])))->toThrow(QueryException::class);

    // La transacción debe revertir el Tenant y el Domain del intento fallido:
    // solo debe sobrevivir el tenant/usuario que ya existía antes de correr la Action.
    expect(Domain::where('domain', 'restaurantenuevo')->exists())->toBeFalse()
        ->and(Tenant::count())->toBe(1)
        ->and(User::count())->toBe(1);
});
