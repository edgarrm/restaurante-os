<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * Ver _ai/specs/gestion-staff.spec.md, Security Considerations: la gestión
 * de staff es exclusiva de `role=admin`. El middleware `role:admin` (ver
 * EnsureUserHasRole) ya protege el grupo de rutas /staff/*; esta Policy
 * autoriza la acción sobre la cuenta específica desde el controller
 * (ADR-007), y además bloquea actuar sobre una cuenta admin — esta
 * pantalla es exclusivamente para mesero/cocina (Happy Path #2: "el admin
 * mismo no aparece aquí"), las cuentas admin se gestionan fuera de este
 * flujo.
 */
class UserPolicy
{
    public function create(User $user): bool
    {
        return $user->role === Role::Admin;
    }

    public function update(User $user, User $staff): bool
    {
        return $user->role === Role::Admin && $staff->role !== Role::Admin;
    }
}
