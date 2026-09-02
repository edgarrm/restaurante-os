<?php

declare(strict_types=1);

namespace App\Exceptions\Staff;

use RuntimeException;

/**
 * Se lanza cuando `VerifyPaymentPinAction` detecta 5 intentos fallidos de
 * PIN en el último minuto para un usuario — ver
 * _ai/specs/bloqueo-tablet-pin.spec.md, PASO 0.4. Mismo criterio de
 * throttling que el login de Fortify (`config/fortify.php`), pero por
 * usuario en vez de por email+IP (ver el spec para el porqué).
 */
class TooManyPinAttemptsException extends RuntimeException
{
    public function __construct(int $secondsRemaining)
    {
        parent::__construct(sprintf('Demasiados intentos. Intenta de nuevo en %d segundos.', $secondsRemaining));
    }
}
