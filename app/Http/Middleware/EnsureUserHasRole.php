<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe un grupo de rutas a los roles indicados
 * (`->middleware('role:admin')`, `->middleware('role:admin,cocina')`).
 *
 * Resuelve F-06 (_ai/docs/threat-model.md): antes de esto, ningún middleware
 * ni Policy verificaba `role` por grupo de rutas — cada spec con
 * restricción por rol lo asumía sin que existiera. Reutilizable tal cual
 * por cualquier spec futuro (ver ADR-003, Consequences → Neutral).
 */
class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowedRoles = array_map(fn (string $role) => Role::from($role), $roles);

        abort_unless(
            $request->user() !== null && in_array($request->user()->role, $allowedRoles, true),
            403
        );

        return $next($request);
    }
}
