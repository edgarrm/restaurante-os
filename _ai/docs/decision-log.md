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
