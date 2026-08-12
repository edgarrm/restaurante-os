<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\Orders\OrderNotEditableException;
use App\Models\OrderItem;

class UpdateOrderItemQuantityAction
{
    /**
     * Ajusta la cantidad de un `OrderItem` ya agregado a la orden (stepper
     * de "La Cuenta", ver _ai/specs/toma-de-pedido.spec.md, Happy Path #7 y
     * Edge Cases). `quantity=0` elimina el renglón en vez de dejar un
     * `OrderItem` con `quantity=0` (Edge Cases del spec).
     *
     * Solo editable mientras la orden sigue `abierta` — una vez enviada a
     * cocina, cambiar cantidades aquí desincronizaría lo que cocina ya está
     * preparando.
     *
     * @throws OrderNotEditableException si la orden ya no está `abierta`
     */
    public function handle(OrderItem $orderItem, int $quantity): ?OrderItem
    {
        if ($orderItem->order->status !== OrderStatus::Abierta) {
            throw new OrderNotEditableException;
        }

        if ($quantity === 0) {
            $orderItem->delete();

            return null;
        }

        $orderItem->update(['quantity' => $quantity]);

        return $orderItem;
    }
}
