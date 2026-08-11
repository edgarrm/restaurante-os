<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Enums\Role;
use App\Exceptions\Staff\InvalidStaffRoleException;
use App\Models\User;

class UpdateStaffRoleAction
{
    /**
     * Edita el role de una cuenta de staff existente (ej. pasó de mesero a
     * cocina — ver _ai/specs/gestion-staff.spec.md, Happy Path #5). `role`
     * no es fillable en User (F-04, _ai/docs/threat-model.md), se cambia
     * con forceFill tras validar contra la lista blanca.
     *
     * @throws InvalidStaffRoleException si `role` no es mesero/cocina
     */
    public function handle(User $user, string $role): User
    {
        if (! in_array($role, [Role::Mesero->value, Role::Cocina->value], true)) {
            throw new InvalidStaffRoleException;
        }

        $user->forceFill(['role' => Role::from($role)])->save();

        return $user;
    }
}
