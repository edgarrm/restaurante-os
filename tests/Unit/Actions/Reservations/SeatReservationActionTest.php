<?php

use App\Actions\Reservations\SeatReservationAction;
use App\Enums\ReservationStatus;
use App\Exceptions\Reservations\InvalidReservationTransitionException;
use App\Models\Reservation;

beforeEach(function () {
    tenancy()->initialize(actingInTenant());
});

test('pasa una reserva confirmada a sentada', function () {
    $reservation = Reservation::factory()->create(['status' => ReservationStatus::Confirmada]);

    $updated = (new SeatReservationAction)->handle($reservation);

    expect($updated->status)->toBe(ReservationStatus::Sentada)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Sentada);
});

test('reserva ya sentada es idempotente', function () {
    $reservation = Reservation::factory()->create(['status' => ReservationStatus::Sentada]);

    $updated = (new SeatReservationAction)->handle($reservation);

    expect($updated->status)->toBe(ReservationStatus::Sentada);
});

test('reserva cancelada lanza excepción de transición inválida', function () {
    $reservation = Reservation::factory()->create(['status' => ReservationStatus::Cancelada]);

    expect(fn () => (new SeatReservationAction)->handle($reservation))
        ->toThrow(InvalidReservationTransitionException::class, 'No se puede sentar una reserva cancelada.');

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Cancelada);
});
