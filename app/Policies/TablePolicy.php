<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Table;
use App\Models\User;

/**
 * Ver _ai/specs/gestion-mesas.spec.md, Security Considerations: la gestión
 * de mesas es exclusiva de `role=admin`, sin más granularidad por ahora.
 * El middleware `role:admin` (ver EnsureUserHasRole) ya protege el grupo de
 * rutas /mesas/gestion/*; esta Policy existe para autorizar la acción sobre
 * el modelo específico desde el controller (ADR-003), y es el punto de
 * extensión si un spec futuro necesita reglas por instancia.
 */
class TablePolicy
{
    public function create(User $user): bool
    {
        return $user->role === Role::Admin;
    }

    public function update(User $user, Table $table): bool
    {
        return $user->role === Role::Admin;
    }

    public function delete(User $user, Table $table): bool
    {
        return $user->role === Role::Admin;
    }
}
