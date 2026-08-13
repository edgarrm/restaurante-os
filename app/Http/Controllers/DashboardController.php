<?php

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Enums\TableStatus;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Table;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Resumen del día para el admin (_ai/specs/dashboard-del-dia.spec.md,
     * #13). Sin Action: solo lectura, composición de queries, sin lógica
     * de negocio que reutilizar — mismo criterio que
     * KitchenController::index() / InventarioController::index() (ver
     * PASO 0 del spec).
     */
    public function index(): Response
    {
        $today = today();

        return Inertia::render('dashboard/Index', [
            'salesTotal' => (float) Payment::query()
                // `Payment` no tiene `BelongsToTenant` propio — hereda el
                // aislamiento vía `Order` (F-05, mismo patrón que
                // `OrderItem`, ver KitchenController::markReady()).
                // `whereHas('order')` aplica el TenantScope de `Order`.
                ->whereHas('order')
                ->whereDate('paid_at', $today)
                ->sum('amount'),

            'activeTables' => Table::query()
                ->where('status', '!=', TableStatus::Libre)
                ->orderBy('name')
                ->get(),

            'todayReservations' => Reservation::query()
                ->whereDate('reserved_at', $today)
                ->whereIn('status', [ReservationStatus::Confirmada, ReservationStatus::Sentada])
                ->with('table')
                ->orderBy('reserved_at')
                ->get(),
        ]);
    }
}
