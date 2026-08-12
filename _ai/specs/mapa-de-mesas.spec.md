# Feature: Mapa de Mesas

## Status
[ ] Draft  [ ] Review  [ ] Approved  [x] Implemented

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
- [ ] **F-05 — aislamiento entre tenants**: `GET /mesas` del restaurante A no
      debe incluir ninguna mesa del restaurante B. Esta pantalla no tiene ruta
      parametrizada, pero es la que más revelaría de un vistazo si el scope
      fallara (todo el piso del competidor). Ver `_ai/docs/threat-model.md`.

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
- [x] `GET /mesas` devuelve todas las mesas con su `status` actual
- [x] `GET /mesas` con cero mesas devuelve lista vacía (no error)
- [x] Usuario con `role=cocina` accede a `/mesas` → 403
- [x] **F-05**: con mesas existentes en dos restaurantes, `GET /mesas` como
      usuario del restaurante A devuelve exclusivamente las mesas de A

### E2E Tests
- [x] Happy path: admin crea una mesa → mesero la ve en `/mesas` con estado
      `libre` → mesero la toca → llega a `/mesas/{id}/pedido` — verificado
      manualmente en browser (2026-08-12, ver nota de Fase 03 abajo): mesa
      `libre`/`ocupada` navega a `pedido.show`, confirmado leyendo los
      `href` resueltos de cada tarjeta.
- [x] Una mesa marcada `por_cobrar` navega a `/mesas/{id}/cobro` al tocarla —
      verificado igual que el caso anterior.

> Nota de implementación (#4, backend): se creó `TableMapController` (nuevo,
> `GET /mesas`) en vez de reutilizar `TableController` — son
> responsabilidades distintas: `TableController` es el CRUD exclusivo de
> admin en `/mesas/gestion` (con `TablePolicy`), mientras que este es
> solo-lectura para mesero+admin y no tiene Policy (el spec no define
> reglas de autorización propias). El name group de rutas es `mesas.` (no
> `tables.`) para no colisionar con `tables.index`.

> **Nota de Fase 03 (pantalla Vue, 2026-08-12):** implementada en
> `resources/js/pages/mesas/Index.vue` — grid de mesas coloreado por status
> (verde=libre, ámbar=ocupada, terracota=por_cobrar, ver design system),
> `usePoll(4000)`, estado vacío con CTA a Gestión de Mesas solo para admin.
> Stitch se abandonó para esta pantalla (ver `decision-log.md`) — los
> tokens del design system se tradujeron a mano a
> `resources/css/app.css`. Verificado visualmente en browser (login real,
> light y dark mode, estado vacío, navegación por status) — no es un test
> automatizado E2E (Playwright/Dusk), es verificación manual como pide el
> criterio de esta fase.
>
> **Hallazgo no resuelto en esta sesión:** la consola del navegador muestra
> "Hydration completed but contains mismatches" y un error no capturado
> ("Cannot read properties of undefined (reading 'createProvider')") en
> `/mesas` — pero también en `/dashboard` (página del starter kit sin
> tocar), confirmando que es un problema preexistente de toda la app, no
> de esta pantalla. Fuera de alcance de esta sesión; queda pendiente de
> investigar en `decision-log.md`.

## Definition of Done
- [x] Todos los test cases de Integration de este spec pasando (Pest)
- [ ] Code review completado y aprobado
- [x] Spec actualizado con comportamiento real implementado
- [ ] Desplegado en staging y verificado manualmente en tablet real
- [ ] Sin errores en consola / logs — ver "Hallazgo no resuelto" arriba,
      preexistente en toda la app, no introducido por esta pantalla
- [ ] Poll dentro de 500ms p95 — pendiente de medir
- [x] Pantalla Vue de `/mesas` (E2E, incluye `poll()`)
