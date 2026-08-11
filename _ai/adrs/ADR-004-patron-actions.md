# ADR-004: Patrón Actions para lógica de negocio

## Status
Accepted

## Date
2026-08-10

## Context
Este repo ya sigue las Laravel Boost guidelines (`CLAUDE.md`), que establecen
Actions como el lugar para lógica de negocio y controllers delgados. Hoy solo
existe `app/Actions/Fortify/` (auth). Al construir el dominio de restaurante
(pedidos, cocina, cobro, reservas, inventario), hace falta fijar la convención
exacta antes de que el primer spec de feature se implemente, para que no haya
ambigüedad entre sesiones de Claude Code.

## Decision
Cada operación de negocio es una clase invocable en
`app/Actions/{Domain}/{Verb}{Entity}Action.php`, con un único método público
`execute()` (o `__invoke()`). Los controllers validan (Form Requests) y delegan
a una Action, que retorna datos planos o lanza excepciones de dominio — nunca
retorna una respuesta Inertia directamente (eso lo hace el controller).

Ejemplos concretos para las épicas del PRD:
- `app/Actions/Orders/AddItemToOrderAction.php`
- `app/Actions/Orders/SendOrderToKitchenAction.php`
- `app/Actions/Orders/CloseOrderAction.php`
- `app/Actions/Kitchen/MarkOrderItemReadyAction.php`
- `app/Actions/Inventory/AdjustInventoryAction.php`
- `app/Actions/Reservations/CreateReservationAction.php`

## Options Considered

### Opción A: Actions (una clase = una operación de negocio) ← ELEGIDA
**Pros:**
- Ya es la convención documentada del repo (CLAUDE.md, Laravel Boost guidelines)
- Cada Action es trivialmente testeable de forma aislada (Unit test sin HTTP)
- Un nombre de clase (`SendOrderToKitchenAction`) documenta la intención sin
  necesitar comentarios
**Cons:**
- Más archivos que un enfoque "todo en el Model" o "todo en el Controller"

### Opción B: Lógica en Eloquent Models (fat models)
**Pros:**
- Menos archivos, todo relacionado a `Order` vive en `Order.php`
**Cons:**
- Un modelo termina mezclando persistencia con reglas de negocio de varias
  épicas distintas (agregar ítem, enviar a cocina, cobrar) — difícil de testear
  una sola operación de forma aislada
**Rechazada porque:** contradice la convención ya establecida en este repo.

### Opción C: Service classes genéricos (un `OrderService` con muchos métodos)
**Pros:**
- Agrupa por dominio en vez de por operación
**Cons:**
- Un `OrderService` con 10 métodos se vuelve una "clase todopoderosa" difícil de
  navegar; las Actions de un solo método fuerzan responsabilidad única
**Rechazada porque:** el patrón Actions ya elegido logra la misma agrupación por
dominio (vía el namespace `Actions/{Domain}/`) sin el problema de la clase gigante.

## Consequences

### Positive
- Cada spec de `_ai/specs/{feature}.spec.md` mapea 1:1 a una o pocas Actions —
  trazabilidad directa entre requerimiento y código
- Tests unitarios rápidos, sin necesitar el framework HTTP completo

### Negative
- Operaciones que tocan múltiples entidades (ej. `CloseOrderAction` que cierra la
  orden Y libera la mesa) requieren que la Action orqueste ambos cambios de forma
  explícita — más código que dejarlo implícito en un observer

### Neutral
- Los Jobs asíncronos (si se necesitan) llaman a una Action desde dentro, no
  duplican su lógica

## Related
- ADR-001: Monolito — las Actions viven dentro del mismo codebase, sin fronteras
  de red
