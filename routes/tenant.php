<?php

declare(strict_types=1);

use App\Http\Controllers\KitchenController;
use App\Http\Controllers\MenuItemController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\TableController;
use App\Http\Controllers\TableMapController;
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

    // Gestión de Mesas (_ai/specs/gestion-mesas.spec.md, #1). `role:admin`
    // resuelve F-06 (_ai/docs/threat-model.md) para esta pantalla completa;
    // TablePolicy (ver TableController) autoriza cada acción sobre el
    // modelo.
    Route::middleware(['auth', 'role:admin'])
        ->prefix('mesas/gestion')
        ->name('tables.')
        ->group(function () {
            Route::get('/', [TableController::class, 'index'])->name('index');
            Route::post('/', [TableController::class, 'store'])->name('store');
            Route::patch('/{table}', [TableController::class, 'update'])->name('update');
            Route::delete('/{table}', [TableController::class, 'destroy'])->name('destroy');
        });

    // Mapa de Mesas (_ai/specs/mapa-de-mesas.spec.md, #4). Distinto de
    // Gestión de Mesas: solo lectura, mesero+admin, sin Policy (el spec
    // dice explícitamente "Ninguna" regla de autorización propia) —
    // `role:admin,mesero` resuelve el acceso a esta pantalla completa.
    // Name group `mesas.` (no `tables.`) para no colisionar con
    // `tables.index` de arriba; path `/mesas` (sin `/gestion`) tampoco
    // colisiona con el prefix `mesas/gestion`.
    Route::middleware(['auth', 'role:admin,mesero'])
        ->prefix('mesas')
        ->name('mesas.')
        ->group(function () {
            Route::get('/', [TableMapController::class, 'index'])->name('index');
        });

    // Gestión de Menú (_ai/specs/gestion-menu.spec.md, #2). Mismo patrón que
    // Gestión de Mesas: `role:admin` resuelve F-06 para esta pantalla
    // completa; MenuItemPolicy (ver MenuItemController) autoriza cada acción
    // sobre el modelo específico.
    Route::middleware(['auth', 'role:admin'])
        ->prefix('menu')
        ->name('menu.')
        ->group(function () {
            Route::get('/', [MenuItemController::class, 'index'])->name('index');
            Route::post('/', [MenuItemController::class, 'store'])->name('store');
            Route::patch('/{menuItem}', [MenuItemController::class, 'update'])->name('update');
            Route::patch('/{menuItem}/disponibilidad', [MenuItemController::class, 'toggleAvailability'])->name('toggle-availability');
        });

    // Gestión de Staff (_ai/specs/gestion-staff.spec.md, #3). Mismo patrón
    // que Gestión de Mesas/Menú: `role:admin` resuelve F-06 para esta
    // pantalla completa; UserPolicy (ver StaffController) autoriza cada
    // acción sobre la cuenta específica y bloquea actuar sobre cuentas
    // admin (fuera de alcance de este flujo).
    Route::middleware(['auth', 'role:admin'])
        ->prefix('staff')
        ->name('staff.')
        ->group(function () {
            Route::get('/', [StaffController::class, 'index'])->name('index');
            Route::post('/', [StaffController::class, 'store'])->name('store');
            Route::patch('/{user}', [StaffController::class, 'update'])->name('update');
            Route::patch('/{user}/desactivar', [StaffController::class, 'deactivate'])->name('deactivate');
        });

    // Toma de Pedido (_ai/specs/toma-de-pedido.spec.md, #5). Mismo patrón
    // que Mapa de Mesas: `role:admin,mesero` resuelve F-06 para esta
    // pantalla completa; sin Policy — el spec dice explícitamente que no
    // hay reglas de autorización propias más allá del rol (ninguna
    // asignación mesero↔mesa en el MVP).
    Route::middleware(['auth', 'role:admin,mesero'])
        ->prefix('mesas/{table}/pedido')
        ->name('pedido.')
        ->group(function () {
            Route::get('/', [OrderController::class, 'show'])->name('show');
            Route::post('/items', [OrderController::class, 'addItem'])->name('add-item');
            Route::post('/enviar', [OrderController::class, 'send'])->name('send');
        });

    // Cocina / KDS (_ai/specs/cocina-kds.spec.md, #6). `role:admin,cocina`
    // resuelve F-06 para esta pantalla completa; sin Policy — el spec dice
    // explícitamente que no hay reglas de autorización propias (ninguna
    // estación de cocina separada en el MVP). F-05: {orderItem} no usa
    // route model binding implícito porque OrderItem no tiene
    // BelongsToTenant propio — ver KitchenController::markReady().
    Route::middleware(['auth', 'role:admin,cocina'])
        ->prefix('cocina')
        ->name('cocina.')
        ->group(function () {
            Route::get('/', [KitchenController::class, 'index'])->name('index');
            Route::patch('items/{orderItem}/listo', [KitchenController::class, 'markReady'])->name('items.mark-ready');
        });

    // Cobro y Cierre de Cuenta (_ai/specs/cobro.spec.md, #7). Mismo patrón
    // que Toma de Pedido: `role:admin,mesero` resuelve F-06 para esta
    // pantalla completa; `Table` sí usa `BelongsToTenant`, así que
    // `{table}` en la URL usa route model binding normal (a diferencia de
    // `{orderItem}` en #6) — TenantScope ya protege F-05. Sin Policy — el
    // spec dice explícitamente que no hay reglas de autorización propias.
    Route::middleware(['auth', 'role:admin,mesero'])
        ->prefix('mesas/{table}/cobro')
        ->name('cobro.')
        ->group(function () {
            Route::get('/', [PaymentController::class, 'show'])->name('show');
            Route::post('/', [PaymentController::class, 'close'])->name('close');
        });
});
