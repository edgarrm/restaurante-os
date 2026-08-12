<?php

namespace App\Http\Controllers;

use App\Actions\Reservations\CreateReservationAction;
use App\Exceptions\Reservations\PastReservationException;
use App\Models\Reservation;
use App\Models\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ReservationController extends Controller
{
    /**
     * Calendario de reservas del día (_ai/specs/reservas.spec.md, US-4.2).
     *
     * PASO 0a (ver decision-log.md): filtra solo por `reserved_at` de hoy,
     * sin excluir por `status` — el staff también necesita ver las
     * canceladas del día. Sin selector de fecha (fuera de alcance, no hay
     * query param en el contrato).
     */
    public function index(): Response
    {
        $reservations = Reservation::query()
            ->whereDate('reserved_at', today())
            ->orderBy('reserved_at')
            ->get();

        return Inertia::render('reservas/Index', [
            'reservations' => $reservations,
            'tables' => Table::query()->orderBy('name')->get(),
        ]);
    }

    /**
     * Crea una reserva (US-4.1).
     *
     * F-05 (_ai/docs/threat-model.md): `table_id` se resuelve vía
     * `whereKey()->firstOrFail()` (respeta `TenantScope`), nunca con una
     * regla `exists:tables,id` del Validator — igual que `menu_item_id` en
     * `_ai/specs/toma-de-pedido.spec.md` (#5).
     */
    public function store(Request $request, CreateReservationAction $action): RedirectResponse
    {
        $data = $request->validate([
            'customer_name' => ['required', 'string'],
            'customer_phone' => ['required', 'string'],
            'party_size' => ['required', 'integer', 'min:1'],
            'reserved_at' => ['required', 'date'],
            'table_id' => ['nullable', 'integer'],
        ], [
            'customer_name.required' => 'Completa nombre, teléfono, personas y hora.',
            'customer_phone.required' => 'Completa nombre, teléfono, personas y hora.',
            'party_size.required' => 'Completa nombre, teléfono, personas y hora.',
            'reserved_at.required' => 'Completa nombre, teléfono, personas y hora.',
        ]);

        $table = null;
        if (! empty($data['table_id'])) {
            $table = Table::query()->whereKey($data['table_id'])->firstOrFail();
        }

        try {
            $action->handle($data, $table);
        } catch (PastReservationException $exception) {
            // abort(422, ...) plano no lleva X-Inertia en un cliente Inertia
            // real (Accept: text/html) — Inertia lo muestra como modal con
            // HTML crudo en vez del error inline del form. Mismo fix que
            // OrderController/PaymentController (ver decision-log.md, PASO 0
            // de Toma de Pedido y Cobro).
            throw ValidationException::withMessages([
                'reserved_at' => $exception->getMessage(),
            ]);
        }

        return to_route('reservas.index');
    }
}
