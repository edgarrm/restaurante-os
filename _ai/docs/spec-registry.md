# Spec Registry — restaurante-os

> Estados: 🟡 Draft → 🔵 Review → 🟢 Approved → ✅ Implemented → 🔄 Needs sync

## Orden de implementación sugerido (por dependencias)

Trabajar uno por sesión, con TDD (tests primero, deben fallar, luego implementar).
No saltar a una feature cuyo prerequisito no esté al menos 🟢 Approved.

| # | Feature | Spec | Status | Tests | Impl | Depende de |
|---|---------|------|--------|-------|------|-----------|
| 0 | **Onboarding de Tenant** | `_ai/specs/onboarding-tenant.spec.md` | ✅ Implemented | ✅ | ✅ | — |
| 1 | Gestión de Mesas | `_ai/specs/gestion-mesas.spec.md` | 🟡 Draft | ❌ | ❌ | #0 |
| 2 | Gestión de Menú | `_ai/specs/gestion-menu.spec.md` | 🟡 Draft | ❌ | ❌ | #0 |
| 3 | Gestión de Staff | `_ai/specs/gestion-staff.spec.md` | 🟡 Draft | ❌ | ❌ | #0 |
| 4 | Mapa de Mesas | `_ai/specs/mapa-de-mesas.spec.md` | 🟡 Draft | ❌ | ❌ | #1 |
| 5 | Toma de Pedido | `_ai/specs/toma-de-pedido.spec.md` | 🟡 Draft | ❌ | ❌ | #1, #2 |
| 6 | Cocina (KDS) | `_ai/specs/cocina-kds.spec.md` | 🟡 Draft | ❌ | ❌ | #5 |
| 7 | Cobro | `_ai/specs/cobro.spec.md` | 🟡 Draft | ❌ | ❌ | #5 |
| 8 | Reservas | `_ai/specs/reservas.spec.md` | 🟡 Draft | ❌ | ❌ | #1 (opcional, `table_id` nullable) |

**#0 es el bloqueador real de todos los demás** — sin un tenant existente, las
rutas de `routes/tenant.php` no son alcanzables (requieren
`InitializeTenancyByDomain`). Empezar aquí, no en #1.

## Bloqueadores de seguridad antes de escribir código

Del threat model del 2026-08-10 (`_ai/docs/threat-model.md`):

| ID | Severidad | Qué falta | Afecta |
|---|---|---|---|
| F-01 | 🔴 Crítico | ✅ Resuelto — ver `decision-log.md` y F-01 en `_ai/docs/threat-model.md` | #0 y, por transitividad, todos |
| F-02 | 🟠 Alto | ✅ Resuelto — `ScopeSessions` en `routes/tenant.php` y `config/fortify.php` | #0 |
| F-03 | 🟠 Alto | `Payment.collected_by` — ya agregado al data model, falta implementarlo | #7 Cobro |
| F-06 | 🟡 Medio | **Sin spec del middleware de autorización por rol** — los 9 specs lo asumen ("`role=cocina` → 403") pero nadie lo especifica. Mismo hueco que US-6.3 y onboarding-tenant | Todos los que restringen por rol |

F-01 y F-02 se resolvieron implementando el spec #0. **F-06 necesita su propio
spec** antes de implementar cualquier feature con restricción por rol (es
decir, casi todas).

## Pendiente de spec (Should Have — no bloquea MVP)

- Inventario (US-5.1, US-5.2) — sin spec todavía. No es prerequisito de ninguna
  feature Must Have; se aborda después de completar la tabla de arriba.

## Pendiente de spec (bloqueante)

- **Middleware de autorización por rol** (F-06) — ver tabla de arriba.

## Notas

- **US-6.3 (Gestión de Mesas)** no estaba en el PRD original — se agregó en
  Fase 05 al detectar que el mapa de mesas y toma de pedido no tenían sobre qué
  operar sin ella. Ver nota en `_ai/docs/PRD.md`, Épica 6.
- Cada spec tiene su propio checklist de Definition of Done — este registry solo
  trackea el estado macro.
