# Spec Registry — restaurante-os

> Estados: 🟡 Draft → 🔵 Review → 🟢 Approved → ✅ Implemented → 🔄 Needs sync

## Orden de implementación sugerido (por dependencias)

Trabajar uno por sesión, con TDD (tests primero, deben fallar, luego implementar).
No saltar a una feature cuyo prerequisito no esté al menos 🟢 Approved.

| # | Feature | Spec | Status | Tests | Impl | Depende de |
|---|---------|------|--------|-------|------|-----------|
| 1 | Gestión de Mesas | `_ai/specs/gestion-mesas.spec.md` | 🟡 Draft | ❌ | ❌ | — |
| 2 | Gestión de Menú | `_ai/specs/gestion-menu.spec.md` | 🟡 Draft | ❌ | ❌ | — |
| 3 | Gestión de Staff | `_ai/specs/gestion-staff.spec.md` | 🟡 Draft | ❌ | ❌ | — |
| 4 | Mapa de Mesas | `_ai/specs/mapa-de-mesas.spec.md` | 🟡 Draft | ❌ | ❌ | #1 |
| 5 | Toma de Pedido | `_ai/specs/toma-de-pedido.spec.md` | 🟡 Draft | ❌ | ❌ | #1, #2 |
| 6 | Cocina (KDS) | `_ai/specs/cocina-kds.spec.md` | 🟡 Draft | ❌ | ❌ | #5 |
| 7 | Cobro | `_ai/specs/cobro.spec.md` | 🟡 Draft | ❌ | ❌ | #5 |
| 8 | Reservas | `_ai/specs/reservas.spec.md` | 🟡 Draft | ❌ | ❌ | #1 (opcional, `table_id` nullable) |

## Pendiente de spec (Should Have — no bloquea MVP)

- Inventario (US-5.1, US-5.2) — sin spec todavía. No es prerequisito de ninguna
  feature Must Have; se aborda después de completar la tabla de arriba.

## Notas

- **US-6.3 (Gestión de Mesas)** no estaba en el PRD original — se agregó en
  Fase 05 al detectar que el mapa de mesas y toma de pedido no tenían sobre qué
  operar sin ella. Ver nota en `_ai/docs/PRD.md`, Épica 6.
- Cada spec tiene su propio checklist de Definition of Done — este registry solo
  trackea el estado macro.
