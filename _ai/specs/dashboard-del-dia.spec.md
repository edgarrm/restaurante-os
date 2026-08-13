# Feature: Dashboard del día

## Status
[x] Draft  [ ] Review  [ ] Approved  [x] Implemented

## PRD Reference
User Story: sin User Story explícita en `_ai/docs/PRD.md` — la pantalla se
agregó directo en `_ai/design/screen-inventory.md` (fila #13). Se redacta
aquí, en PASO 0, como si fuera US-7.1: "Como admin, quiero un resumen del
día (ventas, mesas activas, reservas), para entender de un vistazo cómo va
el turno sin recorrer cada pantalla."
Épica: nueva Épica 7 — Dashboard (no existía en el PRD original).
Prioridad: **Could** — última pantalla pendiente del inventario original
(ver `_ai/CONTEXT.md`, nota de cierre de División de Cuenta).

## Overview
Pantalla de solo lectura para el admin: tres métricas del día (ventas,
mesas activas, reservas) con el detalle de apoyo (lista de mesas activas y
de reservas de hoy). Sin acciones — es un resumen, no un flujo operativo.

## PASO 0 — Decisiones antes de escribir código

**Gap 1: no hay User Story ni contrato previos.** Redactada arriba como
US-7.1. Mismo criterio que el gap de `POST /inventario` en
`inventario.spec.md` — se documenta aquí y en `decision-log.md`, no bloquea
el desarrollo.

**Gap 2 (no estaba en el ticket, encontrado en PASO 0): ya existía una ruta
`dashboard`.** El starter kit registra `Route::inertia('dashboard',
'Dashboard')->name('dashboard')` en `routes/web.php` (sin contexto de
tenant, accesible a los 3 roles) apuntando a un `Dashboard.vue` de
placeholder (`PlaceholderPattern`, sin datos reales). Además,
`config('fortify.home')` = `/dashboard` es el redirect post-login **para
los tres roles** (`Laravel\Fortify\Http\Responses\LoginResponse` hace
`redirect()->intended(Fortify::redirects('login'))`, que cae en
`config('fortify.home')` si no hay override). Decisión (confirmada con el
usuario vía `AskUserQuestion`): **reemplazar** esa ruta — se mueve a
`routes/tenant.php` con `role:admin` (patrón ADR-007) — y agregar un
`App\Http\Responses\LoginResponse` propio, bindeado en
`FortifyServiceProvider`, que calcula el redirect post-login según
`$request->user()->role` (admin → `dashboard`, mesero → `mesas.index`,
cocina → `cocina.index`) en vez del path fijo de `fortify.home`. Alcance
acotado a `LoginResponse`: el resto de respuestas de Fortify no usan
`fortify.home` (`PasswordResetResponse` redirige a `route('login')`) y las
únicas features activas en `config/fortify.php` son login + reset de
password (sin registro ni verificación de email), así que no hay otro
punto de entrada que aterrice en `/dashboard` para un rol no-admin.

**Nombre de ruta `dashboard` (flat, no `dashboard.index`) — a propósito,
deviación documentada del patrón `{dominio}.{acción}` que usan el resto de
pantallas de una sola ruta (ej. `mesas.index`, `cocina.index`).** La ruta
`dashboard` ya existía y dos archivos del starter kit sin tocar
(`Welcome.vue`, `AppHeader.vue`, ambos fuera del flujo real de
tenant/subdominio) importan `dashboard()` de `@/routes`. Mantener el nombre
plano evita romper esos call sites (y su `npm run types:check`) sin
ninguna ganancia real — nadie navega a esas pantallas en el flujo del
tenant. Ambos quedan sin tocar, mismo criterio que Register/ForgotPassword
en la sesión de Login (#1).

**Métricas — confirmadas con el usuario (`AskUserQuestion`):**
- **Ventas del día** = suma de `Payment.amount` con `paid_at` de hoy
  (`whereDate('paid_at', today())`), vía `whereHas('order')` para aplicar
  el `TenantScope` de `Order` — `Payment` no tiene `BelongsToTenant` propio
  (hereda el aislamiento vía `Order`, mismo patrón que `OrderItem` — ver
  comentario de F-05 en `KitchenController::markReady()`). No filtra por
  `Order.status`: un pago ya es dinero cobrado, sin importar si la orden
  sigue abierta (pago parcial, división de cuenta) o ya cerró.
- **Mesas activas** = `Table.status != libre` (ocupada + por_cobrar).
  `Table` sí tiene `BelongsToTenant` propio, aislamiento directo.
- **Reservas del día** = `Reservation` con `reserved_at` de hoy
  (`whereDate`, mismo criterio que `reservas.spec.md` #8) **y** `status` en
  `{confirmada, sentada}` — a diferencia de `ReservationController::index()`
  (que no excluye canceladas, porque el staff necesita verlas para no
  ofrecer una mesa que ya se liberó), aquí el usuario decidió excluirlas
  porque un conteo resumen que incluye canceladas sería engañoso para el
  admin en un vistazo rápido.

**Sin Action, controller delgado directo** — mismo criterio que
`KitchenController::index()`/`InventarioController::index()`: no hay
lógica de negocio que mutar ni reutilizar fuera del controller, solo
composición de queries de lectura.

**Poll de 4s (`usePoll`)** — reutiliza el patrón ya establecido por ADR-005
(Mapa de Mesas, Cocina) para pantallas cuyos datos cambian en tiempo casi
real durante el servicio; el Dashboard agrega exactamente esas mismas
entidades (mesas, pagos, reservas), así que aplica el mismo criterio.

## Users Affected
- **Admin**: única persona con acceso. Ve el resumen del día al entrar a
  `/dashboard` (o llega ahí automáticamente al iniciar sesión).
- **Mesero / Cocina**: sin acceso (403 si navegan a `/dashboard`
  directamente); tras login aterrizan en su pantalla habitual (`/mesas` /
  `/cocina`), no en `/dashboard`.

## Inputs & Outputs
**Input:** ninguno — GET sin parámetros ni query string (sin selector de
fecha, siempre "hoy").
**Output:** ventas del día (monto), cantidad de mesas activas + su lista
(nombre, estado), cantidad de reservas de hoy + su lista (cliente, hora,
personas, mesa asignada si tiene).

## Happy Path
1. Admin inicia sesión → aterriza directo en `/dashboard`.
2. Ve tres tarjetas de resumen: "Ventas de hoy" ($X), "Mesas activas" (N de
   el total), "Reservas de hoy" (N).
3. Debajo, ve la lista de mesas activas (nombre + estado ocupada/por
   cobrar) y la lista de reservas de hoy (cliente, hora, personas, mesa si
   tiene asignada).
4. Los datos se refrescan cada 4s (`usePoll`) sin que el admin recargue la
   página — si se cobra una cuenta o cambia una reserva desde otra
   pestaña/dispositivo, el resumen lo refleja solo.

## Edge Cases
| Escenario | Comportamiento esperado |
|-----------|------------------------|
| Restaurante sin ninguna venta hoy | "Ventas de hoy" muestra $0.00, no un estado vacío ni error. |
| Ninguna mesa activa (todas libres) | "Mesas activas" muestra 0; la lista de mesas activas muestra un estado vacío ("Todas las mesas están libres"). |
| Ninguna reserva hoy | "Reservas de hoy" muestra 0; la lista muestra un estado vacío ("Sin reservas para hoy"). |
| Reserva de hoy en status `cancelada` | No cuenta en "Reservas de hoy" ni aparece en la lista (ver PASO 0 — decisión explícita del usuario, distinto del criterio de `reservas.spec.md`). |
| Pago de una orden ya cerrada ayer, cobrado hoy (pago tardío) | Cuenta en "Ventas de hoy" — el criterio es `paid_at` de hoy, no la fecha de apertura/cierre de la orden. |
| Pago parcial (división de cuenta, `AddPaymentToOrderAction`) | Cada `Payment` individual cuenta por separado — dos pagos parciales de la misma orden hoy suman ambos a "Ventas de hoy". |
| Mesa `por_cobrar` (orden lista para cobrar, aún no pagada) | Cuenta como "mesa activa" (status != libre), aunque no haya generado ventas todavía. |
| Reserva sin mesa asignada (`table_id` null) | Aparece en la lista de reservas de hoy sin nombre de mesa (ej. "Sin mesa asignada"), no se oculta. |

## Error States
No hay inputs de usuario en esta pantalla (solo lectura, sin formularios) —
sin estados de error propios más allá de la autorización:

| Error | Mensaje al usuario | Acción de recuperación |
|-------|-------------------|----------------------|
| Rol distinto de admin navega a `/dashboard` | 403 (página de error estándar de Laravel) | Ninguna — no tiene acceso; su login lo lleva directo a su pantalla habitual. |

## Security Considerations
- [x] ¿Requiere autenticación? Sí — `role=admin` exclusivamente, sin
      compartir con mesero/cocina (mismo criterio que Inventario).
- [x] ¿Reglas de autorización? Ninguna Policy — es acceso a pantalla
      completa vía middleware `role:admin` (ADR-007), no autorización por
      instancia de modelo (no hay modelos que editar aquí).
- [x] ¿Validación de inputs? No aplica — GET sin parámetros.
- [x] ¿Rate limiting? No aplica.
- [x] ¿Datos sensibles en logs? Ninguno.
- [x] **Aislamiento entre tenants (obligatorio)**: `Table`/`Reservation`
      tienen `BelongsToTenant` propio (`TenantScope` automático).
      `Payment` hereda el aislamiento vía `whereHas('order')` — sin ese
      `whereHas`, un `Payment::sum('amount')` plano leería pagos de
      **todos** los tenants (la tabla no tiene `tenant_id` propio). Test de
      integración obligatorio: admin del tenant A no debe ver en su total
      pagos/mesas/reservas del tenant B.
- [x] **Mass assignment**: no aplica — no hay escritura en esta pantalla.
- [x] **Redirect post-login por rol**: `App\Http\Responses\LoginResponse`
      confía en `$request->user()->role` (columna del modelo autenticado
      por Fortify tras `Auth::attempt`/`authenticateUsing`, no un valor del
      request) — no es spoofable desde el body del login.

## Performance Requirements
- Max response time: 500ms (p95) — mismo budget que Inventario, uso
  esporádico de vistazo, no un flujo de alta frecuencia.
- Expected load: refresco cada 4s mientras la pantalla está abierta
  (`usePoll`), típicamente una sola sesión de admin a la vez por
  restaurante.
- Data volume: decenas de mesas, reservas y pagos por día — sin
  paginación, mismo criterio que Cocina/Mapa de Mesas.

## Test Cases

### Unit Tests
No aplica — sin Actions ni lógica de negocio propia que aislar (controller
delgado de solo lectura, mismo criterio que `KitchenController::index()`).
Cubierto por Integration Tests.

### Integration Tests
- [x] `GET /dashboard` devuelve `salesTotal` = suma de `Payment.amount` con
      `paid_at` de hoy del tenant actual
- [x] `GET /dashboard`: un pago con `paid_at` de ayer no cuenta en
      `salesTotal`
- [x] `GET /dashboard`: dos pagos parciales de la misma orden, ambos hoy,
      se suman ambos a `salesTotal`
- [x] `GET /dashboard` devuelve `activeTables` = mesas con
      `status != libre` (incluye `ocupada` y `por_cobrar`, excluye
      `libre`)
- [x] `GET /dashboard` devuelve `todayReservations` = reservas con
      `reserved_at` de hoy y `status` en `{confirmada, sentada}` —
      excluye una reserva `cancelada` de hoy y una `confirmada` de otro día
- [x] Usuario con `role=mesero` o `role=cocina` accede a `GET /dashboard` →
      403
- [x] **Aislamiento entre tenants (obligatorio)**: admin del tenant A no ve
      en `salesTotal`/`activeTables`/`todayReservations` datos del tenant B
      (pago, mesa y reserva de otro tenant, todos de "hoy", creados en el
      test para el tenant B)
- [x] Login de un usuario `role=admin` → redirect a `route('dashboard')`
- [x] Login de un usuario `role=mesero` → redirect a `route('mesas.index')`
      (no a `/dashboard`)
- [x] Login de un usuario `role=cocina` → redirect a `route('cocina.index')`
      (no a `/dashboard`)

### E2E Tests
- [x] Happy path completo: login como admin → aterriza en `/dashboard` →
      ve las 3 tarjetas y las 2 listas con datos reales (verificado en
      browser real, `demo.localhost:8000`)
- [x] Estado vacío: tenant sin reservas hoy → tarjeta en 0, lista con su
      mensaje de estado vacío (verificado en browser real). Mesas activas
      con datos reales (4 mesas, 0 libres en el tenant demo) — el caso de
      0 mesas activas queda cubierto por Integration Test, no reproducido
      visualmente en esta sesión (hubiera requerido liberar las 4 mesas
      del tenant demo).

## Definition of Done
- [x] Todos los test cases de Integration de este spec pasando (Pest)
- [ ] Code review completado y aprobado
- [x] Spec actualizado con las decisiones de PASO 0
- [ ] Desplegado en staging y verificado manualmente en tablet real
- [x] Sin errores en consola / logs — verificado en browser real
      (`demo.localhost:8000`), light y dark mode; no se reprodujo el
      mismatch de hidratación transversal (`_ai/CONTEXT.md`, REDEV-30) en
      esta sesión
- [x] Performance dentro del budget definido arriba — GET de solo lectura
      con 3 queries agregadas, sin N+1 (uso esporádico, no medido con
      profiler pero muy por debajo de 500ms en desarrollo)
- [x] Pantalla Vue `dashboard/Index.vue` (E2E, incluye `poll()`)
