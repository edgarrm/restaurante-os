<?php

declare(strict_types=1);

namespace App\Exceptions\Reservations;

use RuntimeException;

/**
 * Se lanza al intentar crear una reserva con `reserved_at` en el pasado
 * (ver _ai/specs/reservas.spec.md, Edge Cases).
 */
class PastReservationException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('La hora de la reserva debe ser futura.');
    }
}
