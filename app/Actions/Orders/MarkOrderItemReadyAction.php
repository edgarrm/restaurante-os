<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\OrderItem;

class MarkOrderItemReadyAction
{
    /**
     * Marca un ítem como listo (ver _ai/specs/cocina-kds.spec.md, Happy
     * Path #4). Idempotente: marcar un ítem ya `listo` no lanza error, solo
     * vuelve a escribir el mismo valor.
     *
     * Efecto colateral: cuando este es el último ítem pendiente/preparando
     * de su orden, la `Order` completa pasa a `lista` (Happy Path #5) — vive
     * aquí, no en el controller, para que cualquier llamador se beneficie
     * de la misma regla.
     */
    public function handle(OrderItem $orderItem): OrderItem
    {
        $orderItem->update(['status' => OrderItemStatus::Listo]);

        $hasPendingItems = $orderItem->order->items()
            ->whereIn('status', [OrderItemStatus::Pendiente, OrderItemStatus::Preparando])
            ->exists();

        if (! $hasPendingItems) {
            $orderItem->order->update(['status' => OrderStatus::Lista]);
        }

        return $orderItem;
    }
}
