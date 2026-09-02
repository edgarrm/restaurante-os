<?php

use App\Actions\Fortify\AuthorizePasskeyLogin;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Passkeys\Passkey;

// F-01/F-05 (_ai/specs/passkeys.spec.md, PASO 0): este closure es lo único
// que evita un TypeError sin control cuando `$passkey->user` resuelve null
// (passkey de otro tenant, ver TenantScope sobre User) — ver también
// tests/Unit/PasskeyCrossTenantScopeTest.php para la pieza que produce ese
// `null` en primer lugar.
beforeEach(function () {
    tenancy()->initialize(actingInTenant());
});

test('niega el login cuando el usuario resuelto es null (passkey de otro tenant)', function () {
    $action = new AuthorizePasskeyLogin;

    expect($action(new Request, null, new Passkey))->toBeFalse();
});

test('niega el login cuando la cuenta esta desactivada', function () {
    $user = User::factory()->inactive()->create();
    $passkey = new Passkey(['user_id' => $user->id]);

    $action = new AuthorizePasskeyLogin;

    expect($action(new Request, $user, $passkey))->toBeFalse();
});

test('permite el login cuando el usuario esta activo y en el tenant correcto', function () {
    // `is_active` no es fillable (F-04) y no está en UserFactory::definition()
    // — el modelo devuelto por create() no trae el default de columna
    // (`true`) sincronizado en memoria hasta un fresh()/refresh() explícito.
    $user = User::factory()->create()->fresh();
    $passkey = new Passkey(['user_id' => $user->id]);

    $action = new AuthorizePasskeyLogin;

    expect($user->is_active)->toBeTrue()
        ->and($action(new Request, $user, $passkey))->toBeTrue();
});
