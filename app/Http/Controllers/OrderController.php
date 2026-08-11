<?php

namespace App\Http\Controllers;

use App\Actions\Orders\AddItemToOrderAction;
use App\Actions\Orders\OpenOrReuseOrderForTableAction;
use App\Actions\Orders\SendOrderToKitchenAction;
use App\Enums\OrderStatus;
use App\Exceptions\Orders\EmptyOrderException;
use App\Exceptions\Orders\MenuItemNotAvailableException;
use App\Exceptions\Orders\TableNotAcceptingOrdersException;
use App\Models\MenuItem;
use App\Models\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function show(Request $request, Table $table, OpenOrReuseOrderForTableAction $action): Response
    {
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
            abort(422, $exception->getMessage());
        }

        return to_route('pedido.show', $table);
    }

    public function send(Table $table, SendOrderToKitchenAction $action): RedirectResponse
    {
        $order = $table->orders()->where('status', OrderStatus::Abierta)->latest()->firstOrFail();

        try {
            $action->handle($order);
        } catch (EmptyOrderException $exception) {
            abort(422, $exception->getMessage());
        }

        return to_route('pedido.show', $table);
    }
}
