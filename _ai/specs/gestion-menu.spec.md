# Feature: Gestión de Menú

## Status
[x] Draft  [ ] Review  [ ] Approved  [x] Implemented

## PRD Reference
User Story: US-6.1 "Como admin, quiero crear y editar platillos del menú (nombre,
precio, categoría, disponibilidad), para que el POS tenga qué ofrecer."
Épica: Épica 6 — Datos Base
Prioridad: Must

## Overview
CRUD de platillos: nombre, precio, categoría y disponibilidad. Es el dato base
sin el cual `toma-de-pedido` no tiene qué ofrecer.

## Users Affected
- **Admin**: crea, edita platillos y alterna su disponibilidad.
- **Mesero**: consume el menú (vía `toma-de-pedido`), no lo edita.

## Inputs & Outputs
**Input:** admin en `/menu` crea/edita un `MenuItem` con `name`, `category`,
`price`, `available`.
**Output:** el platillo queda disponible (o no) para agregarse a órdenes.

## Happy Path
1. Admin abre `/menu`.
2. Ve los platillos existentes agrupados por categoría (vacío en el primer uso).
3. Admin toca "Nuevo platillo", ingresa nombre, categoría y precio.
4. Al guardar, el platillo queda `available=true` por defecto y visible en
   `toma-de-pedido`.
5. Admin puede alternar `available` en cualquier momento (ej. "se acabó el
   platillo del día") sin eliminar el registro.

## Edge Cases
| Escenario | Comportamiento esperado |
|-----------|------------------------|
| Marcar `available=false` un platillo que ya está en una orden `abierta` (sin enviar aún) | La orden existente conserva el ítem ya agregado (con su `unit_price` snapshot); solo se bloquea agregarlo de nuevo — ver edge case correspondiente en `toma-de-pedido.spec.md` |
| Cambiar el precio de un platillo | No afecta órdenes ya creadas — `OrderItem.unit_price` es snapshot (ver `_ai/docs/data-model.md`); solo aplica a órdenes nuevas |
| Categoría nueva escrita a mano (no existe una lista fija) | Permitido — `category` es texto libre en el MVP (ver nota de alcance en data-model.md); dos platillos pueden usar categorías con distinta capitalización sin normalizarse automáticamente, riesgo conocido |
| Precio negativo o cero | Rechazado por validación |
| Eliminar un platillo referenciado en órdenes históricas | No se permite eliminación dura — solo desactivar (`available=false`); eliminar rompería el historial vía FK |

## Error States
| Error | Mensaje al usuario | Acción de recuperación |
|-------|-------------------|----------------------|
| Precio inválido (≤ 0) | "El precio debe ser mayor a cero." | Corregir el campo |
| Nombre vacío | "El platillo necesita un nombre." | Completar el campo |

## Security Considerations
- [x] ¿Requiere autenticación? Sí — solo `role=admin`.
- [x] ¿Reglas de autorización? Exclusivamente admin — mesero y cocina no tienen
      acceso de escritura, solo consumen el menú indirectamente.
- [x] ¿Validación de inputs? `name` requerido; `price` decimal > 0; `category`
      texto no vacío.
- [x] ¿Rate limiting? No aplica.
- [x] ¿Datos sensibles en logs? Ninguno.
- [x] **F-05 — IDOR entre tenants**: editar el precio o la disponibilidad de un
      platillo de otro restaurante debe devolver 404. Es un vector con
      motivación real: el menú y los precios son justamente lo que un
      competidor alojado en la misma plataforma querría ver o alterar. Ver
      `_ai/docs/threat-model.md`.

## Performance Requirements
- Max response time: 500ms (p95).
- Expected load: uso esporádico (configuración inicial, ajustes ocasionales de
  precio/disponibilidad).
- Data volume: decenas a cientos de platillos por restaurante.

## Test Cases

### Unit Tests
- [x] `CreateMenuItemAction`: crea un platillo con `available=true` por defecto
- [x] `UpdateMenuItemAction`: cambiar el precio no afecta `OrderItem` existentes
      (verificar que el snapshot se mantiene) — ver nota de PASO 0 abajo:
      `order_items` se agregó como migración/modelo mínimo solo para este test
      (mismo patrón que `orders` en spec #1); el dominio completo lo construye
      `toma-de-pedido.spec.md` (#5).
- [x] `CreateMenuItemAction`: precio ≤ 0 lanza excepción de validación
- [x] `ToggleMenuItemAvailabilityAction`: alterna `available` sin tocar otros
      campos

### Integration Tests
- [x] `GET /menu` devuelve todos los platillos del tenant
- [x] `POST /menu` con datos válidos → redirect, platillo creado con
      `available=true`
- [x] `POST /menu` con precio inválido → 422
- [x] `PATCH /menu/{menuItem}` actualiza nombre/categoría/precio
- [x] `PATCH /menu/{menuItem}/disponibilidad` cambia disponibilidad sin afectar
      otros campos
- [x] Usuario con `role=mesero` o `role=cocina` accede a `/menu` (escritura) → 403
- [x] **F-05**: `GET /menu` del restaurante A no lista ningún platillo del
      restaurante B
- [x] **F-05**: `PATCH /menu/{menuItem}` y `PATCH /menu/{menuItem}/disponibilidad`
      sobre un platillo de otro restaurante → 404, y el platillo del otro
      tenant no cambia

> Nota de implementación: `GET /menu` no tiene pantalla Vue todavía (backend
> only, ver E2E abajo), así que los tests de este endpoint simulan la
> navegación XHR de Inertia (`inertiaXhrHeaders()` en `tests/Pest.php`) en vez
> de un GET normal — un GET normal intenta renderizar
> `resources/js/pages/menu/Index.vue` vía `@vite()` y falla con
> `ViteException` porque el archivo no existe. Mismo motivo por el que
> `POST /menu` inválido se prueba con `postJson()`: el patrón normal de
> Inertia ante un error de validación es redirect 302 con errores flasheados
> en sesión, no 422 — 422 es la respuesta real de Laravel a un cliente que
> pide JSON explícitamente.

### E2E Tests
- [ ] Happy path: admin crea un platillo → aparece disponible en
      `toma-de-pedido` — **pendiente**: requiere la pantalla Vue de `/menu` y
      `toma-de-pedido.spec.md` (#5), fuera de alcance de esta sesión (backend
      only). El backend (Actions, Policy, middleware, rutas) ya está cubierto
      por Unit + Integration tests.
- [ ] Admin desactiva un platillo → deja de poder agregarse en
      `toma-de-pedido`, pero las órdenes que ya lo tenían no cambian —
      **pendiente**, mismo motivo.

## Definition of Done
- [x] Todos los test cases de Unit + Integration de este spec pasando (Pest)
- [ ] Code review completado y aprobado
- [x] Spec actualizado con comportamiento real implementado
- [ ] Desplegado en staging y verificado manualmente
- [x] Sin errores en consola / logs
- [x] Cambiar precio no altera `OrderItem` ya creados (verificado con test)
- [ ] Pantalla Vue de `/menu` (E2E) — pendiente, ver nota arriba
