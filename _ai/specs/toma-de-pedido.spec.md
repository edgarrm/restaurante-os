# Feature: Toma de Pedido

## Status
[x] Draft  [ ] Review  [ ] Approved  [x] Implemented

## PRD Reference
User Stories:
- US-1.2 "Como mesero, quiero agregar ítems del menú a la cuenta de una mesa, para
  registrar el pedido del cliente."
- US-1.3 "Como mesero, quiero enviar el pedido a cocina, para que empiecen a
  prepararlo sin que yo tenga que avisar en persona."

Épica: Épica 1 — Toma de Pedidos (POS)
Prioridad: Must

## Overview
Pantalla donde el mesero agrega platillos del menú a la cuenta abierta de una mesa
y la envía a cocina. Es el núcleo del diferenciador del producto (ver PRD): debe
ser operable por un mesero que nunca ha visto el sistema, sin ayuda.

## Users Affected
- **Mesero**: agrega ítems, ajusta cantidades, envía la orden a cocina.
- **Admin**: tiene el mismo acceso que un mesero a esta pantalla (puede cubrir un
  turno).
- **Cocina**: no tiene acceso a esta pantalla — su flujo es `/cocina` (spec
  separado).

## Inputs & Outputs
**Input:** el mesero navega a `/mesas/{table}/pedido`. Ahí selecciona platillos del
menú (con cantidad) y confirma el envío a cocina.
**Output:** la orden de esa mesa queda con los ítems agregados; al enviar a
cocina, esos ítems se vuelven visibles en `/cocina` (spec de Cocina/KDS) y la
orden pasa a status `enviada_cocina`.

## Happy Path
1. Mesero abre `/mesas/{table}/pedido` desde el mapa de mesas.
2. Si la mesa está `libre`, el sistema abre automáticamente una orden nueva
   (status `abierta`) y marca la mesa como `ocupada` — sin un paso explícito de
   "abrir mesa", para no agregar fricción al flujo.
3. Si la mesa ya está `ocupada`, el sistema reutiliza su orden `abierta` existente.
4. El mesero ve el menú organizado por categoría y el panel "La Cuenta" (vacío al
   inicio).
5. El mesero toca un platillo disponible → se agrega a "La Cuenta" con cantidad 1.
6. El mesero puede tocar el mismo platillo de nuevo → incrementa la cantidad del
   mismo renglón (no crea un renglón duplicado).
7. El mesero ajusta cantidades con el stepper de "La Cuenta" si es necesario.
8. El mesero toca "Enviar a Cocina" (habilitado solo si hay al menos un ítem) → la
   orden pasa a `enviada_cocina`; los ítems quedan con status `pendiente` (los
   consume el spec de Cocina/KDS).

## Edge Cases
| Escenario | Comportamiento esperado |
|-----------|------------------------|
| Mesa en estado `por_cobrar` (ya se pidió la cuenta) | No se pueden agregar más ítems; la pantalla redirige a `/mesas/{table}/cobro` con un aviso |
| El mismo platillo se agrega dos veces | Se incrementa `quantity` del `OrderItem` existente, no se crea un segundo renglón |
| Un ítem se marca no disponible después de cargar la pantalla (otro admin lo desactivó) | El intento de agregarlo devuelve 422; el frontend refresca el estado del menú para reflejar la baja |
| Dos meseros abren la misma mesa `ocupada` casi al mismo tiempo | Ambos ven y editan la misma orden abierta — no hay conflicto porque cada agregado es un insert, no un overwrite |
| Enviar a cocina con la orden vacía (forzado vía API directamente, sin pasar por el botón deshabilitado del UI) | 422 — "Agrega al menos un platillo antes de enviar a cocina" |
| Mesa inexistente en la URL | 404 estándar de Laravel |
| Cantidad de un ítem ajustada a 0 en el stepper | El renglón se elimina de "La Cuenta" (no queda un `OrderItem` con `quantity=0`) |

## Error States
| Error | Mensaje al usuario | Acción de recuperación |
|-------|-------------------|----------------------|
| Ítem no disponible al agregarlo | "Este platillo ya no está disponible." | El menú se refresca automáticamente; el ítem queda visualmente deshabilitado |
| Mesa en `por_cobrar` | "Esta mesa ya solicitó la cuenta — no se pueden agregar más platillos." | Redirige a la pantalla de cobro de esa mesa |
| Envío a cocina sin ítems | "Agrega al menos un platillo antes de enviar a cocina." | El botón "Enviar a Cocina" permanece deshabilitado hasta que haya ≥1 ítem |
| Cantidad inválida (negativa o no numérica) | "Cantidad inválida." | El stepper no permite valores fuera de rango desde el UI; el backend revalida igual |

## Security Considerations
- [x] ¿Requiere autenticación? Sí — `role` en `mesero` o `admin`. `role=cocina` recibe 403.
- [x] ¿Reglas de autorización? Ninguna mesa está asignada a un mesero específico en
      el MVP — cualquier mesero puede operar cualquier mesa (decisión de alcance
      consciente, no un descuido; revisitar si un piloto lo pide).
- [x] ¿Validación de inputs? `menu_item_id` debe existir y tener `available=true`
      **revalidado en el servidor** al momento de la escritura (no confiar en el
      estado que trae el cliente); `quantity` entero positivo.
- [x] ¿Rate limiting? No aplica — uso interno, pocos dispositivos por restaurante.
- [x] ¿Datos sensibles en logs? Ninguno — nombres de platillos, cantidades y
      precios no son datos sensibles.
- [ ] **F-05 — IDOR entre tenants**: `/mesas/{table}/pedido` resuelve el modelo
      por ID de la URL. La protección depende enteramente de que `Table` use
      `BelongsToTenant` y de que tenancy esté inicializada. Pedir una mesa de
      otro restaurante debe devolver **404**, no sus datos. Lo mismo aplica a
      `menu_item_id` en el body: agregar un platillo de otro tenant debe
      fallar. Ver `_ai/docs/threat-model.md`.

## Performance Requirements
- Max response time: 500ms (p95) para agregar ítem y para enviar a cocina — ver
  Non-Functional Requirements del PRD, es el requisito no funcional más crítico.
- Expected load: unos pocos meseros por restaurante, decenas de requests/minuto
  en hora pico — carga baja, no es el cuello de botella del sistema.
- Data volume: cientos de `MenuItem` por restaurante, decenas de `OrderItem` por
  orden activa.

## Test Cases

### Unit Tests
- [x] `AddItemToOrderAction`: agregar un ítem nuevo crea un `OrderItem` con
      `unit_price` igual al precio actual del `MenuItem` (snapshot)
- [x] `AddItemToOrderAction`: agregar un ítem ya presente incrementa `quantity`
      del renglón existente en vez de duplicarlo
- [x] `AddItemToOrderAction`: agregar un ítem con `available=false` lanza
      excepción de dominio
- [x] `AddItemToOrderAction`: agregar un ítem a una mesa en `por_cobrar` lanza
      excepción de dominio
- [x] `SendOrderToKitchenAction`: una orden con ítems cambia su status a
      `enviada_cocina`
- [x] `SendOrderToKitchenAction`: una orden sin ítems lanza excepción de dominio
- [x] Lógica de apertura de orden: mesa `libre` crea una `Order` nueva y marca la
      mesa `ocupada`; mesa `ocupada` reutiliza la `Order` `abierta` existente
      (`OpenOrReuseOrderForTableAction`)

### Integration Tests
- [x] Mesero visita `/mesas/{mesa_libre}/pedido` → la mesa queda `ocupada` y existe
      una orden `abierta` para ella
- [x] `POST /mesas/{table}/pedido/items` con un ítem disponible → la orden
      refleja el ítem con la cantidad correcta (redirect 302 a la misma
      página, patrón PRG — ver nota abajo)
- [x] `POST /mesas/{table}/pedido/items` con un ítem no disponible → 422 con el
      mensaje exacto del spec
- [x] `POST /mesas/{table}/pedido/enviar` con ítems → orden en `enviada_cocina`
      (redirect 302, mismo patrón)
- [x] `POST /mesas/{table}/pedido/enviar` sin ítems → 422
- [x] Usuario con `role=cocina` accede a `/mesas/{table}/pedido` → 403
- [x] **F-05**: un mesero del restaurante A pide
      `/mesas/{mesa_del_restaurante_B}/pedido` → 404
- [x] **F-05**: agregar un `menu_item_id` que pertenece a otro restaurante →
      404, no se agrega a la orden

> Nota de implementación: igual que `gestion-mesas.spec.md` (#1), el "200"
> original de este documento para `POST .../items` y `POST .../enviar`
> asumía una respuesta directa; el patrón PRG ya establecido en este repo
> (redirect 302 a la misma página) es el implementado — consistente además
> con la propia descripción de `api-contract.yaml` para estos dos
> endpoints ("redirect Inertia a la misma página"). Los tests verifican
> `assertRedirect()` + el estado del modelo en BD.
>
> **Brecha de alcance decidida en PASO 0 (ver `decision-log.md`,
> 2026-08-11):** Happy Path y Edge Cases de este documento narran un
> stepper que ajusta/quita cantidades de un `OrderItem` ya agregado
> (incluyendo "cantidad ajustada a 0 → el renglón se elimina"), pero ni
> `api-contract.yaml` ni los Integration Tests de arriba definen un
> endpoint para editarlo/eliminarlo — solo agregar (con incremento) existe.
> Se dejó **fuera de alcance de esta sesión**, documentado aquí en vez de
> construir un endpoint no pedido por ningún test case; es trabajo
> pendiente para cuando se implemente la pantalla Vue de
> `/mesas/{table}/pedido`.

### E2E Tests
- [ ] Happy path completo desde UI: abrir mesa libre → agregar 2 platillos →
      ajustar cantidad de uno → enviar a cocina → los ítems aparecen en `/cocina`
- [ ] Error crítico desde UI: intentar agregar un ítem que se desactivó
      (`available=false`) después de cargar la pantalla → mensaje correcto, la
      orden no queda en estado inconsistente

## Definition of Done
- [x] Todos los test cases de Unit + Integration de este spec pasando (Pest)
- [ ] Code review completado y aprobado
- [x] Spec actualizado con comportamiento real implementado
- [ ] Desplegado en staging y verificado manualmente en un dispositivo tablet real
- [x] Sin errores en consola / logs
- [ ] Agregar ítem y enviar a cocina dentro de 500ms p95 — pendiente de medir
      junto con la pantalla Vue
- [x] Sin lógica de negocio en el controller — vive en `AddItemToOrderAction` /
      `SendOrderToKitchenAction` / `OpenOrReuseOrderForTableAction` (ver
      ADR-004)
- [ ] Pantalla Vue de `/mesas/{table}/pedido` (E2E) — pendiente, fuera de
      alcance de esta sesión (backend only, mismo criterio que #1/#2/#3/#4);
      incluye el endpoint de editar/quitar `OrderItem` documentado como
      brecha arriba
