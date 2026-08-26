# ADR-003: Fortify + un campo `role` enum, sin paquete de permisos

## Status
Accepted

## Date
2026-08-10

## Context
US-6.2 del PRD requiere que admin cree cuentas de staff con un rol (mesero/cocina)
y que cada quien vea solo las pantallas de su rol. Laravel Fortify ya está instalado
y provee autenticación por sesión — apropiado para una app Inertia server-rendered,
no se necesita autenticación por token/API separada (ver ADR-001).

La pregunta real es cómo modelar los roles: un paquete de permisos granular
(ej. Spatie Laravel-Permission) o algo más simple. El PRD no pide permisos
configurables por el admin — pide 3 roles fijos (admin, mesero, cocina) con
capacidades fijas conocidas de antemano.

## Decision
Autenticación: Fortify, sesión estándar (ya instalado, sin cambios).
Autorización: un campo `role` (enum: `admin`, `mesero`, `cocina`) en la tabla
`users`, más Policies de Laravel por modelo (`OrderPolicy`, `MenuItemPolicy`, etc.)
que consultan ese campo. Sin paquete de permisos adicional.

## Options Considered

### Opción A: Campo `role` enum + Policies ← ELEGIDA
**Pros:**
- Cero dependencias nuevas — este repo tiene regla explícita de no cambiar
  dependencias sin aprobación (ver CLAUDE.md)
- 3 roles fijos y conocidos no necesitan un sistema de permisos configurable
- Las Policies de Laravel ya son el mecanismo idiomático para "quién puede hacer
  qué" sobre un modelo específico
**Cons:**
- Si en el futuro se necesitan permisos granulares por restaurante (multi-tenant,
  fuera de alcance hoy), habrá que migrar

### Opción B: Spatie Laravel-Permission
**Pros:**
- Permisos y roles configurables sin tocar código
- Estándar de facto en el ecosistema Laravel
**Cons:**
- Dependencia nueva no aprobada — y resuelve un problema (roles configurables por
  el usuario final) que el PRD no tiene: los 3 roles son fijos por diseño
**Rechazada porque:** el problema que resuelve no existe en el alcance actual del
MVP; se puede introducir después si el negocio lo pide explícitamente.

## Consequences

### Positive
- Sin dependencias nuevas que aprobar
- Autorización simple de razonar: `if ($user->role === 'cocina')` o vía Policy

### Negative
- Agregar un cuarto rol o permisos parciales dentro de un rol requiere tocar
  código (aceptable a esta escala; se revisita si el negocio lo exige)

### Neutral
- El middleware de rutas debe verificar `role` explícitamente por grupo de rutas
  (`/cocina/*` solo accesible a `role=cocina` o `admin`)

## Passkeys (resuelto, 2026-08-26)
`laravel/fortify` v1.37 trae `laravel/passkeys` (WebAuthn) como dependencia
directa — venía instalado, sin que nadie lo hubiera pedido explícitamente
(encontrado en auditoría del 2026-08-10, `composer why laravel/passkeys`).

**Decisión:** implementado como método de login **adicional**, no como
reemplazo de email+contraseña — encaja con "staff productivo sin
entrenamiento" (Face ID/Touch ID/PIN del SO reduce fricción de login) y con
que las tablets del piso son compartidas (un autenticador de plataforma
soporta múltiples credenciales por dispositivo, cada miembro de staff
registra la suya — F-03 no se rompe). Detalle completo de la mecánica
(qué trae el paquete de fábrica, qué se construyó, y el hallazgo de
seguridad específico de WebAuthn para multi-tenancy) en
`_ai/specs/passkeys.spec.md`.

**Hallazgo de seguridad no trivial, digno de nota aquí:** a diferencia del
login por contraseña, WebAuthn ata cada credencial a un "Relying Party ID"
en el momento del registro — por defecto derivado de `config('app.url')`
(un único valor global), que en este setup multi-tenant por subdominio
haría que el navegador ofreciera/validara passkeys de un tenant distinto en
el subdominio de otro (RP ID compartido = "same site" para todos los
subdominios). Se resolvió con un middleware nuevo
(`App\Http\Middleware\ScopePasskeysToTenantDomain`) que ata el RP ID al
subdominio real de cada petición — el aislamiento entre tenants ocurre en
la ceremonia criptográfica misma, no solo en el servidor. Ver
decision-log.md, entrada 2026-08-26.

**Implementado:** `_ai/specs/passkeys.spec.md` (spec completo), UI de
registro/gestión/revocación en `/settings/passkeys`, opción "Ingresar con
passkey" en `/login`, 12 tests nuevos (incluye F-05 cross-tenant).

## Related
- ADR-001: Monolito — auth por sesión, no token, porque no hay cliente API externo
