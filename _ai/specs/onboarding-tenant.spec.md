# Feature: Onboarding de Tenant (Restaurante nuevo en el SaaS)

## Status
[x] Draft  [ ] Review  [ ] Approved  [ ] Implemented

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

> ⚠️ **Bloqueadores del threat model (`_ai/docs/threat-model.md`).** Este spec
> prueba login por subdominio, así que no puede implementarse mientras F-01 y
> F-02 sigan abiertos — sus tests de aislamiento fallarían o, peor, pasarían
> por la razón equivocada.

- [ ] **F-01 (CRÍTICO) — las rutas de auth no tienen contexto de tenant.**
      Verificado: `php artisan route:list` muestra cero middleware de tenancy
      en `/login`, `/logout`, `/forgot-password`, `/settings/*`.
      `TenantScope` no filtra cuando `tenancy()->initialized` es false, así que
      con `users.tenant_id` implementado, un usuario del restaurante B podría
      autenticarse en el subdominio del restaurante A. **Debe resolverse antes
      de implementar este spec** (decisión de routing pendiente, ver
      `decision-log.md`).
- [ ] **F-02 (ALTO) — agregar `ScopeSessions` al grupo de middleware de
      `routes/tenant.php`.** Ata la sesión al tenant y aborta con 403 si se
      reutiliza bajo otro. Hoy `SESSION_DOMAIN=null` protege por accidente; sin
      `ScopeSessions` no hay defensa si alguien cambia esa variable.
- [ ] `SESSION_DOMAIN=null` debe quedar documentado como decisión de seguridad
      explícita, no como default heredado.

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
- [ ] El comando crea `Tenant`, `Domain` y `User` admin con los datos correctos
- [ ] Subdominio duplicado no crea ninguna entidad (falla antes de escribir)
- [ ] Falla a mitad de la operación no deja registros huérfanos (verifica la
      transacción — ej. mockeando una excepción entre pasos)
- [ ] El `User` creado tiene `role=admin` y el `tenant_id` correcto

### Integration Tests
- [ ] Correr el comando end-to-end permite login exitoso en el subdominio
      nuevo con las credenciales creadas
- [ ] Correr el comando dos veces con el mismo subdominio falla en la segunda
      sin duplicar datos
- [ ] **Aislamiento entre tenants** (primer spec que lo prueba de verdad):
      onboardear dos restaurantes distintos → el admin del restaurante A no
      puede iniciar sesión en el subdominio del restaurante B, y una query
      de `User::all()` ejecutada con tenancy inicializada para A no incluye
      usuarios de B
- [ ] **F-01 — el login es tenant-aware**: las credenciales válidas del admin
      del restaurante B son **rechazadas** en `restauranteA.../login`. Este
      test debe fallar hoy con la configuración actual; si pasa antes de
      resolver F-01, está pasando por la razón equivocada (probablemente
      porque `users.tenant_id` aún no existe) — verificar el motivo, no solo
      el color del test.
- [ ] **F-02 — la sesión está acotada al tenant**: tomar una sesión válida del
      restaurante A y usarla contra el subdominio del restaurante B devuelve
      403, no acceso.

### E2E Tests
- [ ] Happy path completo: comando corre → admin inicia sesión en su
      subdominio → ve `/staff` vacío (no ve datos de ningún otro tenant)

## Definition of Done
- [ ] Todos los test cases de este spec pasando (Pest)
- [ ] Code review completado y aprobado
- [ ] Spec actualizado con comportamiento real implementado
- [ ] Verificado manualmente: onboardear un tenant de prueba y loguearse
- [ ] Sin errores en consola / logs
- [ ] La contraseña del admin nunca aparece en logs, historial de shell, ni
      salida del comando
- [ ] Operación atómica confirmada (transacción DB, verificada con test de
      falla a mitad de camino)
