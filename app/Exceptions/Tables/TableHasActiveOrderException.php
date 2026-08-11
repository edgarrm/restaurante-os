<?php

declare(strict_types=1);

namespace App\Exceptions\Tables;

use RuntimeException;

/**
 * Se lanza al intentar eliminar una mesa que tiene una orden `abierta` o
 * `enviada_cocina` (ver _ai/specs/gestion-mesas.spec.md, Edge Cases).
 */
class TableHasActiveOrderException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No se puede eliminar una mesa con una cuenta activa.');
    }
}
