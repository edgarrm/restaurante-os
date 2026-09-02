# Threat Model — restaurante-os

> Fecha: 2026-08-10 · Alcance: diseño (ADRs, data model, specs) + código
> existente (starter kit + scaffolding de Fortify/tenancy).
>
> **Esto no es un pentest de una aplicación corriendo** — no hay código de
> dominio implementado todavía. Es una revisión de diseño y del código que sí
> existe, hecha en el momento en que corregir es barato.
>
> Cada hallazgo indica cómo se verificó. Los marcados "verificado" se
> comprobaron ejecutando comandos contra este repo, no por inspección visual.

## Modelo de atacante

Para un SaaS de POS de restaurantes, los actores realistas son:

| Actor | Capacidad | Motivación |
|---|---|---|
| **Empleado de otro restaurante-cliente** | Credenciales válidas de SU tenant | Ver ventas/menú de un competidor alojado en la misma plataforma |
| **Empleado interno malicioso** | Credenciales válidas, acceso físico a la tablet | Robo de efectivo, anular cuentas, encubrir faltantes |
| **Persona con acceso físico al local** | Acceso a una tablet desatendida en el piso | Usar una sesión abierta de un mesero |
| **Atacante externo sin credenciales** | Solo red | Enumerar usuarios, fuerza bruta de login |

El primero es el que hace que multi-tenancy sea la superficie crítica: no es un
atacante hipotético, es **un cliente legítimo de tu propio producto**.

---

## F-01 — CRÍTICO: Bypass de autenticación entre tenants

**Estado:** 🟢 Resuelto (2026-08-11) · **Verificado**

Resuelto en `_ai/specs/onboarding-tenant.spec.md` (#0): `config('fortify.middleware')`
incluye ahora `InitializeTenancyByDomain` + `PreventAccessFromCentralDomains` +
`ScopeSessions` para las rutas propias de Fortify, y `routes/settings.php` se
movió al mismo grupo de middleware en `routes/tenant.php`. Detalle completo y
justificación en `decision-log.md`. Verificado con
`tests/Feature/OnboardingTenantTest.php` (tests explícitos de F-01: login
cross-tenant rechazado, motivo confirmado vía `DB::table('users')`).

Las rutas de autenticación de Fortify (`/login`, `/logout`,
`/forgot-password`, `/settings/*`) **no tienen ningún middleware de tenancy**.

Verificado con:
```
php artisan route:list --json   → tenancy-mw: NINGUNO en las 12 rutas de auth
config/fortify.php:104          → 'middleware' => ['web']
config/fortify.php:91           → 'domain' => null
```

`TenantScope` (leído en
`vendor/stancl/tenancy/src/Database/TenantScope.php`) empieza con:
```php
if (! tenancy()->initialized) {
    return;   // no aplica ningún filtro
}
```

**Consecuencia una vez que `users.tenant_id` exista** (como manda ADR-006 y el
data model): al hacer POST a `/login` en `restauranteA.restaurante-os.com`,
tenancy NO está inicializada, el scope no filtra, y `User::where('email',...)`
busca en **todos los tenants**. Un empleado del restaurante B se autentica
exitosamente en el subdominio del restaurante A, y a partir de ahí navega a
rutas de tenant que sí inicializan tenancy — quedando autenticado como usuario
de B dentro del contexto de A.

Esto no es teórico: es la consecuencia directa de la configuración actual más
el data model ya decidido.

**Mitigación requerida:** las rutas de auth deben resolverse dentro del
contexto del tenant. Opciones a evaluar (decisión pendiente, ver
`decision-log.md`):
- Mover las rutas de Fortify a `routes/tenant.php` con
  `InitializeTenancyByDomain`
- Configurar `config/fortify.php` → `'middleware'` para incluir el middleware
  de identificación
- Un guard de autenticación explícitamente tenant-aware

**Debe resolverse antes de implementar `onboarding-tenant.spec.md`**, porque
ese spec ya prueba login por subdominio.

---

## F-02 — ALTO: Sesiones no acotadas por tenant

**Estado:** 🟢 Resuelto (2026-08-11) · **Verificado**

`ScopeSessions` se agregó al grupo de middleware de `routes/tenant.php` y de
`config('fortify.middleware')` (mismo cambio que F-01). `SESSION_DOMAIN=null`
queda documentado como decisión explícita en los comentarios de ambos
archivos. Verificado con el test "F-02" de
`tests/Feature/OnboardingTenantTest.php` (una sesión válida del tenant A
reutilizada en el subdominio de B devuelve 403).

`stancl/tenancy` incluye el middleware `ScopeSessions`
(`vendor/stancl/tenancy/src/Middleware/ScopeSessions.php`), que guarda el
`tenant_id` en la sesión y aborta con 403 si una sesión se usa bajo otro
tenant. **No está en uso en ninguna ruta.**

Hoy `SESSION_DOMAIN=null`, lo que acota la cookie al host exacto — eso protege
por accidente, no por diseño. En el momento en que alguien ponga
`SESSION_DOMAIN=.restaurante-os.com` (algo que se hace habitualmente para
compartir sesión entre subdominios), **una sesión del restaurante A pasa a ser
válida en el subdominio del restaurante B**, sin ninguna defensa detrás.

**Mitigación:** agregar `ScopeSessions` al grupo de middleware de
`routes/tenant.php`, y fijar `SESSION_DOMAIN=null` explícitamente documentado
como decisión de seguridad, no como default accidental.

---

## F-03 — ALTO: Los pagos no registran quién cobró

**Estado:** 🟢 Resuelto (2026-08-12) · **Verificado**

Resuelto en `_ai/specs/cobro.spec.md` (#7): la tabla `payments` incluye
`collected_by` (FK → users, required, ver migración
`2026_08_12_011655_create_payments_table.php`). `CloseOrderAction` lo asigna
siempre desde el `User $collectedBy` tipado que le pasa el controller
(`$request->user()`) — nunca desde un campo del body, así que un
`collected_by` inyectado en el request se ignora por completo. Verificado
con `tests/Unit/Actions/Orders/CloseOrderActionTest.php` (caso "F-03: el
Payment creado tiene collected_by igual al usuario autenticado") y
`tests/Feature/CobroTest.php` (caso "F-03: un collected_by enviado en el
request es ignorado").

La entidad `Payment` de `_ai/docs/data-model.md` es:
`id, order_id, amount, method, paid_at` — **sin referencia al usuario**.

`InventoryMovement`, en el mismo documento, **sí** tiene `created_by`. La
inconsistencia delata que fue un olvido, no una decisión.

En un POS que maneja efectivo, esto significa que es imposible responder "¿qué
mesero tomó este pago?" — el control interno más básico contra el vector de
fraude más común en restaurantes. `Order` tiene `opened_by` pero tampoco
`closed_by`.

**Mitigación:** agregar `collected_by` (FK → users, required) a `Payment`.
Costo: una columna, ahora que no hay datos en producción. Retroadaptarlo
después de que el ancla lleve meses operando es mucho más caro, y los pagos
históricos quedarían sin atribución para siempre.

---

## F-04 — MEDIO: Escalación de privilegios por mass assignment

**Estado:** 🟡 Preventivo · **Verificado**

`app/Models/User.php` usa hoy
`#[Fillable(['name', 'email', 'password'])]` — `role` y `tenant_id` **no** son
asignables masivamente. El estado actual es seguro.

El riesgo es futuro y concreto: `gestion-staff.spec.md` requiere que el admin
asigne `role` al crear cuentas. La forma "obvia" de implementarlo es agregar
`role` a `Fillable` y hacer `User::create($request->validated())` — momento en
el cual un `role=admin` inyectado en el request produce escalación de
privilegios.

**Mitigación:** `role` y `tenant_id` nunca en `Fillable`. Asignarlos
explícitamente en la Action, nunca desde datos del request sin lista blanca.
`gestion-staff.spec.md` ya rechaza `role=admin`, pero eso es validación de un
valor — no protege contra el vector de mass assignment en sí.

---

## F-05 — MEDIO: IDOR entre tenants vía route model binding

**Estado:** 🟡 Preventivo

Rutas como `/mesas/{table}/pedido` resuelven el modelo por ID de la URL. La
protección depende **enteramente** de que `TenantScope` esté activo: si
`Table` usa `BelongsToTenant` y tenancy está inicializada, pedir la mesa 42 de
otro restaurante devuelve 404 en vez de sus datos.

Es correcto por diseño, pero hasta `gestion-mesas.spec.md` (#1) **ningún spec
lo probaba**. Es la clase de protección que se rompe silenciosamente si
alguien olvida el trait en un modelo nuevo, y nadie se entera hasta que un
cliente ve datos de otro.

`gestion-mesas.spec.md` (#1) es el primer spec implementado que incluye este
test (`tests/Feature/GestionMesasTest.php`, caso "F-05"): admin del tenant A
intenta editar/eliminar una mesa del tenant B → 404. Sigue siendo
**Preventivo**, no Resuelto: la mitigación es una disciplina por spec, no un
cambio de una sola vez — cada spec nuevo con rutas parametrizadas debe seguir
incluyendo su propio test.

**Mitigación:** cada spec con rutas parametrizadas debe incluir un test que
pida explícitamente un recurso de otro tenant y espere 404.

---

## F-06 — MEDIO: No existe spec del middleware de autorización por rol

**Estado:** 🟢 Resuelto (2026-08-11) · **Verificado**

Resuelto implementando `_ai/specs/gestion-mesas.spec.md` (#1):
`App\Http\Middleware\EnsureUserHasRole` (alias `role` en `bootstrap/app.php`)
protege grupos de rutas completos en `routes/tenant.php`
(`->middleware('role:admin')`), y `TablePolicy` autoriza la acción específica
sobre el modelo desde el controller vía `Gate::authorize()`. Decisión y
opciones consideradas documentadas en `_ai/adrs/ADR-007-autorizacion-por-rol-en-rutas.md`.
Reutilizable tal cual por cualquier spec futuro con restricción por rol —
solo agrega el middleware a su grupo de rutas. Verificado con los tests de
rol en `tests/Feature/GestionMesasTest.php` (`role=mesero`/`role=cocina` →
403 en `/mesas/gestion`) y la suite completa
(`php artisan test --compact`, 57 passed / 4 skipped).

Los 9 specs afirman cosas como "`role=cocina` recibe 403", pero **ningún spec
definía el middleware que lo implementa**. Es el mismo tipo de hueco que ya
apareció dos veces en este proyecto (US-6.3 gestión de mesas, y
onboarding-tenant): un prerequisito que todos asumen y nadie especifica.

Sin él, cada feature implementaría su propio chequeo de rol a mano —
inconsistente y fácil de omitir en una ruta nueva.

---

## F-07 — MEDIO: Tablet compartida y sesión sin bloqueo por inactividad

**Estado:** 🟢 Resuelto (2026-08-26) · **Verificado**

Resuelto en `_ai/specs/bloqueo-tablet-pin.spec.md`: PIN corto (4 dígitos) por
usuario, autoconfigurado en `/settings/pin` (autoservicio, mismo patrón que
el cambio de contraseña). Gatea únicamente el *submit* de los 3 endpoints que
registran un `Payment` real (`PaymentController::close()`/`addPayment()`/
`addPaymentByItems()`, middleware `EnsurePaymentPinVerified`) — nunca la
navegación a la pantalla de Cobro ni ninguna otra acción, preservando "cero
fricción" para todo lo que no mueve dinero. Umbral: 5 minutos desde la
última verificación exitosa, guardada como `pin_verified_at` en la sesión
del navegador (no en la base de datos — por dispositivo físico, no por
usuario global). `pin_hash` hasheado con `Hash::make()`, nunca en `#[Fillable]`
de `User` (mismo trap que `Table.status`/`MenuItem.available`, ver
`.ai/rules/actions.md`), fijado con `forceFill()->save()`. Rate limiting de 5
intentos/minuto por usuario (`VerifyPaymentPinAction`, mismo criterio que el
login de Fortify). La verificación nunca hace una query "buscar usuario por
PIN" — solo compara el hash de `$request->user()`, así que un PIN correcto
de otro usuario (o de otro tenant, F-05) nunca puede pasar la verificación de
la sesión actual. Verificado con `tests/Unit/Actions/Staff/SetPaymentPinActionTest.php`,
`tests/Unit/Actions/Staff/VerifyPaymentPinActionTest.php` y
`tests/Feature/BloqueoTabletPinTest.php` (incluye casos F-05), más
verificación visual en browser real (PIN configurado, cobro bloqueado sin
verificar, modal de PIN, verificación correcta reintenta el pago
automáticamente, PIN incorrecto rechazado sin filtrar información, light y
dark mode, sin errores de consola).

Riesgo original: las tablets viven en el piso del restaurante y en cocina,
compartidas, frecuentemente desatendidas. `SESSION_LIFETIME=120` (2 horas)
sin bloqueo por inactividad significaba que cualquiera que tomara una tablet
desatendida operaba como el mesero que la dejó — podía tomar pedidos,
**cobrar cuentas** y (con F-03 entonces sin resolver) sin dejar rastro de
quién fue. El diferenciador del producto es "cero fricción" — un bloqueo
agresivo con contraseña habría contradicho ese objetivo directamente; el PIN
corto por acción sensible es el balance descrito textualmente en la versión
original de este hallazgo.

---

## F-08 — BAJO: Endurecimiento de despliegue

**Estado:** 🟡 Preventivo · **Verificado**

`.env.example` (la plantilla de lo que se despliega) trae:
- `APP_DEBUG=true` — en producción expone stack traces con rutas, queries y
  fragmentos de configuración
- `SESSION_SECURE_COOKIE` sin definir — permite enviar la cookie de sesión
  sobre HTTP plano
- `APP_ENV=local`

Son los defaults estándar de Laravel para desarrollo, no un error del
proyecto. Pero con subdominios + HTTPS en producción, `SESSION_SECURE_COOKIE`
y `SESSION_SAME_SITE` dejan de ser opcionales.

**Mitigación:** checklist de despliegue verificando `APP_DEBUG=false`,
`APP_ENV=production`, `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN=null`.

---

## F-09 — BAJO: Enumeración de cuentas entre tenants

**Estado:** 🟡 Aceptable con F-01 resuelto

`users.email` es único a nivel **global**, no por tenant (verificado en
`database/migrations/0001_01_01_000000_create_users_table.php:17`). Al crear
una cuenta de staff, un error de "email ya registrado" revela que ese correo
existe en **algún** restaurante de la plataforma.

Impacto bajo (fuga mínima de información), pero es consecuencia directa de una
decisión de alcance ya documentada en `onboarding-tenant.spec.md`: la misma
persona no puede ser staff de dos restaurantes.

---

## F-10 — INFO: El escape hatch `withoutTenancy()` está disponible

`TenantScope` registra un macro `withoutTenancy()` que desactiva el filtrado.
Es intencional del paquete y a veces necesario, pero cualquier llamada suya —
igual que `DB::table()` y los Jobs sin contexto — evade el aislamiento.

Ya está listado en el "Never Do" de `_ai/CONTEXT.md`. Se anota aquí para que
quede en un solo inventario junto al resto de vectores.

---

## Resumen

| ID | Severidad | Hallazgo | Bloquea implementación |
|---|---|---|---|
| F-01 | 🔴 Crítico → 🟢 Resuelto | Auth sin contexto de tenant → bypass entre tenants | **Sí** |
| F-02 | 🟠 Alto → 🟢 Resuelto | Sesiones no acotadas por tenant | **Sí** |
| F-03 | 🟠 Alto → 🟢 Resuelto | Pagos sin atribución de usuario | Sí (cambio de esquema) |
| F-04 | 🟡 Medio | Mass assignment de `role`/`tenant_id` | No (preventivo) |
| F-05 | 🟡 Medio | IDOR entre tenants sin cobertura de tests | No (preventivo) |
| F-06 | 🟡 Medio → 🟢 Resuelto | Sin spec del middleware de roles | Sí, para features con rol |
| F-07 | 🟡 Medio → 🟢 Resuelto | Tablet compartida sin bloqueo — PIN de cobro | No (decisión de producto) |
| F-08 | 🟢 Bajo | Endurecimiento de despliegue | No (previo a producción) |
| F-09 | 🟢 Bajo | Enumeración de cuentas entre tenants | No |
| F-10 | ⚪ Info | `withoutTenancy()` disponible | No |

**F-01, F-02 y F-03 deben resolverse antes de escribir código de dominio.** Los
tres son más baratos ahora que después: F-01 y F-02 son cambios de routing y
middleware sin datos de por medio, y F-03 es una columna en una tabla vacía.
