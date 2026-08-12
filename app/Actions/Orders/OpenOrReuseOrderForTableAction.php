<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Enums\TableStatus;
use App\Models\Order;
use App\Models\Table;
use App\Models\User;

class OpenOrReuseOrderForTableAction
{
    /**
     * Abre o reutiliza la orden activa de una mesa, sin un paso explícito
     * de "abrir mesa" (ver _ai/specs/toma-de-pedido.spec.md, Happy Path).
     * Mesa `libre` → crea una `Order` nueva (`abierta`) y marca la mesa
     * `ocupada`. Mesa `ocupada` → reutiliza su `Order` `abierta` existente
     * (dos meseros abriendo la misma mesa casi al mismo tiempo ven y editan
     * la misma orden, sin conflicto).
     */
    public function handle(Table $table, User $openedBy): Order
    {
        if ($table->status === TableStatus::Libre) {
            $order = $table->orders()->create([
                'opened_by' => $openedBy->id,
                'status' => OrderStatus::Abierta,
                'opened_at' => now(),
            ]);

            // 'status' no es fillable en Table (igual que 'available' en
            // MenuItem), así que se cambia con forceFill en vez de update()
            // — ver ToggleMenuItemAvailabilityAction.
            $table->forceFill(['status' => TableStatus::Ocupada])->save();

            return $order;
        }

        // Mesa ocupada puede tener su orden activa en cualquier estado
        // previo a por_cobrar (abierta → enviada_cocina → lista) — no solo
        // abierta. Sin esto, GET /mesas/{table}/pedido devolvía 404 al
        // recargar después de "Enviar a Cocina" (bug encontrado
        // construyendo la pantalla Vue, ver decision-log.md). Mismo
        // criterio de estados "activos" ya usado en RequestBillAction (#7).
        return $table->orders()
            ->whereIn('status', [OrderStatus::Abierta, OrderStatus::EnviadaCocina, OrderStatus::Lista])
            ->latest()
            ->firstOrFail();
    }
}
