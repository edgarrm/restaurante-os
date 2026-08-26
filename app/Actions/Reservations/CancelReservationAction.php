<?php

declare(strict_types=1);

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Exceptions\Reservations\InvalidReservationTransitionException;
use App\Models\Reservation;

class CancelReservationAction
{
    /**
     * Marca la reserva `cancelada` (ver _ai/specs/reservas.spec.md, Happy
     * Path #5). Solo `confirmada` puede pasar a `cancelada`; ya `cancelada`
     * es idempotente (protección de doble-tap, mismo criterio que
     * `RequestBillAction`/`CloseOrderAction` sobre `Order.status`). No se
     * puede cancelar una reserva `sentada` — el cliente ya llegó (Edge
     * Cases). `status` sí es fillable en `Reservation` (a diferencia de
     * `Table.status`/`MenuItem.available`), así que se usa `update()`, no
     * `forceFill()` — ver `.ai/rules/actions.md`.
     *
     * @throws InvalidReservationTransitionException si la reserva está `sentada`
     */
    public function handle(Reservation $reservation): Reservation
    {
        if ($reservation->status === ReservationStatus::Sentada) {
            throw InvalidReservationTransitionException::cannotCancelSeated();
        }

        if ($reservation->status === ReservationStatus::Cancelada) {
            return $reservation;
        }

        $reservation->update(['status' => ReservationStatus::Cancelada]);

        return $reservation;
    }
}
