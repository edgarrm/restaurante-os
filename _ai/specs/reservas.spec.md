# Feature: Reservas

## Status
[x] Draft  [ ] Review  [ ] Approved  [ ] Implemented

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

## Error States
| Error | Mensaje al usuario | Acción de recuperación |
|-------|-------------------|----------------------|
| Fecha/hora en el pasado | "La hora de la reserva debe ser futura." | Corregir la hora |
| Campos requeridos faltantes | "Completa nombre, teléfono, personas y hora." | Completar el formulario |

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
- [ ] `CreateReservationAction`: crea una reserva con status `confirmada` por
      defecto
- [ ] `CreateReservationAction`: `reserved_at` en el pasado lanza excepción de
      validación
- [ ] `CreateReservationAction`: `table_id` nulo es válido

### Integration Tests
- [ ] `GET /reservas` devuelve las reservas del día ordenadas por `reserved_at`
- [ ] `POST /reservas` con datos válidos → 200, reserva creada
- [ ] `POST /reservas` con fecha pasada → 422
- [ ] Usuario con `role=cocina` accede a `/reservas` → 403
- [ ] **F-05**: con reservas en dos restaurantes, `GET /reservas` como usuario
      del restaurante A no expone ningún `customer_name` ni `customer_phone`
      del restaurante B
- [ ] **F-05**: asignar `table_id` de una mesa de otro restaurante a una
      reserva → falla

### E2E Tests
- [ ] Happy path: staff crea una reserva sin mesa asignada → aparece en el
      calendario del día correspondiente

## Definition of Done
- [ ] Todos los test cases de este spec pasando (Pest)
- [ ] Code review completado y aprobado
- [ ] Spec actualizado con comportamiento real implementado
- [ ] Desplegado en staging y verificado manualmente
- [ ] Sin errores en consola / logs
- [ ] `customer_name`/`customer_phone` ausentes de logs de aplicación
