# Feature: Mapa de Mesas

## Status
[x] Draft  [ ] Review  [ ] Approved  [ ] Implemented

## PRD Reference
User Story: US-1.1 "Como mesero, quiero ver el mapa de mesas y su estado, para
saber inmediatamente cuáles están libres, ocupadas o listas para cobrar."
Épica: Épica 1 — Toma de Pedidos (POS)
Prioridad: Must
Depende de: `gestion-mesas.spec.md` (debe haber mesas creadas)

## Overview
Pantalla principal del mesero — punto de entrada a Toma de Pedido y Cobro. Muestra
todas las mesas y su estado de un vistazo, sin necesitar un segundo clic.

## Users Affected
- **Mesero / Admin**: consulta el estado de las mesas y navega a la mesa que
  necesita atender.
- **Cocina**: no tiene acceso — su vista es `/cocina`.

## Inputs & Outputs
**Input:** ninguno — vista de solo lectura sondeada automáticamente.
**Output:** lista/mapa de mesas con `name`, `capacity` y `status` (libre/ocupada/
por_cobrar).

## Happy Path
1. Mesero abre `/mesas` (o llega aquí después de iniciar sesión).
2. Ve todas las mesas configuradas, cada una con su estado visualmente distinto
   por color (ver design system: verde=libre, ámbar=ocupada, terracota/rojo
   suave=por_cobrar — a definir en Fase 03 cuando el pipeline de Stitch esté
   disponible).
3. La vista se actualiza sola cada 3-5s vía `poll()` de Inertia (ver ADR-005) —
   el mesero no necesita refrescar manualmente.
4. Mesero toca una mesa `libre` u `ocupada` → navega a `/mesas/{id}/pedido`.
5. Mesero toca una mesa `por_cobrar` → navega a `/mesas/{id}/cobro`.

## Edge Cases
| Escenario | Comportamiento esperado |
|-----------|------------------------|
| Cero mesas configuradas | Estado vacío con mensaje guiando al admin a `/mesas/gestion` (solo visible/accionable si el usuario actual es admin) |
| Una mesa cambia de estado mientras el mesero la está mirando (otro mesero la cerró) | El siguiente poll refleja el nuevo estado; no hay acción especial, es el comportamiento esperado del polling |
| Mesero toca una mesa justo cuando cambia de estado (race entre el tap y el poll) | La navegación usa el estado más reciente conocido en el servidor al momento del request — si ya no aplica (ej. pasó a `por_cobrar`), la pantalla destino redirige según su propio spec (ver `toma-de-pedido.spec.md`, edge case de mesa `por_cobrar`) |
| Muchas mesas (30+) en una pantalla de tablet | El layout debe seguir siendo legible — grid responsivo, no una lista que requiera scroll excesivo para ver el estado general |

## Error States
No hay error states propios de esta pantalla — es de solo lectura. Un fallo de
red al hacer poll no debe mostrar un error intrusivo; se reintenta en el
siguiente ciclo silenciosamente (ver Performance Requirements).

## Security Considerations
- [x] ¿Requiere autenticación? Sí — `role` en `mesero` o `admin`. `role=cocina`
      recibe 403.
- [x] ¿Reglas de autorización? Ninguna — cualquier mesero ve todas las mesas (sin
      asignación mesero↔mesa en el MVP, ver `toma-de-pedido.spec.md`).
- [x] ¿Validación de inputs? No aplica — sin inputs de usuario en esta pantalla.
- [x] ¿Rate limiting? No aplica al usuario, pero el intervalo de polling debe ser
      razonable (3-5s) para no generar carga innecesaria.
- [x] ¿Datos sensibles en logs? Ninguno.

## Performance Requirements
- Max response time: 500ms (p95) por cada poll.
- Expected load: 1 request cada 3-5s por dispositivo activo — con pocos
  dispositivos por restaurante, la carga es insignificante.
- Data volume: decenas de mesas como máximo.

## Test Cases

### Unit Tests
- [ ] No aplica lógica de negocio propia — esta feature solo consulta datos ya
      modelados por `gestion-mesas` y `toma-de-pedido`

### Integration Tests
- [ ] `GET /mesas` devuelve todas las mesas con su `status` actual
- [ ] `GET /mesas` con cero mesas devuelve lista vacía (no error)
- [ ] Usuario con `role=cocina` accede a `/mesas` → 403

### E2E Tests
- [ ] Happy path: admin crea una mesa → mesero la ve en `/mesas` con estado
      `libre` → mesero la toca → llega a `/mesas/{id}/pedido`
- [ ] Una mesa marcada `por_cobrar` navega a `/mesas/{id}/cobro` al tocarla

## Definition of Done
- [ ] Todos los test cases de este spec pasando (Pest)
- [ ] Code review completado y aprobado
- [ ] Spec actualizado con comportamiento real implementado
- [ ] Desplegado en staging y verificado manualmente en tablet real
- [ ] Sin errores en consola / logs
- [ ] Poll dentro de 500ms p95
