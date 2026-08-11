<?php

declare(strict_types=1);

namespace App\Actions\MenuItems;

use App\Models\MenuItem;
use Illuminate\Support\Facades\Validator;

class UpdateMenuItemAction
{
    /**
     * Actualiza nombre, categoría y precio de un platillo existente sin
     * tocar `available` (ver _ai/specs/gestion-menu.spec.md). Cambiar el
     * precio no afecta `OrderItem.unit_price` ya creados — ese campo es un
     * snapshot tomado al momento de agregar el ítem a la orden, nunca
     * recalculado (ver _ai/docs/data-model.md).
     *
     * @param  array{name: string, category: string, price: float|string}  $data
     */
    public function handle(MenuItem $menuItem, array $data): MenuItem
    {
        Validator::make($data, [
            'name' => ['required', 'string'],
            'category' => ['required', 'string'],
            'price' => ['required', 'numeric', 'gt:0'],
        ], [
            'price.gt' => 'El precio debe ser mayor a cero.',
        ])->validate();

        $menuItem->update([
            'name' => $data['name'],
            'category' => $data['category'],
            'price' => $data['price'],
        ]);

        return $menuItem;
    }
}
