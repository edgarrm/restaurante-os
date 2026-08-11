# ADR-005: Sin store global; polling de Inertia v3 para KDS en tiempo casi real

## Status
Accepted

## Date
2026-08-10

## Context
Dos preguntas de arquitectura frontend quedan abiertas:

1. ¿Se necesita un store global (Pinia) para manejar estado entre páginas, o basta
   con las props de Inertia + estado local de componente?
2. La Épica 2 (Cocina/KDS) requiere que los pedidos aparezcan "en tiempo real" —
   ¿eso implica WebSockets (Laravel Reverb + Echo), o algo más simple alcanza para
   el MVP?

`package.json` no tiene Pinia instalado hoy, y este repo tiene regla explícita de
no agregar dependencias sin aprobación (CLAUDE.md).

## Decision
**Estado:** sin store global. Inertia ya es la fuente de verdad — cada visita
retorna las props actualizadas del servidor. Estado efímero de UI (ej. qué ítem
está expandido en el menú) vive en `ref()`/`reactive()` local del componente. Si
aparece un caso real de estado compartido entre componentes no relacionados por
jerarquía, se resuelve con `provide/inject` antes de considerar una dependencia
nueva.

**Tiempo real:** Inertia v3 `poll()` (ya incluido en `@inertiajs/vue3`, sin
dependencia nueva) sobre la vista de cocina y el mapa de mesas, con intervalo
corto (a definir en el spec de cada feature, punto de partida: 3-5s). No se
introduce Laravel Reverb/WebSockets en el MVP.

## Options Considered

### Opción A: Sin store + polling de Inertia ← ELEGIDA
**Pros:**
- Cero dependencias nuevas
- Polling es operacionalmente trivial: no hay servidor de WebSockets que mantener
  vivo, no hay reconexión que manejar en el cliente
- Para 1-3 restaurantes con pocos dispositivos, la carga de polling cada 3-5s es
  insignificante
**Cons:**
- No es verdadero tiempo real — hay una ventana de latencia igual al intervalo
  de polling
- No escala indefinidamente (a cientos de restaurantes concurrentes, el polling
  se vuelve costoso) — no es el problema de este MVP

### Opción B: Pinia + Laravel Reverb (WebSockets)
**Pros:**
- Tiempo real genuino, sin ventana de latencia
- Pinia centralizaría el estado de pedidos/mesas de forma más "correcta"
**Cons:**
- Dos dependencias nuevas (Pinia + Reverb) sin aprobación
- Reverb requiere un proceso adicional corriendo en producción — más superficie
  operativa para un solo desarrollador manteniendo el sistema
**Rechazada porque:** el PRD no exige tiempo real estricto ("tiempo real, o con
retraso máximo aceptable") — polling cumple el requisito real sin el costo
operativo de mantener infraestructura de WebSockets para 1-3 restaurantes.

## Consequences

### Positive
- Cero infraestructura nueva que operar o monitorear
- Si el piloto revela que el intervalo de polling es perceptible y molesto,
  hay una ruta clara de escalamiento (Reverb) documentada aquí, no hay que
  redescubrirla

### Negative
- Cocina puede tardar hasta el intervalo de polling en ver un pedido nuevo — debe
  quedar dentro del budget de Performance Requirements del PRD (<500ms es para
  acciones del usuario, no para la latencia de polling; esto se documenta aparte
  en cada spec)

### Neutral
- Si el negocio crece más allá de "restaurantes independientes pequeños" (hoy
  Out-of-Scope), este ADR es el primero que se revisita

## Related
- ADR-001: Monolito
- Out-of-Scope del PRD: multi-sucursal — el día que se reconsidere, este ADR
  también se reabre
