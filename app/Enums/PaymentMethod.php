<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Método de pago aplicado a una orden (ver _ai/docs/data-model.md, entidad
 * Payment). Conjunto cerrado — decidido en PASO 0 de
 * _ai/specs/cobro.spec.md (#7), ver decision-log.md.
 */
enum PaymentMethod: string
{
    case Efectivo = 'efectivo';
    case Tarjeta = 'tarjeta';
    case Transferencia = 'transferencia';
}
