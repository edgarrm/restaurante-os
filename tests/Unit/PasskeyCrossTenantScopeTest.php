<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * F-05 (_ai/specs/passkeys.spec.md, "Limitación documentada"): sin poder
 * fabricar una ceremonia WebAuthn real en un test, esta es la prueba directa
 * del mecanismo del que depende `App\Actions\Fortify\AuthorizePasskeyLogin`
 * para negar un login cross-tenant — el Global Scope de `BelongsToTenant`
 * sobre `User` (heredado por `Passkey::user()`, una relación `belongsTo`
 * normal, sin tenant scope propio en el modelo `Passkey`).
 */
test('Passkey::user() resuelve null cuando se consulta desde el tenant equivocado', function () {
    $tenantA = Tenant::create(['name' => 'Tenant A']);
    Domain::create(['tenant_id' => $tenantA->getTenantKey(), 'domain' => Str::lower(Str::random(12)).'.test']);

    tenancy()->initialize($tenantA);
    $userA = User::factory()->create();
    $passkey = $userA->passkeys()->create([
        'name' => 'Passkey de prueba',
        'credential_id' => Str::random(32),
        'credential' => ['type' => 'test'],
    ]);
    tenancy()->end();

    $tenantB = Tenant::create(['name' => 'Tenant B']);
    Domain::create(['tenant_id' => $tenantB->getTenantKey(), 'domain' => Str::lower(Str::random(12)).'.test']);

    tenancy()->initialize($tenantB);
    expect($passkey->fresh()->user)->toBeNull();
    tenancy()->end();

    tenancy()->initialize($tenantA);
    expect($passkey->fresh()->user)->not->toBeNull()
        ->and($passkey->fresh()->user->id)->toBe($userA->id);
    tenancy()->end();
});
