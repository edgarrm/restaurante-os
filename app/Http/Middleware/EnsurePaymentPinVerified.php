<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * F-07 (_ai/docs/threat-model.md): gatea el *submit* de los endpoints de
 * cobro con una verificación de PIN reciente — ver
 * _ai/specs/bloqueo-tablet-pin.spec.md, PASO 0.2/0.3. No se aplica a
 * `PaymentController::show()` (navegar a la pantalla de Cobro nunca pide
 * PIN, solo el envío real del pago).
 *
 * `ValidationException::withMessages()`, no `abort()`: un `abort()` plano
 * no trae el header `X-Inertia`, así que el cliente Inertia real lo
 * trataría como respuesta "no-Inertia" y mostraría un modal con HTML
 * crudo — mismo bug ya documentado en `PaymentController`/`StaffController`.
 */
class EnsurePaymentPinVerified
{
    /**
     * Umbral de frescura de la verificación — 5 minutos, ver el spec.
     */
    private const TTL_SECONDS = 300;

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user->pin_hash === null) {
            throw ValidationException::withMessages([
                'pin_not_set' => __('Configura tu PIN de cobro en Ajustes antes de cobrar.'),
            ]);
        }

        $verifiedAt = $request->session()->get('pin_verified_at');

        if (is_int($verifiedAt) && (now()->timestamp - $verifiedAt) <= self::TTL_SECONDS) {
            return $next($request);
        }

        throw ValidationException::withMessages([
            'pin' => __('Verifica tu PIN para continuar con el cobro.'),
        ]);
    }
}
