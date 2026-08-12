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
| Se agrega un ítem a una orden que ya está `lista` (todos sus ítems previos `listo`) | La `Order` regresa a status `enviada_cocina` — el ítem nuevo (y la orden completa) vuelve a aparecer en `GET /cocina` (bug REDEV-31) |

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
- [x] `POST /mesas/{table}/pedido/items` sobre una orden `lista` → la
      `Order` regresa a `enviada_cocina` y el pedido reaparece en
      `GET /cocina` (bug REDEV-31, ver nota abajo)

> **Bug REDEV-31, corregido (ver `decision-log.md`):** `AddItemToOrderAction`
> permitía agregar un `OrderItem` a una orden `lista` (la mesa sigue
> `ocupada`, solo `por_cobrar` bloquea el agregado), pero `Order.status`
> se quedaba en `lista` — `KitchenController::index()` solo filtra por
> `enviada_cocina`, así que el ítem nuevo nunca llegaba a cocina en la
> práctica. Confirmado con el usuario vía `AskUserQuestion` entre tres
> opciones (revertir el status de la orden, ampliar el query de cocina, o
> bloquear el agregado): se eligió revertir `Order.status` a
> `enviada_cocina` dentro de `AddItemToOrderAction` cuando la orden estaba
> `lista` — mantiene `KitchenController` como única fuente de verdad de
> "activa" (`Order.status = enviada_cocina`) sin duplicar esa lógica en el
> query de `completedOrders`.

> Nota de implementación: igual que `gestion-mesas.spec.md` (#1), el "200"
> original de este documento para `POST .../items` y `POST .../enviar`
> asumía una respuesta directa; el patrón PRG ya establecido en este repo
> (redirect 302 a la misma página) es el implementado — consistente además
> con la propia descripción de `api-contract.yaml` para estos dos
> endpoints ("redirect Inertia a la misma página"). Los tests verifican
> `assertRedirect()` + el estado del modelo en BD.
>
> **Brecha de alcance decidida en PASO 0 de 2026-08-11 (ver `decision-log.md`)
> — cerrada en PASO 0 de 2026-08-12 (pantalla Vue):** Happy Path y Edge
> Cases de este documento narran un stepper que ajusta/quita cantidades de
> un `OrderItem` ya agregado (incluyendo "cantidad ajustada a 0 → el
> renglón se elimina"). En la sesión de 2026-08-11 se dejó fuera de
> alcance porque ningún Integration Test lo pedía. Al construir la
> pantalla Vue (2026-08-12), el usuario decidió ampliar el alcance
> (mismo criterio que `por_cobrar` en #7) en vez de lanzar sin stepper:
> ahora existe `PATCH /mesas/{table}/pedido/items/{orderItem}`
> (`UpdateOrderItemQuantityAction`), con los Integration Tests
> correspondientes abajo. Solo editable mientras la orden sigue `abierta`
> — ver `OrderNotEditableException`.

- [x] `PATCH /mesas/{table}/pedido/items/{orderItem}` ajusta la cantidad de
      un `OrderItem`
- [x] `PATCH .../items/{orderItem}` con `quantity=0` elimina el renglón
- [x] `PATCH .../items/{orderItem}` sobre una orden que ya no está `abierta`
      (p. ej. `enviada_cocina`) devuelve 422
- [x] **F-05**: ajustar un `OrderItem` de una mesa de otro restaurante → 404
- [x] Mesero visita `/mesas/{mesa_por_cobrar}/pedido` → redirige a
      `/mesas/{table}/cobro` con un aviso (Edge Case documentado arriba;
      hueco encontrado construyendo la pantalla Vue — antes de esto, la
      única ruta existente devolvía 404 en ese caso)

> **Bug de plomería encontrado construyendo la pantalla Vue (2026-08-12):**
> `OpenOrReuseOrderForTableAction` solo reutilizaba órdenes en `abierta`
> para una mesa `ocupada`. Recargar `GET /mesas/{table}/pedido` después de
> "Enviar a Cocina" (orden ya en `enviada_cocina`) devolvía 404 — nunca se
> detectó antes porque los Integration Tests de `enviar` solo hacían
> `assertRedirect()` sin seguir el redirect. Corregido ampliando el query a
> `[abierta, enviada_cocina, lista]`, mismo criterio de estados "activos"
> que `RequestBillAction` (#7).
>
> **Cambio de arquitectura descubierto en la misma sesión:** los 422 de
> dominio (`abort(422, $mensaje)`) de `addItem`/`send` nunca se probaron
> contra una petición Inertia real (solo contra `postJson()`, que fuerza
> `Accept: application/json`). Contra el cliente Inertia real (`Accept:
> text/html`), un `abort(422, ...)` no trae el header `X-Inertia`, así que
> Inertia lo trata como respuesta "no-Inertia" y muestra un modal con el
> HTML crudo de error en vez del mensaje del spec — confirmado con un test
> exploratorio antes de decidir el fix. Los tres 422 de dominio de este
> flujo (`addItem`, `send`, `updateItem`) ahora se lanzan como
> `ValidationException::withMessages([...])`, que sí viaja por el redirect
> 302 + errores flasheados que Inertia espera (ver `.ai/rules/feature.md`).
> Los dos Integration Tests existentes que verificaban `json('message')` se
> actualizaron a `json('errors.<campo>.0')`.

### E2E Tests
- [x] Happy path completo desde UI: abrir mesa libre → agregar 2 platillos
      (incrementando uno) → ajustar cantidad con el stepper (incluyendo
      bajar a 0 para quitar un renglón) → enviar a cocina → la orden queda
      `enviada_cocina` y la pantalla se puede seguir usando (verificado en
      browser real, `demo.localhost:8000`, light y dark mode)
- [x] Error crítico desde UI: intentar agregar un ítem que se desactivó
      (`available=false`) después de cargar la pantalla → mensaje correcto
      en un banner (no un modal de error crudo), el menú se refresca
      automáticamente (el ítem queda deshabilitado), la orden no queda en
      estado inconsistente (verificado en browser real)

## Definition of Done
- [x] Todos los test cases de Unit + Integration de este spec pasando (Pest)
- [ ] Code review completado y aprobado
- [x] Spec actualizado con comportamiento real implementado
- [ ] Desplegado en staging y verificado manualmente en un dispositivo tablet real
- [x] Sin errores en consola / logs propios de esta pantalla (persiste el
      hallazgo de hidratación pre-existente de toda la app, ver
      `decision-log.md`, Fase 03 — no introducido aquí)
- [ ] Agregar ítem y enviar a cocina dentro de 500ms p95 — pendiente de medir
- [x] Sin lógica de negocio en el controller — vive en `AddItemToOrderAction` /
      `SendOrderToKitchenAction` / `OpenOrReuseOrderForTableAction` /
      `UpdateOrderItemQuantityAction` (ver ADR-004)
- [x] Pantalla Vue de `/mesas/{table}/pedido`
      (`resources/js/pages/mesas/Pedido.vue`) — incluye el stepper de
      editar/quitar `OrderItem` (brecha cerrada, ver arriba)
