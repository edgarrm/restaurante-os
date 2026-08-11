<?php

declare(strict_types=1);

namespace App\Models;

use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * Extiende el Tenant base de stancl/tenancy solo para agregar HasDomains: el
 * paquete no la incluye en su modelo base, pero su propio
 * DomainTenantResolver asume que la relación domains() existe (hace
 * `whereHas('domains', ...)` al resolver un tenant por Domain). Sin esto,
 * InitializeTenancyByDomain revienta con BadMethodCallException en cuanto
 * intenta identificar un tenant — descubierto implementando los tests de
 * aislamiento de _ai/specs/onboarding-tenant.spec.md (F-01).
 */
class Tenant extends BaseTenant
{
    use HasDomains;
}
