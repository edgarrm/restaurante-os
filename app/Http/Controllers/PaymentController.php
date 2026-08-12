<?php

namespace App\Http\Controllers;

use App\Actions\Orders\CloseOrderAction;
use App\Actions\Orders\RequestBillAction;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\Orders\InsufficientPaymentException;
use App\Models\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    /**
     * Pantalla de cobro de una mesa (_ai/specs/cobro.spec.md, US-3.1).
     *
     * PASO 0b (ver decision-log.md): abrir esta pantalla es lo que marca la
     * orden y la mesa como `por_cobrar` — no hay endpoint dedicado de
     * "pedir la cuenta" (ver RequestBillAction).
     */
    public function show(Table $table, RequestBillAction $action): Response
    {
        $order = $table->orders()
            ->whereIn('status', [OrderStatus::Abierta, OrderStatus::EnviadaCocina, OrderStatus::Lista, OrderStatus::PorCobrar])
            ->latest()
            ->firstOrFail();

        $order = $action->handle($order);

        return Inertia::render('mesas/Cobro', [
            'order' => $order->load('items'),
        ]);
    }

    /**
     * Aplica el pago y cierra la cuenta (US-3.1).
     *
     * F-03 (_ai/docs/threat-model.md): `collected_by` nunca se lee del
     * request — siempre es `$request->user()`, pasado explícitamente a
     * `CloseOrderAction`. Un `collected_by` en el body (spoofed) se ignora
     * por completo.
     */
    public function close(Request $request, Table $table, CloseOrderAction $action): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
        ]);

        // Incluye `Pagada` para que un doble tap sobre una orden ya cerrada
        // encuentre la misma orden en vez de 404 (Edge Cases: idempotente).
        $order = $table->orders()
            ->whereIn('status', [
                OrderStatus::Abierta,
                OrderStatus::EnviadaCocina,
                OrderStatus::Lista,
                OrderStatus::PorCobrar,
                OrderStatus::Pagada,
            ])
            ->latest()
            ->firstOrFail();

        try {
            $action->handle($order, (float) $data['amount'], PaymentMethod::from($data['method']), $request->user());
        } catch (InsufficientPaymentException $exception) {
            abort(422, $exception->getMessage());
        }

        return to_route('mesas.index');
    }
}
