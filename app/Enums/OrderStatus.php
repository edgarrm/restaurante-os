<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de una orden (ver _ai/docs/data-model.md, entidad Order).
 *
 * `Order` en sí es dominio de _ai/specs/toma-de-pedido.spec.md (#5, sin
 * implementar todavía) — este enum y el modelo mínimo que lo usa solo
 * existen aquí como prerequisito de DeleteTableAction, que necesita saber
 * si una mesa tiene una cuenta activa antes de dejarla eliminar (ver
 * _ai/specs/gestion-mesas.spec.md).
 */
enum OrderStatus: string
{
    case Abierta = 'abierta';
    case EnviadaCocina = 'enviada_cocina';
    case Lista = 'lista';
    case PorCobrar = 'por_cobrar';
    case Pagada = 'pagada';
    case Cancelada = 'cancelada';
}
