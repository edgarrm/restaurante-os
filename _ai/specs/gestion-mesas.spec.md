# Feature: Gestión de Mesas

## Status
[ ] Draft  [ ] Review  [ ] Approved  [x] Implemented

## PRD Reference
User Story: US-6.3 "Como admin, quiero crear y editar las mesas del restaurante
(nombre, capacidad), para que el mapa de mesas y la toma de pedidos tengan sobre
qué operar."
Épica: Épica 6 — Datos Base
Prioridad: Must — *agregada en Fase 05, no estaba en el PRD original (ver nota ahí).
Es prerequisito de Mapa de Mesas y Toma de Pedido.*

## Overview
CRUD simple de mesas: nombre y capacidad. Sin esto no hay datos base sobre los que
operar el resto del POS.

## Users Affected
- **Admin**: crea, edita y (opcionalmente) desactiva mesas. Ningún otro rol accede
  a esta pantalla.

## Inputs & Outputs
**Input:** admin en `/mesas/gestion` crea una mesa con `name` y `capacity`.
**Output:** la mesa queda disponible con `status=libre` en el mapa de mesas y en
toma de pedidos.

## Happy Path
1. Admin abre `/mesas/gestion`.
2. Ve la lista de mesas existentes (vacía en el primer uso del restaurante).
3. Admin toca "Nueva mesa", ingresa nombre (ej. "Mesa 4") y capacidad (ej. 4).
4. Al guardar, la mesa aparece en la lista con `status=libre`.
5. Admin puede editar nombre/capacidad de una mesa existente en cualquier momento.

## Edge Cases
| Escenario | Comportamiento esperado |
|-----------|------------------------|
| Nombre de mesa duplicado | Permitido — no hay restricción de unicidad de nombre (dos "Mesa Terraza 1" en zonas distintas es un caso real); si se vuelve un problema en piloto, se revisita |
| Editar una mesa que tiene una orden `abierta` | Permitido editar nombre/capacidad; no afecta la orden en curso |
| Intentar eliminar una mesa con una orden `abierta` o `enviada_cocina` | Bloqueado — "No se puede eliminar una mesa con una cuenta activa." |
| Eliminar una mesa sin órdenes activas | Eliminación permitida (soft delete recomendado para no romper el historial de órdenes pasadas vía FK) |
| Capacidad en 0 o negativa | Rechazado por validación — capacidad mínima 1 |
| Primer uso del restaurante (cero mesas creadas) | El mapa de mesas muestra un estado vacío guiando al admin a crear mesas aquí |

## Error States
| Error | Mensaje al usuario | Acción de recuperación |
|-------|-------------------|----------------------|
| Capacidad inválida | "La capacidad debe ser al menos 1." | Corregir el campo |
| Eliminar mesa con orden activa | "No se puede eliminar una mesa con una cuenta activa." | Cerrar/cobrar la orden primero |

## Security Considerations
- [x] ¿Requiere autenticación? Sí — solo `role=admin`.
- [x] ¿Reglas de autorización? Ninguna otra — es exclusivamente de admin.
- [x] ¿Validación de inputs? `name` requerido no vacío; `capacity` entero ≥ 1.
- [x] ¿Rate limiting? No aplica.
- [x] ¿Datos sensibles en logs? Ninguno.
- [x] **F-05 — IDOR entre tenants**: editar o eliminar una mesa de otro
      restaurante debe devolver 404. Ver `_ai/docs/threat-model.md`.

## Performance Requirements
- Max response time: 500ms (p95) — no es un flujo de alta frecuencia durante
  servicio, pero se mantiene el mismo estándar del resto del sistema.
- Expected load: uso esporádico (configuración inicial, cambios ocasionales).
- Data volume: decenas de mesas por restaurante como máximo.

## Test Cases

### Unit Tests
- [x] `CreateTableAction`: crea una mesa con `status=libre` por defecto
- [x] `UpdateTableAction`: actualiza nombre/capacidad sin afectar `status`
- [x] `DeleteTableAction`: lanza excepción de dominio si la mesa tiene una orden
      `abierta` o `enviada_cocina`
- [x] `CreateTableAction`: capacidad ≤ 0 lanza error de validación

### Integration Tests
- [x] `POST /mesas/gestion` con datos válidos → mesa creada con `status=libre`
      (redirect 302 a `tables.index`, patrón PRG — ver nota abajo)
- [x] `PATCH /mesas/gestion/{table}` actualiza nombre/capacidad
- [x] `DELETE /mesas/gestion/{table}` con orden activa → 422
- [x] Usuario con `role=mesero` o `role=cocina` accede a `/mesas/gestion` → 403
- [x] **F-05**: admin del restaurante A edita/elimina una mesa del restaurante
      B → 404, y la mesa del otro tenant queda intacta

> Nota de implementación: el "200" original de este documento asumía una
> respuesta directa; el patrón ya establecido en este repo (ver
> `ProfileController`) es POST/PATCH/DELETE → redirect 302 (303 para
> PATCH/DELETE vía el adaptador de Inertia) al índice, no una respuesta 200
> directa. Los tests verifican `assertRedirect()` + el estado del modelo en
> BD, consistente con `tests/Feature/Settings/ProfileUpdateTest.php`.

### E2E Tests
- [ ] Happy path completo: admin crea una mesa → la mesa aparece en el mapa de
      mesas con estado libre — **pendiente**: requiere la pantalla Vue de
      `/mesas/gestion`, fuera de alcance de esta sesión (backend only, ver
      PASO 1 del prompt de implementación). El backend (Actions, Policy,
      middleware, rutas) ya está cubierto por Unit + Integration tests.

## Definition of Done
- [x] Todos los test cases de Unit + Integration de este spec pasando (Pest)
- [ ] Code review completado y aprobado
- [x] Spec actualizado con comportamiento real implementado
- [ ] Desplegado en staging y verificado manualmente
- [x] Sin errores en consola / logs
- [x] Sin lógica de negocio en el controller — vive en Actions (ver ADR-004)
- [ ] Pantalla Vue de `/mesas/gestion` (E2E) — pendiente, ver nota arriba
