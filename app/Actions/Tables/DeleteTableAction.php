<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Enums\OrderStatus;
use App\Exceptions\Tables\TableHasActiveOrderException;
use App\Models\Table;

class DeleteTableAction
{
    /**
     * Elimina (soft delete) una mesa, salvo que tenga una orden `abierta` o
     * `enviada_cocina` — ver _ai/specs/gestion-mesas.spec.md, Edge Cases.
     *
     * @throws TableHasActiveOrderException
     */
    public function handle(Table $table): void
    {
        $tieneOrdenActiva = $table->orders()
            ->whereIn('status', [OrderStatus::Abierta, OrderStatus::EnviadaCocina])
            ->exists();

        if ($tieneOrdenActiva) {
            throw new TableHasActiveOrderException;
        }

        $table->delete();
    }
}
