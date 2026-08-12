<?php

namespace App\Http\Controllers;

use App\Actions\Orders\AddItemToOrderAction;
use App\Actions\Orders\OpenOrReuseOrderForTableAction;
use App\Actions\Orders\SendOrderToKitchenAction;
use App\Actions\Orders\UpdateOrderItemQuantityAction;
use App\Enums\OrderStatus;
use App\Enums\TableStatus;
use App\Exceptions\Orders\EmptyOrderException;
use App\Exceptions\Orders\MenuItemNotAvailableException;
use App\Exceptions\Orders\OrderNotEditableException;
use App\Exceptions\Orders\TableNotAcceptingOrdersException;
use App\Models\MenuItem;
use App\Models\OrderItem;
use App\Models\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    /**
     * Toma de pedido de una mesa (_ai/specs/toma-de-pedido.spec.md). Sin
     * paso explícito de "abrir mesa": mesa libre abre una Order nueva y
     * queda ocupada; mesa ocupada reutiliza su Order abierta (ver
     * OpenOrReuseOrderForTableAction).
     */
    public function show(Request $request, Table $table, OpenOrReuseOrderForTableAction $action): Response|RedirectResponse
    {
        // Edge Case del spec: una mesa en por_cobrar ya no acepta más
        // ítems — redirige a la pantalla de cobro con un aviso, en vez de
        // dejar que OpenOrReuseOrderForTableAction lance 404 (no hay
        // ninguna orden Abierta/EnviadaCocina/Lista que reutilizar en ese
        // estado). Bug encontrado construyendo la pantalla Vue: Mapa de
        // Mesas ya evita este camino en el flujo normal (enlaza directo a
        // /cobro), pero una pestaña vieja o el botón "atrás" del navegador
        // sí puede llegar aquí con la mesa ya en por_cobrar.
        if ($table->status === TableStatus::PorCobrar) {
            Inertia::flash('notice', 'Esta mesa ya solicitó la cuenta — no se pueden agregar más platillos.');

            return to_route('cobro.show', $table);
        }

        $order = $action->handle($table, $request->user());

        return Inertia::render('mesas/Pedido', [
            'table' => $table,
            'menuItems' => MenuItem::query()->orderBy('category')->orderBy('name')->get(),
            'order' => $order->load('items'),
        ]);
    }

    public function addItem(Request $request, Table $table, AddItemToOrderAction $action): RedirectResponse
    {
        $data = $request->validate([
            'menu_item_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
        ], [
            'quantity.min' => 'Cantidad inválida.',
            'quantity.integer' => 'Cantidad inválida.',
        ]);

        // F-05 (_ai/docs/threat-model.md): whereKey()->firstOrFail() pasa
        // por BelongsToTenant/TenantScope, así que un menu_item_id de otro
        // tenant devuelve 404 en vez de agregarse — a diferencia de una
        // regla `exists:menu_items,id` en el Validator, que consulta la
        // tabla directamente sin respetar el scope de tenant.
        $menuItem = MenuItem::query()->whereKey($data['menu_item_id'])->firstOrFail();

        try {
            $action->handle($table, $menuItem, $data['quantity'], $request->user());
        } catch (TableNotAcceptingOrdersException|MenuItemNotAvailableException $exception) {
            // ValidationException, no abort(422): un abort() plano no trae
            // el header X-Inertia, así que el cliente Inertia lo trata como
            // respuesta "no-Inertia" y muestra un modal con el HTML crudo
            // de error en vez del mensaje del spec. ValidationException sí
            // viaja por el redirect 302 + errores flasheados que Inertia
            // espera (ver .ai/rules/feature.md) — mismo mecanismo que
            // cualquier error de validación normal del formulario.
            throw ValidationException::withMessages(['menu_item_id' => $exception->getMessage()]);
        }

        return to_route('pedido.show', $table);
    }

    /**
     * Ajusta o quita un ítem de "La Cuenta" (stepper). Solo existe mientras
     * la orden sigue `abierta` (ver UpdateOrderItemQuantityAction).
     * `quantity=0` elimina el renglón.
     */
    public function updateItem(Request $request, Table $table, int $orderItem, UpdateOrderItemQuantityAction $action): RedirectResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:0'],
        ], [
            'quantity.min' => 'Cantidad inválida.',
            'quantity.integer' => 'Cantidad inválida.',
        ]);

        // F-05 (_ai/docs/threat-model.md): igual que
        // KitchenController::markReady, OrderItem no tiene BelongsToTenant
        // propio — se resuelve vía whereHas('order'), que sí aplica el
        // TenantScope de Order. Se restringe además a la orden de esta
        // mesa, para que un OrderItem de otra mesa del mismo tenant no sea
        // editable desde aquí.
        $item = OrderItem::query()
            ->whereHas('order', fn ($query) => $query->where('table_id', $table->id))
            ->findOrFail($orderItem);

        try {
            $action->handle($item, $data['quantity']);
        } catch (OrderNotEditableException $exception) {
            throw ValidationException::withMessages(['quantity' => $exception->getMessage()]);
        }

        return to_route('pedido.show', $table);
    }

    public function send(Table $table, SendOrderToKitchenAction $action): RedirectResponse
    {
        $order = $table->orders()->where('status', OrderStatus::Abierta)->latest()->firstOrFail();

        try {
            $action->handle($order);
        } catch (EmptyOrderException $exception) {
            throw ValidationException::withMessages(['items' => $exception->getMessage()]);
        }

        return to_route('pedido.show', $table);
    }
}
