# Feature: Gestión de Menú

## Status
[x] Draft  [ ] Review  [ ] Approved  [ ] Implemented

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
- [ ] **F-05 — IDOR entre tenants**: editar el precio o la disponibilidad de un
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
- [ ] `CreateMenuItemAction`: crea un platillo con `available=true` por defecto
- [ ] `UpdateMenuItemAction`: cambiar el precio no afecta `OrderItem` existentes
      (verificar que el snapshot se mantiene)
- [ ] `CreateMenuItemAction`: precio ≤ 0 lanza excepción de validación
- [ ] `ToggleMenuItemAvailabilityAction`: alterna `available` sin tocar otros
      campos

### Integration Tests
- [ ] `GET /menu` devuelve todos los platillos agrupados/filtrables por categoría
- [ ] `POST /menu` con datos válidos → 200, platillo creado
- [ ] `POST /menu` con precio inválido → 422
- [ ] `PATCH /menu/{menuItem}` cambia disponibilidad sin afectar órdenes pasadas
- [ ] Usuario con `role=mesero` o `role=cocina` accede a `/menu` (escritura) → 403
- [ ] **F-05**: `GET /menu` del restaurante A no lista ningún platillo del
      restaurante B
- [ ] **F-05**: `PATCH /menu/{menuItem}` sobre un platillo de otro restaurante
      → 404, y el platillo del otro tenant no cambia

### E2E Tests
- [ ] Happy path: admin crea un platillo → aparece disponible en
      `toma-de-pedido`
- [ ] Admin desactiva un platillo → deja de poder agregarse en
      `toma-de-pedido`, pero las órdenes que ya lo tenían no cambian

## Definition of Done
- [ ] Todos los test cases de este spec pasando (Pest)
- [ ] Code review completado y aprobado
- [ ] Spec actualizado con comportamiento real implementado
- [ ] Desplegado en staging y verificado manualmente
- [ ] Sin errores en consola / logs
- [ ] Cambiar precio no altera `OrderItem` ya creados (verificado con test)
