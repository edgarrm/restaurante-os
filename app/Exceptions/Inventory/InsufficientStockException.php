<?php

declare(strict_types=1);

namespace App\Exceptions\Inventory;

use App\Models\InventoryItem;
use RuntimeException;

/**
 * Se lanza al intentar registrar una `salida` que dejaría
 * `quantity_on_hand` negativa (ver _ai/specs/inventario.spec.md, Edge
 * Cases). No se permite stock negativo en v1.
 */
class InsufficientStockException extends RuntimeException
{
    public function __construct(InventoryItem $item)
    {
        parent::__construct(sprintf(
            "No hay stock suficiente de '%s' para esta salida (disponible: %s %s).",
            $item->name,
            number_format((float) $item->quantity_on_hand, 3),
            $item->unit,
        ));
    }
}
