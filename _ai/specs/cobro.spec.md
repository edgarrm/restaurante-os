# Feature: Cobro y Cierre de Cuenta

## Status
[x] Draft  [ ] Review  [ ] Approved  [ ] Implemented

## PRD Reference
User Story: US-3.1 "Como mesero, quiero cobrar la cuenta de una mesa, para
cerrarla y liberarla para el siguiente cliente."
Épica: Épica 3 — Cobro y Cierre de Cuenta
Prioridad: Must
*(US-3.2 — dividir cuenta entre varios pagos — es Could y no está cubierta por
este spec; el data model ya soporta 1:N Order→Payment para cuando se aborde,
ver `_ai/docs/data-model.md`.)*

## Overview
Pantalla donde el mesero aplica un pago a la cuenta de una mesa, cerrando la
orden y liberando la mesa para el siguiente cliente.

## Users Affected
- **Mesero / Admin**: aplica el pago y cierra la cuenta.

## Inputs & Outputs
**Input:** el mesero navega a `/mesas/{table}/cobro`, ingresa `amount` y
`method`, confirma.
**Output:** se crea un `Payment` ligado a la orden; la orden pasa a `pagada`; la
mesa vuelve a `status=libre`.

## Happy Path
1. Mesero abre `/mesas/{table}/cobro` (desde el mapa de mesas o desde toma de
   pedido).
2. Ve el detalle de la orden: ítems, cantidades, subtotal y total.
3. Mesero selecciona el método de pago y confirma el monto (por defecto, el
   total exacto de la orden).
4. Al confirmar, se registra el `Payment`, la orden pasa a `pagada` y la mesa a
   `libre`.
5. El mesero regresa automáticamente al mapa de mesas.

## Edge Cases
| Escenario | Comportamiento esperado |
|-----------|------------------------|
| Monto ingresado menor al total de la orden | Rechazado — 422, no se permite cerrar una cuenta parcialmente pagada en v1 (split bill es Could, fuera de este spec) |
| Monto ingresado mayor al total (el cliente paga con un billete grande) | Aceptado — se registra el `amount` real recibido; el "cambio a dar" es cálculo de UI (`amount - total`), no afecta el modelo de datos |
| Mesa sin orden activa (ya fue cobrada o nunca se abrió) | 404 o redirect al mapa de mesas con aviso — no se puede cobrar lo que no existe |
| Orden todavía en `abierta` (nunca se envió a cocina) | Permitido cobrar igualmente — un mesero puede cerrar una cuenta sin pasar por cocina (ej. solo bebidas de barra); no es un estado bloqueante |
| Doble tap en "Confirmar pago" | Idempotente a nivel de una orden — si la orden ya está `pagada`, un segundo intento de pago no crea un `Payment` duplicado, devuelve el estado ya cerrado |

## Error States
| Error | Mensaje al usuario | Acción de recuperación |
|-------|-------------------|----------------------|
| Monto insuficiente | "El monto no cubre el total de la cuenta ($X.XX)." | Corregir el monto o completar con otro método (fuera de alcance v1 — un solo pago por cobro) |
| Orden ya pagada | "Esta cuenta ya fue cobrada." | Redirige al mapa de mesas |
| Mesa sin orden activa | "No hay una cuenta abierta para esta mesa." | Redirige al mapa de mesas |

## Security Considerations
- [x] ¿Requiere autenticación? Sí — `role` en `mesero` o `admin`. `role=cocina`
      recibe 403.
- [x] ¿Reglas de autorización? Ninguna — cualquier mesero puede cobrar cualquier
      mesa (mismo criterio que `toma-de-pedido`).
- [x] ¿Validación de inputs? `amount` numérico ≥ total de la orden; `method` de
      un conjunto cerrado de valores válidos (no texto libre).
- [x] ¿Rate limiting? No aplica.
- [x] ¿Datos sensibles en logs? **No loggear el monto exacto en logs de
      aplicación de forma que quede fuera de la tabla `payments`** — el registro
      contable vive en la base de datos, no en logs de texto plano.
- [ ] **F-03 (ALTO) — todo `Payment` debe registrar `collected_by`** con el
      usuario autenticado que ejecutó el cobro. Es control interno, no
      metadato: sin él no hay forma de investigar un faltante de caja. El valor
      se toma **del usuario autenticado en el servidor**, nunca de un campo del
      request (sería falsificable). Ver `_ai/docs/data-model.md`.
- [ ] **F-05 — IDOR entre tenants**: cobrar la mesa de otro restaurante debe
      devolver 404, no sus datos.
- [ ] **F-07 — riesgo de tablet desatendida**: cobrar es la acción más sensible
      que un mesero ejecuta. Si se decide algún bloqueo (PIN, reautenticación),
      esta pantalla es la primera candidata. Decisión pendiente en
      `decision-log.md`.

## Performance Requirements
- Max response time: 500ms (p95) — cerrar la cuenta es un flujo crítico de
  servicio (libera la mesa para el siguiente cliente).
- Expected load: bajo — un cobro por mesa por ciclo de servicio.
- Data volume: decenas de `Payment` por día en un restaurante pequeño.

## Test Cases

### Unit Tests
- [ ] `CloseOrderAction`: monto igual al total → crea `Payment`, orden pasa a
      `pagada`, mesa pasa a `libre`
- [ ] `CloseOrderAction`: monto menor al total → lanza excepción de dominio
- [ ] `CloseOrderAction`: monto mayor al total → acepta, registra el monto real
- [ ] `CloseOrderAction`: orden ya `pagada` → no crea un segundo `Payment`
      (idempotente)
- [ ] **F-03**: el `Payment` creado tiene `collected_by` igual al usuario
      autenticado
- [ ] **F-03**: un `collected_by` enviado en el request es ignorado — se usa
      siempre el usuario autenticado del servidor

### Integration Tests
- [ ] `GET /mesas/{table}/cobro` devuelve el detalle de la orden abierta
- [ ] `POST /mesas/{table}/cobro` con monto suficiente → 200, orden `pagada`,
      mesa `libre`
- [ ] `POST /mesas/{table}/cobro` con monto insuficiente → 422
- [ ] `POST /mesas/{table}/cobro` sobre una orden ya `pagada` → 200 idempotente,
      sin nuevo `Payment`
- [ ] Usuario con `role=cocina` accede a `/mesas/{table}/cobro` → 403

### E2E Tests
- [ ] Happy path completo: orden con ítems → cobrar el total exacto → mesa
      vuelve a `libre` en el mapa de mesas
- [ ] Error crítico: intentar cobrar con monto insuficiente → mensaje correcto,
      la mesa NO cambia de estado

## Definition of Done
- [ ] Todos los test cases de este spec pasando (Pest)
- [ ] Code review completado y aprobado
- [ ] Spec actualizado con comportamiento real implementado
- [ ] Desplegado en staging y verificado manualmente
- [ ] Sin errores en consola / logs
- [ ] Cobro dentro de 500ms p95
- [ ] Monto no aparece en logs de texto plano fuera de la tabla `payments`
