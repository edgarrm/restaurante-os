<?php

use App\Actions\Reservations\CreateReservationAction;
use App\Enums\ReservationStatus;
use App\Exceptions\Reservations\PastReservationException;
use App\Models\Table;

beforeEach(function () {
    tenancy()->initialize(actingInTenant());
});

test('crea una reserva con status confirmada por defecto', function () {
    $table = Table::factory()->create();
    $data = [
        'customer_name' => 'Ana Pérez',
        'customer_phone' => '555-1234',
        'party_size' => 4,
        'reserved_at' => now()->addDay()->toDateTimeString(),
    ];

    $reservation = (new CreateReservationAction)->handle($data, $table);

    expect($reservation->status)->toBe(ReservationStatus::Confirmada)
        ->and($reservation->customer_name)->toBe('Ana Pérez')
        ->and($reservation->table_id)->toBe($table->id);
});

test('reserved_at en el pasado lanza excepción de validación', function () {
    $data = [
        'customer_name' => 'Ana Pérez',
        'customer_phone' => '555-1234',
        'party_size' => 2,
        'reserved_at' => now()->subDay()->toDateTimeString(),
    ];

    expect(fn () => (new CreateReservationAction)->handle($data, null))
        ->toThrow(PastReservationException::class, 'La hora de la reserva debe ser futura.');
});

test('table_id nulo es válido', function () {
    $data = [
        'customer_name' => 'Ana Pérez',
        'customer_phone' => '555-1234',
        'party_size' => 2,
        'reserved_at' => now()->addHour()->toDateTimeString(),
    ];

    $reservation = (new CreateReservationAction)->handle($data, null);

    expect($reservation->table_id)->toBeNull();
});
