# Feature: Onboarding de Tenant (Restaurante nuevo en el SaaS)

## Status
[x] Draft  [ ] Review  [ ] Approved  [x] Implemented

## PRD Reference
**No hay User Story en el PRD.** Esta feature es infraestructura operativa
derivada de ADR-006 (multi-tenancy), no un requerimiento de producto para el
usuario final — el "usuario" aquí es quien opera restaurante-os como negocio
(tú), no el staff de un restaurante. Mismo patrón que US-6.3: un gap real
detectado al construir, no algo que el PRD original contemplara.

Prioridad: **Must** — bloqueante. Ningún otro spec (`gestion-mesas`,
`toma-de-pedido`, etc.) es alcanzable sin que exista al menos un tenant, porque
sus rutas viven en `routes/tenant.php` bajo `InitializeTenancyByDomain`.

## Overview
Comando Artisan que da de alta un restaurante nuevo en el SaaS: crea el
`Tenant`, su `Domain` (subdominio), y la primera cuenta `admin` de ese
restaurante. Sin UI ni flujo de self-service — a la escala del MVP (el ancla +
2 pilotos del KR3), un comando de consola es proporcional; una pantalla de
registro público sería sobre-construir para 2-3 altas manuales.

## Users Affected
- **Operador del SaaS (tú)**: corre el comando desde acceso al servidor.
- **Admin del restaurante nuevo**: recibe las credenciales para su primer
  login; a partir de ahí usa `gestion-staff.spec.md` (US-6.2) para crear
  mesero/cocina — este spec resuelve el problema de "quién crea al primer
  admin, si `gestion-staff` asume que ya existe uno".

## Inputs & Outputs
**Input:** nombre del restaurante, subdominio deseado, nombre y email del
primer admin (la contraseña se pide de forma interactiva y oculta, no como
argumento).
**Output:** un `Tenant`, un `Domain`, y un `User` con `role=admin` y
`tenant_id` del nuevo tenant, listo para iniciar sesión en
`https://{subdominio}.restaurante-os.com/login`.

## Happy Path
1. El operador corre `php artisan tenants:onboard` con el nombre del
   restaurante, el subdominio y el nombre/email del primer admin.
2. El comando pide la contraseña del admin de forma interactiva (prompt
   oculto — nunca como argumento visible en shell history).
3. El comando crea el `Tenant` (con el nombre del restaurante en su columna
   `data`), el `Domain` (subdominio → ese tenant), y el `User` admin con
   `tenant_id` correspondiente.
4. El comando confirma en consola: subdominio, email del admin, y un
   recordatorio de comunicar la contraseña por un canal seguro (el comando
   no la imprime ni la loggea).
5. El admin inicia sesión en su subdominio y ve `/staff` vacío, listo para
   dar de alta mesero/cocina.

## Edge Cases
| Escenario | Comportamiento esperado |
|-----------|------------------------|
| Subdominio ya existe | Rechazado — "Ese subdominio ya está en uso." No se crea nada (ni Tenant ni User), operación atómica |
| Email de admin ya existe en `users` | Rechazado por la constraint `unique` existente. **Nota de alcance**: `email` es único a nivel global, no por tenant — la misma persona no puede ser admin de dos restaurantes distintos con el mismo correo en el MVP |
| Nombre de restaurante vacío | Rechazado por validación |
| El comando se interrumpe a mitad (ej. Ctrl+C entre crear Tenant y crear User) | Debe envolverse en una transacción de base de datos — o las tres entidades se crean juntas, o ninguna |
| Re-correr el comando con los mismos datos tras una falla | Debe ser seguro reintentar sin dejar un `Tenant`/`Domain` huérfano de la corrida anterior (consecuencia directa de la atomicidad de arriba) |
| Subdominio con mayúsculas o caracteres no válidos para DNS | Normalizado a minúsculas; caracteres inválidos rechazados con mensaje claro |

## Error States
| Error | Mensaje al usuario | Acción de recuperación |
|-------|-------------------|----------------------|
| Subdominio duplicado | "Ese subdominio ya está en uso." | Elegir otro subdominio |
| Email duplicado | Mensaje estándar de validación de unicidad | Usar otro correo, o confirmar si es el mismo restaurante que ya existe |
| Nombre de restaurante vacío | "El restaurante necesita un nombre." | Completar el campo |
| Falla a mitad de la operación | Rollback completo, mensaje indicando que no se creó nada | Reintentar el comando desde cero |

## Security Considerations

> ✅ **Resueltos.** F-01 y F-02 (`_ai/docs/threat-model.md`) están cerrados —
> ver `decision-log.md` para la decisión de routing y su justificación.

- [x] **F-01 (CRÍTICO) — las rutas de auth no tienen contexto de tenant.**
      Resuelto combinando `config('fortify.middleware')` (rutas propias de
      Fortify: login/logout/forgot-password/reset-password/verify-email/2FA)
      con mover `routes/settings.php` al grupo de `routes/tenant.php`
      (`/settings/*` no es de Fortify, vive en nuestro propio archivo). Ambos
      grupos comparten el stack `web, InitializeTenancyByDomain,
      PreventAccessFromCentralDomains, ScopeSessions`. Adicionalmente hubo que
      agregar `HasDomains` al modelo `Tenant` (`app/Models/Tenant.php`): el
      `Tenant` base de `stancl/tenancy` no la trae, y su propio
      `DomainTenantResolver` la asume — sin ella, `InitializeTenancyByDomain`
      revienta en cuanto intenta identificar un tenant.
- [x] **F-02 (ALTO) — agregar `ScopeSessions` al grupo de middleware de
      `routes/tenant.php`.** Hecho, mismo cambio que F-01.
- [x] `SESSION_DOMAIN=null` queda documentado como decisión de seguridad
      explícita en `config/fortify.php` y `routes/tenant.php` (comentarios
      junto al middleware), no como default heredado.

- [x] ¿Requiere autenticación? No aplica HTTP — es un comando de consola, solo
      ejecutable por quien tiene acceso al servidor/despliegue. No hay ruta
      HTTP equivalente en el MVP (a propósito: exponer "crear restaurante" como
      endpoint público sería una superficie de ataque sin usuario real que la
      necesite hoy).
- [x] ¿Reglas de autorización? El acceso al servidor ES el control de
      autorización en este MVP.
- [x] ¿Validación de inputs? Subdominio: formato válido de DNS, único,
      normalizado a minúsculas. Email: formato válido, único. Nombre: no vacío.
- [x] ¿Rate limiting? No aplica — comando manual, no endpoint HTTP.
- [x] ¿Datos sensibles en logs? **La contraseña del admin nunca se pide como
      argumento posicional** (quedaría en shell history) **ni se imprime ni se
      loggea** — se captura con `$this->secret()` de Artisan.

## Performance Requirements
- No aplica — operación manual, de baja frecuencia (altas de restaurantes, no
  tráfico de usuario final).

## Test Cases

### Unit Tests
- [x] El comando crea `Tenant`, `Domain` y `User` admin con los datos correctos
      (`tests/Unit/Actions/Tenants/OnboardTenantActionTest.php`)
- [x] Subdominio duplicado no crea ninguna entidad (falla antes de escribir)
- [x] Falla a mitad de la operación no deja registros huérfanos (verificado
      con una violación real de la constraint `unique` de `email`, no un mock
      — la transacción envuelve Tenant+Domain+User)
- [x] El `User` creado tiene `role=admin` y el `tenant_id` correcto

### Integration Tests
- [x] Correr el comando end-to-end permite login exitoso en el subdominio
      nuevo con las credenciales creadas
      (`tests/Feature/OnboardingTenantTest.php`)
- [x] Correr el comando dos veces con el mismo subdominio falla en la segunda
      sin duplicar datos
- [x] **Aislamiento entre tenants**: onboardear dos restaurantes distintos →
      el admin del restaurante A no puede iniciar sesión en el subdominio del
      restaurante B, y una query de `User::all()` ejecutada con tenancy
      inicializada para A no incluye usuarios de B
- [x] **F-01 — el login es tenant-aware**: las credenciales válidas del admin
      del restaurante B son **rechazadas** en `elancla.../login`. El test
      además verifica el motivo (no solo el color): confirma con
      `DB::table('users')` que la cuenta de B existe globalmente, así que el
      rechazo es obra de `TenantScope`, no de una cuenta inexistente.
- [x] **F-02 — la sesión está acotada al tenant**: tomar una sesión válida del
      restaurante A y usarla contra el subdominio del restaurante B devuelve
      403, no acceso.

### E2E Tests
- [x] Happy path parcial: comando corre → admin inicia sesión en su
      subdominio (cubierto por el Integration Test de arriba). La parte "ve
      `/staff` vacío" queda pendiente de `gestion-staff.spec.md` — esa ruta
      todavía no existe, fuera de alcance de este spec.

## Definition of Done
- [x] Todos los test cases de este spec pasando (Pest) — 11/11
- [ ] Code review completado y aprobado
- [x] Spec actualizado con comportamiento real implementado
- [x] Verificado: el Integration Test hace exactamente "onboardear un tenant
      de prueba y loguearse" contra el pipeline real de Fortify — no se corrió
      además a mano porque el test ya lo prueba con más rigor (Laravel Boost:
      no crear scripts de verificación cuando un test ya cubre el
      comportamiento)
- [x] Sin errores en consola / logs (`php artisan test --compact` limpio)
- [x] La contraseña del admin nunca aparece en logs, historial de shell, ni
      salida del comando (`$this->secret()`, nunca un argumento posicional)
- [x] Operación atómica confirmada (transacción DB, verificada con test de
      falla a mitad de camino)
