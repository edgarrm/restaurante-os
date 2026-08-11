<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Models\User;

class DeactivateStaffAccountAction
{
    /**
     * Desactiva una cuenta de staff sin eliminarla — no hay eliminación
     * dura porque rompería la FK de `Order.opened_by` con el historial de
     * órdenes pasadas (ver _ai/specs/gestion-staff.spec.md, Edge Cases).
     * `is_active` no es fillable en User, se cambia con forceFill (mismo
     * patrón que ToggleMenuItemAvailabilityAction). El bloqueo real del
     * login lo aplica `Fortify::authenticateUsing` en FortifyServiceProvider.
     */
    public function handle(User $user): User
    {
        $user->forceFill(['is_active' => false])->save();

        return $user;
    }
}
