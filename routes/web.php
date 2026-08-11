<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

// settings.php se registra en routes/tenant.php, no aquí — ver F-01 en
// _ai/docs/threat-model.md: /settings/* opera sobre el usuario autenticado
// de un tenant y necesita el mismo contexto de tenancy que /login.
