<?php

declare(strict_types=1);

namespace App\Exceptions\Orders;

use RuntimeException;

/**
 * Se lanza al intentar cerrar una orden con un `amount` menor a su total
 * (ver _ai/specs/cobro.spec.md, Edge Cases). No se permite cerrar una
 * cuenta parcialmente pagada en v1 (split bill es Could, fuera de alcance).
 */
class InsufficientPaymentException extends RuntimeException
{
    public function __construct(float $total)
    {
        parent::__construct(sprintf('El monto no cubre el total de la cuenta ($%s).', number_format($total, 2)));
    }
}
