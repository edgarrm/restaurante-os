<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\TableStatus;
use App\Exceptions\Orders\InsufficientPaymentException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;

class CloseOrderAction
{
    /**
     * Aplica un pago y cierra la cuenta (ver _ai/specs/cobro.spec.md, Happy
     * Path): crea el `Payment`, la orden pasa a `pagada` y la mesa a
     * `libre`. Idempotente: si la orden ya está `pagada`, no crea un
     * segundo `Payment` (Edge Cases, "doble tap en Confirmar pago").
     *
     * F-03 (_ai/docs/threat-model.md): `collected_by` viene siempre de
     * `$collectedBy` — no hay forma de que el llamador inyecte otro valor,
     * a diferencia de leerlo de un array de datos del request.
     *
     * @throws InsufficientPaymentException si `$amount` no cubre el total de la orden
     */
    public function handle(Order $order, float $amount, PaymentMethod $method, User $collectedBy): Order
    {
        if ($order->status === OrderStatus::Pagada) {
            return $order;
        }

        $total = (float) $order->items->sum(
            fn (OrderItem $item): float => $item->quantity * (float) $item->unit_price
        );

        if ($amount < $total) {
            throw new InsufficientPaymentException($total);
        }

        $order->payments()->create([
            'collected_by' => $collectedBy->id,
            'amount' => $amount,
            'method' => $method,
            'paid_at' => now(),
        ]);

        $order->update(['status' => OrderStatus::Pagada, 'closed_at' => now()]);

        // 'status' no es fillable en Table (igual que en
        // OpenOrReuseOrderForTableAction), así que se cambia con forceFill
        // en vez de update().
        $order->table->forceFill(['status' => TableStatus::Libre])->save();

        return $order;
    }
}
