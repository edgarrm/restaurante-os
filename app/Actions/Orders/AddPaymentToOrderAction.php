<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\TableStatus;
use App\Models\Order;
use App\Models\User;

class AddPaymentToOrderAction
{
    /**
     * Registra un pago — parcial o que completa el total — contra una orden
     * (ver _ai/specs/division-de-cuenta.spec.md, US-3.2). A diferencia de
     * `CloseOrderAction`, nunca rechaza por monto insuficiente: crea el
     * `Payment` y solo cierra la orden + libera la mesa cuando la suma de
     * todos sus pagos alcanza o supera `Order::total()`.
     *
     * Idempotente: si la orden ya está `pagada`, no crea un segundo
     * `Payment` (mismo criterio que `CloseOrderAction`).
     *
     * F-03 (_ai/docs/threat-model.md): `collected_by` viene siempre de
     * `$collectedBy` — no hay forma de que el llamador inyecte otro valor.
     */
    public function handle(Order $order, float $amount, PaymentMethod $method, User $collectedBy): Order
    {
        if ($order->status === OrderStatus::Pagada) {
            return $order;
        }

        $order->payments()->create([
            'collected_by' => $collectedBy->id,
            'amount' => $amount,
            'method' => $method,
            'paid_at' => now(),
        ]);

        $paid = (float) $order->payments()->sum('amount');

        if ($paid >= $order->total()) {
            $order->update(['status' => OrderStatus::Pagada, 'closed_at' => now()]);

            // 'status' no es fillable en Table (ver .ai/rules/actions.md),
            // así que se cambia con forceFill en vez de update().
            $order->table->forceFill(['status' => TableStatus::Libre])->save();
        }

        return $order->fresh();
    }
}
