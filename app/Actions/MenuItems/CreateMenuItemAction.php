<?php

declare(strict_types=1);

namespace App\Actions\MenuItems;

use App\Models\MenuItem;
use Illuminate\Support\Facades\Validator;

class CreateMenuItemAction
{
    /**
     * Crea un platillo nuevo con `available=true` por defecto (ver
     * _ai/specs/gestion-menu.spec.md). `tenant_id` lo rellena
     * `BelongsToTenant` automáticamente.
     *
     * @param  array{name: string, category: string, price: float|string}  $data
     */
    public function handle(array $data): MenuItem
    {
        Validator::make($data, [
            'name' => ['required', 'string'],
            'category' => ['required', 'string'],
            'price' => ['required', 'numeric', 'gt:0'],
        ], [
            'price.gt' => 'El precio debe ser mayor a cero.',
        ])->validate();

        return MenuItem::create([
            'name' => $data['name'],
            'category' => $data['category'],
            'price' => $data['price'],
        ]);
    }
}
