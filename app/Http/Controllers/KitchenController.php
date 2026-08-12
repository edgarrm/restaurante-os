<?php

namespace App\Http\Controllers;

use App\Actions\Orders\MarkOrderItemReadyAction;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class KitchenController extends Controller
{
    /**
     * Vista de cocina / KDS (_ai/specs/cocina-kds.spec.md, US-2.1). Devuelve
     * las órdenes en `enviada_cocina` con todos sus ítems (incluyendo los
     * que ya están `listo`) — así cocina conserva el contexto completo de
     * la orden, según el Happy Path del spec. Cuando una orden pasa a
     * `lista` (ver MarkOrderItemReadyAction), deja de aparecer aquí.
     */
    public function index(): Response
    {
        $orders = Order::query()
            ->where('status', OrderStatus::EnviadaCocina)
            ->with('items')
            ->get();

        return Inertia::render('cocina/Index', [
            'orders' => $orders,
        ]);
    }

    /**
     * Marca un ítem de orden como listo (US-2.2).
     *
     * F-05 (_ai/docs/threat-model.md): `OrderItem` no tiene `BelongsToTenant`
     * propio — hereda el aislamiento vía `Order`. Route model binding
     * implícito (`OrderItem::findOrFail($id)`) NO filtraría por tenant, así
     * que se resuelve manualmente vía `whereHas('order')`, que sí aplica el
     * `TenantScope` de `Order`: un `orderItem` de otro restaurante no
     * coincide y devuelve 404.
     */
    public function markReady(int $orderItem, MarkOrderItemReadyAction $action): RedirectResponse
    {
        $item = OrderItem::query()->whereHas('order')->findOrFail($orderItem);

        $action->handle($item);

        return to_route('cocina.index');
    }
}
