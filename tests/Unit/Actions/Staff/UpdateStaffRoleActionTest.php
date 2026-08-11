<?php

use App\Actions\Staff\UpdateStaffRoleAction;
use App\Enums\Role;
use App\Exceptions\Staff\InvalidStaffRoleException;
use App\Models\User;

beforeEach(function () {
    tenancy()->initialize(actingInTenant());
});

test('actualiza el role de una cuenta de staff existente', function (string $role) {
    $user = User::factory()->mesero()->create();

    $updated = (new UpdateStaffRoleAction)->handle($user, $role);

    expect($updated->role->value)->toBe($role)
        ->and($user->refresh()->role->value)->toBe($role);
})->with([
    'mesero' => 'mesero',
    'cocina' => 'cocina',
]);

test('intentar cambiar el role a admin lanza excepción de dominio y no modifica la cuenta', function () {
    $user = User::factory()->mesero()->create();

    expect(fn () => (new UpdateStaffRoleAction)->handle($user, 'admin'))
        ->toThrow(InvalidStaffRoleException::class);

    expect($user->refresh()->role)->toBe(Role::Mesero);
});
