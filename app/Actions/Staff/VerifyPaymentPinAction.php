<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Exceptions\Staff\TooManyPinAttemptsException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class VerifyPaymentPinAction
{
    /**
     * Mismo criterio de throttling que el login de Fortify
     * (`config/fortify.php`): 5 intentos, ventana de 1 minuto — ver
     * _ai/specs/bloqueo-tablet-pin.spec.md, PASO 0.4.
     */
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    /**
     * Verifica el PIN ingresado contra el hash del usuario ya autenticado
     * en la sesión actual — nunca busca un usuario por PIN (F-05,
     * _ai/docs/threat-model.md: eso es lo que hace imposible que un PIN de
     * otro tenant, o de otro usuario, pase esta verificación). Un usuario
     * sin `pin_hash` simplemente nunca hace match — no lanza un error
     * distinto, para no filtrar si el usuario tiene o no un PIN
     * configurado desde este endpoint (ver Security Considerations del
     * spec).
     *
     * @throws TooManyPinAttemptsException tras 5 intentos fallidos en el
     *                                     último minuto para este usuario
     */
    public function handle(User $user, string $pin): bool
    {
        $key = $this->throttleKey($user);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw new TooManyPinAttemptsException(RateLimiter::availableIn($key));
        }

        if ($user->pin_hash === null || ! Hash::check($pin, $user->pin_hash)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            return false;
        }

        RateLimiter::clear($key);

        return true;
    }

    private function throttleKey(User $user): string
    {
        return 'payment-pin:'.$user->id;
    }
}
