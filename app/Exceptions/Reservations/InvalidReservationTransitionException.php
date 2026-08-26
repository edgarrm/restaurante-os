<?php

declare(strict_types=1);

namespace App\Exceptions\Reservations;

use RuntimeException;

/**
 * Se lanza al intentar una transición de `Reservation.status` que cruza
 * `sentada`↔`cancelada` en cualquier dirección (ver
 * _ai/specs/reservas.spec.md, Edge Cases). Repetir la transición sobre una
 * reserva ya en el estado destino no lanza esta excepción — es idempotente,
 * mismo criterio que `RequestBillAction`/`CloseOrderAction` sobre
 * `Order.status`.
 */
class InvalidReservationTransitionException extends RuntimeException
{
    public static function cannotSeatCancelled(): self
    {
        return new self('No se puede sentar una reserva cancelada.');
    }

    public static function cannotCancelSeated(): self
    {
        return new self('No se puede cancelar una reserva que ya fue sentada.');
    }
}
