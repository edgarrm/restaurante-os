<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de una mesa (ver _ai/docs/data-model.md, entidad Table).
 */
enum TableStatus: string
{
    case Libre = 'libre';
    case Ocupada = 'ocupada';
    case PorCobrar = 'por_cobrar';
}
