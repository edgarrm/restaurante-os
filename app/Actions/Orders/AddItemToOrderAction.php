<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Enums\TableStatus;
use App\Exceptions\Orders\MenuItemNotAvailableException;
use App\Exceptions\Orders\TableNotAcceptingOrdersException;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Table;
use App\Models\User;

class AddItemToOrderAction
{
    /**
     * Agrega un platillo a la orden activa de una mesa (ver
     * _ai/specs/toma-de-pedido.spec.md, Happy Path). Si el platillo ya
     * está en la cuenta, incrementa `quantity` del renglón existente en
     * vez de duplicarlo. `unit_price` es un snapshot del precio actual del
     * `MenuItem`, tomado solo la primera vez que se agrega.
     *
     * `available` se revalida aquí en el servidor, nunca se confía en el
     * estado que trae el cliente (F-05/Security Considerations del spec).
     *
     * @throws TableNotAcceptingOrdersException si la mesa está en `por_cobrar`
     * @throws MenuItemNotAvailableException si el platillo no está disponible
     */
    public function handle(Table $table, MenuItem $menuItem, int $quantity, User $openedBy): OrderItem
    {
        if ($table->status === TableStatus::PorCobrar) {
            throw new TableNotAcceptingOrdersException;
        }

        if (! $menuItem->available) {
            throw new MenuItemNotAvailableException;
        }

        $order = (new OpenOrReuseOrderForTableAction)->handle($table, $openedBy);

        $this->reopenIfAlreadyReady($order);

        $orderItem = $order->items()->where('menu_item_id', $menuItem->id)->first();

        if ($orderItem) {
            $orderItem->increment('quantity', $quantity);

            return $orderItem;
        }

        return $order->items()->create([
            'menu_item_id' => $menuItem->id,
            'quantity' => $quantity,
            'unit_price' => $menuItem->price,
        ]);
    }

    /**
     * Bug REDEV-31: agregar un ítem a una orden `lista` (todos sus ítems
     * previos ya `listo`) nunca la hacía reaparecer en `GET /cocina`, que
     * solo filtra por `Order.status = enviada_cocina`. Regresar la orden a
     * `enviada_cocina` aquí reutiliza ese mismo filtro sin tocar
     * `KitchenController` — decidido vía `AskUserQuestion` sobre ampliar el
     * query de cocina o bloquear el agregado (ver decision-log.md).
     */
    private function reopenIfAlreadyReady(Order $order): void
    {
        if ($order->status === OrderStatus::Lista) {
            $order->update(['status' => OrderStatus::EnviadaCocina]);
        }
    }
}
