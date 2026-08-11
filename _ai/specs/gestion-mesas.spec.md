# Feature: Gestión de Mesas

## Status
[x] Draft  [ ] Review  [ ] Approved  [ ] Implemented

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

## Performance Requirements
- Max response time: 500ms (p95) — no es un flujo de alta frecuencia durante
  servicio, pero se mantiene el mismo estándar del resto del sistema.
- Expected load: uso esporádico (configuración inicial, cambios ocasionales).
- Data volume: decenas de mesas por restaurante como máximo.

## Test Cases

### Unit Tests
- [ ] `CreateTableAction`: crea una mesa con `status=libre` por defecto
- [ ] `UpdateTableAction`: actualiza nombre/capacidad sin afectar `status`
- [ ] `DeleteTableAction`: lanza excepción de dominio si la mesa tiene una orden
      `abierta` o `enviada_cocina`
- [ ] `CreateTableAction`: capacidad ≤ 0 lanza error de validación

### Integration Tests
- [ ] `POST /mesas/gestion` con datos válidos → 200, mesa creada con `status=libre`
- [ ] `PATCH /mesas/gestion/{table}` actualiza nombre/capacidad → 200
- [ ] `DELETE /mesas/gestion/{table}` con orden activa → 422
- [ ] Usuario con `role=mesero` o `role=cocina` accede a `/mesas/gestion` → 403

### E2E Tests
- [ ] Happy path completo: admin crea una mesa → la mesa aparece en el mapa de
      mesas con estado libre

## Definition of Done
- [ ] Todos los test cases de este spec pasando (Pest)
- [ ] Code review completado y aprobado
- [ ] Spec actualizado con comportamiento real implementado
- [ ] Desplegado en staging y verificado manualmente
- [ ] Sin errores en consola / logs
- [ ] Sin lógica de negocio en el controller — vive en Actions (ver ADR-004)
