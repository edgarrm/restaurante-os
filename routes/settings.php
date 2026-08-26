<?php

use App\Http\Controllers\Settings\PasskeysController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    // Passkeys (_ai/specs/passkeys.spec.md): mismo patrón que
    // `settings/security` — RequirePassword a nivel de ruta, así el
    // registro/borrado (contra las rutas propias de `laravel/passkeys`,
    // gateadas con `password.confirm`) no pide una segunda confirmación
    // mientras el usuario sigue en esta página.
    Route::get('settings/passkeys', [PasskeysController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('passkeys.edit');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});
