<?php

declare(strict_types=1);

namespace App\Exceptions\Orders;

use RuntimeException;

/**
 * Se lanza al intentar agregar un ítem a la orden de una mesa en
 * `por_cobrar` — ya se pidió la cuenta, no se aceptan más platillos (ver
 * _ai/specs/toma-de-pedido.spec.md, Edge Cases).
 */
class TableNotAcceptingOrdersException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Esta mesa ya solicitó la cuenta — no se pueden agregar más platillos.');
    }
}
