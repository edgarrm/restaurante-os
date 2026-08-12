<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipo de ajuste manual de stock (ver _ai/docs/data-model.md, entidad
 * InventoryMovement).
 */
enum InventoryMovementType: string
{
    case Entrada = 'entrada';
    case Salida = 'salida';
}
