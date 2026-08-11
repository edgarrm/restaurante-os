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

## Pendiente — Passkeys (no decidido)
`laravel/fortify` v1.37 trae `laravel/passkeys` (WebAuthn) como dependencia
directa — viene instalado, sin que nadie lo haya pedido explícitamente
(encontrado en auditoría del 2026-08-10, `composer why laravel/passkeys`).

Es relevante para el producto, no solo trivia de dependencias: el
diferenciador central es "staff productivo sin entrenamiento" — login sin
contraseña (un dispositivo/tablet reconocido, o biometría) podría reducir aún
más la fricción de que un mesero nuevo empiece a usar el sistema. También
podría ser ruido que no aporta nada a la escala de una tablet compartida en
el piso de un restaurante.

**No implementado, no descartado.** Se necesita una decisión explícita antes
de la Fase 06 (Implementation) de `gestion-staff.spec.md` — hoy ese spec no
lo menciona.

## Related
- ADR-001: Monolito — auth por sesión, no token, porque no hay cliente API externo
