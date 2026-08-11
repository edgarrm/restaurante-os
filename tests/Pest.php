<?php

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Models\Domain;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Crea un Tenant de prueba con su Domain asociado y fuerza el root URL que
 * usan route()/url() a ese subdominio, para que tanto las peticiones HTTP
 * del test como las redirecciones internas de la app aterricen en el mismo
 * host. Ver F-01/F-02 en _ai/docs/threat-model.md — desde que las rutas de
 * auth y de settings requieren tenancy inicializada, todo test que dependa
 * de un User persistido necesita un tenant real detrás.
 */
function actingInTenant(?string $domain = null): Tenant
{
    $domain ??= Str::lower(Str::random(12)).'.test';

    $tenant = Tenant::create(['name' => 'Tenant de prueba']);

    Domain::create([
        'tenant_id' => $tenant->getTenantKey(),
        'domain' => $domain,
    ]);

    URL::forceRootUrl('http://'.$domain);

    return $tenant;
}
