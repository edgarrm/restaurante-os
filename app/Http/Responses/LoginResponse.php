<?php

namespace App\Http\Responses;

use App\Enums\Role;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;

/**
 * Reemplaza `Laravel\Fortify\Http\Responses\LoginResponse` (bindeada en
 * `FortifyServiceProvider`). El default de Fortify redirige siempre a
 * `config('fortify.home')` (`/dashboard`) sin importar el rol —
 * `/dashboard` ahora es `role:admin` (ver
 * _ai/specs/dashboard-del-dia.spec.md, PASO 0), así que mesero/cocina
 * recibirían 403 justo después de iniciar sesión si no se calcula el
 * home por rol aquí.
 *
 * También implementa `Laravel\Passkeys\Contracts\PasskeyLoginResponse`
 * (_ai/specs/passkeys.spec.md, PASO 0: "Redirect post-login por rol —
 * reuso, no duplicado") — ambos contratos son estructuralmente idénticos
 * (`Responsable::toResponse($request)`), así que el login por passkey
 * reutiliza el mismo cálculo de home por rol en vez de duplicarlo.
 */
class LoginResponse implements LoginResponseContract, PasskeyLoginResponseContract
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
