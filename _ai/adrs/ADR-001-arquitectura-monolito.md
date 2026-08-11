# ADR-001: Monolito Laravel + Inertia, no microservicios ni API+SPA separados

## Status
Accepted

## Date
2026-08-10

## Context
restaurante-os es un MVP para un solo desarrollador (ver Discovery), validando con un
restaurante ancla y 1-2 pilotos. El starter kit del repo ya trae Laravel 13 +
Inertia v3 + Vue 3 instalado — la pregunta real no es "qué framework" sino si vale
la pena romper ese monolito en servicios separados o mantenerlo unificado.

Las operaciones del dominio (tomar pedido, enviar a cocina, cobrar) son
transaccionales y de baja latencia — un mesero que agrega un ítem espera ver el
total actualizado de inmediato. Cualquier frontera de red adicional entre
"POS service" y "kitchen service" solo agrega latencia y puntos de falla a un
producto cuyo KR2 es *cero caídas durante servicio*.

## Decision
Un único monolito Laravel + Inertia + Vue. Sin separación en microservicios, sin
backend API + SPA desacoplada.

## Options Considered

### Opción A: Monolito Laravel + Inertia ← ELEGIDA
**Pros:**
- Ya es el estado del repo — cero costo de migración
- Inertia elimina la necesidad de mantener un contrato JSON versionado entre
  frontend y backend; los componentes Vue reciben props tipadas directamente
- Una sola base de código, un solo despliegue — apropiado para un solo desarrollador
**Cons:**
- Escalar horizontalmente el frontend y el backend por separado no es trivial (no es
  un problema real a la escala de 1-3 restaurantes)

### Opción B: Microservicios (POS, Cocina, Inventario, Reservas como servicios independientes)
**Pros:**
- Escalado y despliegue independientes por dominio
**Cons:**
- Latencia de red entre servicios en flujos que hoy son transacciones locales
  (agregar ítem → actualizar total)
- Overhead operativo (orquestación, observabilidad distribuida) que un solo
  desarrollador no puede sostener en un MVP
**Rechazada porque:** el costo operativo no tiene contrapartida — no hay carga que
lo justifique a la escala de restaurantes independientes pequeños.

### Opción C: Backend API (Laravel) + SPA desacoplada (Vue standalone, sin Inertia)
**Pros:**
- Frontend y backend se despliegan y versionan independientemente
- Permitiría reusar el backend para una futura app móvil nativa
**Cons:**
- Duplica el trabajo de definir y mantener un contrato API + validación en ambos
  lados, cuando Inertia ya resuelve esto
- App móvil nativa está explícitamente Out-of-Scope en el PRD — no hay para qué
  pagar ese costo hoy
**Rechazada porque:** resuelve un problema (reuso multi-cliente) que el PRD no tiene.

## Consequences

### Positive
- Un solo pipeline de CI/CD, un solo lugar donde razonar sobre autenticación y
  autorización (ya cubierto por Fortify)
- Cambios de dominio (ej. agregar un campo a Order) no requieren coordinar dos
  despliegues

### Negative
- Si en el futuro se requiere una app móvil nativa (hoy Out-of-Scope), habrá que
  exponer una API REST/JSON adicional sobre el mismo dominio — trabajo diferido,
  no evitado

### Neutral
- El "API Contract" de este proyecto (`_ai/docs/api-contract.yaml`) documenta rutas
  Inertia (que devuelven componentes + props), no una API JSON pública

## Related
- ADR-004: Patrón de código (Actions) — vive dentro de este mismo monolito
- ADR-005: Manejo de estado frontend — depende de que Inertia sea la fuente de verdad
