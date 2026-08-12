# Feature: División de Cuenta (Split Bill)

## Status
[x] Draft  [ ] Review  [ ] Approved  [x] Implemented

## PRD Reference
User Story: US-3.2 "Como mesero, quiero dividir la cuenta entre varios pagos,
para acomodar a grupos que pagan por separado."
Épica: Épica 3 — Cobro y Cierre de Cuenta
Prioridad: Could

*(Extiende `_ai/specs/cobro.spec.md`, #7 — Must, ya en producción. No lo
reemplaza: el flujo de un solo pago que cubre el total sigue existiendo tal
cual, sin cambio de comportamiento observable.)*

## Decisión de producto (PASO 0)

El PRD deja abierto el mecanismo de división ("puede asignar ítems o montos a
pagos independientes — se valida en piloto"). Presentado al usuario
(AskUserQuestion, 2026-08-12) con tres opciones — (a) split por monto libre,
(b) split por ítems asignados a un grupo de pago, (c) ambos, empezando por (a)
y documentando (b) como brecha — **eligió (a): split por monto libre.**

**Brecha documentada para después** (mismo criterio que las transiciones
sentada/cancelada de `Reservation`, ver `decision-log.md`): split por ítems
(b) — asignar cada `OrderItem` a un "grupo de pago" y que el sistema calcule
el monto de cada grupo — queda **fuera de esta sesión**. No hay modelo de
"grupo de pago" ni UI de selección de ítems. Si el piloto lo pide, es una
extensión futura sobre el mismo `Payment` 1:N ya existente (agregar una FK
opcional `payment_group_id` o similar a `OrderItem`, no un rediseño).

## Overview
Permite que un mesero registre **varios pagos parciales** contra la misma
orden — por ejemplo, un grupo de 4 personas que paga cada quien su parte en
efectivo — en vez de exigir un único pago que cubra el total completo. La
orden solo pasa a `pagada` y la mesa a `libre` cuando la suma de todos sus
pagos alcanza o supera el total.

## Users Affected
- **Mesero / Admin**: registra uno o más pagos parciales contra la misma
  cuenta, ve el saldo pendiente y el historial de pagos ya registrados.

## Inputs & Outputs
**Input:** desde `/mesas/{table}/cobro` (la misma pantalla de Cobro, #7), el
mesero ingresa un `amount` (puede ser menor, igual o mayor al saldo
pendiente) y un `method`, y confirma.
**Output:**
- Si `amount` **no cubre** el saldo pendiente: se crea un `Payment` parcial,
  la orden permanece en su estado actual (no `pagada`), la mesa no se libera.
  El mesero se queda en la pantalla de Cobro, con el saldo pendiente y el
  historial de pagos actualizados.
- Si `amount` **cubre** el saldo pendiente (sumado a los pagos previos): se
  crea el `Payment`, la orden pasa a `pagada`, la mesa a `libre`. Mismo
  resultado que el flujo de un solo pago de #7.

## Happy Path
1. Mesero abre `/mesas/{table}/cobro` (igual que #7). Ve el detalle de la
   orden, el **saldo pendiente** (total - suma de pagos ya registrados) y,
   si ya hay pagos previos, el historial de pagos (monto, método, quién
   cobró, hora).
2. El campo de monto se precarga con el **saldo pendiente** (no el total
   fijo) — en una cuenta sin pagos previos, saldo pendiente = total, así que
   el comportamiento por defecto es idéntico al de #7.
3. El mesero ajusta el monto a lo que un cliente del grupo va a pagar (menor
   al saldo pendiente) y confirma.
4. Se registra el `Payment`. Como no cubre el saldo, la orden y la mesa no
   cambian de estado. La pantalla se refresca: nuevo saldo pendiente, nueva
   línea en el historial de pagos.
5. El mesero repite los pasos 2-4 con el resto del grupo. En el último pago,
   el monto ingresado cubre el saldo pendiente restante → la orden pasa a
   `pagada`, la mesa a `libre`, el mesero regresa automáticamente al mapa de
   mesas (mismo resultado final que #7).

## Edge Cases
| Escenario | Comportamiento esperado |
|-----------|------------------------|
| Un solo pago que cubre el total de una vez (sin pagos previos) | Idéntico a #7 — cierra inmediatamente, sin pasos intermedios. Un mesero que no divide la cuenta no nota ningún cambio. |
| Pago parcial que deja saldo pendiente en $0.00 exactos | Se trata como "cubre el saldo" (`>=`, no `>`) — cierra la orden igual que un pago exacto en #7. |
| Suma de pagos que excede el total (el último pago es un billete grande) | Aceptado — mismo criterio que el overpay de un pago único ya documentado en #7; se registra el monto real recibido, "cambio a dar" es cálculo de UI sobre ese último pago. |
| Pago parcial sobre una orden ya `pagada` | No crea un `Payment` nuevo — idempotente, mismo criterio que el "doble tap" de #7. |
| Mesa sin orden activa | 404 — mismo criterio que #7. |
| Pago parcial de $0 o negativo | Rechazado — 422, misma validación `min:0.01` que #7. |
| El mesero recarga la pantalla a mitad de la división | El saldo pendiente y el historial se recalculan desde los `Payment` ya persistidos — no hay estado de "división en progreso" en sesión/cliente, todo vive en la base de datos. |

## Error States
| Error | Mensaje al usuario | Acción de recuperación |
|-------|-------------------|----------------------|
| Monto inválido (≤ 0) | "El monto debe ser mayor a $0.00." | Corregir el monto |
| Orden ya pagada | "Esta cuenta ya fue cobrada." | Redirige al mapa de mesas (mismo criterio que #7) |
| Mesa sin orden activa | "No hay una cuenta abierta para esta mesa." | Redirige al mapa de mesas |

> Nota: a diferencia de #7, esta feature **no tiene** un error de "monto
> insuficiente" — un monto que no cubre el saldo ya no es un error, es un
> pago parcial válido. El único endpoint que conserva el rechazo por monto
> insuficiente es el de #7 (`POST /mesas/{table}/cobro`, sin cambios), para
> el caso de un mesero que explícitamente intenta cerrar la cuenta con un
> pago que no alcanza.

## Security Considerations
- [x] ¿Requiere autenticación? Sí — mismo middleware `role:admin,mesero` que
      #7 (`routes/tenant.php`, grupo `cobro.`).
- [x] ¿Reglas de autorización? Ninguna — cualquier mesero puede registrar
      pagos contra cualquier mesa (mismo criterio que #7).
- [x] ¿Validación de inputs? `amount` numérico ≥ 0.01; `method` del mismo
      enum cerrado `PaymentMethod` que #7.
- [x] ¿Rate limiting? No aplica.
- [x] ¿Datos sensibles en logs? Mismo criterio que #7 — el monto no se
      loggea fuera de la tabla `payments`.
- [x] **F-03 (heredado de #7)** — cada `Payment` parcial registra
      `collected_by` del usuario autenticado en el servidor, nunca del
      request. Se valida por cada pago individual, no solo el que cierra la
      orden.
- [x] **F-05 (heredado de #7)** — aislamiento entre tenants en la ruta
      nueva: pedir el saldo/pagos de la mesa de otro restaurante → 404.
- [x] **Consistencia de suma bajo pagos concurrentes**: dos pagos parciales
      simultáneos sobre la misma orden (dos meseros cobrando distintos
      clientes del mismo grupo casi al mismo tiempo) podrían, en teoría,
      leer el mismo saldo pendiente antes de que el otro se persista. Fuera
      de alcance de esta sesión — el volumen esperado (un restaurante
      pequeño, pagos secuenciales de un mismo mesero en la misma pantalla)
      no lo justifica; documentado como brecha conocida, no como bug.

## Performance Requirements
- Max response time: 500ms (p95) — mismo criterio que #7.
- Expected load: bajo — 2-4 pagos parciales por cuenta dividida, cuentas
  divididas son la minoría de los cobros.
- Data volume: incrementa filas de `payments` proporcionalmente (varias por
  orden en vez de una), sin cambio de esquema.

## Arquitectura (decisión de implementación)

Dos opciones evaluadas para no romper `CloseOrderAction` (Must-have, #7):

1. Modificar `CloseOrderAction` para que compare la suma de pagos vs. total
   y solo cierre cuando se cubre.
2. Agregar una Action nueva (`AddPaymentToOrderAction`) que `CloseOrderAction`
   reutiliza para el caso de pago único.

**Elegida: la (2), con un ajuste mínimo a `CloseOrderAction` para que siga
siendo correcta si ya existían pagos parciales.**

- **`AddPaymentToOrderAction`** (nueva): registra un pago — parcial o que
  completa el total — **sin rechazar nunca por insuficiencia**. Crea el
  `Payment`, recalcula `SUM(payments.amount)` y cierra la orden + libera la
  mesa solo si ese total cubre `Order::total()`. Idempotente: si la orden ya
  está `pagada`, no crea un segundo `Payment` (mismo criterio que #7).
- **`CloseOrderAction`** (modificada, no reescrita): en vez de comparar
  `amount < total`, compara `(pagos ya registrados de la orden + amount) <
  total` antes de decidir si lanza `InsufficientPaymentException`. Con cero
  pagos previos —el caso de **todos** los tests existentes de #7— esto es
  matemáticamente idéntico a la comparación anterior, así que
  `CloseOrderActionTest.php` queda en verde sin modificarlo. Si cubre,
  delega el guardado real (crear `Payment` + cerrar + liberar mesa) a
  `AddPaymentToOrderAction`, en vez de duplicar esa lógica.
- **`Order::total()`** (nuevo método, no accessor Eloquent): extrae el
  cálculo de total (`items->sum(quantity * unit_price)`) que hoy vive inline
  en `CloseOrderAction`, para que ambas Actions usen la misma fuente de
  verdad.
- **Ruta nueva**: `POST /mesas/{table}/cobro/pagos` (`cobro.pagos.store`),
  mismo grupo/middleware que `cobro.show`/`cobro.close`. Usa
  `AddPaymentToOrderAction`. El destino del redirect depende del resultado:
  si el pago **no** cerró la orden, redirige de vuelta a `cobro.show` (el
  mesero se queda en pantalla, todavía queda saldo); si el pago **sí** cerró
  la orden, redirige a `mesas.index` — mismo destino final que `cobro.close`
  — porque `cobro.show` solo acepta órdenes en
  `abierta`/`enviada_cocina`/`lista`/`por_cobrar` (no `pagada`), igual que
  hoy.
- **Ruta existente** `POST /mesas/{table}/cobro` (`cobro.close`, #7): sin
  cambios de firma, contrato ni comportamiento observable.

## Test Cases

### Unit Tests
- [x] `AddPaymentToOrderAction`: pago parcial (menor al saldo) no cierra la
      orden ni libera la mesa
- [x] `AddPaymentToOrderAction`: segundo pago que completa el total sí
      cierra la orden y libera la mesa
- [x] `AddPaymentToOrderAction`: suma de pagos que excede el total se acepta
      (overpay), cierra igual
- [x] `AddPaymentToOrderAction`: orden ya `pagada` → no crea un segundo
      `Payment` (idempotente)
- [x] **F-03**: `collected_by` de cada pago parcial es siempre el
      `$collectedBy` pasado a la Action, nunca inferible de otro lado
- [x] `CloseOrderAction`: con pagos parciales previos que ya cubren el
      total, un `amount` adicional pequeño cierra la orden sin lanzar
      excepción (regresión del ajuste de suma)
- [x] `CloseOrderAction`: con pagos parciales previos insuficientes, un
      `amount` que tampoco alcanza a cubrir el total sigue lanzando
      `InsufficientPaymentException`
- [x] Todos los tests existentes de `CloseOrderActionTest.php` (#7) siguen
      pasando sin modificarlos

### Integration Tests
- [x] `POST /mesas/{table}/cobro/pagos` con monto parcial → 302 de vuelta a
      `cobro.show`, `Payment` creado, orden y mesa sin cambio de estado
- [x] `POST /mesas/{table}/cobro/pagos` con un segundo pago que completa el
      saldo → orden `pagada`, mesa `libre`
- [x] `GET /mesas/{table}/cobro` después de un pago parcial muestra el saldo
      pendiente actualizado y el historial de pagos en las props
- [x] Usuario con `role=cocina` → 403 en `cobro.pagos.store`
- [x] **F-03**: un `collected_by` enviado en el body de `cobro.pagos.store`
      es ignorado
- [x] **F-05**: mesero del restaurante A pide `cobro.pagos.store` de la mesa
      de otro restaurante → 404
- [x] Pago parcial de monto ≤ 0 → 422
- [x] `POST /mesas/{table}/cobro/pagos` sobre una orden ya `pagada` → sin
      nuevo `Payment` (idempotente)
- [x] Todos los tests existentes de `CobroTest.php` (#7) siguen pasando sin
      modificarlos

### E2E Tests
- [x] Happy path: cuenta con total > 0 → dos pagos parciales que juntos
      cubren el total → mesa vuelve a `libre` en el mapa de mesas
      (verificado en browser real)
- [x] El flujo de un solo pago (#7) sigue funcionando igual en la misma
      pantalla — verificado en browser real, sin regresión visual

> **Notas de implementación (ver `decision-log.md`, 2026-08-12):**
> - `Order::total()` extraído como método del modelo (no accessor Eloquent)
>   — antes vivía inline en `CloseOrderAction`, ahora es la única fuente de
>   verdad, usada también por `AddPaymentToOrderAction`.
> - **Bug encontrado en verificación visual con browser real** (no cubierto
>   por los tests de Pest, que no ejercitan la reactividad de Vue): tras un
>   pago parcial, Inertia recarga las props de la misma instancia del
>   componente en vez de remontarlo, así que el campo "Monto recibido" se
>   quedaba con el valor del pago recién registrado en vez de reflejar el
>   nuevo saldo pendiente. Corregido con un `watch(saldoPendiente, ...)` en
>   `Cobro.vue` que resetea `amount` cuando cambia el saldo.
> - Wayfinder nombra la función generada por el último segmento del route
>   *name* (`pagos.store` → `store`), no por el nombre del método del
>   controller (`PaymentController::addPayment`) — el docblock `@see` que
>   genera queda con el nombre "equivocado" pero la URL/método son
>   correctos; no es un bug, es cómo funciona `wayfinder:generate`.
> - Verificación visual en browser real hecha en `demo.localhost:8001` (no
>   8000: puerto ocupado por la sesión concurrente de Inventario, ver
>   `decision-log.md`) — `php artisan serve --port=8001` sobre assets ya
>   compilados (`npm run build`), no `composer run dev`. Datos de prueba
>   creados y borrados manualmente (mesa/orden dedicadas, no se tocaron las
>   mesas ya usadas por otras sesiones en la DB compartida).

## Definition of Done
- [x] Todos los test cases de este spec pasando (Pest)
- [x] `tests/Feature/CobroTest.php` y
      `tests/Unit/Actions/Orders/CloseOrderActionTest.php` (#7) siguen en
      verde, sin modificarlos
- [ ] Code review completado y aprobado
- [x] Spec actualizado con comportamiento real implementado
- [x] `_ai/specs/cobro.spec.md` actualizado: la nota del Edge Case "Monto
      insuficiente... split bill fuera de este spec" apunta a este archivo
- [x] Verificado manualmente en browser real (`demo.localhost:8001` — ver
      nota de implementación arriba)
- [x] Sin errores en consola / logs
- [x] `npm run lint:check` y `npm run types:check` sin errores nuevos
