<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;
use Stancl\Tenancy\Middleware\ScopeSessions;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Here you can register the tenant routes for your application.
| These routes are loaded by the TenantRouteServiceProvider.
|
| Feel free to customize them however you want. Good luck!
|
*/

Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
    // F-02 (_ai/docs/threat-model.md): ata la sesión al tenant que la creó y
    // aborta con 403 si se reutiliza bajo otro subdominio. SESSION_DOMAIN=null
    // protegía por accidente; esto protege por diseño aunque esa variable
    // cambie.
    ScopeSessions::class,
])->group(function () {
    // El stub original ("This is your multi-tenant application...") definía
    // GET / y colisionaba con la ruta `home` de routes/web.php — Laravel
    // registra este archivo DESPUÉS (vía $app->booted() en
    // TenancyServiceProvider::mapRoutes()), y esa colisión sobreescribía por
    // completo la ruta `home`, no solo la ensombrecía. Confirmado con
    // `route:list --name=home` devolviendo vacío en auditoría del 2026-08-10.
    //
    // Las rutas de dominio de restaurante (mesas, pedidos, cocina, menú,
    // staff, reservas — ver _ai/specs/) van aquí conforme se implementen.
    // Ninguna usa todavía el path raíz "/".

    // No son rutas de dominio de restaurante, pero operan sobre el usuario
    // autenticado de un tenant y por lo tanto necesitan el mismo contexto de
    // tenancy que /login (F-01, _ai/docs/threat-model.md).
    require __DIR__.'/settings.php';
});
