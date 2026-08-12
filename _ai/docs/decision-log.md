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

**Verificado con:** `npm run lint:check` (0 errores), `npm run types:check` (0 errores), `npm run build` (✓ 3254 modules), tests Pest 12/12, y verificación visual manual en browser real (`http://demo.restaurante-os.test/staff`, login con `admin.qa@demo.test / password`): lista renderiza, diálogo "Nueva cuenta" abre con campos correctos, "Editar rol" muestra select mesero/cocina, "Desactivar" muestra diálogo de confirmación. ✅ Spec cerrado.

**Fix adicional descubierto al verificar con Herd nginx:** `asset_helper_tenancy => true` en `config/tenancy.php` hacía que `@vite` generara URLs `/tenancy/assets/build/assets/...` → 404. Este proyecto no tiene assets por tenant; cambiado a `false`. Aplica a toda la app — las sesiones anteriores no lo detectaron porque usaban `composer run dev` (dev server de Vite, no pasa por el asset helper de tenancy).

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

**🟢 Resuelto (2026-08-12, REDEV-32):** `TableController::destroy` y
`StaffController` (`store` y `update`, dos ocurrencias) ya cambiaron a
`ValidationException::withMessages(['name' => ...])` /
`['role' => ...]` — mismo patrón que `OrderController`/
`PaymentController`/`ReservationController`/`InventarioController`.
`tests/Feature/GestionMesasTest.php` y `GestionStaffTest.php` se
ajustaron primero a `deleteJson`/`postJson`/`patchJson` +
`errors.<campo>.0` (antes solo comprobaban `assertStatus(422)`, que no
detectaba el bug porque `abort(422, ...)` también devuelve ese status —
confirmado en rojo antes del fix). Verificado además en browser real
(`demo.localhost:8000`) con requests directos (`fetch` con
`X-XSRF-TOKEN`) contra una mesa con orden `abierta` y un intento de
`role=admin`: ambos devuelven `422` con `errors.<campo>` estructurado, ni
la mesa se eliminó ni la cuenta se creó.

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

### 2026-08-12 — Pantalla Vue de Login (#1)

**Estado:** ✅ Implementada y verificada (sin browser real — ver nota)
**Contexto:** `resources/js/pages/auth/Login.vue` ya existía completo
(starter kit de Fortify), pero seguía ⬜ en `screen-inventory.md` porque
todo su copy estaba en inglés ("Log in to your account", "Email
address", etc.) mientras el resto de la app está en español. Verificado
antes de tocar código: ningún test en `tests/Feature/Auth/` referencia el
copy en inglés, solo comportamiento — traducir era seguro.
**Decisión de alcance (confirmada con el usuario, `AskUserQuestion`):**
"Traducir + alinear branding visual", no solo traducción de copy. Implicó:
- Traducir todos los strings de `Login.vue` (título, descripción, labels,
  placeholders, botón, `<Head title>`) a español, tono consistente con el
  resto de la app.
- Cambiar `AuthLayout.vue` (el wrapper que Inertia aplica a toda página
  bajo `auth/*`, ver `resources/js/app.ts`) de `AuthSimpleLayout.vue` a
  `AuthCardLayout.vue` — ambos ya existían en el starter kit, sin
  construir nada nuevo. `AuthCardLayout` envuelve el formulario en un
  `Card` (mismo componente que usan Cocina/Cobro/Pedido), dando el look
  boxed consistente con el resto de la app en vez del div plano de
  `AuthSimpleLayout`. Los tokens de color/tipografía (acento terracota
  `#98430b`, Work Sans) ya se heredaban automáticamente vía
  `resources/css/app.css` — es CSS global cargado en todo `app.blade.php`
  sin importar la ruta, así que **no** hacía falta tocar nada para que el
  botón/inputs ya salieran con el acento correcto; el único cambio real de
  branding fue el layout boxed.
- **Efecto colateral aceptado:** `AuthLayout.vue` es compartido con
  Register, ForgotPassword, ConfirmPassword, ResetPassword — el cambio de
  layout (look boxed) se aplica también a esas pantallas, aunque su copy
  (en inglés) no se tocó, sigue fuera de alcance de este spec.
**Verificado con:**
- `npm run lint:check` y `npm run types:check` — limpios (tras regenerar
  Wayfinder con `php artisan wayfinder:generate --with-form
  --no-interaction`, ver `.ai/rules/js.md` — el worktree no traía
  `resources/js/routes`/`actions` generados).
- `php artisan test --compact --filter=Authentication` — 8/9 (1 skip
  preexistente por `skipUnlessFortifyHas`, no relacionado a este cambio).
- **Verificación visual real bloqueada:** la extensión de Chrome
  (`claude-in-chrome`) no conectó, y a diferencia de sesiones anteriores
  (ver `browser-verification-setup` en memoria) no había un preview
  sandbox disponible como fallback en este entorno. Verificación
  alternativa por HTTP/curl contra `demo.localhost:8000` (tenant demo
  onboardeado en este worktree vía `tenants:onboard`, dominio corregido a
  `demo.localhost` porque el comando lo guardó como `demo` a secas):
  el HTML servido por SSR de Inertia confirma los strings en español
  (`Iniciar sesión`, `Correo electrónico`, `Contraseña`,
  `¿Olvidaste tu contraseña?`, `Recordarme`) y el wrapper `Card`
  (`data-slot="card"`); un login real por POST con `admin.qa@demo.test` /
  `password` devolvió sesión autenticada (confirmado con `GET /dashboard`
  → 200 con la cookie de sesión); el link "¿Olvidaste tu contraseña?"
  apunta a `href="/forgot-password"`, la ruta correcta. No hay
  confirmación pixel-a-pixel del render — pendiente si se recupera la
  extensión de Chrome o un preview sandbox en una sesión futura.
- **Nota de higiene del entorno (revertida, no forma parte del commit):**
  para levantar `composer run dev` en este worktree, `pnpm run dev` falló
  por scripts de instalación bloqueados (`unrs-resolver`, `vue-demi`) y
  casi se resuelve poniendo `allowBuilds: true` en `pnpm-workspace.yaml`
  — eso habría revertido la protección intencional
  `ignore-scripts=true` de `.npmrc` (documentada en
  `.ai/rules/general.md` como el control #1 contra npm-worms). Se
  revirtió antes de verificar nada y no se usó para la verificación de
  arriba (que fue por HTTP directo, sin `pnpm run dev`/HMR). Dejar
  anotado por si otra sesión se topa con el mismo bloqueo: requiere
  decisión explícita del usuario, no bypass automático.

Rama `login` (worktree Orca, no se creó el worktree `feature/login-vue`
que pedía el spec original porque Orca ya había aislado la sesión en
`orca/workspaces/restaurante-os/login`). Merge a `main` hecho a pedido
explícito del usuario en esta misma sesión, después de la verificación de
arriba — ver commit de merge en `main` para el resumen de los conflictos
de docs resueltos (`CONTEXT.md`, este archivo) contra el trabajo en
paralelo de Gestión de Mesas y Gestión de Staff, ya mergeados antes.

### 2026-08-12 — PASO 0 de la pantalla Vue de Reservas (#8, filas #6/#7 del inventario)

**Estado:** 🟢 Resuelta — implementada en `resources/js/pages/reservas/Index.vue`,
branch `Reservas` (worktree propio), mergeada a `main`.
**Contexto:** sesión solo-Vue, backend ya completo (spec #8, Implemented).
Dos decisiones de PASO 0 confirmadas antes de tocar el primer componente:

1. **Fusión #6/#7 del inventario.** El inventario original
   (`screen-inventory.md`) lista "Calendario de reservas" (#6) y "Nueva
   reserva" (#7) como filas separadas, pero `ReservationController` solo
   tiene `index`+`store`, ambos renderizando `reservas/Index` — es una
   sola pantalla. El formulario de nueva reserva vive en un diálogo
   (`Dialog` + `useForm`), mismo patrón que "Nuevo platillo" en
   `menu/Index.vue`. `screen-inventory.md` actualizado: #7 marcada como
   fusionada en #6, ambas ✅ en Stack.
2. **Manejo de errores de fecha pasada — mismo trap ya documentado en
   #3/#7 (Toma de Pedido/Cobro).** `ReservationController::store` usaba
   `abort(422, $exception->getMessage())` para `PastReservationException`,
   sin probar contra un cliente Inertia real — mismo bug de plomería que
   `OrderController`/`PaymentController` antes de su fix. Corregido a
   `ValidationException::withMessages(['reserved_at' => ...])` antes de
   escribir el primer componente Vue (anticipado por el prompt de arranque
   de esta sesión). Ajustado el test correspondiente en
   `ReservasTest.php` (`errors.reserved_at.0` en vez de `message`, mismo
   criterio que `CobroTest`/`TomaDePedidoTest`).

**Otro cambio de plomería, no ambiguo (documentado aquí, no preguntado):**
`ReservationController::index` no pasaba la lista de mesas — necesaria
para el selector opcional de mesa del diálogo. Se agregó
`'tables' => Table::query()->orderBy('name')->get()`, mismo patrón que
`TableController::index`.

**Bug encontrado y corregido en verificación manual: desfase de huso
horario en la hora mostrada.** `APP_TIMEZONE=UTC` (`config/app.php`) +
`<input type="datetime-local">` (valor naive, sin zona) hacen que el
servidor guarde la hora tal cual se tipeó, interpretada como UTC. Al
mostrarla, `new Date(reserved_at).toLocaleTimeString()` sin fijar
`timeZone` hace que el navegador reinterprete esos dígitos en la zona
horaria *local del viewer* — se tipeó 8:00 p.m. y la lista mostraba
1:00 p.m. en un navegador con otro huso que el servidor. Corregido
fijando `timeZone: 'UTC'` en el formateador, para que se muestren los
mismos dígitos que se guardaron (ver comentario en `formatTime`,
`reservas/Index.vue`). Aplicaría a cualquier pantalla futura que muestre
un datetime capturado por el usuario vía `datetime-local` bajo el mismo
`APP_TIMEZONE=UTC`.

**Primer uso del componente `resources/js/components/ui/select` en el
proyecto** (selector opcional de mesa) — confirmado funcional (reka-ui),
con sentinel `"sin-mesa"` porque `SelectItem` no acepta `value=""`,
convertido a `null` en `createForm.transform()` antes de enviar.

**Entorno de este worktree (Orca), no del código de la app:**
- El worktree se creó con `node_modules` ya poblado pero `vendor` vacío;
  `composer install` sí hizo falta, `npm install` no (ver corrección del
  usuario en esta sesión — no asumir que un worktree nuevo necesita
  reinstalar todo sin verificar primero).
- Se encontró y limpió un `pnpm-lock.yaml` suelto (no rastreado) +
  `pnpm-workspace.yaml` corrupto (bloque `allowBuilds` con placeholder sin
  resolver, `"set this to true or false"`) que hacían que `php artisan
  dev` detectara `pnpm` en vez de `npm` (el proyecto usa npm, ver
  `.ai/rules/general.md`) y fallara con `ERR_PNPM_IGNORED_BUILDS`. No es
  un archivo del proyecto — probablemente un `pnpm install` corrido por
  error antes de esta sesión. `pnpm-workspace.yaml` revertido a su versión
  commiteada, `pnpm-lock.yaml` borrado.
- `.env` de este worktree = copia del `.env` de `~/Herd/restaurante-os`
  (main), con `database/database.sqlite` symlinkeado al sqlite real de
  main (indicación explícita del usuario: "ya está conectado a una DB").
  Los datos de prueba creados durante la verificación manual (reservas
  "Ana Pérez", "Carlos Ruiz") se borraron al terminar para no ensuciar la
  DB compartida.
- `demo.restaurante-os.test` (Herd, sirve `~/Herd/restaurante-os`) **no
  sirve el código de este worktree** — se confirmó con un 500
  (`ViteException: Unable to locate file... reservas/Index.vue`) que main
  no tiene este trabajo. Verificación real se hizo contra
  `demo.localhost:8000`, con `php artisan dev` corriendo en este worktree
  (mismo patrón que #3/#5/#7). `main` además ya avanzó con merges de las
  sesiones `login`/`gestion-staff` que este branch no tiene.

**Verificado con:** `tests/Feature/ReservasTest.php` (7/7), suite completa
(`php artisan test --compact`, 169 tests / 165 passed / 4 skipped), `npm
run lint:check`, `npm run types:check`, y verificación visual manual en
browser real (`demo.localhost:8000`, login `admin.qa@demo.test`): crear
reserva con mesa asignada, crear reserva sin mesa (aparece "Sin mesa
asignada"), reserva de hoy aparece en la lista y una de fin de año no
(confirma el filtro `whereDate` sin selector de fecha), fecha pasada
rechazada con banner inline (`InputError`, no modal crudo), light y dark
mode. Sin errores en consola.

Merge a `main` hecho a pedido explícito del usuario en esta misma sesión,
después de la verificación de arriba. Conflicto anticipado (y confirmado)
en `AppSidebar.vue` y 2 de los 3 archivos de `_ai/` (`CONTEXT.md`,
`decision-log.md` — `screen-inventory.md` se fusionó solo) contra el
`main` actual, ya adelantado por `login`/`gestion-staff`; resuelto
combinando ambos lados (ítem de nav "Reservas" + "Staff"/"Gestión de
Mesas" en `AppSidebar.vue`, entradas de `decision-log.md` concatenadas en
orden cronológico, conteo de pantallas Must en `CONTEXT.md` actualizado a
9/9).

### 2026-08-12 — PASO 0 de División de Cuenta (US-3.2, #12): mecanismo de split
**Estado:** 🟢 Resuelta — implementada en `division-de-cuenta.spec.md` (#12),
2026-08-12
**Contexto:** el PRD deja abierto el mecanismo de división ("puede asignar
ítems o montos a pagos independientes — se valida en piloto"). `Payment` ya
es 1:N respecto a `Order` desde el día uno específicamente para esto (nota de
alcance en `_ai/docs/data-model.md`), pero no hay UI ni Action que lo use.
**Opciones evaluadas (`AskUserQuestion`):** (a) split por monto libre —
registrar N pagos con un monto cada uno hasta cubrir el total, sin UI de
selección de ítems; (b) split por ítems — asignar cada `OrderItem` a un
"grupo de pago" y que el sistema calcule el monto de cada grupo; (c) ambos,
(a) en esta sesión y (b) documentado como brecha.
**Decisión:** (a), split por monto libre. (b) queda documentado como brecha
en `division-de-cuenta.spec.md` — mismo criterio que las transiciones
sentada/cancelada de `Reservation` (ver entrada de Reservas más abajo): sin
modelo de "grupo de pago" ni UI de selección de ítems, extensión futura sobre
el `Payment` 1:N ya existente si el piloto lo pide.

**Decisión de arquitectura (misma sesión, no preguntada — el prompt de
arranque delegó explícitamente esta decisión a la sesión):** para no romper
`CloseOrderAction` (Must-have, #7, en producción), se evaluó (1) modificar
`CloseOrderAction` directamente para comparar suma-de-pagos vs. total, o (2)
agregar una Action nueva que `CloseOrderAction` reutiliza para el caso de
pago único. Elegida (2) con un ajuste mínimo a `CloseOrderAction`:
`AddPaymentToOrderAction` (nueva) registra un pago sin rechazar nunca por
insuficiencia y cierra la orden + libera la mesa solo cuando
`SUM(payments.amount) >= Order::total()`; `CloseOrderAction` pasó de
comparar `amount < total` a comparar `(pagos ya registrados + amount) <
total` — con cero pagos previos (el caso de **todos** los tests existentes
de #7) es matemáticamente idéntico, así que `CloseOrderActionTest.php` y
`CobroTest.php` quedaron en verde **sin modificarlos** (verificado con
`git diff --stat` antes de cerrar la sesión). `Order::total()` se extrajo
como método del modelo (antes vivía inline en `CloseOrderAction`) para que
ambas Actions compartan la misma fuente de verdad. Ruta nueva `POST
/mesas/{table}/cobro/pagos`, mismo middleware que `cobro.show`/`cobro.close`.

**Bug encontrado en verificación visual (no en Pest — los tests de backend no
ejercitan la reactividad de Vue):** tras un pago parcial, Inertia recarga las
props de la misma instancia de `Cobro.vue` en vez de remontarla, así que el
campo "Monto recibido" se quedaba con el valor del pago recién registrado en
vez de reflejar el nuevo saldo pendiente. Corregido con
`watch(saldoPendiente, ...)` que resetea `amount` cuando cambia el saldo.

**Verificado con:** `tests/Unit/Actions/Orders/AddPaymentToOrderActionTest.php`
(7/7, nuevo), `tests/Feature/DivisionDeCuentaTest.php` (8/8, nuevo),
`tests/Feature/CobroTest.php` + `tests/Unit/Actions/Orders/CloseOrderActionTest.php`
(12/12, #7, sin modificar), suite completa (184 tests / 180 passed / 4
skipped preexistentes / 0 fallos), `npm run lint:check`, `npm run
types:check`, y verificación visual en browser real: mesa/orden de prueba
dedicada (creada y borrada por tinker, sin tocar mesas de otras sesiones en
la DB compartida), dos pagos parciales ($50 + $80 sobre una cuenta de
$130.00) cerraron la orden y liberaron la mesa, el flujo de un solo pago
(sin dividir) se ve idéntico al de #7 (mismo botón "Confirmar pago ·
$130.00", sin sección de historial), sin errores en consola.

**Entorno de este worktree (Orca), no del código de la app:** mismo `pnpm
install` accidental que en la sesión de Reservas — `pnpm-workspace.yaml`
revertido, `pnpm-lock.yaml` borrado. `.env` copiado de `~/Herd/restaurante-os`
+ `database/database.sqlite` symlinkeado al sqlite compartido (mismo patrón
que sesiones anteriores). Puerto 8000 ocupado por la sesión concurrente de
Inventario (confirmado con `lsof`) — verificación visual hecha en
`demo.localhost:8001` vía `php artisan serve --port=8001` sobre assets ya
compilados (`npm run build`), no `composer run dev`.

No se hizo merge a `main` en su momento — `feature/split-bill` quedó lista
para revisión manual y se mergeó después (ver commit de merge en `main`).

### 2026-08-12 — PASO 0 de `inventario.spec.md` (#9, US-5.1/US-5.2, primera
feature Should Have): gap de alta de insumos, nombre de componente y
alcance de autorización

Primera sesión sin backend previo del dominio (worktree `feature/inventario`
aislado, mismo patrón de las sesiones anteriores). Decisiones de PASO 0,
documentadas en el spec y aquí:

- **Gap: no existe operación de alta de insumos** ni en el PRD ni en
  `api-contract.yaml` — solo `GET /inventario` (listar) y
  `POST /inventario/{item}/ajustar` (ajustar). Sin una forma de crear el
  primer `InventoryItem` la pantalla no sirve el día uno, mismo problema
  que tuvo Mapa de Mesas sin Gestión de Mesas (US-6.3). Se agregó
  `POST /inventario` (alta simple: `name`, `unit`, `low_stock_threshold`,
  `quantity_on_hand` inicial opcional, default 0) — mismo criterio que la
  nota "US-6.3 no estaba en el PRD original" en `spec-registry.md`.
- **Nombre de componente `Inventario/Index` con mayúscula inicial** —
  confirmado literalmente en `x-inertia-component` de `api-contract.yaml`
  para ambas rutas del contrato original, a diferencia de todos los demás
  dominios (`mesas/Index`, `menu/Index`, `tables/Index`, etc., en
  minúscula). Se respetó el contrato tal cual: archivo en
  `resources/js/pages/Inventario/Index.vue`,
  `Inertia::render('Inventario/Index', ...)`.
- **Una sola pantalla**: el ajuste de stock (US-5.2) es un diálogo dentro
  del índice, no una ruta/pantalla separada — mismo patrón que Reservas
  (#6/#7) y Gestión de Menú. `screen-inventory.md` #11 se fusiona en #10.
- **Autorización exclusiva `role=admin`**, sin compartir con
  mesero/cocina (a diferencia de Mesas/Cobro/Reservas) — el PRD (US-5.1,
  US-5.2) solo menciona admin en ambas historias.
- **`quantity_on_hand` excluido de `$fillable`** (mismo patrón que
  `Table.status`/`MenuItem.available`, ver `.ai/rules/actions.md`): solo
  se muta vía `forceFill()` desde `CreateInventoryItemAction` (valor
  inicial) y `RegisterInventoryMovementAction` (ajustes), nunca desde
  `$request->validated()` directo.
- **`InsufficientStockException`** (`app/Exceptions/Inventory/`, mismo
  patrón que `InsufficientPaymentException`): una `salida` que dejaría
  `quantity_on_hand` negativa se rechaza con
  `ValidationException::withMessages(['quantity' => ...])` (no
  `abort(422, ...)` — mismo trap ya documentado en OrderController/
  PaymentController/ReservationController, el header `X-Inertia` se
  pierde con `abort()` plano).

**Backend (TDD):** migraciones `inventory_items`/`inventory_movements`
(`InventoryMovement` sin `tenant_id` propio, hereda aislamiento vía
`InventoryItem`, mismo patrón que `Payment`↔`Order`); modelos con
`BelongsToTenant`/casts `decimal:3`; `InventoryItemPolicy` (mismo patrón
que `TablePolicy`); `CreateInventoryItemAction` +
`RegisterInventoryMovementAction`; `InventarioController`; rutas en
`routes/tenant.php` bajo `role:admin`. Tests escritos primero (rojo
confirmado: `Route [inventario.index] not defined`, `Class
"App\Actions\Inventory\CreateInventoryItemAction" not found`), luego
implementación (verde: 8 Feature + 14 Unit, suite completa 187/187 con 4
skipped preexistentes).

**Trap del entorno reencontrado** (ya documentado en
`.ai/rules/migrations.md` y en la sesión de Reservas): el
`database/database.sqlite` que sirve `demo.localhost:8000` vía
`composer run dev` es un symlink al sqlite de `~/Herd/restaurante-os`
(main), compartido entre worktrees — no se actualiza con las migraciones
nuevas hasta correr `php artisan migrate --no-interaction` explícitamente
contra él (Pest usa `RefreshDatabase`, una BD de test separada). Primer
login tras `composer run dev` dio `SQLSTATE[HY000]: ... no such table:
inventory_items`; corregido migrando el sqlite compartido antes de la
verificación visual.

**Worktree Orca ya provisto** en `feature/inventario` con `node_modules`
parcialmente poblado y `vendor` vacío (mismo patrón que sesiones previas):
`composer install` sí hizo falta, `npm install` también (solo 32
paquetes de node_modules antes de instalar). Mismo `pnpm-workspace.yaml`
corrupto (`allowBuilds` con placeholder sin resolver) y `pnpm-lock.yaml`
suelto ya documentados en la sesión de Reservas — revertido/borrado antes
de `npm install`. Se permaneció en la rama `Inventario` provista por Orca
(no se creó `feature/inventario` con `git worktree add` manualmente, ya
existía la misma isolación vía el worktree preexistente).

**Verificación visual en browser real** (`demo.localhost:8000`, login
`admin.qa@demo.test`): crear insumo "Tomate" (20 kg, umbral 5 kg) → aparece
en la lista sin resaltado; salida de 25 kg (> stock) → rechazada con
banner inline "No hay stock suficiente de 'Tomate' para esta salida
(disponible: 20.000 kg)." (no modal crudo); salida de 15 kg → stock baja a
5 kg, exactamente el umbral, resaltado ámbar "Bajo el umbral"; salida de 5
kg adicional → stock en 0, resaltado rojo "Sin stock"; confirmado en light
y dark mode (forzado vía JS, ver nota abajo). Sin errores en consola. Datos
de prueba borrados al terminar (mismo criterio que sesiones previas, DB
compartida).

**Bug de automatización de browser encontrado durante la verificación**
(no relacionado con el código de la app): el trigger del diálogo "Nuevo
insumo" y el `<Select>` de "Tipo" no respondieron a un primer clic
sintético — mismo síntoma que el mismatch de hidratación ya documentado
("un `.click()` nativo sí funciona"). Adicionalmente, la extensión de
Chrome del entorno quedó en un estado roto (`Cannot access a
chrome-extension:// URL of different extension`) varias veces durante la
sesión, requiriendo recrear el tab/grupo de tabs repetidamente — no
reproducible con acciones de usuario real, exclusivo del entorno de
automatización.

**Verificado con:** `tests/Feature/InventarioTest.php` (8/8),
`tests/Unit/Actions/Inventory/*` (14/14), suite completa (`php artisan
test --compact`, 191 tests / 187 passed / 4 skipped preexistentes), `npm
run lint:check` (0 errores), `npm run types:check` (0 errores), `npm run
build` (✓), `vendor/bin/pint --dirty` (sin cambios), y verificación visual
manual en browser real descrita arriba.

No se hizo merge a `main` en su momento — `feature/inventario` quedó lista
para revisión manual, a pedido explícito del prompt de arranque de esa
sesión, y se mergeó a pedido explícito del usuario a continuación (ver
commit de merge en `main`).
