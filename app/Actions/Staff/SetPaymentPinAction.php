<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class SetPaymentPinAction
{
    /**
     * Fija (o cambia) el PIN de cobro del propio usuario — ver
     * _ai/specs/bloqueo-tablet-pin.spec.md, F-07 del threat model. Cada
     * staff configura su propio PIN desde Settings, mismo patrón de
     * autoservicio que `SecurityController::update()` para la contraseña;
     * a diferencia de la contraseña, no exige el PIN actual para
     * cambiarlo (no reemplaza login, es una verificación corta — ver
     * Security Considerations del spec).
     *
     * F-04 (_ai/docs/threat-model.md), mismo trap documentado en
     * `.ai/rules/actions.md` para `Table.status`/`MenuItem.available`:
     * `pin_hash` no está en `#[Fillable]` de `User`, así que se fija con
     * `forceFill()->save()`, nunca con `update()`.
     *
     * @param  array{pin: string, pin_confirmation?: string}  $data
     */
    public function handle(User $user, array $data): User
    {
        Validator::make($data, [
            'pin' => ['required', 'digits:4', 'confirmed'],
        ])->validate();

        $user->forceFill(['pin_hash' => Hash::make($data['pin'])])->save();

        return $user;
    }
}
