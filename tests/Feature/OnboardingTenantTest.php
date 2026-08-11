<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\PendingCommand;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\Middleware\ScopeSessions;

/**
 * Corre `tenants:onboard` end-to-end (incluida la contraseña, capturada por
 * prompt oculto — nunca como argumento).
 */
function runOnboardCommand(array $overrides = []): PendingCommand
{
    $data = array_merge([
        'name' => 'El Ancla',
        'subdomain' => 'elancla',
        'admin-name' => 'Ada Admin',
        'admin-email' => 'ada@elancla.test',
        'password' => 'password-segura-123',
    ], $overrides);

    return test()->artisan('tenants:onboard', [
        'name' => $data['name'],
        'subdomain' => $data['subdomain'],
        'admin-name' => $data['admin-name'],
        'admin-email' => $data['admin-email'],
    ])->expectsQuestion('Contraseña del admin', $data['password']);
}

test('correr el comando end-to-end permite login exitoso en el subdominio nuevo', function () {
    runOnboardCommand()->assertExitCode(0);

    URL::forceRootUrl('http://elancla');

    $response = $this->post(route('login.store'), [
        'email' => 'ada@elancla.test',
        'password' => 'password-segura-123',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('correr el comando dos veces con el mismo subdominio falla en la segunda sin duplicar datos', function () {
    runOnboardCommand()->assertExitCode(0);

    runOnboardCommand(['admin-email' => 'otro-admin@elancla.test'])->assertExitCode(1);

    expect(Tenant::count())->toBe(1)
        ->and(Domain::count())->toBe(1)
        ->and(User::count())->toBe(1);
});

test('aislamiento entre tenants: el admin de A no inicia sesión en el subdominio de B', function () {
    runOnboardCommand([
        'name' => 'El Ancla',
        'subdomain' => 'elancla',
        'admin-name' => 'Ada Admin',
        'admin-email' => 'ada@elancla.test',
        'password' => 'password-de-ada-123',
    ])->assertExitCode(0);

    runOnboardCommand([
        'name' => 'El Piloto',
        'subdomain' => 'elpiloto',
        'admin-name' => 'Pablo Piloto',
        'admin-email' => 'pablo@elpiloto.test',
        'password' => 'password-de-pablo-123',
    ])->assertExitCode(0);

    $tenantA = Domain::where('domain', 'elancla')->sole()->tenant_id;

    // F-05 / aislamiento a nivel de query: con tenancy inicializada para A,
    // User::all() no debe incluir al admin de B.
    tenancy()->initialize(Tenant::find($tenantA));
    $emailsVisiblesParaA = User::all()->pluck('email')->all();
    tenancy()->end();

    expect($emailsVisiblesParaA)
        ->toContain('ada@elancla.test')
        ->not->toContain('pablo@elpiloto.test');

    // F-01: las credenciales VÁLIDAS del admin de B son rechazadas en el
    // subdominio de A. Si esto pasa antes de que F-01 esté resuelto, es
    // porque `users.tenant_id` no existía y el scope nunca filtró — no
    // porque el sistema haya distinguido correctamente los tenants.
    URL::forceRootUrl('http://elancla');

    $response = $this->post(route('login.store'), [
        'email' => 'pablo@elpiloto.test',
        'password' => 'password-de-pablo-123',
    ]);

    $this->assertGuest();
    $response->assertSessionHasErrors();

    // Confirma el motivo: la cuenta de Pablo SÍ existe globalmente (no es un
    // simple "email no encontrado") — el login falló porque TenantScope, ya
    // activo, la excluyó de la búsqueda en el subdominio de A.
    expect(DB::table('users')->where('email', 'pablo@elpiloto.test')->exists())->toBeTrue();
});

test('F-02: una sesión válida del tenant A es rechazada con 403 en el subdominio de B', function () {
    runOnboardCommand([
        'subdomain' => 'elancla',
        'admin-email' => 'ada@elancla.test',
        'password' => 'password-de-ada-123',
    ])->assertExitCode(0);

    runOnboardCommand([
        'name' => 'El Piloto',
        'subdomain' => 'elpiloto',
        'admin-name' => 'Pablo Piloto',
        'admin-email' => 'pablo@elpiloto.test',
        'password' => 'password-de-pablo-123',
    ])->assertExitCode(0);

    $tenantA = Domain::where('domain', 'elancla')->sole()->tenant_id;
    $userA = User::where('email', 'ada@elancla.test')->sole();

    URL::forceRootUrl('http://elpiloto');

    $response = $this
        ->withSession([ScopeSessions::$tenantIdKey => $tenantA])
        ->actingAs($userA)
        ->get('/settings/profile');

    $response->assertForbidden();
});
