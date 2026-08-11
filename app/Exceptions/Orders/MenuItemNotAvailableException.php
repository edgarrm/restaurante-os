<?php

declare(strict_types=1);

namespace App\Exceptions\Orders;

use RuntimeException;

/**
 * Se lanza al intentar agregar un `MenuItem` con `available=false` a una
 * orden (ver _ai/specs/toma-de-pedido.spec.md, Edge Cases). El servidor
 * siempre revalida `available` al momento de escribir, sin confiar en el
 * estado que trae el cliente.
 */
class MenuItemNotAvailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Este platillo ya no está disponible.');
    }
}
