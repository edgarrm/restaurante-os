<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkey;

/**
 * Registrado vía `Passkeys::authorizeLoginUsing()` (ver
 * `FortifyServiceProvider::configurePasskeys()`). Corre DESPUÉS de que el
 * paquete verificó criptográficamente la passkey, con el usuario ya
 * resuelto por la relación `Passkey::user()`.
 *
 * `?PasskeyUser $user` es intencionalmente nullable (a diferencia del
 * ejemplo del README del paquete, que lo tipa non-null): cuando la passkey
 * pertenece a un usuario de OTRO tenant, el Global Scope de
 * `BelongsToTenant` sobre `User` hace que la relación resuelva `null` (ver
 * F-01/F-05, _ai/specs/passkeys.spec.md) — si este callback tipara `User`
 * a secas, PHP lanzaría un `TypeError` (500 sin control) en vez de un
 * rechazo de login limpio.
 */
class AuthorizePasskeyLogin
{
    /**
     * Determine si el login por passkey debe continuar.
     */
    public function __invoke(Request $request, ?PasskeyUser $user, Passkey $passkey): bool
    {
        // `is_active`: mismo criterio que el login por contraseña en
        // FortifyServiceProvider::configureActions(). `$user` en null
        // cubre el caso cross-tenant descrito arriba.
        return $user instanceof User && $user->is_active;
    }
}
