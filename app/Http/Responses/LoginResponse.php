<?php

namespace App\Http\Responses;

use App\Enums\Role;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

/**
 * Reemplaza `Laravel\Fortify\Http\Responses\LoginResponse` (bindeada en
 * `FortifyServiceProvider`). El default de Fortify redirige siempre a
 * `config('fortify.home')` (`/dashboard`) sin importar el rol —
 * `/dashboard` ahora es `role:admin` (ver
 * _ai/specs/dashboard-del-dia.spec.md, PASO 0), así que mesero/cocina
 * recibirían 403 justo después de iniciar sesión si no se calcula el
 * home por rol aquí.
 */
class LoginResponse implements LoginResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return response()->json(['two_factor' => false]);
        }

        $home = match ($request->user()->role) {
            Role::Admin => route('dashboard'),
            Role::Mesero => route('mesas.index'),
            Role::Cocina => route('cocina.index'),
        };

        return redirect()->intended($home);
    }
}
