<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de un ítem dentro de una orden (ver _ai/docs/data-model.md,
 * entidad OrderItem).
 *
 * `OrderItem` en sí es dominio de _ai/specs/toma-de-pedido.spec.md (#5, sin
 * implementar todavía) — este enum y el modelo mínimo que lo usa solo
 * existen aquí como prerequisito de UpdateMenuItemAction, que necesita
 * probar que `unit_price` es un snapshot (ver
 * _ai/specs/gestion-menu.spec.md).
 */
enum OrderItemStatus: string
{
    case Pendiente = 'pendiente';
    case Preparando = 'preparando';
    case Listo = 'listo';
    case Servido = 'servido';
}
