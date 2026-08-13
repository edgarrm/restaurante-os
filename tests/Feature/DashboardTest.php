<?php

use App\Models\User;

// `dashboard` es ahora una ruta de tenant, `role:admin` (ver
// _ai/specs/dashboard-del-dia.spec.md, PASO 0) — necesita un tenant real
// detrás, igual que el resto de rutas de dominio. Cobertura completa de
// métricas/aislamiento/rol en tests/Feature/DashboardDelDiaTest.php; este
// archivo se conserva como smoke test mínimo de auth heredado del starter
// kit.

test('guests are redirected to the login page', function () {
    actingInTenant();

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated admins can visit the dashboard', function () {
    $tenant = actingInTenant();
    $admin = User::factory()->for($tenant, 'tenant')->admin()->create();
    $this->actingAs($admin);

    $response = $this->get(route('dashboard'), inertiaXhrHeaders());
    $response->assertOk();
});
