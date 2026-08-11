# Decision Log — restaurante-os

> Este archivo existe porque `tenancy for Laravel` se propuso antes de que
> existiera este proceso, no quedó registrada en ningún lado, y casi se pierde
> — solo sobrevivió porque alguien la recordó de memoria (2026-08-10).
>
> Un ADR documenta una decisión ya tomada. Un spec documenta una feature ya
> definida. Ninguno de los dos tiene espacio para "alguien propuso X, no sabemos
> todavía si sí". Este archivo es ese espacio intermedio.

## Cómo usarlo

- Agrega una entrada cuando: alguien propone algo (una librería, un enfoque, un
  requisito) que todavía no se decide; el cliente menciona una restricción o
  preferencia fuera de una sesión formal de Discovery/PRD; surge una pregunta
  que bloquea una decisión de arquitectura pero no amerita detener todo.
- Cuando se decide: mover el resultado a un ADR (`_ai/adrs/`) o a un spec, y
  marcar la entrada aquí como **Resuelta**, con el link al documento final.
- No se borran entradas resueltas — quedan como rastro de por qué se decidió
  algo, igual que la sección "Nota sobre la primera versión" en ADR-006.

## Entradas

### 2026-08-10 — Cómo hacer las rutas de auth tenant-aware (F-01)
**Estado:** 🟢 Resuelta — implementada en `onboarding-tenant.spec.md` (#0),
2026-08-11
**Contexto:** las rutas de Fortify (`/login`, `/logout`, `/forgot-password`,
`/settings/*`) no tienen middleware de tenancy (verificado con `route:list`).
Cuando `users.tenant_id` exista, esto permite que un usuario del restaurante B
se autentique en el subdominio del restaurante A. Ver F-01 en
`_ai/docs/threat-model.md`.
**Opciones evaluadas:** (a) mover las rutas de Fortify a `routes/tenant.php`
con `InitializeTenancyByDomain`; (b) configurar `config/fortify.php` →
`'middleware'` para incluir el middleware de identificación; (c) un guard de
autenticación explícitamente tenant-aware.
**Decisión:** combinación de (a) y (b), porque las rutas afectadas vienen de
dos fuentes distintas que ninguna opción pura cubre sola:
- Las rutas que sí registra el paquete Fortify (`/login`, `/logout`,
  `/forgot-password`, `/reset-password`, `/verify-email`,
  `/confirm-password`, 2FA) se resolvieron con **(b)**: `config('fortify.middleware')`
  es el punto de extensión oficial del paquete
  (`FortifyServiceProvider::configureRoutes()` envuelve sus rutas en
  `Route::group(['middleware' => config('fortify.middleware', ['web'])], ...)`)
  — no requiere tocar vendor ni desregistrar rutas.
- `/settings/*` **no es de Fortify** — vive en nuestro propio
  `routes/settings.php`, cargado antes desde `routes/web.php` (central). Se
  resolvió con el equivalente de **(a)**: se movió el `require` de
  `settings.php` al grupo de middleware de `routes/tenant.php`.
- Ambos grupos comparten el mismo stack: `web, InitializeTenancyByDomain,
  PreventAccessFromCentralDomains, ScopeSessions` (este último cierra F-02 en
  el mismo cambio).
**Bug de plomería descubierto al implementar:** el `Tenant` base de
`stancl/tenancy` no incluye el trait `HasDomains`, pero su propio
`DomainTenantResolver::resolveWithoutCache()` hace `Tenant::whereHas('domains', ...)`
— sin la relación, `InitializeTenancyByDomain` revienta con
`BadMethodCallException` en cuanto intenta identificar un tenant por su
`Domain`. Se creó `app/Models/Tenant.php` extendiendo el `Tenant` del paquete
con `HasDomains`, y `config('tenancy.tenant_model')` ahora apunta ahí.
**Efecto colateral:** todos los tests de `tests/Feature/Auth/*` y
`tests/Feature/Settings/*` (preexistentes del starter kit) necesitaron un
Tenant + Domain de fixture — ver el helper `actingInTenant()` en
`tests/Pest.php`.
**Verificado con:** `tests/Unit/Actions/Tenants/OnboardTenantActionTest.php`,
`tests/Feature/OnboardingTenantTest.php` (incluye los tests explícitos de
F-01/F-02) y la suite completa (`php artisan test --compact`, 37 passed / 4
skipped).

### 2026-08-10 — Bloqueo de tablet desatendida (F-07)
**Estado:** 🟡 Abierta
**Contexto:** las tablets viven en el piso y en cocina, compartidas y a menudo
desatendidas. `SESSION_LIFETIME=120` sin bloqueo por inactividad: quien tome
una tablet abierta puede tomar pedidos y **cobrar** como el mesero que la dejó.
**Tensión:** el diferenciador del producto es "cero fricción" — un bloqueo con
contraseña lo contradice. Alternativas intermedias: PIN corto por usuario, o
reautenticación solo para acciones sensibles (cobro, anulación).
**Es decisión del cliente ancla, no técnica** — depende de cómo opera su piso.
**Ver:** F-07 en `_ai/docs/threat-model.md`, `_ai/specs/cobro.spec.md`.

### 2026-08-10 — Passkeys / WebAuthn (Fortify)
**Estado:** 🟡 Abierta
**Contexto:** `laravel/fortify` instala `laravel/passkeys` como dependencia
directa — está en el proyecto sin que nadie lo haya pedido. Podría reducir la
fricción de onboarding de staff (el diferenciador central del producto) con
login sin contraseña, o podría ser una feature sin usuario real que la pida.
**Bloquea:** `_ai/specs/gestion-staff.spec.md` no lo contempla todavía —
decidir antes de implementar esa feature.
**Ver:** `_ai/adrs/ADR-003-autenticacion-y-roles.md`, sección "Pendiente —
Passkeys"
