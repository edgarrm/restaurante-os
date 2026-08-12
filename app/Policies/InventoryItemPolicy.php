<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\InventoryItem;
use App\Models\User;

/**
 * Ver _ai/specs/inventario.spec.md, Security Considerations: inventario es
 * exclusivo de `role=admin`, sin más granularidad por ahora. El middleware
 * `role:admin` (ver EnsureUserHasRole) ya protege el grupo de rutas
 * /inventario/*; esta Policy existe para autorizar la acción sobre el
 * modelo específico desde el controller (ADR-007), y es el punto de
 * extensión si un spec futuro necesita reglas por instancia. Mismo patrón
 * que TablePolicy/MenuItemPolicy.
 */
class InventoryItemPolicy
{
    public function create(User $user): bool
    {
        return $user->role === Role::Admin;
    }

    public function update(User $user, InventoryItem $inventoryItem): bool
    {
        return $user->role === Role::Admin;
    }
}
