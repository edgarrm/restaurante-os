# ADR-007: Middleware `role:` por grupo de rutas + Policy por modelo

## Status
Accepted

## Date
2026-08-11

## Context
F-06 (`_ai/docs/threat-model.md`): 9 de los 10 specs de features afirman cosas
como "`role=cocina` recibe 403 en esta pantalla", pero ningún spec ni código
definía el mecanismo que lo implementa. `gestion-mesas.spec.md` (#1) fue el
primero en necesitarlo en código real — verificado con
`grep -rn "role" app/Http app/Policies`, que no encontró nada antes de este
ADR.

ADR-003 ya había anticipado el mecanismo en su sección Consequences →
Neutral ("El middleware de rutas debe verificar `role` explícitamente por
grupo de rutas") y había nombrado Policies como el mecanismo para "quién
puede hacer qué sobre un modelo". Lo que faltaba era decidir el "cómo" a
nivel de ruta/middleware concreto, y si Policies se necesitaban desde el
día uno o solo cuando un spec pidiera granularidad por instancia.

## Decision
Dos mecanismos complementarios, no uno solo:

1. **Middleware `role:` para acceso a pantalla completa.** Un middleware
   genérico `App\Http\Middleware\EnsureUserHasRole`, con alias `role`
   (`bootstrap/app.php`), acepta una lista de roles como parámetros de
   middleware: `->middleware('role:admin')`,
   `->middleware('role:admin,cocina')`. Se aplica por grupo de rutas en
   `routes/tenant.php` (nunca en `routes/web.php`, que no tiene contexto de
   tenant — ver ADR-006). Compara `$request->user()->role` contra los roles
   permitidos y aborta 403 si no coincide.
2. **Policy por modelo para autorizar la acción específica.** Cada modelo
   con reglas de autorización tiene su `{Model}Policy`
   (`app/Policies/{Model}Policy.php`, auto-descubierta por convención de
   Laravel), autorizada desde el controller vía `Gate::authorize()`. Hoy
   `TablePolicy` solo repite `role === admin` en `create`/`update`/`delete`
   (mismo chequeo que el middleware, sin granularidad adicional) porque
   `gestion-mesas.spec.md` no pide más ("Ninguna otra regla de
   autorización"). Es el punto de extensión ya en su lugar para cuando un
   spec futuro sí necesite reglas por instancia (ej. "un mesero solo cierra
   sus propias órdenes" en `cobro.spec.md`).

## Options Considered

### Opción A: Solo middleware genérico por grupo de rutas
**Pros:**
- Un archivo, cero ceremonia, resuelve el 100% de lo que los specs piden hoy
  ("pantalla completa solo para role=X")
**Cons:**
- Sin lugar establecido para cuando un spec necesite autorizar una acción
  sobre una instancia específica del modelo (no solo "eres admin", sino "eres
  el mesero dueño de esta orden")
**Rechazada como única opción porque:** ADR-003 ya nombra Policies
explícitamente como el mecanismo de este repo para autorización por modelo;
no usarlas en ningún lado sería contradecir una decisión ya tomada, no
solo posponerla.

### Opción B: Solo Policy, autorizada vía `$this->authorize()` en cada acción
**Pros:**
- Un solo mecanismo, sin duplicar la regla `role === admin` en dos lugares
**Cons:**
- No protege una ruta que solo lista/muestra la pantalla sin una acción de
  modelo asociada (ej. un futuro endpoint de solo lectura) — habría que
  acordarse de invocar la Policy en cada controller nuevo, exactamente el
  tipo de olvido que F-06 señala como riesgo ("inconsistente y fácil de
  omitir en una ruta nueva")
**Rechazada porque:** el middleware por grupo de rutas es una defensa
estructural (imposible de omitir por accidente en una ruta del grupo);
depender solo de que cada controller recuerde llamar a la Policy reintroduce
el problema original.

### Opción C: Middleware + Policy (ambos) ← ELEGIDA
**Rechazada:** no aplica, es la opción elegida. Ver Decision.

## Consequences

### Positive
- F-06 queda resuelto de forma reutilizable: cualquier spec futuro con
  restricción por rol de pantalla completa (menú #2, staff #3, KDS #6, ...)
  solo agrega `->middleware('role:admin')` (o los roles que aplique) a su
  grupo de rutas — cero código nuevo.
- El middleware es una defensa que no se puede omitir por accidente en una
  ruta dentro del grupo protegido, a diferencia de depender de que cada
  controller recuerde autorizar.
- El punto de extensión para autorización por instancia (Policies) ya existe
  y sigue el patrón que ADR-003 nombra, en vez de tener que introducirlo
  la primera vez que un spec lo necesite de verdad.

### Negative
- Hoy hay dos lugares que dicen "role === admin" para Table (middleware y
  Policy) — redundante mientras ningún spec pida granularidad por instancia.
  Aceptado conscientemente: la alternativa (solo uno de los dos) deja un
  hueco real, ver Options Considered.

### Neutral
- El nombre del alias es `role` (no `roles` ni `can-role`) — corto, sigue la
  convención de alias de Laravel (`auth`, `verified`, `can`).

## Related
- ADR-003: Autenticación y roles — nombra Policies como mecanismo, no
  especificaba el middleware de rutas
- ADR-006: Multi-tenancy — por qué estas rutas viven en `routes/tenant.php`
- `_ai/docs/threat-model.md`: F-06
- `_ai/specs/gestion-mesas.spec.md`: primer spec que lo implementa
