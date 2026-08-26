<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Enums\OrderStatus;
use App\Exceptions\Tables\TableHasActiveOrderException;
use App\Models\Table;

class DeleteTableAction
{
    /**
     * Elimina (soft delete) una mesa, salvo que tenga una orden con una
     * cuenta viva (`abierta`, `enviada_cocina`, `lista` o `por_cobrar`) —
     * ver _ai/specs/gestion-mesas.spec.md, Edge Cases. `lista`/`por_cobrar`
     * también bloquean: son dinero pendiente de cobrar, no solo "orden en
     * curso" — dejarlas fuera permitía borrar una mesa con una cuenta por
     * cobrar y volvía inaccesible su cobro (404 vía route binding, `Table`
     * usa SoftDeletes).
     *
     * @throws TableHasActiveOrderException
     */
    public function handle(Table $table): void
    {
        $tieneOrdenActiva = $table->orders()
            ->whereIn('status', [
                OrderStatus::Abierta,
                OrderStatus::EnviadaCocina,
                OrderStatus::Lista,
                OrderStatus::PorCobrar,
            ])
            ->exists();

        if ($tieneOrdenActiva) {
            throw new TableHasActiveOrderException;
        }

        $table->delete();
    }
}
