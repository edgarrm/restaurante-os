<?php

namespace App\Http\Controllers;

use App\Actions\Inventory\CreateInventoryItemAction;
use App\Actions\Inventory\RegisterInventoryMovementAction;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Models\InventoryItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InventarioController extends Controller
{
    /**
     * Lista los insumos del restaurante (_ai/specs/inventario.spec.md,
     * US-5.1). Nombre de componente con mayúscula inicial
     * (`Inventario/Index`, no `inventario/Index`) — así lo especifica
     * `x-inertia-component` en api-contract.yaml, a diferencia del resto
     * de dominios (ver PASO 0 del spec).
     */
    public function index(): Response
    {
        return Inertia::render('Inventario/Index', [
            'items' => InventoryItem::query()->orderBy('name')->get(),
        ]);
    }

    /**
     * Crea un insumo (US-5.1). Endpoint agregado en PASO 0 del spec —
     * gap de cobertura entre el PRD y api-contract.yaml, mismo criterio
     * que US-6.3 en `gestion-mesas.spec.md`.
     */
    public function store(Request $request, CreateInventoryItemAction $action): RedirectResponse
    {
        Gate::authorize('create', InventoryItem::class);

        $action->handle([
            'name' => $request->string('name')->toString(),
            'unit' => $request->string('unit')->toString(),
            'low_stock_threshold' => $request->input('low_stock_threshold'),
            'quantity_on_hand' => $request->input('quantity_on_hand'),
        ]);

        return to_route('inventario.index');
    }

    /**
     * Registra una entrada/salida manual de stock (US-5.2).
     *
     * `created_by` nunca se lee del request — siempre es
     * `$request->user()`, pasado explícitamente a
     * `RegisterInventoryMovementAction`. Un `created_by` en el body
     * (spoofed) se ignora por completo.
     */
    public function adjust(Request $request, InventoryItem $item, RegisterInventoryMovementAction $action): RedirectResponse
    {
        Gate::authorize('update', $item);

        try {
            $action->handle($item, [
                'type' => $request->string('type')->toString(),
                'quantity' => $request->input('quantity'),
                'note' => $request->input('note'),
            ], $request->user());
        } catch (InsufficientStockException $exception) {
            // ValidationException, no abort(422, ...): un abort() plano no
            // trae el header X-Inertia, así que el cliente Inertia real lo
            // trata como respuesta "no-Inertia" y muestra un modal con el
            // HTML crudo del error en vez del mensaje del spec — mismo bug
            // ya documentado en OrderController/PaymentController/
            // ReservationController (ver decision-log.md).
            throw ValidationException::withMessages(['quantity' => $exception->getMessage()]);
        }

        return to_route('inventario.index');
    }
}
