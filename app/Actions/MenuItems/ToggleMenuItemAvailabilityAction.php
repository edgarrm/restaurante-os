<?php

declare(strict_types=1);

namespace App\Actions\MenuItems;

use App\Models\MenuItem;

class ToggleMenuItemAvailabilityAction
{
    /**
     * Alterna `available` sin tocar ningún otro campo (ver
     * _ai/specs/gestion-menu.spec.md, Happy Path #5 — ej. "se acabó el
     * platillo del día"). `available` no es fillable en `MenuItem` (igual
     * que `status` en `Table`), así que se cambia con `forceFill` en vez de
     * `update()`.
     */
    public function handle(MenuItem $menuItem): MenuItem
    {
        $menuItem->forceFill(['available' => ! $menuItem->available])->save();

        return $menuItem;
    }
}
