<?php

declare(strict_types=1);

namespace App\Exceptions\Orders;

use RuntimeException;

/**
 * Se lanza al intentar enviar a cocina una orden sin ítems (ver
 * _ai/specs/toma-de-pedido.spec.md, Edge Cases).
 */
class EmptyOrderException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Agrega al menos un platillo antes de enviar a cocina.');
    }
}
