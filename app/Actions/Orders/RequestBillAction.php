<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Enums\TableStatus;
use App\Models\Order;

class RequestBillAction
{
    /**
     * Marca la orden y su mesa como `por_cobrar` (ver
     * _ai/specs/cobro.spec.md, PASO 0b — decisión registrada en
     * decision-log.md). Efecto colateral de `GET /mesas/{table}/cobro`: no
     * hay endpoint dedicado de "pedir la cuenta", abrir la pantalla de
     * cobro ya deja la mesa en ese estado, igual que
     * `OpenOrReuseOrderForTableAction` hace con `GET /mesas/{table}/pedido`.
     *
     * Idempotente: si la orden ya está `por_cobrar` (o ya `pagada`), no
     * hace nada — evita sobrescribir `pagada` de vuelta a `por_cobrar` si
     * la pantalla se refresca después de cobrar.
     */
    public function handle(Order $order): Order
    {
        if (in_array($order->status, [OrderStatus::Abierta, OrderStatus::EnviadaCocina, OrderStatus::Lista], true)) {
            $order->update(['status' => OrderStatus::PorCobrar]);

            // 'status' no es fillable en Table (igual que en
            // OpenOrReuseOrderForTableAction), así que se cambia con
            // forceFill en vez de update().
            $order->table->forceFill(['status' => TableStatus::PorCobrar])->save();
        }

        return $order;
    }
}
