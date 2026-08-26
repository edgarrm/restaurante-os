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

**2026-08-12 — Los 11 specs del registry (#0-#9, #12) están `✅ Implemented`**
(ver `_ai/docs/spec-registry.md`): Onboarding de Tenant, Gestión de Mesas,
Gestión de Menú, Gestión de Staff, Mapa de Mesas, Toma de Pedido, Cocina
(KDS), Cobro, Reservas, Inventario, División de Cuenta — backend completo
(Actions + controllers + rutas + tests Pest) en los 11. F-01 a F-06 del
threat model están 🟢 Resueltos; quedan abiertos F-07 (bloqueo de tablet,
decisión de producto pendiente del cliente ancla) y F-08/F-09/F-10
(bajos, no bloqueantes).

**Pantallas Vue construidas (9 de 9 pantallas Must del inventario,
`_ai/design/screen-inventory.md` — #7 fusionada en #6, ver nota de
Reservas más abajo):** Login, Mapa de Mesas, Toma de Pedido, Cocina (KDS),
Cobro/Cierre de Cuenta, Gestión de Menú, Gestión de Mesas, Gestión de
Staff, Reservas — el loop operativo completo (mesero→cocina→cobro, más
altas de mesas/staff/reservas) es navegable end-to-end en browser real
(`decision-log.md`, entradas del 2026-08-12 con verificación manual).
Gestión de Menú tiene click-through completo (crear, editar, alternar
disponibilidad) verificado — ver REDEV-30 en `decision-log.md`. Gestión de
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

**2026-08-12 — REDEV-30: investigado el mismatch de hidratación transversal
— no reproducido, causa raíz real identificada como la extensión de
automatización, no la app.** La deuda documentada abajo (Mapa de Mesas,
Gestión de Menú, Inventario) se investigó a fondo: SSR real de Inertia
(`data-server-rendered="true"`, confirmado activo vía el modo simplificado
de `@inertiajs/vite`), la sospecha original del script `dark` de
`app.blade.php` y una hipótesis de carrera por estado a nivel de módulo en
`@inertiajs/vue3` (`headManager`/`component`/`page` — coincide
estructuralmente con el error `createProvider` documentado, ver
`node_modules/@inertiajs/vue3/dist/index.js`) se descartaron con pruebas
directas (toggling de dark/system, 40 requests concurrentes contra el
endpoint SSR de Vite tanto por `composer run dev` como por Herd nginx con
PHP-FPM real — cero corrupción, cero warnings, cero errores, en más de 20
cargas de página reales). Los "clics sintéticos poco confiables" sí se
reprodujeron, pero se rastrearon hasta la extensión `claude-in-chrome`
misma quedando en un estado roto a media sesión (mismo error
`Cannot access a chrome-extension:// URL of different extension` ya
documentado antes en Inventario) — con listeners inyectados se confirmó
cero eventos `click`/`pointerdown` llegando a la página durante ese estado,
mientras `.click()` nativo siempre funcionó. No es un bug de la app.
Cerrado sin cambio de código — ver `decision-log.md`, entrada REDEV-30.

Brecha documentada pendiente en `decision-log.md`: transiciones de
`Reservation` a `sentada`/`cancelada` (#8, sin endpoint ni control en UI).

**2026-08-12 — División de Cuenta (#12 del inventario, US-3.2, Could)
implementada** (`_ai/specs/division-de-cuenta.spec.md`, branch
`feature/split-bill`, mergeada a `main`): split por monto libre —
elegido sobre split por ítems vía `AskUserQuestion` (split por ítems
queda documentado como brecha). No es pantalla nueva, extiende
`mesas/Cobro.vue` (#5) con saldo pendiente + historial de pagos.
Arquitectura: `AddPaymentToOrderAction` nueva (nunca rechaza por
insuficiencia, cierra solo cuando la suma de pagos cubre el total);
`CloseOrderAction` (#7, Must, en producción) generalizada para comparar
pagos-ya-registrados + monto vs. total en vez de solo el monto —
verificado que sus tests y los de `CobroTest.php` siguen en verde sin
modificarlos. Ruta nueva `POST /mesas/{table}/cobro/pagos`. Bug de
reactividad encontrado en verificación visual (el campo de monto no se
actualizaba tras un pago parcial) y corregido — ver nota de
implementación en el spec. Suite completa: 184 tests, 180 passed, 4
skipped (preexistentes), 0 fallos.

**2026-08-12 — Dashboard del día (#13 del inventario, Could, última
pantalla pendiente del inventario original) implementada**
(`_ai/specs/dashboard-del-dia.spec.md`, branch
`redev-27-dashboard-del-d-a`): resumen de solo lectura con 3 métricas
(ventas de hoy = suma de `Payment.amount` con `paid_at` de hoy; mesas
activas = `Table.status != libre`; reservas de hoy = `reserved_at` de hoy
con `status` en `{confirmada, sentada}`, excluyendo canceladas —
decisiones confirmadas con el usuario vía `AskUserQuestion` en PASO 0) más
dos listas de apoyo (mesas activas, reservas de hoy). Sin Action —
`DashboardController::index()` delgado, solo composición de queries,
mismo criterio que `KitchenController`/`InventarioController`.

**Hallazgo no anticipado en el ticket, resuelto en PASO 0: ya existía una
ruta `dashboard` genérica del starter kit** (`routes/web.php`, sin
contexto de tenant, accesible a los 3 roles, apuntando a un `Dashboard.vue`
de placeholder) y `config('fortify.home')` la usaba como redirect
post-login **para los tres roles**. Reemplazada por completo: la ruta
`dashboard` (mismo nombre, deliberadamente sin agrupar como
`dashboard.index` para no romper los call sites de Wayfinder en
`Welcome.vue`/`AppHeader.vue`) se movió a `routes/tenant.php` con
`role:admin`, y se agregó `App\Http\Responses\LoginResponse` (bindeada en
`FortifyServiceProvider`) que calcula el redirect post-login según el rol:
admin → `dashboard`, mesero → `mesas.index`, cocina → `cocina.index`. Sin
este cambio, mesero/cocina habrían recibido 403 justo después de iniciar
sesión. `tests/Feature/Auth/AuthenticationTest.php` y
`tests/Feature/DashboardTest.php` (starter kit) actualizados para reflejar
el nuevo comportamiento — ambos en verde.

Click-through verificado en browser real (`demo.localhost:8000`, cuenta
`Admin QA`): datos reales del tenant demo (4 mesas activas, $0.00 en
ventas, estado vacío de reservas), light y dark mode, sin errores de
consola. Suite completa: 218 tests, 214 passed, 4 skipped (preexistentes),
0 fallos.

**Hallazgo fuera de alcance, no corregido en esta sesión:** `POST
/mesas/{table}/cobro` (ver `_ai/specs/cobro.spec.md`, #7) devuelve 404 en
el tenant demo para una mesa real en status `por_cobrar` (Mesa 3, id 4) —
reproducido tanto por clic real como por navegación directa a
`/mesas/4/cobro`. No investigado a fondo (fuera del alcance de REDEV-27);
ver `decision-log.md` y el follow-up creado en Linear.

Con Dashboard del día implementado, las 9 pantallas Must, la Should
(Inventario) y las 2 Could (División de Cuenta, Dashboard del día) del
inventario original están construidas — ver `_ai/docs/spec-registry.md`.

**2026-08-13 — REDEV-29: Split por Ítems (ampliación de #12, División de
Cuenta) implementado.** Resuelve la brecha dejada por la sesión original
de #12 (split por monto libre). Segundo modo en `mesas/Cobro.vue` —
selección de `OrderItem`s, monto calculado en el servidor, mismo criterio
de cierre por monto acumulado que el modo existente; ambos modos conviven
sin acoplarse. Ver `_ai/specs/division-de-cuenta.spec.md`, sección
"Ampliación (REDEV-29)", y `decision-log.md` para el detalle de
arquitectura, un bug de carrera encontrado en code review (no en
verificación visual) y corregido, y la verificación completa. Suite: 233
tests, 229 passed, 4 skipped, 0 fallos.

**2026-08-20 — Backlog abierto (auditoría de continuidad, ningún spec
nuevo pendiente).** Los 12 specs de `_ai/docs/spec-registry.md` (#0-#9,
#12, #13) están `✅ Implemented` — **no hay ningún spec en `_ai/specs/` a
medio implementar ni en Draft/Review**. Lo que queda es deuda técnica y
decisiones ya documentadas, no specs nuevos:

1. ~~**Bug 404 en Cobro**~~ — **Corregido 2026-08-25 (REDEV-33), commit
   `d594c47` en `main`.** Causa raíz real: `DeleteTableAction` no
   bloqueaba el borrado de una mesa con orden `lista`/`por_cobrar` (solo
   `abierta`/`enviada_cocina`); al ser `Table` un modelo `SoftDeletes`,
   una mesa borrada con cuenta pendiente de cobro devolvía 404 directo en
   el binding de ruta. Fix + specs/tests actualizados (ver
   `decision-log.md`, entrada REDEV-33). Pendiente: verificar/corregir el
   dato huérfano real del tenant demo (Mesa 3, id 4) si sigue vivo en
   algún entorno — no se pudo confirmar in situ, este checkout no tenía
   `database.sqlite`; tampoco se hizo verificación visual en browser real
   del fix.
2. **Reservas: transiciones de estado faltantes** — `Reservation` no tiene
   endpoint ni control en UI para pasar a `sentada`/`cancelada` (brecha en
   `_ai/specs/reservas.spec.md`, #8; ver decision-log, PASO 0 de #8).
3. ~~**Toma de Pedido: editar/quitar ítems de la cuenta**~~ — **Nota
   corregida 2026-08-25: esta entrada estaba obsoleta.** La brecha
   documentada el 2026-08-11 (`decision-log.md`) se cerró el 2026-08-12 al
   construir la pantalla Vue: `PATCH /mesas/{table}/pedido/items/{orderItem}`
   (`UpdateOrderItemQuantityAction`) + stepper en `Pedido.vue`, editable
   solo mientras la orden sigue `abierta` (decisión de diseño confirmada
   con el usuario). Ver `_ai/specs/toma-de-pedido.spec.md`, sección E2E
   Tests/Definition of Done ("brecha cerrada, ver arriba"). Esta auditoría
   de continuidad no la había marcado como resuelta — no hay trabajo
   pendiente aquí.
4. **F-07 del threat model** — bloqueo de tablet desatendida: decisión de
   producto pendiente del cliente ancla, no técnica (ver decision-log,
   2026-08-10).
5. **Gestión de Staff** — tests Pest y lint/types en verde, pero falta
   verificación visual en browser real (click-through completo).
6. **Passkeys/WebAuthn** — pendiente revisitar si el cliente ancla o un
   piloto lo pide (ver `ADR-003`, sección "Pendiente — Passkeys").
7. Deuda menor diferida (no defectos): duplicación de la query de lookup
   de orden y del selector "Método de pago" entre los dos modos de
   `Cobro.vue` — candidatas a extracción si se agrega un tercer modo de
   split.

Housekeeping: la rama local `División-de-cuenta` ya está fusionada a
`main` (sin commits propios) y no existe en `origin` — se puede borrar
con `git branch -d División-de-cuenta` sin pérdida.

Ninguno de estos bloquea operar el MVP. Para elegir el siguiente trabajo,
partir de esta lista + `decision-log.md`; ninguno tiene spec propio
todavía, así que la siguiente sesión debe empezar por PASO 0 (spec) del
punto elegido, no saltar directo a código.
