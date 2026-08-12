<?php

declare(strict_types=1);

namespace App\Exceptions\Orders;

use RuntimeException;

/**
 * Se lanza al intentar ajustar/quitar un `OrderItem` de una orden que ya no
 * está `abierta` (p. ej. ya se envió a cocina) — ver
 * _ai/specs/toma-de-pedido.spec.md, Happy Path #7-8: el stepper de "La
 * Cuenta" ajusta cantidades antes de enviar a cocina, no después.
 */
class OrderNotEditableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Esta orden ya fue enviada a cocina — no se pueden modificar sus cantidades.');
    }
}
