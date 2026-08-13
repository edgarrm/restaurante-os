<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\TableStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        $this->createPayment($order, $amount, $method, $collectedBy);

        return $this->closeIfCovered($order);
    }

    /**
     * Registra un pago cuyo monto se calcula sumando un grupo de
     * `OrderItem`s seleccionados (REDEV-29, split por ítems — ver
     * _ai/specs/division-de-cuenta.spec.md, "Ampliación"). El monto nunca
     * viene del cliente: se calcula 100% en el servidor a partir de los
     * ítems validados. Reutiliza `createPayment()`/`closeIfCovered()`, los
     * mismos helpers que `handle()`, para no duplicar la lógica de cierre.
     *
     * Idempotente: si la orden ya está `pagada`, no crea un segundo
     * `Payment`.
     *
     * F-03: mismo criterio que `handle()` — `collected_by` viene siempre de
     * `$collectedBy`.
     *
     * @param  array<int, int>  $itemIds
     *
     * @throws ValidationException si algún ítem no pertenece a la orden o
     *                             ya fue asignado a otro pago
     */
    public function handleForItems(Order $order, array $itemIds, PaymentMethod $method, User $collectedBy): Order
    {
        if ($order->status === OrderStatus::Pagada) {
            return $order;
        }

        $itemIds = array_values(array_unique($itemIds));

        $items = $order->items()->whereIn('id', $itemIds)->whereNull('payment_id')->get();

        if ($items->count() !== count($itemIds)) {
            throw ValidationException::withMessages([
                'item_ids' => 'Uno o más ítems ya fueron cobrados en otro pago.',
            ]);
        }

        $amount = (float) $items->sum(
            fn (OrderItem $item): float => $item->quantity * (float) $item->unit_price
        );

        return DB::transaction(function () use ($order, $amount, $method, $collectedBy, $itemIds) {
            $payment = $this->createPayment($order, $amount, $method, $collectedBy);

            $order->items()->whereIn('id', $itemIds)->update(['payment_id' => $payment->id]);

            return $this->closeIfCovered($order);
        });
    }

    private function createPayment(Order $order, float $amount, PaymentMethod $method, User $collectedBy): Payment
    {
        return $order->payments()->create([
            'collected_by' => $collectedBy->id,
            'amount' => $amount,
            'method' => $method,
            'paid_at' => now(),
        ]);
    }

    private function closeIfCovered(Order $order): Order
    {
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
