<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\Orders\InsufficientPaymentException;
use App\Models\Order;
use App\Models\User;

class CloseOrderAction
{
    /**
     * Default instanciado en el propio parámetro ("new in initializers",
     * PHP 8.1+) para que `new CloseOrderAction` sin argumentos —
     * `tests/Unit/Actions/Orders/CloseOrderActionTest.php` (#7), que no se
     * modifica en esta sesión — siga funcionando igual, además de seguir
     * resoluble por el container de Laravel en el controller.
     */
    public function __construct(private AddPaymentToOrderAction $addPaymentToOrder = new AddPaymentToOrderAction) {}

    /**
     * Aplica un pago y cierra la cuenta (ver _ai/specs/cobro.spec.md, Happy
     * Path): crea el `Payment`, la orden pasa a `pagada` y la mesa a
     * `libre`. Idempotente: si la orden ya está `pagada`, no crea un
     * segundo `Payment` (Edge Cases, "doble tap en Confirmar pago").
     *
     * Rechaza si `$amount`, sumado a los pagos ya registrados de la orden,
     * no cubre `Order::total()` (_ai/specs/division-de-cuenta.spec.md) — con
     * cero pagos previos esto es idéntico a comparar `$amount` contra el
     * total, el caso de este spec original. Si cubre, delega el guardado
     * real a `AddPaymentToOrderAction` en vez de duplicar esa lógica.
     *
     * F-03 (_ai/docs/threat-model.md): `collected_by` viene siempre de
     * `$collectedBy` — no hay forma de que el llamador inyecte otro valor,
     * a diferencia de leerlo de un array de datos del request.
     *
     * @throws InsufficientPaymentException si `$amount` no cubre el saldo pendiente de la orden
     */
    public function handle(Order $order, float $amount, PaymentMethod $method, User $collectedBy): Order
    {
        if ($order->status === OrderStatus::Pagada) {
            return $order;
        }

        $total = $order->total();
        $alreadyPaid = (float) $order->payments()->sum('amount');

        if ($alreadyPaid + $amount < $total) {
            throw new InsufficientPaymentException($total);
        }

        return $this->addPaymentToOrder->handle($order, $amount, $method, $collectedBy);
    }
}
