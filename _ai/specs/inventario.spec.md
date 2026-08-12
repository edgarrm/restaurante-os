# Feature: Inventario

## Status
[x] Draft  [ ] Review  [ ] Approved  [x] Implemented

## PRD Reference
User Story: US-5.1 "Como admin, quiero ver la cantidad actual de cada insumo,
para saber qué necesito reponer." + US-5.2 "Como admin, quiero registrar
manualmente una entrada o salida de un insumo, para mantener el conteo
actualizado."
Épica: Épica 5 — Inventario
Prioridad: **Should** — no bloquea al restaurante ancla operar (ver
`_ai/docs/spec-registry.md`, sección "Pendiente de spec"); se aborda después
de completar las 9 pantallas Must.

## Overview
Pantalla única de conteo simple de stock: lista de insumos con su cantidad
actual y umbral de alerta, con un diálogo para registrar ajustes manuales
(entrada/salida). Sin descuento automático por venta ni costeo de receta —
eso es explícitamente Out-of-Scope del MVP (ver `_ai/docs/PRD.md`).

## PASO 0 — Decisiones antes de escribir código

**Gap: no existe operación de alta de insumos ni en el PRD ni en
`api-contract.yaml`.** Solo hay `GET /inventario` (listar) y
`POST /inventario/{item}/ajustar` (ajustar). Sin una forma de crear el
primer `InventoryItem`, la pantalla no sirve el día uno — mismo problema
que tuvo Mapa de Mesas sin Gestión de Mesas (US-6.3, ver
`_ai/docs/PRD.md`, Épica 6, y `_ai/specs/gestion-mesas.spec.md` intro).

**Decisión: se agrega `POST /inventario`** (alta simple de insumo: `name`,
`unit`, `low_stock_threshold`, `quantity_on_hand` inicial opcional,
default 0). Documentado aquí y en `decision-log.md`, mismo criterio que la
nota "US-6.3 no estaba en el PRD original" en `spec-registry.md`. No se
considera una desviación del contrato — es la misma clase de gap de
cobertura ya resuelta dos veces en este proyecto (mesas, y de forma
implícita cada pantalla de "Gestión de X").

**Nombre de componente Inertia: `Inventario/Index` (con mayúscula
inicial).** Así lo especifica literalmente `x-inertia-component` en
`api-contract.yaml` para ambas rutas (`/inventario` y
`/inventario/{item}/ajustar`) — a diferencia de todos los dominios
anteriores, que usan carpetas en minúscula (`mesas/Index`, `menu/Index`,
`tables/Index`, `staff/Index`, `reservas/Index`, `cocina/Index`). Se
respeta el contrato tal cual está escrito: el archivo Vue vive en
`resources/js/pages/Inventario/Index.vue` y el controller renderiza
`Inertia::render('Inventario/Index', ...)`.

**Una sola pantalla, mismo patrón que Reservas (#6/#7) y Gestión de
Menú/Mesas.** El ajuste de stock (US-5.2) es un diálogo dentro del índice
de inventario, no una ruta/pantalla separada — `api-contract.yaml` ya
documenta ambas rutas renderizando el mismo componente
`Inventario/Index`. `_ai/design/screen-inventory.md` fila #11 ("Ajuste de
inventario") se fusiona en la fila #10.

**Autorización: exclusivamente `role=admin`**, sin compartir con
mesero/cocina — a diferencia de Mesas/Cobro (`role:admin,mesero`) o
Cocina (`role:admin,cocina`). El PRD (US-5.1, US-5.2) solo menciona
"admin" en ambas historias; no hay indicio de que mesero o cocina
necesiten esta pantalla.

## Users Affected
- **Admin**: ve la lista de insumos, crea insumos nuevos y registra
  entradas/salidas manuales. Ningún otro rol accede a esta pantalla.

## Inputs & Outputs
**Input:** admin en `/inventario` crea un insumo (`name`, `unit`,
`low_stock_threshold`, `quantity_on_hand` inicial) o registra un
movimiento sobre un insumo existente (`type`, `quantity`, `note`
opcional).
**Output:** el insumo aparece en la lista con su `quantity_on_hand`
actualizada; cada ajuste queda registrado como un `InventoryMovement` con
`created_by` del usuario autenticado.

## Happy Path
1. Admin abre `/inventario`.
2. Ve la lista de insumos existentes (vacía en el primer uso del
   restaurante), cada uno con nombre, unidad, cantidad actual y si está
   bajo el umbral de alerta.
3. Admin toca "Nuevo insumo", ingresa nombre (ej. "Tomate"), unidad (ej.
   "kg"), umbral de alerta (ej. 5) y cantidad inicial (ej. 20). Al
   guardar, el insumo aparece en la lista.
4. Admin toca "Registrar movimiento" sobre un insumo, elige tipo (entrada
   o salida), cantidad y una nota opcional (ej. "Compra a proveedor" /
   "Merma").
5. Al guardar, `quantity_on_hand` se actualiza (+cantidad si entrada,
   −cantidad si salida) y el insumo refleja el nuevo total en la lista.

## Edge Cases
| Escenario | Comportamiento esperado |
|-----------|------------------------|
| Salida que dejaría `quantity_on_hand` negativa | Rechazada — 422, "No hay stock suficiente de '{insumo}' para esta salida (disponible: {cantidad} {unidad})." No se permite stock negativo en v1. |
| Salida que deja `quantity_on_hand` exactamente en 0 | Permitida — 0 es un valor válido, no un error. |
| Insumo con `quantity_on_hand` en 0 | Se muestra en la lista con resaltado crítico (rojo), no se oculta. |
| `quantity_on_hand <= low_stock_threshold` (pero no en 0) | Resaltado visual ámbar — "bajo el umbral", sin bloquear ninguna acción. |
| `low_stock_threshold` en 0 (admin no configuró alerta) | Nunca se resalta por bajo stock a menos que `quantity_on_hand` también sea 0 — comportamiento matemático de la comparación, no un caso especial de código. |
| Nombre de insumo duplicado | Permitido — no hay restricción de unicidad (ej. "Limón" comprado a dos proveedores con presentaciones distintas es un caso real); mismo criterio que nombres de mesa duplicados en `gestion-mesas.spec.md`. |
| Cantidad del movimiento en 0 o negativa | Rechazada por validación — cantidad mínima 0.01 (un movimiento de 0 no es un ajuste real). |
| Primer uso del restaurante (cero insumos creados) | La lista muestra un estado vacío guiando al admin a crear el primer insumo aquí. |
| Nota del movimiento vacía | Permitida — `note` es opcional (ej. una entrada rutinaria no siempre necesita explicación). |

## Error States
| Error | Mensaje al usuario | Acción de recuperación |
|-------|-------------------|----------------------|
| Salida deja stock negativo | "No hay stock suficiente de '{insumo}' para esta salida (disponible: {cantidad} {unidad})." | Corregir la cantidad o registrar primero una entrada |
| Cantidad de movimiento inválida | "La cantidad debe ser mayor a 0." | Corregir el campo |
| Nombre o unidad de insumo vacíos | "Completa nombre y unidad." | Corregir el campo |
| `low_stock_threshold` o `quantity_on_hand` inicial negativos | "La cantidad no puede ser negativa." | Corregir el campo |

## Security Considerations
- [x] ¿Requiere autenticación? Sí — solo `role=admin` (ver PASO 0).
- [x] ¿Reglas de autorización? Ninguna otra — es exclusivamente de admin,
      mismo patrón que `TablePolicy`/`MenuItemPolicy` (ADR-007).
- [x] ¿Validación de inputs? `name`/`unit` requeridos no vacíos;
      `low_stock_threshold`/`quantity_on_hand` numéricos ≥ 0; `type` de
      `InventoryMovement` en `{entrada, salida}`; `quantity` del
      movimiento numérico > 0.
- [x] ¿Rate limiting? No aplica.
- [x] ¿Datos sensibles en logs? Ninguno.
- [x] **`InventoryMovement.created_by` siempre del usuario autenticado en
      el servidor**, nunca de un campo del request (sería falsificable) —
      mismo control que F-03 (`Payment.collected_by`, ver
      `_ai/docs/data-model.md` y `cobro.spec.md`). Sin él es imposible
      responder "¿quién ajustó este insumo?".
- [x] **F-05 — IDOR entre tenants**: crear/leer un insumo, o ajustar el
      stock de un insumo de otro restaurante por ID, debe devolver 404.
      `InventoryItem` usa `BelongsToTenant`; el route model binding de
      `{item}` respeta `TenantScope`. Ver `_ai/docs/threat-model.md`.
- [x] **Mass assignment**: `quantity_on_hand` se excluye de `$fillable`
      (mismo patrón que `Table.status`/`MenuItem.available`, ver
      `.ai/rules/actions.md`) — solo se muta vía `forceFill()` dentro de
      las Actions, nunca desde `$request->validated()` directo.

## Performance Requirements
- Max response time: 500ms (p95) — uso esporádico, no es un flujo de alta
  frecuencia durante servicio.
- Expected load: uso esporádico (conteo diario/semanal, ajustes
  ocasionales).
- Data volume: decenas de insumos por restaurante; movimientos crecen con
  el tiempo pero sin un límite relevante para el MVP.

## Test Cases

### Unit Tests
- [x] `CreateInventoryItemAction`: crea un insumo con `quantity_on_hand`
      inicial dada
- [x] `CreateInventoryItemAction`: sin `quantity_on_hand` inicial → default 0
- [x] `CreateInventoryItemAction`: `low_stock_threshold`/`quantity_on_hand`
      negativos → error de validación
- [x] `RegisterInventoryMovementAction`: `entrada` incrementa
      `quantity_on_hand`
- [x] `RegisterInventoryMovementAction`: `salida` decrementa
      `quantity_on_hand`
- [x] `RegisterInventoryMovementAction`: `salida` que dejaría stock
      negativo → lanza `InsufficientStockException`, no muta el insumo
- [x] `RegisterInventoryMovementAction`: `salida` que deja el stock
      exactamente en 0 → permitida
- [x] `RegisterInventoryMovementAction`: crea el `InventoryMovement` con
      `created_by` igual al usuario pasado explícitamente (nunca de un
      array de datos del request)
- [x] `RegisterInventoryMovementAction`: cantidad ≤ 0 → error de validación

### Integration Tests
- [x] `GET /inventario` devuelve la lista de insumos del tenant actual
- [x] `POST /inventario` con datos válidos → insumo creado (redirect 302
      a `inventario.index`, patrón PRG — ver nota en `gestion-mesas.spec.md`)
- [x] `POST /inventario/{item}/ajustar` con `entrada` → `quantity_on_hand`
      incrementada, `InventoryMovement` creado
- [x] `POST /inventario/{item}/ajustar` con `salida` que excede el stock
      disponible → 422, `quantity_on_hand` sin cambios
- [x] Usuario con `role=mesero` o `role=cocina` accede a `/inventario` → 403
- [x] Un `created_by` enviado en el body del POST es ignorado — se usa
      siempre el usuario autenticado del servidor
- [x] **F-05**: admin del tenant A intenta crear/ajustar/leer un insumo
      del tenant B → 404, y el insumo del otro tenant queda intacto

### E2E Tests
- [x] Happy path completo: crear insumo → aparece en la lista con stock
      inicial → registrar entrada → cantidad aumenta → registrar salida →
      cantidad disminuye (verificado en browser real,
      `demo.localhost:8000`, ver `decision-log.md`)
- [x] Insumo con `quantity_on_hand <= low_stock_threshold` se resalta en
      ámbar; insumo en 0 se resalta en rojo (verificado en browser real)
- [x] Error crítico: salida mayor al stock disponible → banner inline con
      el mensaje del spec, no modal crudo (verificado en browser real)

## Definition of Done
- [x] Todos los test cases de Unit + Integration de este spec pasando (Pest)
- [ ] Code review completado y aprobado
- [x] Spec actualizado con comportamiento real implementado
- [x] Desplegado en staging y verificado manualmente (browser real,
      `demo.localhost:8000`)
- [x] Sin errores en consola / logs
- [x] Sin lógica de negocio en el controller — vive en Actions (ver ADR-004)
- [x] Pantalla Vue `Inventario/Index.vue` (E2E) — implementada
