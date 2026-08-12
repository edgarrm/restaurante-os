# Decision Log — restaurante-os

> Este archivo existe porque `tenancy for Laravel` se propuso antes de que
> existiera este proceso, no quedó registrada en ningún lado, y casi se pierde
> — solo sobrevivió porque alguien la recordó de memoria (2026-08-10).
>
> Un ADR documenta una decisión ya tomada. Un spec documenta una feature ya
> definida. Ninguno de los dos tiene espacio para "alguien propuso X, no sabemos
> todavía si sí". Este archivo es ese espacio intermedio.

## Cómo usarlo

- Agrega una entrada cuando: alguien propone algo (una librería, un enfoque, un
  requisito) que todavía no se decide; el cliente menciona una restricción o
  preferencia fuera de una sesión formal de Discovery/PRD; surge una pregunta
  que bloquea una decisión de arquitectura pero no amerita detener todo.
- Cuando se decide: mover el resultado a un ADR (`_ai/adrs/`) o a un spec, y
  marcar la entrada aquí como **Resuelta**, con el link al documento final.
- No se borran entradas resueltas — quedan como rastro de por qué se decidió
  algo, igual que la sección "Nota sobre la primera versión" en ADR-006.

## Entradas

### 2026-08-10 — Cómo hacer las rutas de auth tenant-aware (F-01)
**Estado:** 🟢 Resuelta — implementada en `onboarding-tenant.spec.md` (#0),
2026-08-11
**Contexto:** las rutas de Fortify (`/login`, `/logout`, `/forgot-password`,
`/settings/*`) no tienen middleware de tenancy (verificado con `route:list`).
Cuando `users.tenant_id` exista, esto permite que un usuario del restaurante B
se autentique en el subdominio del restaurante A. Ver F-01 en
`_ai/docs/threat-model.md`.
**Opciones evaluadas:** (a) mover las rutas de Fortify a `routes/tenant.php`
con `InitializeTenancyByDomain`; (b) configurar `config/fortify.php` →
`'middleware'` para incluir el middleware de identificación; (c) un guard de
autenticación explícitamente tenant-aware.
**Decisión:** combinación de (a) y (b), porque las rutas afectadas vienen de
dos fuentes distintas que ninguna opción pura cubre sola:
- Las rutas que sí registra el paquete Fortify (`/login`, `/logout`,
  `/forgot-password`, `/reset-password`, `/verify-email`,
  `/confirm-password`, 2FA) se resolvieron con **(b)**: `config('fortify.middleware')`
  es el punto de extensión oficial del paquete
  (`FortifyServiceProvider::configureRoutes()` envuelve sus rutas en
  `Route::group(['middleware' => config('fortify.middleware', ['web'])], ...)`)
  — no requiere tocar vendor ni desregistrar rutas.
- `/settings/*` **no es de Fortify** — vive en nuestro propio
  `routes/settings.php`, cargado antes desde `routes/web.php` (central). Se
  resolvió con el equivalente de **(a)**: se movió el `require` de
  `settings.php` al grupo de middleware de `routes/tenant.php`.
- Ambos grupos comparten el mismo stack: `web, InitializeTenancyByDomain,
  PreventAccessFromCentralDomains, ScopeSessions` (este último cierra F-02 en
  el mismo cambio).
**Bug de plomería descubierto al implementar:** el `Tenant` base de
`stancl/tenancy` no incluye el trait `HasDomains`, pero su propio
`DomainTenantResolver::resolveWithoutCache()` hace `Tenant::whereHas('domains', ...)`
— sin la relación, `InitializeTenancyByDomain` revienta con
`BadMethodCallException` en cuanto intenta identificar un tenant por su
`Domain`. Se creó `app/Models/Tenant.php` extendiendo el `Tenant` del paquete
con `HasDomains`, y `config('tenancy.tenant_model')` ahora apunta ahí.
**Efecto colateral:** todos los tests de `tests/Feature/Auth/*` y
`tests/Feature/Settings/*` (preexistentes del starter kit) necesitaron un
Tenant + Domain de fixture — ver el helper `actingInTenant()` en
`tests/Pest.php`.
**Verificado con:** `tests/Unit/Actions/Tenants/OnboardTenantActionTest.php`,
`tests/Feature/OnboardingTenantTest.php` (incluye los tests explícitos de
F-01/F-02) y la suite completa (`php artisan test --compact`, 37 passed / 4
skipped).

### 2026-08-10 — Bloqueo de tablet desatendida (F-07)
**Estado:** 🟡 Abierta
**Contexto:** las tablets viven en el piso y en cocina, compartidas y a menudo
desatendidas. `SESSION_LIFETIME=120` sin bloqueo por inactividad: quien tome
una tablet abierta puede tomar pedidos y **cobrar** como el mesero que la dejó.
**Tensión:** el diferenciador del producto es "cero fricción" — un bloqueo con
contraseña lo contradice. Alternativas intermedias: PIN corto por usuario, o
reautenticación solo para acciones sensibles (cobro, anulación).
**Es decisión del cliente ancla, no técnica** — depende de cómo opera su piso.
**Ver:** F-07 en `_ai/docs/threat-model.md`, `_ai/specs/cobro.spec.md`.

### 2026-08-11 — `order_items` no existe todavía al implementar `gestion-menu.spec.md` (#2)
**Estado:** 🟢 Resuelta — implementada en `gestion-menu.spec.md` (#2), 2026-08-11
**Contexto:** el spec de Gestión de Menú pide un Unit Test para
`UpdateMenuItemAction` que verifica que `OrderItem.unit_price` es un snapshot
que no cambia si `menu_items.price` cambia después. La tabla `order_items` es
de `toma-de-pedido.spec.md` (#5, sin implementar) — mismo tipo de hueco que
`orders` al implementar `gestion-mesas.spec.md` (#1).
**Opciones evaluadas:** (a) migración + modelo mínimo de `order_items`, solo
lo necesario para el test (sin Actions/Controllers/rutas de pedidos); (b)
recortar ese test case del spec y resolverlo hasta el #5.
**Decisión:** (a), siguiendo el mismo patrón ya usado para `orders` en #1
(ver `database/migrations/2026_08_11_212112_create_orders_table.php` y
`app/Models/Order.php`, que documentan en un comentario qué falta y qué spec
lo completa). `order_items` **no lleva `tenant_id` propio** — hereda el
aislamiento vía `Order` (ya documentado así en `_ai/docs/data-model.md`, sin
cambios de esquema). Columnas: `order_id`, `menu_item_id`, `quantity`,
`unit_price`, `status` enum. `toma-de-pedido.spec.md` (#5) completa el resto
del dominio (Actions, controller, rutas).
**Verificado con:** `tests/Unit/Actions/MenuItems/UpdateMenuItemActionTest.php`
("cambiar el precio no afecta el snapshot de `OrderItem` existentes") y la
suite completa (`php artisan test --compact`, 72 passed / 4 skipped).

### 2026-08-10 — Passkeys / WebAuthn (Fortify)
**Estado:** 🟢 Resuelta — 2026-08-11, al implementar `gestion-staff.spec.md` (#3)
**Contexto:** `laravel/fortify` instala `laravel/passkeys` como dependencia
directa — está en el proyecto sin que nadie lo haya pedido. Podría reducir la
fricción de onboarding de staff (el diferenciador central del producto) con
login sin contraseña, o podría ser una feature sin usuario real que la pida.
**Decisión:** **No ahora.** `gestion-staff.spec.md` (#3) se implementó
password-only — sin urgencia real del cliente ancla que lo pida todavía, y
el costo de agregar un segundo método de login (registro de passkey por
usuario, UI de gestión, flujo de fallback) no se justifica sin esa señal.
**No descartado** — sigue siendo la misma opción documentada en ADR-003,
sección "Pendiente — Passkeys": revisitar si el cliente ancla o un piloto
pide explícitamente reducir la fricción de login de staff.
**Ver:** `_ai/adrs/ADR-003-autenticacion-y-roles.md`, sección "Pendiente —
Passkeys"; `_ai/specs/gestion-staff.spec.md`, sección "Decisiones tomadas
durante la implementación (PASO 0)"

### 2026-08-11 — Campo de desactivación de cuentas (`users.is_active`)
**Estado:** 🟢 Resuelta — implementada en `gestion-staff.spec.md` (#3),
2026-08-11
**Contexto:** el spec de Gestión de Staff pide que eliminar una cuenta con
historial de órdenes (`Order.opened_by`) no sea una eliminación dura, sino
"desactivar la cuenta (deshabilitar login)". `_ai/docs/data-model.md` no
tenía ningún campo para esto — verificado con `php artisan db:table users`.
**Opciones evaluadas:** (a) migración `is_active` boolean; (b) reutilizar
`email_verified_at = null` como señal de "desactivado"; (c) `SoftDeletes`.
**Decisión:** (a). La opción (b) se descartó tras verificar que no
funcionaría de verdad: `config/fortify.php` no tiene
`Features::emailVerification()` habilitado y `User` no implementa
`MustVerifyEmail`, así que ese campo no interviene en el login en absoluto
en este proyecto — habría sido una desactivación cosmética. `is_active` se
agregó como columna boolean (default `true`, no fillable — mismo patrón
que `available` en `MenuItem`), y el bloqueo real de login se implementó
reemplazando `Fortify::authenticateUsing()` en `FortifyServiceProvider`
para rechazar usuarios con `is_active=false`.
**Verificado con:** `tests/Unit/Actions/Staff/DeactivateStaffAccountActionTest.php`,
el test "una cuenta desactivada no puede iniciar sesión" de
`tests/Feature/GestionStaffTest.php`, y la suite completa
(`php artisan test --compact`, 93 passed / 4 skipped).

### 2026-08-11 — Brecha de contrato en `toma-de-pedido.spec.md` (#5): editar/quitar ítems de la cuenta
**Estado:** 🟢 Resuelta — implementada en `toma-de-pedido.spec.md` (#5), 2026-08-11
**Contexto:** el spec tiene una inconsistencia entre sus propias secciones.
Happy Path (paso 7, "el mesero ajusta cantidades con el stepper") y Edge
Cases ("cantidad ajustada a 0 en el stepper → el renglón se elimina")
narran un endpoint para editar/decrementar/eliminar un `OrderItem` ya
agregado a la cuenta. Pero ni `_ai/docs/api-contract.yaml` ni la sección
Test Cases → Integration Tests del spec definen ese endpoint — los tres
documentados (GET pedido, POST items con incremento si ya existe, POST
enviar) solo cubren agregar.
**Opciones evaluadas:** (a) implementar en esta sesión un endpoint no
documentado en el contrato (ej. `PATCH`/`DELETE`
`/mesas/{table}/pedido/items/{orderItem}`) para cerrar la brecha; (b)
dejarlo fuera de alcance explícitamente, documentado como pendiente, porque
ningún Integration Test del spec lo exige y el stepper en sí es trabajo de
la pantalla Vue de una sesión futura.
**Decisión:** (b). Es consistente con el criterio ya establecido en este
proyecto de hacer TDD contra los Test Cases del spec, no contra la prosa
de Happy Path/Edge Cases — construir un endpoint que ningún test pide
sería anticiparse al contrato, no implementarlo. Documentado como brecha
pendiente en `toma-de-pedido.spec.md` (sección Integration Tests) para
cuando se construya la pantalla Vue de `/mesas/{table}/pedido`.
**Verificado con:** `tests/Unit/Actions/Orders/*`,
`tests/Feature/TomaDePedidoTest.php`, y la suite completa
(`php artisan test --compact`, 114 passed / 4 skipped).

### 2026-08-11 — PASO 0 de `cocina-kds.spec.md` (#6): alcance de `GET /cocina`
**Estado:** 🟢 Resuelta — implementada en `cocina-kds.spec.md` (#6), 2026-08-11
**Contexto:** el spec tiene una ambigüedad entre secciones sobre qué debe
devolver `GET /cocina`. "Inputs & Outputs" dice que el input son "ítems con
`status=pendiente`" — sugiere filtrar también por status del ítem. El Happy
Path (paso 2, "tarjetas de órdenes... cada una con sus ítems") sugiere
devolver todos los ítems de la orden, incluyendo los ya `listo`, para que
cocina tenga contexto completo. El único Integration Test explícito ("`GET
/cocina` devuelve solo ítems de órdenes en `enviada_cocina`") es ambiguo
entre ambas lecturas: filtra por status de la `Order`, pero no aclara si
también filtra por status del ítem.
**Opciones evaluadas:** (a) filtrar solo por `Order.status = enviada_cocina`
y devolver todos los `OrderItem` de esa orden; (b) además, excluir del
payload los `OrderItem` ya en `listo`.
**Decisión:** (a), confirmada con el usuario antes de escribir el primer
test (ver `AskUserQuestion` de esta sesión). Es consistente con el criterio
ya establecido en `toma-de-pedido.spec.md` (#5) de hacer TDD contra los Test
Cases del spec, no contra la prosa de Happy Path/Inputs & Outputs — el único
test explícito solo exige filtrar por status de la `Order`. Añadir un
filtro adicional por status del ítem habría sido anticiparse al contrato.
Efecto práctico: cuando una `Order` pasa a `lista` (todos sus ítems
`listo`), la orden completa desaparece de `GET /cocina` — consistente con
el Happy Path, paso 5.
**Verificado con:** `tests/Unit/Actions/Orders/MarkOrderItemReadyActionTest.php`,
`tests/Feature/CocinaKdsTest.php`, y la suite completa (`php artisan test
--compact`, 125 passed / 4 skipped).

### 2026-08-12 — PASO 0 de `cobro.spec.md` (#7): estados cobrables, transición a `por_cobrar`, y métodos de pago
**Estado:** 🟢 Resuelta — implementada en `cobro.spec.md` (#7), 2026-08-12
**Contexto:** el spec tenía tres ambigüedades/huecos genuinos, cada uno
confirmado con el usuario antes de escribir el primer test (`AskUserQuestion`
de esta sesión):

a) **Estados de `Order` "cobrables"**: el único Integration Test dice
   "`GET /mesas/{table}/cobro` devuelve el detalle de la orden abierta", pero
   el Edge Case de una orden nunca enviada a cocina siendo cobrable
   contradice una lectura literal de "abierta" como `status=abierta`.
   **Decisión:** la orden elegible es la más reciente de la mesa con status
   en `[abierta, enviada_cocina, lista]` (ampliado después a incluir
   `por_cobrar` — ver siguiente punto — y `pagada` solo para el POST, por el
   caso de doble tap idempotente).

b) **¿Construir la transición `Table.status → por_cobrar` en esta sesión?**
   Verificado que ninguna Action existente la produce, pese a que
   `toma-de-pedido.spec.md` y `mapa-de-mesas.spec.md` la narran como
   precondición. El usuario decidió que sí se construyera aquí (a diferencia
   del criterio por defecto de este proyecto de no construir lo que ningún
   Test Case pide). **Mecanismo elegido** (segunda pregunta, también
   confirmada): efecto colateral de `GET /mesas/{table}/cobro`
   (`RequestBillAction`), sin endpoint dedicado de "pedir la cuenta" — mismo
   patrón que `OpenOrReuseOrderForTableAction` en `GET /mesas/{table}/pedido`.
   Efecto práctico: como `GET /cobro` ahora puede dejar la orden en
   `por_cobrar`, ese status se agregó a los estados elegibles del punto (a)
   para que reabrir la misma pantalla (o el siguiente poll) siga encontrando
   la orden en vez de 404.

c) **Valores válidos de `method`**: ni el spec ni `api-contract.yaml` los
   enumeraban (solo un ejemplo, `"efectivo"`). **Decisión:** conjunto
   cerrado `efectivo`, `tarjeta`, `transferencia` — nuevo enum
   `App\Enums\PaymentMethod`, validado con `Rule::enum()` en el controller.

**Bug de plomería descubierto al implementar:** la primera versión de
`RequestBillAction`/`CloseOrderAction` usaba `$order->table->update([...])`
para cambiar `Table.status`, que no tuvo ningún efecto — `status` no está en
`Table::$fillable` (mismo patrón ya usado para `available` en `MenuItem` e
`is_active`/`role` en `User`), así que `update()` lo descarta en silencio sin
error. Corregido a `forceFill(['status' => ...])->save()`, igual que
`OpenOrReuseOrderForTableAction`. Detectado porque los Unit tests fallaban en
la aserción de `Table::fresh()->status` tras implementar — confirmado con
`php artisan tinker` antes de aplicar el fix.

**Verificado con:** `tests/Unit/Actions/Orders/CloseOrderActionTest.php`,
`tests/Unit/Actions/Orders/RequestBillActionTest.php`,
`tests/Feature/CobroTest.php`, y la suite completa (`php artisan test
--compact`, 142 passed / 4 skipped).

### 2026-08-12 — PASO 0 de `reservas.spec.md` (#8): alcance de `GET /reservas` y transiciones de status
**Estado:** 🟢 Resuelta (a) — implementada; 🟡 Documentada como brecha (b) —
`reservas.spec.md` (#8), 2026-08-12
**Contexto:** dos preguntas confirmadas con el usuario (`AskUserQuestion`)
antes de escribir el primer test:

a) **¿Qué reservas devuelve `GET /reservas`?** El único Integration Test
   dice "las reservas del día", sin que `api-contract.yaml` documente un
   query param de fecha. **Decisión:** `whereDate('reserved_at', today())`,
   sin filtrar por `status` (el staff también ve las `cancelada` del día),
   ordenadas por `reserved_at`. Sin selector de fecha para otros días —
   fuera de alcance, ningún Test Case ni el contrato lo piden.

b) **¿Se construyen las transiciones a `sentada`/`cancelada` (Happy Path
   paso 5)?** A diferencia de la transición a `por_cobrar` en #7 (que sí se
   amplió el alcance a petición explícita), aquí el usuario confirmó el
   criterio por defecto de este proyecto: no construir lo que ningún Test
   Case ni `api-contract.yaml` piden. **Queda como brecha pendiente**,
   documentada aquí para cuando se aborde (probablemente junto con la
   pantalla Vue de `/reservas`, que necesitará algún control para marcar
   estas transiciones).

**Nota de diseño (no ambigua, solo verificada antes de implementar):**
`Reservation` es el primer modelo del proyecto con `table_id` **nullable**
y sin ninguna relación padre confiable de la que heredar tenant (a
diferencia de `OrderItem`/`Payment` en #5/#7) — por eso lleva su propio
`tenant_id` + `BelongsToTenant`, igual que `Table`/`MenuItem`/`User`/`Order`.
Documentado en el modelo y la migración.

**Verificado con:** `tests/Unit/Actions/Reservations/CreateReservationActionTest.php`,
`tests/Feature/ReservasTest.php`, y la suite completa (`php artisan test
--compact`, 152 passed / 4 skipped).

### 2026-08-12 — Fase 03: se abandona Stitch, primera pantalla Vue (Mapa de Mesas)
**Estado:** 🟢 Resuelta — implementada, ver `_ai/design/screen-inventory.md`
**Contexto:** al retomar Fase 03 (ver `_ai/CONTEXT.md`, nota de cierre de
los 8 specs backend), `generate_screen_from_text` de Stitch seguía sin
conexión (confirmado por el usuario directamente, sin reintento exitoso
desde el fallo del 2026-08-10).
**Decisión:** abandonar Stitch como mecanismo de generación de código de
pantallas — el proyecto y su design system (`assets/4d55f3c4dae2452583b02110c6f66fcf`)
se conservan como referencia de tokens, pero las pantallas se construyen
directo en Vue 3 + Tailwind v4 + los componentes reka-ui ya instalados del
starter kit. Los tokens (colores, Work Sans, JetBrains Mono, radios,
colores de estado libre/ocupada/por_cobrar) se tradujeron a mano a
`resources/css/app.css` y `vite.config.ts` (fuentes vía `bunny()`) —
aplican a **toda la app**, no solo a esta pantalla, porque son tokens CSS
globales.
**Primera pantalla construida:** Mapa de Mesas (`resources/js/pages/mesas/Index.vue`),
elegida por ser el punto de entrada real de mesero/admin y la más simple
(solo lectura, sin formularios) para validar el patrón antes de pantallas
con más interacción.
**Efectos colaterales necesarios para llegar a esta pantalla:**
- `php artisan wayfinder:generate` no se había corrido desde que se
  implementaron los backends de #1-#8 — ningún `resources/js/routes/*`
  ni `resources/js/actions/*` existía para esas rutas. Regenerado con
  `--with-form` (el flag por defecto de `vite.config.ts`, `formVariants: true`)
  — la primera corrida sin ese flag rompió `.form()` en 6 páginas
  preexistentes del starter kit (auth/settings), detectado por
  `npm run types:check`.
- `php artisan migrate` no se había corrido contra la SQLite de desarrollo
  desde que se crearon `payments`/`reservations` (#7/#8) — solo existían
  en el entorno de test (`RefreshDatabase`). Sin esto, cualquier verificación
  manual en browser habría fallado con "no such table".
- `APP_NAME` seguía en "Laravel" (default del starter kit) — cambiado a
  "Restaurante OS" en `.env` y `.env.example`; requirió
  `php artisan config:clear` para que surtiera efecto (estaba cacheado).
- El sidebar (`AppSidebar.vue`) apuntaba a un "Dashboard" genérico del
  starter kit sin ningún nav item hacia el dominio de restaurante, más
  links de "Repository"/"Documentation" irrelevantes — cambiado a un nav
  "Mesas" y se quitó el footer nav; sin esto la pantalla no era alcanzable
  desde la UI después del login.
**Verificado con:** browser real (login con cuenta admin de un tenant de
prueba), `npm run lint:check`, `npm run types:check`, y verificación visual
manual en light y dark mode — happy path completo (mesa libre/ocupada →
pedido, mesa por_cobrar → cobro), estado vacío con CTA solo-admin.
**Hallazgo abierto, no resuelto en esta sesión:** la consola del navegador
muestra "Hydration completed but contains mismatches" y un error no
capturado ("Cannot read properties of undefined (reading 'createProvider')")
en **toda la app**, incluyendo `/dashboard` (página del starter kit sin
tocar) — confirmado que no lo introdujo esta pantalla. Sospecha: conflicto
entre el script inline de `app.blade.php` que aplica la clase `dark` antes
de montar Vue, y el manejo reactivo de tema de `useAppearance`/reka-ui. Sin
investigar a fondo — queda como deuda técnica para la próxima sesión de
frontend.

### 2026-08-12 — PASO 0 de la pantalla Vue de Toma de Pedido (#3): stepper de "La Cuenta"
**Estado:** 🟢 Resuelta — implementada, ver `toma-de-pedido.spec.md`
**Contexto:** la brecha ya documentada el 2026-08-11 (Happy Path narra un
stepper de editar/quitar `OrderItem`, pero no existía endpoint) llegó a su
momento de decisión real al construir la pantalla Vue.
**Opciones evaluadas:** (a) lanzar sin stepper (renglones de solo lectura,
agregar más solo re-tocando el platillo en el menú); (b) construir el
endpoint ahora, ampliando alcance — mismo criterio que `por_cobrar` en #7.
**Decisión:** (b), confirmada con el usuario (`AskUserQuestion`) antes de
tocar el primer componente. Se agregó `PATCH
/mesas/{table}/pedido/items/{orderItem}` (`UpdateOrderItemQuantityAction`),
solo editable mientras la orden sigue `abierta` (`OrderNotEditableException`
si ya se envió a cocina — decisión de diseño no ambigua, consistente con
que el Happy Path ordena el stepper *antes* de "Enviar a Cocina").

**Dos hallazgos de plomería, no ambiguos, corregidos sin `AskUserQuestion`
(mismo criterio que el bug de `forceFill` en #7 — se documentan aquí, no se
preguntan):**

1. **Bug: recargar `/pedido` tras "Enviar a Cocina" daba 404.**
   `OpenOrReuseOrderForTableAction` solo reutilizaba órdenes `abierta` para
   una mesa `ocupada`; una vez la orden pasa a `enviada_cocina`, la
   siguiente carga de la pantalla no encontraba ninguna orden que
   reutilizar. Nunca se detectó en Fase 02 porque los Integration Tests de
   `enviar` solo hacían `assertRedirect()` sin seguir el redirect — la
   primera vez que algo realmente sigue ese redirect es un navegador real
   con la pantalla Vue montada. Corregido ampliando el query a `[abierta,
   enviada_cocina, lista]`, mismo criterio de "estados activos" que
   `RequestBillAction` (#7).

2. **Arquitectura: los 422 de dominio no llegaban limpios al cliente Inertia
   real.** `addItem`/`send` usaban `abort(422, $mensaje)`, probado solo con
   `postJson()` (fuerza `Accept: application/json`, por eso los tests
   pasaban). Un cliente Inertia real manda `Accept: text/html` — Laravel
   entonces NO trata la petición como "expects JSON" (confirmado con un test
   exploratorio: `expectsJson()` es `false` para una petición Inertia
   real), así que el `abort()` devuelve una página HTML completa sin el
   header `X-Inertia`. Inertia trata eso como respuesta "no-Inertia" y
   muestra un modal con el HTML crudo del error — no el mensaje del spec
   ("Este platillo ya no está disponible."). Es la primera pantalla con
   formularios POST/PATCH reales del proyecto (Mapa de Mesas es solo
   lectura), así que es la primera vez que este patrón se ejercita de
   verdad. **Fix:** los tres 422 de dominio de este flujo
   (`MenuItemNotAvailableException`, `TableNotAcceptingOrdersException`,
   `EmptyOrderException`, `OrderNotEditableException`) ahora se lanzan como
   `ValidationException::withMessages([...])`, que sí viaja por el redirect
   302 + errores flasheados que Inertia espera (mismo mecanismo documentado
   en `.ai/rules/feature.md` para errores de validación normales). Verificado
   en browser real: banner inline con el mensaje correcto, menú
   refrescado automáticamente (ítem deshabilitado), sin modal ni página de
   error. **Aplica también a Cobro/Reservas** cuando se construyan sus
   pantallas Vue — sus controllers actuales (`PaymentController`,
   `ReservationController`) probablemente tengan el mismo patrón de
   `abort(422, ...)` sin probar contra un cliente Inertia real.

3. **Edge Case sin implementar: mesa en `por_cobrar` navegando a
   `/pedido`.** El spec ya documentaba el comportamiento esperado
   (redirige a `/cobro` con aviso), pero `OrderController::show()` nunca lo
   implementó — antes de este fix, devolvía 404 (ninguna orden en
   `[abierta, enviada_cocina, lista]` que reutilizar). En el flujo normal
   Mapa de Mesas ya evita esta ruta (enlaza directo a `/cobro` para mesas
   `por_cobrar`), pero una pestaña vieja o el botón "atrás" del navegador sí
   puede llegar aquí. Corregido con un redirect a `cobro.show` +
   `Inertia::flash('notice', ...)`.

**Otro hallazgo, no corregido (fuera de alcance):** si un mesero agrega un
`OrderItem` nuevo a una orden que ya está `lista` (todos sus ítems previos
`listo`), ese ítem nuevo no hace reaparecer la orden en `GET /cocina`
(`KitchenController::index()` filtra por `Order.status = enviada_cocina`,
no `lista`). Es un cruce entre `toma-de-pedido` y `cocina-kds` fuera de
alcance de esta sesión — pendiente para cuando se toque cualquiera de las
dos pantallas de nuevo.

**Verificado con:** `tests/Unit/Actions/Orders/UpdateOrderItemQuantityActionTest.php`,
`tests/Unit/Actions/Orders/OpenOrReuseOrderForTableActionTest.php`,
`tests/Feature/TomaDePedidoTest.php`, la suite completa (`php artisan test
--compact`, 164 passed / 4 skipped), `npm run lint:check`, `npm run
types:check`, y verificación visual manual en browser real
(`demo.localhost:8000`) en light y dark mode: agregar/incrementar ítems,
bajar cantidad a 0 para quitar un renglón, enviar a cocina (orden queda
`enviada_cocina`, mesa sigue `ocupada`, pantalla se puede seguir usando), y
el caso de ítem desactivado a medio uso (banner correcto, menú refrescado).

### 2026-08-12 — PASO 0 de la pantalla Vue de Cobro y Cierre de Cuenta (#7)
**Estado:** 🟢 Resuelta — implementada, ver `cobro.spec.md`
**Contexto:** dos puntos confirmados con el usuario antes de tocar el primer
componente (`resources/js/pages/mesas/Cobro.vue`):

a) **Campo `amount`**: precargado con el total exacto de la orden (Happy
   Path #3), pero editable — el mesero puede subirlo para registrar un
   billete grande (Edge Cases, "cambio a dar"). Sin tope superior; el
   servidor solo rechaza `amount < total`. El "cambio a dar" es cálculo de
   UI (`amount - total`), no viaja al servidor.
b) **F-07 (tablet desatendida)**: confirmado fuera de alcance de esta
   sesión — sigue 🟡 Abierta, decisión del cliente ancla, sin PIN ni
   reautenticación en esta pantalla.

**Plomería corregida sin `AskUserQuestion` (mismo criterio que #3 — no
ambigua, se documenta aquí):**
1. `PaymentController::close()` tenía el mismo bug ya anticipado en el
   prompt de arranque de esta sesión y documentado en #3: `abort(422, ...)`
   para `InsufficientPaymentException` no trae `X-Inertia`, así que un
   cliente Inertia real lo mostraba como modal crudo. Corregido a
   `ValidationException::withMessages(['amount' => $exception->getMessage()])`
   — mismo patrón que `OrderController`. Ajustado el Integration Test
   correspondiente (`errors.amount.0` en vez de `message`, igual que
   `TomaDePedidoTest`).
2. `PaymentController::show()` no pasaba `table` como prop a
   `Inertia::render()` (solo `order`) — necesario para breadcrumbs y
   encabezado, mismo patrón que `OrderController::show()`. Se agregó
   `'table' => $table`.
3. El eager-load de `show()` se amplió de `items` a `items.menuItem` para
   mostrar el nombre real del platillo en "La Cuenta" en vez de
   `Platillo #{id}` (el spec no lo exigía explícitamente, pero es la misma
   información que ya se muestra en Toma de Pedido).

**Verificado con:** `tests/Feature/CobroTest.php`, la suite completa
(`php artisan test --compact`, 168 tests / 164 passed / 4 skipped),
`npm run lint:check`, `npm run types:check`, y verificación visual manual
en browser real (Chrome real vía extensión, no el preview sandbox — el
preview sandbox de este entorno no tenía red hacia Herd/`demo.localhost`;
`demo.localhost:8000` sirve vía `composer run dev`, no vía nginx de Herd en
este momento): cobrar una mesa con el total exacto (mesa vuelve a `libre`
en el mapa) y el caso de monto insuficiente (banner inline correcto, mesa
permanece `por_cobrar`). Usuario de prueba: se creó un mesero temporal
(`mesero.qa@demo.test`) porque no se contaba con la contraseña real de
Ana Admin y resetearla fue bloqueado por el clasificador de permisos
(acción sobre credenciales); limpieza posterior dejó la cuenta de prueba
en la base (el borrado falló por una FK, no crítico para un tenant demo).

### 2026-08-11 — PASO 0 de la pantalla Vue de Cocina/KDS (#6)
**Estado:** 🟢 Resuelta — implementada, ver `cocina-kds.spec.md`
**Contexto:** tres puntos que el spec dejaba explícitamente "a definir en
Fase 03", confirmados con el usuario antes de tocar el primer componente
(`resources/js/pages/cocina/Index.vue`):

a) **Umbral de urgencia visual (>15 min)**: SÍ se implementa esta sesión.
   Cálculo 100% client-side (minutos transcurridos desde `order.opened_at`,
   sin lógica nueva de servidor) — a partir de 15 min la tarjeta y el badge
   de tiempo cambian a `destructive` (rojo, semántica ya definida en el
   design system para "necesita atención").
b) **Órdenes en `lista`**: se agrega una sección "Completadas" en la misma
   pantalla (en vez de solo desaparecer). Requirió ampliar
   `KitchenController::index()`: nuevo prop `completedOrders` con las
   últimas 20 órdenes en status `lista` (`latest('updated_at')->limit(20)`),
   puramente informativo — sin botones, ya que todos sus ítems están
   `listo`. `orders` (activas) no cambió de criterio de filtrado, así que
   los tests de PASO 0 anterior (#6 backend) siguen pasando sin tocar.
c) **Alcance del botón "Listo"**: ítem individual + botón "Listo (orden)".
   Sin nuevo endpoint — el botón de orden encadena `PATCH
   /cocina/items/{orderItem}/listo` uno a la vez (recursión en
   `onFinish`, no en paralelo) sobre los ítems `pendiente`/`preparando` de
   esa orden, reusando el único endpoint ya existente.

**Plomería corregida sin `AskUserQuestion` (mismo criterio que #3/#7 — no
ambigua, se documenta aquí):**
1. `KitchenController::index()` solo hacía `with('items')` — se amplió a
   `with(['table', 'items.menuItem'])`. El spec pide tarjetas "agrupadas
   por mesa" con nombre de platillo, no solo IDs (mismo hueco que tenía
   `PaymentController` antes de la sesión de Cobro, #7).
2. `Order` (TS) no tenía el campo `table?: Table` — se agregó en
   `resources/js/types/models.ts` (`OrderItem.menu_item?` ya existía).
3. `AppSidebar.vue` mostraba "Mesas" para cualquier rol, sin importar que
   `role:admin,mesero` en `routes/tenant.php` le devuelve 403 a `cocina`.
   Se hizo el nav consciente de rol (`Mesas` para admin/mesero, `Cocina`
   para admin/cocina, incluyendo el logo-link) — sin esto, un cocinero que
   entrara a `/dashboard` (redirect genérico de Fortify tras login, sin
   relación con esta spec) no tenía ninguna forma de llegar a `/cocina`
   desde la UI.
4. Wayfinder ya estaba generado con `--with-form` para `cocina.*` desde una
   sesión anterior — no hizo falta regenerar (trampa de `.ai/rules/js.md`
   no aplicó esta vez).

**Verificado con:** `tests/Feature/CocinaKdsTest.php` (7 tests, incluye 2
nuevos para `completedOrders` + aislamiento de tenant F-05), la suite
completa (`php artisan test --compact`, 169 tests / 165 passed / 4
skipped), `npm run lint:check`, `npm run types:check`, y verificación
visual manual en browser real (Chrome real vía extensión, no el preview
sandbox — mismo motivo que #7): enviar un pedido a cocina desde Toma de
Pedido, verlo aparecer en `/cocina` con sus ítems y "2 min" transcurridos,
marcar un ítem individual (badge "Listo" reemplaza el botón), marcar
"Listo (orden)" sobre el resto → la orden desaparece de la lista activa y
aparece en "Completadas" con badge verde "Lista"; y el caso de urgencia
(orden sintética con `opened_at` a 20 min → tarjeta y badge en rojo).
Usuario de prueba: se creó `cocina.qa@demo.test` (rol `cocina`) por el
mismo motivo que `mesero.qa` en #7 (sin la contraseña real de Ana Admin);
se reutilizó `mesero.qa@demo.test` ya existente (password reseteada a
`password`, es una cuenta de prueba, no una cuenta real). Con esta
pantalla se completan las 8 pantallas Must del inventario
(`_ai/design/screen-inventory.md`).

### 2026-08-12 — Corrección de alcance: solo 4 de 10 pantallas Must tenían Vue, no 8

**Estado:** 🟢 Resuelta (corrección de estado) — ver `_ai/CONTEXT.md`
**Contexto:** el prompt de arranque de esta sesión daba por hecho que "las 8
pantallas Must del inventario" estaban completas (heredado de la nota de
cierre de la entrada anterior, 2026-08-12/Cocina KDS). Verificado contra
`_ai/design/screen-inventory.md`: la columna Stack solo tiene ✅ en 4 filas
(Mapa de Mesas, Toma de Pedido, Cocina KDS, Cobro) — las 4 pantallas de los
specs #4/#5/#6/#7. Las otras 6 filas Must (Login, Gestión de menú, Gestión
de staff, Gestión de mesas, Calendario de reservas, Nueva reserva) seguían
⬜. Lo que sí estaba completo eran los **9 specs backend** (#0-#8).
**Decisión (confirmada con el usuario, `AskUserQuestion`):** completar las
pantallas Vue Must que faltan, una por sesión (mismo patrón que #1-#7),
empezando por Gestión de Menú. `_ai/CONTEXT.md` actualizado con el estado
real. Ver [[spec-relay-workflow]] y `decision-log-habit` (verificar antes
de afirmar que algo está completo).

### 2026-08-12 — Pantalla Vue de Gestión de Staff (#3)

**Estado:** 🟡 Implementada, verificación E2E en browser pendiente (herramienta no disponible en sesión)
**Contexto:** pantalla CRUD de cuentas de staff (`resources/js/pages/staff/Index.vue`). Backend ya existía completo (spec #3, Implemented, 12 tests Pest). Sin `AskUserQuestion` de PASO 0: no había ambigüedad de contrato.

**Decisiones de diseño (no ambiguas, criterio de consistencia con el resto de la app):**
- Lista plana (sin agrupación — no hay categorías en staff, a diferencia de Menú), con nombre, email, badge de rol y badge de estado activo/inactivo.
- Tres acciones: "Nueva cuenta" (diálogo con useForm — name, email, password, role), "Editar rol" (diálogo con useForm — solo role), "Desactivar" (diálogo de confirmación con `router.patch` — no es reversible desde UI, mismo criterio de "destructivo" ya aplicado en Gestión de Mesas).
- `Select` de reka-ui (`mesero`/`cocina`) para campo `role` en ambos diálogos — mitiga el trap de `abort(422, ...)` para `InvalidStaffRoleException`: al ofrecer solo los dos valores válidos, ese path se vuelve inalcanzable desde la UI.
- Botón "Desactivar" solo visible para cuentas activas (`v-if="member.is_active"`).
- Nav: se agregó "Staff" a `AppSidebar.vue` con ícono `Users` de lucide, visible solo para `admin` (mismo patrón que "Menú").

**Plomería de worktree — no ambigua, documentada aquí:**
El worktree en `/Users/edgarrealmorales/orca/workspaces/restaurante-os/gestion-staff` no tiene `vendor` ni `.env` (gitignoreados, existen solo en `/Users/edgarrealmorales/Herd/restaurante-os`). Los archivos de Wayfinder (`resources/js/routes/*`, `actions/*`, `wayfinder/*`) también están gitignoreados y no existían en el worktree. Solución: symlinks `vendor` → main y `.env` → main, más copiar los directorios gitignoreados del main al worktree. El build además requiere PHP 8.5 en PATH (`/tmp/php85-bin/php` → `php85`) porque el plugin Wayfinder de Vite corre `php artisan` al compilar y el `php` del PATH del shell es 8.1.

**Verificado con:** `npm run lint:check` (0 errores), `npm run types:check` (0 errores), `npm run build` (✓ 3254 modules, build exitoso con PHP 8.5 en PATH), tests Pest 12/12 (`php artisan test --compact --filter=GestionStaff`, corrido desde `/Users/edgarrealmorales/Herd/restaurante-os`).
**No verificado — bloqueado por herramienta:** verificación visual en browser real. Las herramientas `mcp__claude-in-chrome__*` y `mcp__Claude_Browser__*` no estaban disponibles en esta sesión. Pendiente para la próxima sesión de frontend: verificar render de lista, crear cuenta, editar rol y desactivar.

### 2026-08-12 — Pantalla Vue de Gestión de Menú (#2)

**Estado:** 🟡 Implementada, verificación E2E en browser incompleta
**Contexto:** primera pantalla CRUD (crear/editar, no solo lectura ni
selección) del proyecto — `resources/js/pages/menu/Index.vue`. Backend ya
existía completo (spec #2, Implemented). Sin `AskUserQuestion` de PASO 0:
no había ambigüedad de contrato, todo el trabajo fue de UI sobre endpoints
ya probados.
**Decisiones de diseño (no ambiguas, criterio de consistencia con el resto
de la app):**
- Lista agrupada por categoría (mismo patrón que el menú de selección en
  `Pedido.vue`), con `Editar` (abre diálogo) y `Desactivar`/`Activar`
  (`router.patch` directo, sin diálogo — reversible y de un tap, como
  `adjustQuantity` en Pedido.vue).
- Dos diálogos (`Dialog` de reka-ui) con `useForm`, no el componente
  `<Form>` declarativo que usan las páginas de `settings/*` del starter
  kit — los diálogos necesitan `onSuccess` para cerrarse y limpiar estado,
  que `useForm` da directo; ningún otro dominio del proyecto usa `<Form>`
  todavía.
- `<datalist>` con las categorías ya existentes, enlazado a ambos inputs
  de categoría — mitiga (sin normalizar) el riesgo ya documentado en
  `gestion-menu.spec.md` de categorías duplicadas con distinta
  capitalización.
- Nav: se agregó "Menú" a `AppSidebar.vue`, visible solo para `admin`
  (único rol con acceso a `/menu`, `role:admin` en `routes/tenant.php`).
**Verificado con:** `tests/Feature/GestionMenuTest.php` (9/9), `npm run
lint:check`, `npm run types:check`, y una verificación visual parcial en
browser real (`demo.localhost:8000`, login como `admin.qa@demo.test` —
cuenta de prueba nueva, mismo motivo que `mesero.qa`/`cocina.qa`: sin la
contraseña real de Ana Admin): la lista renderiza agrupada por categoría y
el diálogo "Nuevo platillo" abre con los campos correctos, confirmado por
captura de pantalla.
**No verificado — bloqueado por herramienta, no por el código:** el
click-through completo de crear/editar/alternar disponibilidad con eventos
de mouse reales. Los clics sintéticos del tool de automatización del
navegador no llegaban de forma confiable a algunos botones (un
`.click()` nativo vía JS sí abría el diálogo correctamente, confirmando
que el componente funciona) — mismo síntoma que el hallazgo abierto de la
sesión de Mapa de Mesas ("Hydration completed but contains mismatches" /
error `createProvider`, ya documentado ahí como deuda técnica de toda la
app, no introducida por esta pantalla). La extensión de Chrome se
desconectó a media verificación y no volvió a conectar en el resto de la
sesión, cortando el intento de aislar la causa. **Pendiente para la
próxima sesión de frontend:** (a) investigar la causa raíz del mismatch de
hidratación (sospecha original: script inline de `app.blade.php` que
aplica la clase `dark` antes de montar Vue, ver entrada de Mapa de Mesas);
(b) completar el click-through real de esta pantalla en cuanto la
extensión esté disponible.

### 2026-08-12 — Pantalla Vue de Gestión de Mesas (#1)

**Estado:** 🟢 Implementada y verificada
**Contexto:** backend ya existía completo (spec #1, Implemented,
`TableController` + `CreateTableAction`/`UpdateTableAction`/
`DeleteTableAction`). Sesión solo-Vue: `resources/js/pages/tables/Index.vue`.

**PASO 0 (confirmado antes de nombrar el archivo, sin ambigüedad final):**
- `TableController::index` ya llamaba `Inertia::render('tables/Index', …)`
  y las rutas ya estaban nombradas `tables.index`/`store`/`update`/`destroy`
  (prefix real `/mesas/gestion`, ver `routes/tenant.php`) — el archivo
  correcto era `resources/js/pages/tables/Index.vue`, no `mesas/gestion/`.
- El link roto documentado en `resources/js/pages/mesas/Index.vue:79`
  (`tablesIndex()` → `ViteException`, componente inexistente) queda
  resuelto por esta sesión sin tocar ese archivo: ahora `tables/Index.vue`
  existe y el componente resuelve.
- `resources/js/routes/tables/*` y los tipos `Table`/`TableStatus` en
  `resources/js/types/models.ts` ya existían (generados/escritos en la
  sesión backend de este spec) — no hizo falta `wayfinder:generate`.

**Decisiones de diseño (no ambiguas, criterio de consistencia con
`menu/Index.vue`, la pantalla CRUD de referencia):**
- Lista plana (no agrupada — a diferencia de Menú por categoría, Mesas no
  tiene una dimensión de agrupación natural), con `Editar` (diálogo,
  mismo patrón `useForm`) y badge de `status` de solo lectura (Libre/
  Ocupada/Por cobrar, mismas etiquetas que `mesas/Index.vue`) para que el
  admin entienda por qué una mesa no se puede eliminar.
- `Eliminar` sí lleva diálogo de confirmación (a diferencia de alternar
  disponibilidad en Menú, que no lo necesita por ser reversible) — es
  destructivo e irreversible desde esta pantalla aunque el modelo use soft
  delete.
- Botón `Eliminar` deshabilitado cuando `table.status === 'ocupada'`: se
  verificó la correlación real en las Actions de `Order`
  (`OpenOrReuseOrderForTableAction` pone `ocupada` para las órdenes
  `abierta`/`enviada_cocina`, exactamente las que bloquea
  `DeleteTableAction`; `RequestBillAction` mueve a `por_cobrar` cuando la
  orden ya no está en esos dos estados) — deshabilitar en `ocupada` evita
  el caso común del error 422 sin tocar `por_cobrar`, que sí es eliminable.
- Nav: se agregó "Gestión de Mesas" a `AppSidebar.vue` (icono `Table2`),
  visible solo para `admin`, junto a "Menú".

**Trap encontrado, no corregido (fuera de alcance — backend ya
"completo" por instrucción de la sesión):** `TableController::destroy`
usa `abort(422, $exception->getMessage())` para "mesa con cuenta activa",
igual que `StaffController` y `ReservationController`. Es el mismo patrón
que el comentario de `OrderController::addItem` (líneas 77-84) ya señala
como problemático: un `abort()` plano no trae el header `X-Inertia`, así
que el cliente Inertia lo trata como respuesta no-Inertia y muestra un
modal con HTML crudo en vez del mensaje del spec — `ValidationException`
sí viaja por el mecanismo que Inertia espera. Mitigado en esta pantalla
deshabilitando el botón en el caso común (ver arriba) más un mensaje de
respaldo genérico en el `onError` del `router.delete` para el residual.
**Sin corregir en el backend por instrucción explícita de "sesión
solo-Vue, backend ya existe completo".** Relevante para las próximas
sesiones de Gestión de Staff y Reservas: mismo patrón `abort(422, …)` sin
corregir ahí tampoco — si se prioriza, cambiar los tres controllers a
`ValidationException::withMessages(...)` en un pase de backend dedicado
sería más consistente que seguir mitigando por pantalla.

**Verificado con:** `php artisan test --compact --filter=GestionMesas`
(8/8, sin cambios — backend intacto), `npm run lint:check`, `npm run
types:check`, y **click-through completo en browser real** (sandbox
preview del harness, no la extensión de Chrome — `claude-in-chrome` no
estaba conectada en esta sesión y no reconectó tras reintentar; el
preview sí alcanzó `demo.localhost:8000` esta vez, a diferencia de lo
asumido en `browser-verification-setup` de memoria) con `admin.qa@demo.test`
(cuenta de prueba reutilizada de la sesión de Gestión de Menú, password
reseteada a `password`): crear "Mesa Terraza 5" (aparece con `Libre`),
editar su capacidad (4→7 refleja en la lista), eliminarla con el diálogo
de confirmación (desaparece de la lista), y confirmar que "Eliminar" no
responde al clic en una mesa `Ocupada` (Mesa 1). Sin errores en consola.
Con esta pantalla son 6 de 10 Must del inventario con Stack ✅.
