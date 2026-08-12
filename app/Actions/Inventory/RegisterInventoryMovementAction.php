<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Enums\InventoryMovementType;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RegisterInventoryMovementAction
{
    /**
     * Aplica un ajuste manual de stock (ver _ai/specs/inventario.spec.md,
     * Happy Path): crea el `InventoryMovement` y actualiza
     * `quantity_on_hand` del insumo. Una `salida` que dejaría el stock
     * negativo se rechaza (Edge Cases) sin mutar el insumo ni crear el
     * movimiento.
     *
     * `created_by` viene siempre de `$createdBy`, nunca de `$data` — no hay
     * forma de que el llamador inyecte otro valor (mismo control que F-03,
     * `Payment.collected_by`, ver `_ai/docs/data-model.md`).
     *
     * `quantity_on_hand` no es fillable (ver InventoryItem), así que se
     * muta con `forceFill()`.
     *
     * @param  array{type: string, quantity: int|float|string, note?: string|null}  $data
     *
     * @throws InsufficientStockException si una `salida` deja el stock negativo
     */
    public function handle(InventoryItem $item, array $data, User $createdBy): InventoryItem
    {
        $validated = Validator::make($data, [
            'type' => ['required', Rule::enum(InventoryMovementType::class)],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string'],
        ], [
            'quantity.min' => 'La cantidad debe ser mayor a 0.',
        ])->validate();

        $type = InventoryMovementType::from($validated['type']);
        $quantity = (float) $validated['quantity'];

        $newQuantity = $type === InventoryMovementType::Entrada
            ? (float) $item->quantity_on_hand + $quantity
            : (float) $item->quantity_on_hand - $quantity;

        if ($newQuantity < 0) {
            throw new InsufficientStockException($item);
        }

        $item->movements()->create([
            'type' => $type,
            'quantity' => $quantity,
            'note' => $validated['note'] ?? null,
            'created_by' => $createdBy->id,
        ]);

        $item->forceFill(['quantity_on_hand' => $newQuantity])->save();

        return $item;
    }
}
