<?php

declare(strict_types=1);

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Exceptions\Reservations\PastReservationException;
use App\Models\Reservation;
use App\Models\Table;
use Illuminate\Support\Carbon;

class CreateReservationAction
{
    /**
     * Crea una reserva con status `confirmada` por defecto (ver
     * _ai/specs/reservas.spec.md, Happy Path). `$table` es nullable — una
     * reserva puede quedar sin mesa asignada, para decidirse el día de la
     * reserva (Edge Cases).
     *
     * `$table`, si se pasa, ya debe haberse resuelto contra el tenant
     * actual en el controller (`Table::query()->whereKey(...)->firstOrFail()`,
     * F-05 en _ai/docs/threat-model.md) — esta Action no vuelve a validarlo.
     *
     * @param  array{customer_name: string, customer_phone: string, party_size: int, reserved_at: string}  $data
     *
     * @throws PastReservationException si `reserved_at` está en el pasado
     */
    public function handle(array $data, ?Table $table): Reservation
    {
        if (Carbon::parse($data['reserved_at'])->isPast()) {
            throw new PastReservationException;
        }

        return Reservation::create([
            'table_id' => $table?->id,
            'customer_name' => $data['customer_name'],
            'customer_phone' => $data['customer_phone'],
            'party_size' => $data['party_size'],
            'reserved_at' => $data['reserved_at'],
            'status' => ReservationStatus::Confirmada,
        ]);
    }
}
