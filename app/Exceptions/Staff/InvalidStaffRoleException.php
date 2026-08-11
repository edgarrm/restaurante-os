<?php

declare(strict_types=1);

namespace App\Exceptions\Staff;

use RuntimeException;

/**
 * Se lanza al intentar asignar (crear o editar) role=admin, o cualquier
 * valor fuera de mesero/cocina, a una cuenta de staff desde este flujo —
 * ver _ai/specs/gestion-staff.spec.md, Edge Cases: "cuentas admin se
 * gestionan fuera de este flujo". Defensa complementaria a que `role`
 * nunca sea fillable en User (F-04, _ai/docs/threat-model.md): esta
 * excepción rechaza el valor; la ausencia de Fillable rechaza el vector.
 */
class InvalidStaffRoleException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No se pueden crear cuentas de administrador desde aquí.');
    }
}
