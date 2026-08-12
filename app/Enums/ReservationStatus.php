<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de una reserva (ver _ai/docs/data-model.md, entidad Reservation).
 */
enum ReservationStatus: string
{
    case Confirmada = 'confirmada';
    case Sentada = 'sentada';
    case Cancelada = 'cancelada';
}
