<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\Orders\EmptyOrderException;
use App\Models\Order;

class SendOrderToKitchenAction
{
    /**
     * Envía la orden a cocina: pasa a `enviada_cocina` (ver
     * _ai/specs/toma-de-pedido.spec.md, Happy Path #8). Los ítems ya
     * quedan `pendiente` desde que se agregaron (default de la columna),
     * no hace falta tocarlos aquí.
     *
     * @throws EmptyOrderException si la orden no tiene ítems
     */
    public function handle(Order $order): Order
    {
        if ($order->items()->doesntExist()) {
            throw new EmptyOrderException;
        }

        $order->update(['status' => OrderStatus::EnviadaCocina]);

        return $order;
    }
}
