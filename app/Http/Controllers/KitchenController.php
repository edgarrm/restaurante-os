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
     * la orden, según el Happy Path del spec. `table` e `items.menuItem` se
     * cargan para que la pantalla muestre el nombre de la mesa y del
     * platillo en vez de solo IDs (mismo criterio que PaymentController).
     *
     * Cuando una orden pasa a `lista` (ver MarkOrderItemReadyAction) deja
     * de aparecer en `orders`, pero se muestra en `completedOrders` (PASO
     * 0, ver decision-log.md) — las 20 más recientes, informativas
     * únicamente (todos sus ítems ya están `listo`, no hay acción posible
     * sobre ellas desde esta pantalla).
     */
    public function index(): Response
    {
        $orders = Order::query()
            ->where('status', OrderStatus::EnviadaCocina)
            ->with(['table', 'items.menuItem'])
            ->oldest('opened_at')
            ->get();

        $completedOrders = Order::query()
            ->where('status', OrderStatus::Lista)
            ->with(['table', 'items.menuItem'])
            ->latest('updated_at')
            ->limit(20)
            ->get();

        return Inertia::render('cocina/Index', [
            'orders' => $orders,
            'completedOrders' => $completedOrders,
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
