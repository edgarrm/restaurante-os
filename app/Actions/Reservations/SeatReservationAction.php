<?php

declare(strict_types=1);

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Exceptions\Reservations\InvalidReservationTransitionException;
use App\Models\Reservation;

class SeatReservationAction
{
    /**
     * Marca la reserva `sentada` — el cliente llegó (ver
     * _ai/specs/reservas.spec.md, Happy Path #5). Solo `confirmada` puede
     * pasar a `sentada`; ya `sentada` es idempotente (protección de
     * doble-tap, mismo criterio que `RequestBillAction`/`CloseOrderAction`
     * sobre `Order.status`). `status` sí es fillable en `Reservation`
     * (a diferencia de `Table.status`/`MenuItem.available`), así que se usa
     * `update()`, no `forceFill()` — ver `.ai/rules/actions.md`.
     *
     * @throws InvalidReservationTransitionException si la reserva está `cancelada`
     */
    public function handle(Reservation $reservation): Reservation
    {
        if ($reservation->status === ReservationStatus::Cancelada) {
            throw InvalidReservationTransitionException::cannotSeatCancelled();
        }

        if ($reservation->status === ReservationStatus::Sentada) {
            return $reservation;
        }

        $reservation->update(['status' => ReservationStatus::Sentada]);

        return $reservation;
    }
}
