# Feature: Cobro y Cierre de Cuenta

## Status
[x] Draft  [ ] Review  [ ] Approved  [x] Implemented

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
- [x] `CloseOrderAction`: monto igual al total → crea `Payment`, orden pasa a
      `pagada`, mesa pasa a `libre`
- [x] `CloseOrderAction`: monto menor al total → lanza excepción de dominio
- [x] `CloseOrderAction`: monto mayor al total → acepta, registra el monto real
- [x] `CloseOrderAction`: orden ya `pagada` → no crea un segundo `Payment`
      (idempotente)
- [x] **F-03**: el `Payment` creado tiene `collected_by` igual al usuario
      autenticado
- [x] **F-03**: un `collected_by` enviado en el request es ignorado — se usa
      siempre el usuario autenticado del servidor (movido a Integration Tests:
      la Action solo acepta un `User $collectedBy` tipado, no un array del
      request, así que "ignorar un valor spoofed" solo es observable en el
      controller — ver `tests/Feature/CobroTest.php`)
- [x] `RequestBillAction` (no estaba en el spec original — ver PASO 0b en
      decision-log.md): marca `Order`+`Table` como `por_cobrar` desde
      `abierta`/`enviada_cocina`/`lista`; idempotente si ya está `por_cobrar`;
      no reabre una orden ya `pagada`

### Integration Tests
- [x] `GET /mesas/{table}/cobro` devuelve el detalle de la orden abierta
- [x] `POST /mesas/{table}/cobro` con monto suficiente → 200, orden `pagada`,
      mesa `libre`
- [x] `POST /mesas/{table}/cobro` con monto insuficiente → 422
- [x] `POST /mesas/{table}/cobro` sobre una orden ya `pagada` → 200 idempotente,
      sin nuevo `Payment`
- [x] Usuario con `role=cocina` accede a `/mesas/{table}/cobro` → 403
- [x] **F-03**: un `collected_by` enviado en el body del POST es ignorado
- [x] **F-05**: mesero del restaurante A pide la mesa de otro restaurante → 404

> **Notas de implementación (PASO 0, ver `decision-log.md`, 2026-08-12):**
> - Estados de `Order` elegibles para `/mesas/{table}/cobro`: `abierta`,
>   `enviada_cocina`, `lista` y `por_cobrar` (GET); los mismos más `pagada`
>   para el POST, de forma que un doble tap sobre una cuenta ya cerrada
>   encuentre la misma orden en vez de 404.
> - `GET /mesas/{table}/cobro` marca la orden y la mesa como `por_cobrar`
>   como efecto colateral (`RequestBillAction`) — no existe un endpoint
>   dedicado de "pedir la cuenta"; abrir la pantalla de cobro ya dispara la
>   transición, igual que `OpenOrReuseOrderForTableAction` en
>   `GET /mesas/{table}/pedido`. No estaba en el alcance original del spec
>   ni en sus Test Cases — se agregó a petición explícita en PASO 0b.
> - `method` acepta `efectivo`, `tarjeta`, `transferencia` (`PaymentMethod`
>   enum) — conjunto cerrado decidido en PASO 0c, el spec original no lo
>   enumeraba.
> - El "200" original de este documento para el POST ya coincidía con el
>   contrato (`x-inertia-component: Mesas/Index`) — implementado como
>   redirect 302 a `mesas.index`, mismo patrón PRG que #5/#6.

### E2E Tests
- [x] Happy path completo: orden con ítems → cobrar el total exacto → mesa
      vuelve a `libre` en el mapa de mesas (verificado en browser real,
      `demo.localhost:8000`, 2026-08-12)
- [x] Error crítico: intentar cobrar con monto insuficiente → mensaje correcto,
      la mesa NO cambia de estado (verificado en browser real, banner inline
      "El monto no cubre el total de la cuenta ($X.XX)." — no modal crudo)

> **Notas de implementación (pantalla Vue, ver `decision-log.md`,
> 2026-08-12):**
> - `PaymentController::close()` tenía el mismo bug ya documentado en
>   Toma de Pedido (#3): `abort(422, ...)` no trae `X-Inertia`, así que un
>   cliente Inertia real lo mostraba como modal crudo. Corregido a
>   `ValidationException::withMessages(['amount' => ...])`, mismo patrón que
>   `OrderController`.
> - `PaymentController::show()` no pasaba `table` como prop — se agregó
>   (necesario para breadcrumbs/encabezado, mismo patrón que
>   `OrderController::show()`), y se amplió el eager-load a
>   `items.menuItem` para mostrar el nombre real del platillo en vez de
>   `Platillo #{id}`.
> - Campo `amount`: precargado con el total exacto de la orden (Happy Path
>   #3), editable por el mesero (para registrar un billete grande — Edge
>   Cases), sin tope superior. "Cambio a dar" es cálculo de UI, no viaja al
>   servidor.
> - F-07 (tablet desatendida) se mantuvo fuera de alcance de esta sesión —
>   sigue 🟡 Abierta, decisión del cliente ancla.

## Definition of Done
- [x] Todos los test cases de Unit + Integration de este spec pasando (Pest)
- [ ] Code review completado y aprobado
- [x] Spec actualizado con comportamiento real implementado
- [ ] Desplegado en staging y verificado manualmente
- [x] Sin errores en consola / logs
- [ ] Cobro dentro de 500ms p95 — pendiente de medir junto con la pantalla Vue
- [x] Monto no aparece en logs de texto plano fuera de la tabla `payments`
      (nunca se loggea explícitamente)
- [x] Pantalla Vue de `/cobro` (E2E) — implementada
      (`resources/js/pages/mesas/Cobro.vue`), verificada en browser real
