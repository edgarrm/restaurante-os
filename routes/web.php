<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// settings.php se registra en routes/tenant.php, no aquí — ver F-01 en
// _ai/docs/threat-model.md: /settings/* opera sobre el usuario autenticado
// de un tenant y necesita el mismo contexto de tenancy que /login.

// 'dashboard' también se registra en routes/tenant.php (no aquí) desde
// _ai/specs/dashboard-del-dia.spec.md (#13, PASO 0): el resumen del día
// necesita datos de tenant (mesas/pagos/reservas), así que reemplaza al
// placeholder genérico del starter kit que vivía en este archivo.
