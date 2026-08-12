# restaurante-os — AI Context

> Incluir en CADA sesión de Claude Code.
> Actualizar "Current Sprint Focus" cada semana.

## What This Project Does
Sistema operativo todo-en-uno para restaurantes independientes pequeños: POS, reservas,
inventario y cocina (KDS) en una sola plataforma. El diferenciador no es tener más
funciones — es que el staff sea productivo desde su primer turno, sin entrenamiento
formal, reemplazando un software actual cuya interfaz es difícil de manejar.

Nace de la petición de un cliente real (el "restaurante ancla") que busca alternativa
a su software actual. El MVP se enfoca en restaurantes independientes de una sola sede,
no cadenas — ver `_ai/docs/PRD.md` para el porqué.

## Tech Stack
- Frontend: Vue 3.5 + Inertia.js v3 (`@inertiajs/vue3`)
- Backend: Laravel 13.17
- Auth: Laravel Fortify 1.37
- Multi-tenancy: `stancl/tenancy` v3.10 en modo **single-database** — una sola
  base compartida, aislada por columna `tenant_id`; identificación por
  subdominio (ver ADR-006)
- Styling: Tailwind CSS v4 (via `@tailwindcss/vite`)
- Database: SQLite en dev/test; **una sola** base PostgreSQL en producción
  (ADR-002 + ADR-006)
- Testing: Pest 5 (`php artisan test --compact`)
- Routing tipado: Laravel Wayfinder (`@/actions`, `@/routes`)
- Tooling de dev: `laravel/pao` (salida optimizada para agentes en Pest/Pint/
  PHPStan — habilita `--format agent`), `laravel/chisel` (toolkit para remover
  código/dependencias no usadas, sin uso activo todavía)
- UI kit base: reka-ui + class-variance-authority (sin Design System propio todavía —
  pendiente de Fase 03: Stitch → Figma → tokens)

## Architecture Pattern
Monolito Laravel + Inertia + Vue, sin microservicios ni API separada (ADR-001).
Lógica de negocio en Actions: `app/Actions/{Domain}/{Verb}{Entity}Action.php`, un
método público por Action; controllers delgados que validan (Form Requests) y
delegan (ADR-004). Auth por sesión de Fortify + campo `role` enum en `users`, sin
paquete de permisos (ADR-003). SQLite en dev/test, PostgreSQL en producción
(ADR-002). Sin store global de frontend; Inertia es la fuente de verdad, tiempo
casi real vía `poll()` de Inertia v3, sin WebSockets en el MVP (ADR-005).

Ver `_ai/adrs/ADR-00{1..6}-*.md` para el razonamiento completo de cada decisión.
Hoy solo existe `app/Actions/Fortify/` (auth); no hay dominio de restaurante
implementado aún — ver `_ai/docs/data-model.md` y `_ai/docs/api-contract.yaml`.

**Importante sobre multi-tenancy (ADR-006):**
- Rutas del dominio de restaurante (mesas, pedidos, cocina, menú, staff,
  reservas) van en `routes/tenant.php`, NO en `routes/web.php` — necesitan el
  middleware de identificación para resolver el tenant desde el subdominio.
- Todo modelo Eloquent del dominio usa el trait
  `Stancl\Tenancy\Database\Concerns\BelongsToTenant`, y su tabla lleva
  `tenant_id`.
- Todo Feature test de una feature debe incluir un caso que verifique que un
  usuario del tenant A **no** ve datos del tenant B.

**Seguridad:** hay un threat model en `_ai/docs/threat-model.md` con 10
hallazgos. Tres son bloqueantes antes de escribir código de dominio (F-01 auth
sin contexto de tenant, F-02 sesiones sin acotar, F-03 pagos sin atribución).
Revisarlo antes de implementar cualquier spec.

**Antes de desplegar a producción (F-08):** verificar `APP_DEBUG=false`,
`APP_ENV=production`, `SESSION_SECURE_COOKIE=true` y `SESSION_DOMAIN=null` —
los defaults de `.env.example` son de desarrollo y filtrarían stack traces o
permitirían cookies sobre HTTP.

## Directory Structure
```
_ai/
  CONTEXT.md          ← este archivo
  specs/               ← un .spec.md por feature, ANTES de implementar
  adrs/                ← decisiones de arquitectura YA tomadas
  design/
    screen-inventory.md ← generado en PRD, alimenta Stitch
  docs/
    PRD.md             ← fuente de verdad del producto
    decision-log.md    ← propuestas y preguntas SIN decidir todavía — revisar
                          al empezar cualquier sesión, antes de que se pierdan
app/
  Actions/{Domain}/     ← lógica de negocio
  Http/Controllers/     ← delgados: validan + llaman Action + retornan Inertia
  Models/               ← Eloquent con scopes y casts
resources/js/
  pages/{Domain}/       ← una página Inertia por ruta
  components/ui/        ← Design System (pendiente, viene de Fase 03)
  components/{domain}/  ← componentes específicos del dominio
tests/
  Feature/              ← espejo de _ai/specs/ (un test file por spec)
  Unit/                 ← un test por Action
```

## Conventions
- Sigue `.ai/rules/` (ver `.ai/rules/index.md`) y las Laravel Boost guidelines en
  CLAUDE.md antes de tocar cualquier archivo — no son opcionales.
- Ninguna feature se implementa sin su `_ai/specs/{feature}.spec.md` primero.
- PHP: constructor promotion, tipos de retorno explícitos, llaves siempre (aunque sea
  una línea), enums en TitleCase.
- Rutas: usar Wayfinder (`@/actions`, `@/routes`), nunca URLs hardcodeadas en el frontend.
- Tests: Pest, un Feature test por spec (mismo nombre), factories en vez de crear
  modelos a mano.

## Never Do
- No agregar lógica de negocio en controllers — va en Actions.
- No implementar una feature sin spec aprobado en `_ai/specs/`.
- No crear archivos de documentación fuera de lo pedido explícitamente.
- No cambiar dependencias (`composer.json`/`package.json`) sin aprobación — ver
  `.ai/rules/general.md`, que además documenta los controles de supply-chain
  (`min-release-age`, `ignore-scripts`, scopes de Socket) ya vigentes en este repo.
- No construir para cadenas/multi-sucursal en el MVP — está explícitamente fuera de
  alcance (ver Out-of-Scope en `_ai/docs/PRD.md`). Ojo: esto es distinto del
  multi-tenancy de ADR-006, que sí está en alcance.
- No usar `DB::table()` ni queries crudas en código de dominio — evaden
  `TenantScope` y pueden filtrar datos entre restaurantes (ADR-006).
- No activar `DatabaseTenancyBootstrapper` en `config/tenancy.php` — rompería el
  modelo single-database.

## Current Sprint Focus
Sprint 0 — Foundations completo (Discovery + PRD v1 + repo git).
Sprint 2 — Fase 04 (Architecture) completo: 5 ADRs, data model, api-contract.yaml.
Sprint 1 — Fase 03 (Design vía Stitch) en pausa: el design system se generó y
quedó guardado (`_ai/design/screen-inventory.md` tiene el detalle del proyecto
Stitch), pero la generación de pantallas está fallando del lado del servicio —
retomar antes de escribir el primer `_ai/specs/{feature}.spec.md`.

**2026-08-12 — Los 10 specs del registry (#0-#9) están `✅ Implemented`**
(ver `_ai/docs/spec-registry.md`): Onboarding de Tenant, Gestión de Mesas,
Gestión de Menú, Gestión de Staff, Mapa de Mesas, Toma de Pedido, Cocina
(KDS), Cobro, Reservas, Inventario — backend completo (Actions +
controllers + rutas + tests Pest) en los 10. F-01 a F-06 del threat model
están 🟢 Resueltos; quedan abiertos F-07 (bloqueo de tablet, decisión de
producto pendiente del cliente ancla) y F-08/F-09/F-10 (bajos, no
bloqueantes).

**Pantallas Vue construidas (9 de 9 pantallas Must del inventario,
`_ai/design/screen-inventory.md` — #7 fusionada en #6, ver nota de
Reservas más abajo):** Login, Mapa de Mesas, Toma de Pedido, Cocina (KDS),
Cobro/Cierre de Cuenta, Gestión de Menú, Gestión de Mesas, Gestión de
Staff, Reservas — el loop operativo completo (mesero→cocina→cobro, más
altas de mesas/staff/reservas) es navegable end-to-end en browser real
(`decision-log.md`, entradas del 2026-08-12 con verificación manual).
Gestión de Menú tiene verificación de render
confirmada por captura, pero el click-through de crear/editar/alternar
disponibilidad con eventos de mouse reales quedó incompleto — la extensión
de Chrome se desconectó a media verificación (ver `decision-log.md`,
entrada del 2026-08-12 "Pantalla Vue de Gestión de Menú"). Gestión de
Mesas tiene click-through completo (crear, editar, eliminar
permitido/bloqueado). Gestión de Staff: lint + types + build pasan; tests
Pest 12/12; verificación visual en browser pendiente (ver
`decision-log.md`, entrada del 2026-08-12 "Pantalla Vue de Gestión de
Staff"). Login: verificado por lint/types/tests y un login real vía
HTTP/curl (sin browser real disponible en esa sesión). Reservas tiene
click-through completo verificado en browser real.

**2026-08-12 — Login (#1):** `resources/js/pages/auth/Login.vue` traducido
a español y su layout (`AuthLayout.vue`) cambiado de
`AuthSimpleLayout` a `AuthCardLayout` (ambos ya existían en el starter
kit) para el look boxed/Card consistente con Cocina/Cobro/Pedido — afecta
visualmente a Register/ForgotPassword/etc. (comparten el mismo layout)
pero no se tradujo ni se tocó su lógica, fuera de alcance de este spec.
Ver `decision-log.md` para el detalle de alcance y verificación.

**Reservas (#6/#7 del inventario, mergeada a `main`):** #6 "Calendario de
reservas" y #7 "Nueva reserva" eran filas separadas en el inventario
original, pero el backend
real (`ReservationController`: solo `index`+`store`, ambos renderizan
`reservas/Index`) es una sola pantalla — el formulario de nueva reserva
vive en un diálogo, mismo patrón que "Nuevo platillo"/Gestión de Menú.
Click-through completo verificado en browser real (crear con/sin mesa
asignada, fecha pasada rechazada con banner inline, no modal crudo, light
+ dark mode) — ver `decision-log.md`, entrada del 2026-08-12 "PASO 0 de la
pantalla Vue de Reservas".

**Pantallas Vue Must:** las 9 pantallas del inventario (`screen-inventory.md`,
#7 fusionada en #6) están construidas — ver detalle de verificación por
pantalla arriba y en `decision-log.md`.

**Inventario (#10/#11 del inventario, Should Have, primera feature Should
implementada):** `InventarioController` (`index`+`store`+`adjust`, todos
renderizan `Inventario/Index` — nombre de componente con mayúscula inicial,
así lo especifica `x-inertia-component` en api-contract.yaml, a diferencia
del resto de dominios) es una sola pantalla — #11 "Ajuste de inventario" se
fusiona en #10, mismo patrón que Reservas. `POST /inventario` (alta de
insumo) se agregó en PASO 0 del spec — gap de cobertura entre el PRD y
api-contract.yaml, mismo criterio que US-6.3. Resaltado ámbar/rojo
(`quantity_on_hand <= low_stock_threshold` / `<= 0`) reutiliza los tokens
de status ya definidos para Mesas. Click-through completo verificado en
browser real: crear insumo, salida que excede el stock (banner inline, no
modal crudo), salida que deja el stock en el umbral (ámbar) y en 0 (rojo),
light y dark mode. Sin errores en consola. Ver `decision-log.md` para el
detalle de PASO 0 y verificación.

**Deuda técnica abierta, recurrente:** mismatch de hidratación en toda la
app (consola: "Hydration completed but contains mismatches" / error
`createProvider`) — causa clics sintéticos poco confiables en algunos
botones (un `.click()` nativo sí funciona). Documentado primero en la
sesión de Mapa de Mesas, reproducido de nuevo en Gestión de Menú. Sin
investigar a fondo — sospecha original: script inline de `app.blade.php`
que aplica la clase `dark` antes de montar Vue.

Brecha documentada pendiente en `decision-log.md`: transiciones de
`Reservation` a `sentada`/`cancelada` (#8, sin endpoint ni control en UI).

Próximo paso a decidir con el usuario: con las 9 pantallas Must y la
primera Should (Inventario) construidas, seguir con el resto de Should
Have (si aparece más adelante) o arrancar Could Have (split bill,
dashboard del día) — ver `_ai/docs/spec-registry.md`.
