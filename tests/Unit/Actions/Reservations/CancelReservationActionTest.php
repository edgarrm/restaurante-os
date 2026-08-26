<?php

use App\Actions\Reservations\CancelReservationAction;
use App\Enums\ReservationStatus;
use App\Exceptions\Reservations\InvalidReservationTransitionException;
use App\Models\Reservation;

beforeEach(function () {
    tenancy()->initialize(actingInTenant());
});

test('pasa una reserva confirmada a cancelada', function () {
    $reservation = Reservation::factory()->create(['status' => ReservationStatus::Confirmada]);

    $updated = (new CancelReservationAction)->handle($reservation);

    expect($updated->status)->toBe(ReservationStatus::Cancelada)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Cancelada);
});

test('reserva ya cancelada es idempotente', function () {
    $reservation = Reservation::factory()->create(['status' => ReservationStatus::Cancelada]);

    $updated = (new CancelReservationAction)->handle($reservation);

    expect($updated->status)->toBe(ReservationStatus::Cancelada);
});

test('reserva sentada lanza excepción de transición inválida', function () {
    $reservation = Reservation::factory()->create(['status' => ReservationStatus::Sentada]);

    expect(fn () => (new CancelReservationAction)->handle($reservation))
        ->toThrow(InvalidReservationTransitionException::class, 'No se puede cancelar una reserva que ya fue sentada.');

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Sentada);
});
