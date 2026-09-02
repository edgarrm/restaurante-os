<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * F-01/F-05 para passkeys (_ai/specs/passkeys.spec.md, PASO 0): a diferencia
 * del login por contraseña, WebAuthn ata cada passkey a un "Relying Party
 * ID" en el momento del registro. Por defecto, `laravel/passkeys` deriva
 * `relying_party_id`/`allowed_origins` de `config('app.url')` — un único
 * valor global. En este setup multi-tenant por subdominio eso es un
 * problema de protocolo, no solo de datos: si el RP ID queda fijo en el
 * dominio base (que además está en `tenancy.central_domains`), CUALQUIER
 * subdominio de tenant es "same site" para ese RP ID, y el navegador
 * ofrecería/validaría passkeys de un tenant distinto al que sirve la
 * petición actual — ninguna verificación del lado del servidor lo arregla
 * si el RP ID sigue siendo compartido.
 *
 * Este middleware sobreescribe esos dos valores de config, en caliente, al
 * host/origin exacto de la petición en curso — un RP ID igual al dominio
 * exacto del origen siempre es válido según la spec de WebAuthn ("same site
 * trivially"), así que cada tenant queda aislado a nivel de la ceremonia
 * criptográfica, antes de que el servidor vea nada.
 */
class ScopePasskeysToTenantDomain
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        config([
            'passkeys.relying_party_id' => $request->getHost(),
            'passkeys.allowed_origins' => [$request->getSchemeAndHttpHost()],
        ]);

        return $next($request);
    }
}
