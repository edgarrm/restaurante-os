<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\MenuItem;
use App\Models\User;

/**
 * Ver _ai/specs/gestion-menu.spec.md, Security Considerations: la gestión
 * de menú es exclusiva de `role=admin`, sin más granularidad por ahora.
 * El middleware `role:admin` (ver EnsureUserHasRole) ya protege el grupo de
 * rutas /menu/*; esta Policy existe para autorizar la acción sobre el
 * modelo específico desde el controller (ADR-003/ADR-007), y es el punto de
 * extensión si un spec futuro necesita reglas por instancia. Mismo patrón
 * que TablePolicy (ver ADR-007).
 */
class MenuItemPolicy
{
    public function create(User $user): bool
    {
        return $user->role === Role::Admin;
    }

    public function update(User $user, MenuItem $menuItem): bool
    {
        return $user->role === Role::Admin;
    }
}
