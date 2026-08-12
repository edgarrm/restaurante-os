<?php

namespace App\Http\Controllers;

use App\Actions\Orders\AddPaymentToOrderAction;
use App\Actions\Orders\CloseOrderAction;
use App\Actions\Orders\RequestBillAction;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\Orders\InsufficientPaymentException;
use App\Models\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
            'table' => $table,
            // 'payments.collector' (_ai/specs/division-de-cuenta.spec.md,
            // US-3.2): historial de pagos ya registrados, para que la
            // pantalla muestre el saldo pendiente en vez de asumir un solo
            // pago.
            'order' => $order->load(['items.menuItem', 'payments.collector']),
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
            // ValidationException, no abort(): un abort() plano no trae el
            // header X-Inertia, así que el cliente Inertia real lo trata
            // como respuesta "no-Inertia" y muestra un modal con el HTML
            // crudo del error en vez del mensaje del spec — mismo bug
            // documentado en OrderController (ver decision-log.md,
            // 2026-08-12, PASO 0 de la pantalla Vue de Toma de Pedido).
            throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
        }

        return to_route('mesas.index');
    }

    /**
     * Registra un pago que puede ser parcial (US-3.2, split bill —
     * _ai/specs/division-de-cuenta.spec.md). A diferencia de `close`, un
     * monto que no cubre el saldo pendiente no es un error: se guarda como
     * abono y la orden sigue abierta.
     *
     * F-03: mismo criterio que `close` — `collected_by` siempre es
     * `$request->user()`, nunca un valor del body.
     */
    public function addPayment(Request $request, Table $table, AddPaymentToOrderAction $action): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
        ]);

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

        $order = $action->handle($order, (float) $data['amount'], PaymentMethod::from($data['method']), $request->user());

        // Mismo saldo cubierto → mismo destino final que `close`: `show`
        // solo acepta órdenes no `pagada` (ver arriba), así que quedarse en
        // pantalla ya no es una opción una vez cerrada la cuenta.
        return $order->status === OrderStatus::Pagada
            ? to_route('mesas.index')
            : to_route('cobro.show', $table);
    }
}
