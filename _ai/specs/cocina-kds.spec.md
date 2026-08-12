# Feature: Cocina (KDS)

## Status
[x] Draft  [ ] Review  [ ] Approved  [x] Implemented

## PRD Reference
User Stories:
- US-2.1 "Como cocinero, quiero ver los pedidos entrantes en orden, para saber qué
  preparar y en qué secuencia."
- US-2.2 "Como cocinero, quiero marcar un pedido (o ítem) como listo, para que el
  mesero sepa que puede servirlo."

Épica: Épica 2 — Cocina (KDS)
Prioridad: Must
Depende de: `toma-de-pedido.spec.md` (produce los ítems que esta pantalla consume)

## Overview
Pantalla de cocina: lista de ítems pendientes de preparar, con acción de marcar
listo. Debe ser legible a distancia y operable bajo presión de servicio, sin
decoración innecesaria (ver design system).

## Users Affected
- **Cocina**: consulta pedidos entrantes y marca ítems como listos.
- **Mesero**: no interactúa aquí, pero ve el resultado reflejado en `/mesas`
  (estado del ítem/orden) a través del siguiente poll.

## Inputs & Outputs
**Input:** ítems con `status=pendiente` de órdenes en `enviada_cocina`.
**Output:** al marcar un ítem como listo, su `status` pasa a `listo` — visible
para el mesero.

## Happy Path
1. Cocina abre `/cocina`.
2. Ve tarjetas de órdenes agrupadas por mesa, cada una con sus ítems, cantidades y
   tiempo transcurrido desde el envío.
3. La vista se actualiza sola cada 3-5s vía `poll()` (ver ADR-005).
4. Cocina toca "Listo" en un ítem (o en toda la orden) → su `status` cambia a
   `listo`, sin diálogo de confirmación (velocidad > prevención de error en este
   flujo, ver design system).
5. Cuando todos los ítems de una orden están `listo`, la orden completa se marca
   `lista` y puede desaparecer de la vista activa de cocina (o moverse a una
   sección "completadas" — a definir en Fase 03).

## Edge Cases
| Escenario | Comportamiento esperado |
|-----------|------------------------|
| Cero pedidos pendientes | Estado vacío: "No hay pedidos pendientes." |
| Un pedido lleva mucho tiempo esperando (ej. >15 min) | Prioridad visual — el orden de la lista y/o un indicador de urgencia lo distingue de pedidos recientes (umbral exacto a definir en el spec de Fase 03/diseño, no bloquea esta spec de backend) |
| Cocina marca "Listo" dos veces sobre el mismo ítem (doble tap accidental) | Idempotente — el segundo tap no produce error, el ítem ya está en `listo` |
| Ítem marcado listo pero el mesero aún no lo ha servido | El ítem permanece visible con status `listo` hasta que el mesero lo marca `servido` desde su propio flujo (fuera de esta spec — puede vivir en `toma-de-pedido` o en un spec de "servir" separado, a definir) |
| Dos cocineros en pantallas distintas marcan el mismo ítem casi simultáneamente | El segundo request es un no-op (mismo comportamiento que el doble tap) |

## Error States
| Error | Mensaje al usuario | Acción de recuperación |
|-------|-------------------|----------------------|
| Ítem inexistente o ya no pertenece a una orden activa | Sin alerta bloqueante — el poll siguiente simplemente ya no lo muestra | Ninguna acción requerida del usuario |

## Security Considerations
- [x] ¿Requiere autenticación? Sí — `role` en `cocina` o `admin`. `role=mesero`
      recibe 403.
- [x] ¿Reglas de autorización? Ninguna — cualquier cocinero ve y opera todos los
      pedidos entrantes (no hay estaciones de cocina separadas en el MVP).
- [x] ¿Validación de inputs? El `orderItem` en la URL debe pertenecer a una orden
      con status `enviada_cocina` o `lista` — si no, 404 en vez de exponer datos
      de una orden ya cerrada.
- [x] ¿Rate limiting? No aplica.
- [x] ¿Datos sensibles en logs? Ninguno.
- [ ] **F-05 — IDOR entre tenants**: marcar como listo un `orderItem` de otro
      restaurante debe devolver 404. El KDS es especialmente sensible a esto
      porque su acción es idempotente y silenciosa — un 200 por error no
      levantaría sospecha. Ver `_ai/docs/threat-model.md`.

## Performance Requirements
- Max response time: 500ms (p95) al marcar un ítem como listo — acción crítica
  de servicio.
- Expected load: 1 poll cada 3-5s por dispositivo de cocina (normalmente 1
  dispositivo por restaurante en el MVP).
- Data volume: decenas de ítems pendientes en el peor caso de hora pico.

## Test Cases

### Unit Tests
- [x] `MarkOrderItemReadyAction`: cambia `status` de `pendiente`/`preparando` a
      `listo`
- [x] `MarkOrderItemReadyAction`: es idempotente — marcar un ítem ya `listo` no
      lanza error
- [x] Lógica de orden completa: cuando todos los `OrderItem` de una `Order` están
      `listo`, la `Order` pasa a status `lista`

### Integration Tests
- [x] `GET /cocina` devuelve solo ítems de órdenes en `enviada_cocina`
- [x] `PATCH /cocina/items/{orderItem}/listo` → 200, ítem actualizado
- [x] `PATCH /cocina/items/{orderItem}/listo` sobre un ítem ya `listo` → 200,
      sin efecto adicional (idempotente)
- [x] Usuario con `role=mesero` accede a `/cocina` → 403
- [x] **F-05**: `PATCH /cocina/items/{orderItem}/listo` sobre un ítem de otro
      restaurante → 404, y el ítem del otro tenant NO cambia de estado
- [x] **F-05**: `GET /cocina` del restaurante A no incluye ningún pedido del
      restaurante B

> **Nota de implementación (PASO 0, ver `decision-log.md`, 2026-08-11):**
> `GET /cocina` filtra únicamente por `Order.status = enviada_cocina` y
> devuelve **todos** los `OrderItem` de esa orden (incluyendo los ya
> `listo`) — no filtra además por status del ítem. Ver decisión completa en
> `decision-log.md`.
>
> **Nota de implementación:** igual que en #5, el "200" original de este
> documento para `PATCH .../listo` asumía una respuesta directa; se
> implementó como redirect 302 a `cocina.index` (patrón PRG ya establecido
> en este repo). Los tests verifican `assertRedirect()` + el estado del
> modelo en BD.

### E2E Tests
- [ ] Happy path: mesero envía un pedido a cocina (spec `toma-de-pedido`) → el
      pedido aparece en `/cocina` → cocina lo marca listo → el estado se refleja
      en el siguiente poll de `/mesas`
- [ ] Cero pedidos pendientes muestra el estado vacío correcto

## Definition of Done
- [x] Todos los test cases de Unit + Integration de este spec pasando (Pest)
- [ ] Code review completado y aprobado
- [x] Spec actualizado con comportamiento real implementado
- [ ] Desplegado en staging y verificado manualmente en tablet real
- [x] Sin errores en consola / logs
- [ ] Marcar listo dentro de 500ms p95 — pendiente de medir junto con la
      pantalla Vue
- [ ] Pantalla Vue de `/cocina` (E2E) — pendiente, fuera de alcance de esta
      sesión (backend only, mismo criterio que #1-#5)
