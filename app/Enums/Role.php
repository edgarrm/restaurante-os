<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Los 3 roles fijos del MVP (ver ADR-003). Sin paquete de permisos: cada
 * capacidad se resuelve consultando este campo directamente o vía Policy.
 */
enum Role: string
{
    case Admin = 'admin';
    case Mesero = 'mesero';
    case Cocina = 'cocina';
}
