<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\InventoryItem;
use Illuminate\Support\Facades\Validator;

class CreateInventoryItemAction
{
    /**
     * Crea un insumo nuevo con `quantity_on_hand` inicial opcional (ver
     * _ai/specs/inventario.spec.md, PASO 0 — gap de alta de insumos).
     * `tenant_id` lo rellena `BelongsToTenant` automáticamente.
     *
     * `quantity_on_hand` no es fillable (ver InventoryItem), así que se
     * asigna con `forceFill()` tras crear, mismo patrón que los defaults
     * de estado en `CreateTableAction`/`Order`.
     *
     * @param  array{name: string, unit: string, low_stock_threshold?: int|float|string|null, quantity_on_hand?: int|float|string|null}  $data
     */
    public function handle(array $data): InventoryItem
    {
        $validated = Validator::make($data, [
            'name' => ['required', 'string'],
            'unit' => ['required', 'string'],
            'low_stock_threshold' => ['nullable', 'numeric', 'min:0'],
            'quantity_on_hand' => ['nullable', 'numeric', 'min:0'],
        ], [
            'low_stock_threshold.min' => 'La cantidad no puede ser negativa.',
            'quantity_on_hand.min' => 'La cantidad no puede ser negativa.',
        ])->validate();

        $item = InventoryItem::create([
            'name' => $validated['name'],
            'unit' => $validated['unit'],
            'low_stock_threshold' => $validated['low_stock_threshold'] ?? 0,
        ]);

        $item->forceFill(['quantity_on_hand' => $validated['quantity_on_hand'] ?? 0])->save();

        return $item;
    }
}
