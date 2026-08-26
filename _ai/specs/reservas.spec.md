# Feature: Reservas

## Status
[x] Draft  [ ] Review  [ ] Approved  [x] Implemented

## PRD Reference
User Stories:
- US-4.1 "Como staff, quiero registrar una reserva con nombre, teléfono, hora y
  número de personas, para tener control de las mesas comprometidas."
- US-4.2 "Como staff, quiero ver las reservas del día, para anticipar qué mesas
  estarán ocupadas y cuándo."

Épica: Épica 4 — Reservas
Prioridad: Must
*(Reservas públicas/online para clientes finales: Out-of-Scope del PRD — este
spec es solo gestión interna por staff.)*

## Overview
Calendario simple de reservas del día, gestionado por staff — no hay portal
público para que el cliente final reserve directamente.

## Users Affected
- **Mesero / Admin**: crea reservas y consulta el calendario del día.
- **Cocina**: no tiene acceso.

## Inputs & Outputs
**Input:** staff en `/reservas/nueva` ingresa `customer_name`, `customer_phone`,
`party_size`, `reserved_at` y opcionalmente `table_id`.
**Output:** la reserva aparece en `/reservas` ordenada por hora.

## Happy Path
1. Staff abre `/reservas` y ve las reservas del día ordenadas por hora.
2. Staff toca "Nueva reserva", completa nombre, teléfono, número de personas y
   hora.
3. Staff opcionalmente asigna una mesa específica (o la deja sin asignar para
   decidir el día de la reserva).
4. Al guardar, la reserva aparece en el calendario con status `confirmada`.
5. El día de la reserva, staff puede marcarla `sentada` (el cliente llegó) o
   `cancelada`.

## Edge Cases
| Escenario | Comportamiento esperado |
|-----------|------------------------|
| Reserva sin mesa asignada | Permitido — `table_id` es nullable; se asigna después manualmente |
| Dos reservas para la misma mesa a la misma hora | Permitido en v1 — no hay validación de conflicto de horario/mesa (el staff lo gestiona manualmente); documentar como limitación conocida, no bug |
| `party_size` mayor a la capacidad de la mesa asignada | Permitido — no se valida contra `capacity` en v1; el staff decide si acepta el overbooking de personas en una mesa |
| Reserva para una fecha/hora pasada | Rechazado — `reserved_at` debe ser una fecha futura al momento de crear la reserva |
| Teléfono con formato no estándar | Aceptado como texto libre — no se valida formato estricto (números internacionales, extensiones, etc. varían) |
| Reserva marcada `sentada` pero la mesa nunca se ocupó en el POS | No hay vínculo automático entre `Reservation.status=sentada` y `Table.status=ocupada` en el MVP — son sistemas independientes; el mesero abre la mesa normalmente vía `toma-de-pedido` |
| Marcar `sentada` una reserva ya `sentada` (doble tap) | Idempotente — no hace nada, no lanza error |
| Marcar `sentada` una reserva `cancelada` | Rechazado — transición inválida, 422 |
| Cancelar una reserva ya `cancelada` (doble tap) | Idempotente — no hace nada, no lanza error |
| Cancelar una reserva `sentada` | Rechazado — transición inválida, 422; el cliente ya llegó, cancelar no tiene sentido operativo (si fue un error de captura, es un caso raro sin flujo dedicado en el MVP) |

## Error States
| Error | Mensaje al usuario | Acción de recuperación |
|-------|-------------------|----------------------|
| Fecha/hora en el pasado | "La hora de la reserva debe ser futura." | Corregir la hora |
| Campos requeridos faltantes | "Completa nombre, teléfono, personas y hora." | Completar el formulario |
| Marcar `sentada` una reserva `cancelada` | "No se puede sentar una reserva cancelada." | Ninguna — la reserva ya se canceló |
| Cancelar una reserva `sentada` | "No se puede cancelar una reserva que ya fue sentada." | Ninguna — el cliente ya llegó |

## Security Considerations
- [x] ¿Requiere autenticación? Sí — `role` en `mesero` o `admin`. `role=cocina`
      recibe 403.
- [x] ¿Reglas de autorización? Ninguna adicional.
- [x] ¿Validación de inputs? `customer_name` y `customer_phone` requeridos;
      `party_size` entero ≥ 1; `reserved_at` fecha futura.
- [x] ¿Rate limiting? No aplica.
- [x] ¿Datos sensibles en logs? `customer_name` y `customer_phone` son datos
      personales del cliente final — **no deben aparecer en logs de aplicación**,
      solo persistir en la tabla `reservations`.
- [ ] **F-05 — IDOR entre tenants**: este es el spec con más impacto si el
      aislamiento falla, porque es el único que almacena **datos personales de
      terceros** (nombre y teléfono de clientes finales, que nunca aceptaron
      nada con tu plataforma). Una fuga aquí no es solo ventaja competitiva:
      es exposición de PII. `GET /reservas` del restaurante A no debe incluir
      reservas de B. Ver `_ai/docs/threat-model.md`.

## Performance Requirements
- Max response time: 500ms (p95).
- Expected load: bajo — decenas de reservas por día en el peor caso.
- Data volume: cientos de reservas acumuladas por mes.

## Test Cases

### Unit Tests
- [x] `CreateReservationAction`: crea una reserva con status `confirmada` por
      defecto
- [x] `CreateReservationAction`: `reserved_at` en el pasado lanza excepción de
      validación
- [x] `CreateReservationAction`: `table_id` nulo es válido
- [x] `SeatReservationAction`: pasa una reserva `confirmada` a `sentada`
- [x] `SeatReservationAction`: reserva ya `sentada` es idempotente (no lanza,
      no cambia nada)
- [x] `SeatReservationAction`: reserva `cancelada` lanza excepción de
      transición inválida
- [x] `CancelReservationAction`: pasa una reserva `confirmada` a `cancelada`
- [x] `CancelReservationAction`: reserva ya `cancelada` es idempotente (no
      lanza, no cambia nada)
- [x] `CancelReservationAction`: reserva `sentada` lanza excepción de
      transición inválida

### Integration Tests
- [x] `GET /reservas` devuelve las reservas del día ordenadas por `reserved_at`
- [x] `POST /reservas` con datos válidos → 200, reserva creada
- [x] `POST /reservas` con fecha pasada → 422
- [x] Usuario con `role=cocina` accede a `/reservas` → 403
- [x] **F-05**: con reservas en dos restaurantes, `GET /reservas` como usuario
      del restaurante A no expone ningún `customer_name` ni `customer_phone`
      del restaurante B
- [x] **F-05**: asignar `table_id` de una mesa de otro restaurante a una
      reserva → falla
- [x] `PATCH /reservas/{reservation}/sentar` marca la reserva `sentada`
- [x] `PATCH /reservas/{reservation}/sentar` sobre una reserva `cancelada` →
      422
- [x] `PATCH /reservas/{reservation}/cancelar` marca la reserva `cancelada`
- [x] `PATCH /reservas/{reservation}/cancelar` sobre una reserva `sentada` →
      422
- [x] Usuario con `role=cocina` accede a `sentar`/`cancelar` → 403
- [x] **F-05**: `PATCH /reservas/{reservation}/sentar` y `.../cancelar` sobre
      una reserva de otro restaurante → 404, y la reserva del otro tenant no
      cambia

> **Notas de implementación (PASO 0, ver `decision-log.md`, 2026-08-12):**
> - `GET /reservas` filtra solo por `reserved_at` de hoy
>   (`whereDate('reserved_at', today())`), sin excluir por `status` — el
>   staff también ve las `cancelada` del día. Sin selector de fecha para
>   otros días (fuera de alcance, sin query param en el contrato).
> - `Reservation` tiene su propio `tenant_id` + `BelongsToTenant` (a
>   diferencia de `OrderItem`/`Payment` en #5/#7) porque `table_id` es
>   nullable — no hay relación padre confiable de la que heredar el
>   aislamiento.
> - El "200" original de este documento para el POST se implementó como
>   redirect 302 a `reservas.index`, mismo patrón PRG que #5/#6/#7.
>
> **Notas de implementación (PASO 0, cierre de la brecha #8, ver
> `decision-log.md`, 2026-08-25):**
> - Dos Actions nuevas, una responsabilidad cada una — mismo patrón que
>   `ToggleMenuItemAvailabilityAction`/`DeactivateStaffAccountAction`, no una
>   Action genérica "update status": `SeatReservationAction`
>   (`app/Actions/Reservations/SeatReservationAction.php`) y
>   `CancelReservationAction`
>   (`app/Actions/Reservations/CancelReservationAction.php`).
> - A diferencia de `Table.status`/`MenuItem.available` (excluidos de
>   `$fillable`, ver `.ai/rules/actions.md`), `Reservation.status` **sí**
>   está en el atributo `#[Fillable(...)]` del modelo (igual que
>   `Order.status`) — las dos Actions nuevas usan `$reservation->update([...])`,
>   no `forceFill()`, mismo patrón que `SendOrderToKitchenAction`/
>   `RequestBillAction` sobre `Order`.
> - Guards de transición (única decisión de diseño no dictada por el spec
>   original, ver tabla de Edge Cases arriba): solo `confirmada` puede pasar
>   a `sentada` o `cancelada`. Repetir la misma transición sobre una reserva
>   que ya está en el estado destino es **idempotente** (protección de
>   doble-tap, mismo criterio que `RequestBillAction`/`CloseOrderAction`
>   sobre `Order.status`); cruzar `sentada`↔`cancelada` en cualquier
>   dirección lanza `InvalidReservationTransitionException`, capturada en el
>   controller como `ValidationException` sobre la key `status` (422) —
>   mismo patrón que `PastReservationException` en `store()`.
> - Rutas nuevas dentro del grupo `reservas.*` ya existente (mismo
>   middleware `role:admin,mesero`, ninguna Policy — el spec no pide
>   reglas de autorización adicionales): `PATCH /reservas/{reservation}/sentar`
>   (`reservas.seat`) y `PATCH /reservas/{reservation}/cancelar`
>   (`reservas.cancel`), mismo estilo kebab-case en español que
>   `menu/{menuItem}/disponibilidad` y `staff/{user}/desactivar`.
>   `{reservation}` usa route model binding normal (`Reservation` tiene su
>   propio `BelongsToTenant`, `TenantScope` ya resuelve F-05, igual que
>   `{table}` en el grupo `cobro.*`).
> - `_ai/docs/api-contract.yaml` no se amplió con estos dos endpoints —
>   mismo criterio ya aplicado ahí para `PATCH /menu/{menuItem}/disponibilidad`
>   y `PATCH /staff/{user}/desactivar`, ninguno de los dos documentado en
>   ese archivo tampoco.
> - UI: `reservas/Index.vue` agrega dos botones por fila ("Sentar"/
>   "Cancelar"), visibles solo cuando la transición es válida para el
>   `status` actual de esa reserva (ocultos para reservas ya `sentada`/
>   `cancelada`, evita depender solo del guard del servidor para la UX).

### E2E Tests
- [x] Happy path: staff crea una reserva sin mesa asignada → aparece en el
      calendario del día correspondiente (verificado manualmente en
      browser real, ver `decision-log.md`)
- [x] Happy path: staff sienta una reserva `confirmada` y cancela otra,
      ambos botones desaparecen/actualizan tras la acción (verificado
      manualmente en browser real, ver `decision-log.md`, entrada del
      2026-08-25)

## Definition of Done
- [x] Todos los test cases de Unit + Integration de este spec pasando (Pest)
- [ ] Code review completado y aprobado
- [x] Spec actualizado con comportamiento real implementado
- [ ] Desplegado en staging y verificado manualmente
- [x] Sin errores en consola / logs
- [x] `customer_name`/`customer_phone` ausentes de logs de aplicación (nunca
      se loggean explícitamente)
- [x] Pantalla Vue de `/reservas` (E2E) — `resources/js/pages/reservas/Index.vue`,
      construida y verificada en browser real (ver `decision-log.md`,
      entrada del 2026-08-12 "PASO 0 de la pantalla Vue de Reservas")
