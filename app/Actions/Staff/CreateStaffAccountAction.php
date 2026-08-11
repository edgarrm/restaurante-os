<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Enums\Role;
use App\Exceptions\Staff\InvalidStaffRoleException;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateStaffAccountAction
{
    /**
     * Crea una cuenta de staff (mesero/cocina) asignada al tenant actual —
     * ver _ai/specs/gestion-staff.spec.md, Happy Path.
     *
     * F-04 (_ai/docs/threat-model.md): `role` y `tenant_id` nunca llegan
     * por mass assignment. Solo se leen explícitamente las claves
     * name/email/password/role de $data — cualquier otra clave (ej. un
     * `tenant_id` inyectado) se ignora por completo, igual que
     * OnboardTenantAction. `tenant_id` lo rellena `BelongsToTenant`
     * automáticamente desde el tenant ya inicializado de la request del
     * admin (a diferencia de OnboardTenantAction, que lo asigna a mano
     * porque ahí el tenant todavía no existe como "actual").
     *
     * @param  array{name: string, email: string, password: string, role: string}  $data
     *
     * @throws InvalidStaffRoleException si `role` no es mesero/cocina
     */
    public function handle(array $data): User
    {
        $role = $this->whitelistedRole($data['role']);

        Validator::make(
            [
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ],
            [
                'name' => ['required', 'string'],
                'email' => ['required', 'email', 'unique:users,email'],
                'password' => ['required', 'string', Password::default()],
            ],
            [
                'email.unique' => 'Ya existe una cuenta con este correo.',
            ]
        )->validate();

        $user = new User;
        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->password = $data['password'];
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function whitelistedRole(string $role): Role
    {
        if (! in_array($role, [Role::Mesero->value, Role::Cocina->value], true)) {
            throw new InvalidStaffRoleException;
        }

        return Role::from($role);
    }
}
