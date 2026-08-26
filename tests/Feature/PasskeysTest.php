<?php

use App\Http\Middleware\ScopePasskeysToTenantDomain;
use App\Http\Responses\LoginResponse;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Laravel\Passkeys\Passkey;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;
use Stancl\Tenancy\Middleware\ScopeSessions;

// F-01/F-02 (_ai/docs/threat-model.md), extendido a passkeys en
// _ai/specs/passkeys.spec.md — cada test corre dentro del subdominio de un
// tenant real, igual que AuthenticationTest.
beforeEach(function () {
    $this->tenant = actingInTenant();
});

test('F-05: todas las rutas de passkeys llevan el mismo stack tenant-aware que /login', function () {
    $routeNames = [
        'passkey.login-options',
        'passkey.login',
        'passkey.registration-options',
        'passkey.store',
        'passkey.destroy',
    ];

    foreach ($routeNames as $name) {
        $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();

        expect($middleware)
            ->toContain(InitializeTenancyByDomain::class)
            ->toContain(PreventAccessFromCentralDomains::class)
            ->toContain(ScopeSessions::class)
            ->toContain(ScopePasskeysToTenantDomain::class);
    }
});

test('ScopePasskeysToTenantDomain ata el Relying Party ID al subdominio real de la peticion, no a config(app.url)', function () {
    $this->get(route('passkey.login-options'))->assertOk();

    expect(config('passkeys.relying_party_id'))
        ->toBe(parse_url(url('/'), PHP_URL_HOST))
        ->not->toBe(parse_url(config('app.url'), PHP_URL_HOST));
});

test('el binding de PasskeyLoginResponse reusa App\Http\Responses\LoginResponse (redirect por rol, no duplicado)', function () {
    expect(app(LoginResponseContract::class))->toBeInstanceOf(LoginResponse::class)
        ->and(app(PasskeyLoginResponseContract::class))->toBeInstanceOf(LoginResponse::class);
});

test('/settings/passkeys requiere autenticacion y confirmacion de contraseña reciente', function () {
    $user = User::factory()->for($this->tenant, 'tenant')->create();

    $this->get(route('passkeys.edit'))->assertRedirect(route('login'));

    $this->actingAs($user)
        ->get(route('passkeys.edit'))
        ->assertRedirect(route('password.confirm'));
});

test('/settings/passkeys lista solo las passkeys del usuario autenticado', function () {
    $user = User::factory()->for($this->tenant, 'tenant')->create();
    $otherUser = User::factory()->for($this->tenant, 'tenant')->create();

    $ownPasskey = $user->passkeys()->create([
        'name' => 'iPad de la barra',
        'credential_id' => Str::random(32),
        'credential' => ['type' => 'test'],
    ]);

    $otherUser->passkeys()->create([
        'name' => 'Passkey de otro usuario',
        'credential_id' => Str::random(32),
        'credential' => ['type' => 'test'],
    ]);

    // settings/Passkeys.vue no está compilado en el manifest de Vite en un
    // test run — se simula la navegación XHR de Inertia en vez de una carga
    // de página completa (.ai/rules/feature.md).
    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('passkeys.edit'), inertiaXhrHeaders());

    $response->assertJsonPath('component', 'settings/Passkeys');
    expect($response->json('props.passkeys'))->toHaveCount(1);
    expect($response->json('props.passkeys.0.id'))->toBe($ownPasskey->id);
    expect($response->json('props.passkeys.0.name'))->toBe('iPad de la barra');
});

test('un usuario no puede borrar la passkey de otro usuario del mismo tenant', function () {
    $user = User::factory()->for($this->tenant, 'tenant')->create();
    $otherUser = User::factory()->for($this->tenant, 'tenant')->create();

    $otherUsersPasskey = $otherUser->passkeys()->create([
        'name' => 'Passkey de otro usuario',
        'credential_id' => Str::random(32),
        'credential' => ['type' => 'test'],
    ]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('passkey.destroy', $otherUsersPasskey))
        ->assertForbidden();

    expect(Passkey::find($otherUsersPasskey->id))->not->toBeNull();
});

test('un usuario puede borrar su propia passkey', function () {
    $user = User::factory()->for($this->tenant, 'tenant')->create();

    $passkey = $user->passkeys()->create([
        'name' => 'iPad de la barra',
        'credential_id' => Str::random(32),
        'credential' => ['type' => 'test'],
    ]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('passkey.destroy', $passkey))
        ->assertRedirect();

    expect(Passkey::find($passkey->id))->toBeNull();
});

test('registrar una passkey con datos invalidos devuelve un error de validacion, no un crash', function () {
    $user = User::factory()->for($this->tenant, 'tenant')->create();

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(route('passkey.store'), [
            'name' => 'Passkey invalida',
            'credential' => ['id' => 'no-es-una-credencial-real'],
        ]);

    $response->assertUnprocessable();
});
