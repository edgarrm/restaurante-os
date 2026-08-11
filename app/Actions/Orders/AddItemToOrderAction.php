<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\TableStatus;
use App\Exceptions\Orders\MenuItemNotAvailableException;
use App\Exceptions\Orders\TableNotAcceptingOrdersException;
use App\Models\MenuItem;
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
}
