# Feature: Passkeys / WebAuthn (login sin contraseña, método adicional)

## Status
[x] Draft  [ ] Review  [ ] Approved  [x] Implemented

## PRD Reference
User Story: "Como miembro de staff (mesero/cocina/admin), quiero poder iniciar
sesión con mi passkey (Face ID/Touch ID/PIN del dispositivo) en vez de escribir
mi contraseña cada vez, para reducir la fricción de login en una tablet
compartida del piso."
Épica: Autenticación y Roles (ADR-003)
Prioridad: Should — no está en el PRD original; decisión de producto tomada
por el dueño del proyecto (ver ADR-003, sección "Pendiente — Passkeys", y el
encargo de esta sesión). Resuelve el punto 6 del backlog abierto en
`_ai/CONTEXT.md`.

## Overview
Passkeys (WebAuthn) se agrega como método de login **adicional**, nunca como
reemplazo: email+contraseña sigue funcionando exactamente igual. Cada usuario
puede registrar una o más passkeys nombradas desde su propia página de
Settings ("iPad de la barra", "Mi celular"), y en `/login` puede elegir
"Ingresar con passkey" en vez de escribir su contraseña. Encaja con que las
tablets del piso son compartidas: un autenticador de plataforma (Face
ID/Touch ID/PIN del SO) soporta múltiples credenciales registradas en el
mismo dispositivo físico, así que varios miembros del staff pueden tener su
propia passkey en la misma tablet sin comprometer la atribución individual
(F-03 del threat model, ya resuelto — no se toca aquí).

## PASO 0 — Mecánica (decisiones tomadas antes de escribir código)

### Qué trae `laravel/passkeys` v0.2.1 de fábrica vs. qué se construyó
Confirmado leyendo el código fuente en `vendor/laravel/passkeys` (no hay
`search-docs` indexado para este paquete en esta sesión — se usó el código
fuente como referencia autoritativa, tal como pide el encargo).

**De fábrica (sin tocar):**
- Migración `passkeys` (`id`, `user_id` FK a `users`, `name`, `credential_id`
  único, `credential` json, `last_used_at`, timestamps). Sin `tenant_id` — el
  modelo `Passkey` no usa `BelongsToTenant`.
- Trait `PasskeyAuthenticatable` + contrato `PasskeyUser` para el modelo
  `User` (relación `passkeys()`, `hasPasskeysEnabled()`, `getPasskeyUserHandle()`,
  `getPasskeyDisplayName()`/`getPasskeyUsername()` — ya funcionan con las
  columnas `name`/`email` existentes, sin overrides).
- 3 controllers + 7 rutas (login sin sesión, confirmación de contraseña vía
  passkey, gestión CRUD de passkeys del usuario autenticado) — ver tabla más
  abajo.
- 5 Actions inyectables/extendibles (`GenerateRegistrationOptions`,
  `GenerateVerificationOptions`, `StorePasskey`, `VerifyPasskey`,
  `DeletePasskey`) y 4 contratos de respuesta bindeables en el contenedor
  (`PasskeyLoginResponse`, `PasskeyRegistrationResponse`,
  `PasskeyDeletedResponse`, `PasskeyConfirmationResponse`).
- Punto de extensión documentado `Passkeys::authorizeLoginUsing(callable)`
  para bloquear el login después de una verificación criptográfica válida —
  exactamente el mecanismo que este spec usa para F-01/F-05 (ver abajo).
- Toda la ceremonia criptográfica WebAuthn (`web-auth/webauthn-lib`),
  incluyendo el nuevo formato JSON de WebAuthn Level 3
  (`PublicKeyCredential.parseCreationOptionsFromJSON`/
  `parseRequestOptionsFromJSON`/`.toJSON()`, soportado nativamente por los
  navegadores evergreen) — el paquete serializa/deserializa en ese formato,
  así que el frontend no necesita hacer base64url encoding a mano.

**Construido en esta sesión (no venía):**
- Integración del trait/contrato en `App\Models\User`.
- Habilitar `Features::passkeys()` en `config/fortify.php` — sin esto,
  `laravel/fortify` (que detecta que `laravel/passkeys` está instalado y
  llama `Passkeys::ignoreRoutes()` de forma incondicional en su propio
  `configurePasskeys()`) no registra NINGUNA ruta de passkeys, ni las suyas
  ni las del paquete — confirmado en runtime: con el feature apagado,
  `route:list --path=passkey` no devuelve nada en absoluto.
- **El fix crítico F-01/F-05 para el RP ID** (ver sección dedicada abajo):
  `Laravel\Fortify\FortifyServiceProvider::configurePasskeys()` sí ata
  `passkeys.middleware` a `config('fortify.middleware')` (ya hardenizado
  para F-01) — así que el aislamiento de tenant por middleware queda
  resuelto automáticamente en cuanto se habilita el feature, sin tocar
  ningún archivo de config de passkeys. Lo que SÍ sigue sin resolver es el
  RP ID/`allowed_origins`, que Fortify también deriva de
  `config('app.url')` (un único valor global) salvo que se sobreescriba —
  problema de protocolo WebAuthn, no solo de datos, ver abajo.
- `App\Actions\Fortify\AuthorizePasskeyLogin`: closure/invokable registrado
  vía `Passkeys::authorizeLoginUsing()` que niega el login si `$passkey->user`
  resuelve `null` (cross-tenant, ver F-05) o si `is_active === false` (mismo
  criterio que ya existe para login por contraseña en
  `FortifyServiceProvider::configureActions()`).
- `App\Http\Middleware\ScopePasskeysToTenantDomain`: reconfigura
  `passkeys.relying_party_id`/`passkeys.allowed_origins` al host/origin real
  de la petición en curso (ver sección F-01/F-05).
- `App\Http\Responses\LoginResponse` ahora implementa también el contrato
  `Laravel\Passkeys\Contracts\PasskeyLoginResponse` (misma firma
  `toResponse($request)` que el contrato de Fortify) — bindeado para las dos
  interfaces, así el redirect por rol es el mismo código para ambos métodos
  de login, no una copia.
- Página nueva de Settings (`resources/js/pages/settings/Passkeys.vue`) +
  `App\Http\Controllers\Settings\PasskeysController` (solo `edit`, lista las
  passkeys del usuario — el registro/borrado real pega directo a los
  endpoints del paquete).
- Opción "Ingresar con passkey" en `resources/js/pages/auth/Login.vue`.
- `resources/js/lib/passkeys.ts`: wrapper delgado sobre
  `navigator.credentials.create()/get()` + `PublicKeyCredential.*FromJSON`.
  **Sin nueva dependencia npm** — el paquete oficial recomienda
  `@laravel/passkeys` (JS) en su README, pero no está instalado y añadirlo
  requeriría aprobación de dependencias (CLAUDE.md); dado que el paquete PHP
  ya habla el formato JSON nativo de WebAuthn L3, un wrapper propio de ~40
  líneas sobre la API nativa del navegador cubre el mismo contrato sin
  dependencia nueva.

### Rutas registradas (por Fortify, no por el paquete directamente)
`laravel/fortify` v1.37 trae integración nativa con `laravel/passkeys`:
detecta que el paquete está instalado y, en su propio
`FortifyServiceProvider::configurePasskeys()`, llama
`Passkeys::ignoreRoutes()` de forma incondicional y registra ÉL MISMO las
rutas en `vendor/laravel/fortify/routes/routes.php`, dentro del mismo
`Route::group(['middleware' => config('fortify.middleware')])` que envuelve
`/login`, `/logout`, etc. — usando los controllers del paquete
(`Laravel\Passkeys\Http\Controllers\*`) tal cual, sin copiarlos. Confirmado
en runtime (`route:list --path=passkey`): sin `Features::passkeys()`
habilitado, CERO rutas se registran (ni las de Fortify ni las del paquete
crudo) — hay que habilitar el feature explícitamente.

| Método | Ruta | Middleware interno adicional | Uso |
|---|---|---|---|
| GET | `/passkeys/login/options` | `guest:web` | Opciones de verificación (login sin username, discoverable credentials) |
| POST | `/passkeys/login` | `guest:web` | Verifica passkey y loguea |
| GET | `/passkeys/confirm/options` | `auth:web` | Opciones de confirmación (no usado en este spec) |
| POST | `/passkeys/confirm` | `auth:web` | Confirma `password.confirm` vía passkey (no usado en este spec) |
| GET | `/user/passkeys/options` | `auth:web` + `password.confirm` | Opciones de registro |
| POST | `/user/passkeys` | `auth:web` + `password.confirm` | Registra una passkey nueva |
| DELETE | `/user/passkeys/{passkey}` | `auth:web` + `password.confirm` | Revoca una passkey |

Todas envueltas además, por el grupo exterior, con
`config('fortify.middleware')` completo — verificado directamente con
`Route::gatherMiddleware()` en runtime para las 7 rutas.

### F-01/F-05 para passkeys — dos hallazgos, uno ya resuelto gratis, otro no
El intento anterior de esta tarea (que abortó sin escribir código) dejó la
nota: "`passkeys.middleware` usa `config('fortify.middleware', ['web'])`".
**Verificado: es correcto, pero la pieza que lo hace cierto no está en
`vendor/laravel/passkeys/config/passkeys.php`** (ese archivo, leído
aisladamente, trae `'middleware' => ['web']` a secas — así se ve si se usa
el paquete SIN Fortify). Está en
`Laravel\Fortify\FortifyServiceProvider::configurePasskeys()`, que
sobreescribe `config(['passkeys.middleware' => config('fortify.middleware',
['web'])])` en su propio `boot()`. Dos config files independientes, uno
puentea al otro — la nota original tenía razón sobre el resultado final, sin
nombrar el mecanismo real. Publicar `config/passkeys.php` y editarlo a mano
(primer intento de esta sesión) resultó ser trabajo muerto: Fortify
sobreescribe TODAS sus claves en su propio `boot()`, así que se eliminó el
archivo publicado otra vez — el único punto de control real es
`config('fortify.middleware')` (ya hardenizado por F-01 original) más
`Features::passkeys()` para activar el registro de rutas.

**Consecuencia práctica:** el problema de datos de F-01/F-05 (`VerifyPasskey`
busca la passkey por `credential_id` en una tabla sin `tenant_id`, y
`$passkey->user` es una relación `belongsTo` hacia `User`, que sí tiene
`BelongsToTenant` — su Global Scope solo filtra por tenant si
`tenancy()->initialized`) **queda resuelto automáticamente en cuanto se
habilita `Features::passkeys()`**, porque las rutas heredan
`InitializeTenancyByDomain`/`PreventAccessFromCentralDomains`/
`ScopeSessions` sin ningún cambio adicional de nuestra parte. Pero
`$passkey->user` resolviendo `null` (cross-tenant) todavía necesita un
manejo explícito: `$guard->login(null, ...)` lanzaría un `TypeError` (500
sin control), no un rechazo limpio. Por eso **`AuthorizePasskeyLogin`**
(`Passkeys::authorizeLoginUsing()`) sigue siendo necesario: niega el login
cuando el `?PasskeyUser $user` recibido es `null`, con el mismo mensaje
genérico ("Unable to sign in with this account.") que el paquete ya usa para
cualquier passkey inválida — sin filtrar si la passkey existe en otro tenant
(F-09, enumeración de cuentas, ya aceptado como bajo pero no había que abrir
un vector nuevo).

**El segundo hallazgo, este sí sin resolver de fábrica — a nivel de
protocolo WebAuthn (específico de passkeys, sin equivalente en el login por
contraseña):** `relying_party_id` por defecto es
`parse_url(config('app.url'), PHP_URL_HOST)` (el mismo bridge de
`configurePasskeys()` lo reafirma: `config('fortify.passkeys.relying_party_id',
parse_url(config('app.url'), PHP_URL_HOST))`) — en este repo,
`APP_URL=http://localhost:8000`, o sea RP ID = `"localhost"` (confirmado en
runtime con `Passkeys::relyingPartyId()`). Ese valor **también está en
`config('tenancy.central_domains')`**. Por las reglas de WebAuthn, un RP ID
igual al dominio base hace que CUALQUIER subdominio (`tenant-a.localhost`,
`tenant-b.localhost`, ...) sea "same site" para ese RP ID — el navegador (o
el autenticador de plataforma) ofrecería passkeys discoverable/resident-key
registradas bajo el subdominio de un tenant **al visitar el subdominio de
otro tenant**, porque ambos comparten el mismo RP ID. Esto es más grave que
un simple bug de datos: es un problema de diseño de protocolo — ninguna
cantidad de scoping del lado del servidor lo arregla si el RP ID sigue
siendo el dominio base compartido, y a diferencia del punto anterior, NO se
resuelve solo con habilitar el feature — Fortify no expone ningún mecanismo
dinámico para esto, solo overrides estáticos vía `config('fortify.passkeys.*')`.
**Fix:** middleware nuevo `App\Http\Middleware\ScopePasskeysToTenantDomain`,
agregado a `config('fortify.middleware')` (el mismo array que
`configurePasskeys()` usa como fuente para `passkeys.middleware` — ver
arriba), que sobreescribe en caliente, por request:
```php
config([
    'passkeys.relying_party_id' => $request->getHost(),      // ej. "tenant-a.localhost"
    'passkeys.allowed_origins' => [$request->getSchemeAndHttpHost()],
]);
```
Esto es válido según la spec de WebAuthn (un RP ID igual al dominio exacto
del origen siempre es aceptado — "same site trivially"), y hace que el
navegador jamás ofrezca, ni el servidor jamás valide, una passkey de un
tenant distinto al que sirve la petición actual — el aislamiento ocurre en
la ceremonia criptográfica, antes de que el servidor vea nada.
**Test F-05 cubre ambos niveles** — ver "Test Cases".

### Listar/revocar passkeys — Edge Case obligatorio (dispositivo perdido)
`DELETE /user/passkeys/{passkey}` ya viene gateada por
`config('passkeys.management_middleware')` (`password.confirm` por
defecto, sin cambios) — exactamente el mecanismo "revocar usando tu
contraseña normal como último recurso" que pide el encargo, sin construir
nada custom. La página `/settings/passkeys` completa se gatea con
`RequirePassword::class` a nivel de ruta (mismo patrón exacto que
`settings/security`, ver `routes/settings.php`), así que al llegar a la
página el usuario ya confirmó su contraseña — el registro y el borrado
funcionan sin una pantalla de confirmación intermedia, dentro de la ventana
de `auth.password_timeout` (3h por defecto). Si esa ventana expira mientras
el usuario sigue en la página, el borrado devuelve 423/redirect — ver Edge
Cases.

### Redirect post-login por rol — reuso, no duplicado
`Laravel\Passkeys\Contracts\PasskeyLoginResponse` y
`Laravel\Fortify\Contracts\LoginResponse` son estructuralmente idénticos
(ambos extienden `Responsable`, un solo método `toResponse($request)`).
`App\Http\Responses\LoginResponse` (ya existente, calcula el home por rol)
ahora implementa ambas interfaces y se bindea para las dos — cero lógica de
redirect duplicada. El frontend llama a `POST /passkeys/login` con
`fetch()` **sin forzar `Accept: application/json`**, de modo que
`$request->wantsJson()` sigue siendo `false` (igual que un submit normal de
Inertia) y se ejecuta exactamente la misma rama `redirect()->intended($home)`
que ya usa el login por contraseña — `fetch()` sigue el 302
automáticamente y expone la URL final resuelta en `response.url`, que el
frontend usa para hacer una navegación real de página
(`window.location.assign`).

## Users Affected
- Staff (mesero/cocina) y admin: registran/gestionan sus propias passkeys en
  Settings; pueden elegir "Ingresar con passkey" en el login.
- Admin: sin capacidades adicionales sobre las passkeys de otros usuarios —
  fuera de alcance (cada quien gestiona solo las suyas, igual que
  `settings/security` no permite cambiar la contraseña de otro).

## Inputs & Outputs
**Input:** en Settings, un nombre para la passkey ("iPad de la barra") +
la ceremonia del navegador (`navigator.credentials.create()`). En login, la
ceremonia del navegador (`navigator.credentials.get()`), sin username.
**Output:** en Settings, lista de passkeys registradas (nombre, autenticador
si se puede identificar, último uso) con botón de revocar. En login, sesión
iniciada y redirect al home según rol — idéntico al login por contraseña.

## Happy Path

### Registro de una passkey (Settings)
1. Usuario autenticado visita `/settings/passkeys` (gateado por
   `RequirePassword` — si su confirmación expiró, Fortify lo manda primero a
   `/user/confirm-password`).
2. Escribe un nombre ("iPad de la barra") y pulsa "Registrar passkey".
3. Frontend pide opciones (`GET /user/passkeys/options`), llama
   `navigator.credentials.create()` con esas opciones, y envía el resultado
   (`POST /user/passkeys`) junto con el nombre.
4. El paquete valida la ceremonia, crea el registro `Passkey` asociado al
   usuario actual.
5. La lista se refresca (`router.reload({ only: ['passkeys'] })`) y muestra
   la nueva passkey.

### Login con passkey
1. En `/login`, usuario pulsa "Ingresar con passkey" (alternativa al form de
   contraseña, no lo reemplaza).
2. Frontend pide opciones (`GET /passkeys/login/options`, sin username —
   discoverable credentials) y llama `navigator.credentials.get()`.
3. El navegador/autenticador de plataforma muestra las passkeys disponibles
   **para el RP ID de ese subdominio específico** (ver F-01/F-05 arriba) —
   si hay varias (varios miembros de staff en la misma tablet), el usuario
   elige la suya.
4. Frontend envía el resultado a `POST /passkeys/login`.
5. El servidor verifica la ceremonia, resuelve el usuario (scoped al tenant
   actual), corre `AuthorizePasskeyLogin`, loguea, redirige por rol — mismo
   destino que hoy con contraseña.

## Edge Cases
| Escenario | Comportamiento esperado |
|-----------|------------------------|
| Usuario sin passkeys registradas ve "Ingresar con passkey" | El botón siempre se muestra (login usernameless no requiere saber de antemano si el usuario tiene passkeys); si no tiene ninguna, el navegador no ofrece nada y el usuario cancela la ceremonia → mensaje "No se encontró ninguna passkey" → sigue disponible el form de contraseña |
| Navegador/dispositivo sin soporte WebAuthn (`window.PublicKeyCredential` ausente) | El botón "Ingresar con passkey" y la sección de registro en Settings no se muestran (feature-detected en el mount del componente) — el resto del login/Settings funciona igual |
| Usuario cancela la ceremonia (Face ID/Touch ID rechazado o timeout) | `navigator.credentials.get()/create()` rechaza (`NotAllowedError`) — mensaje "Operación cancelada" sin tratarlo como error de servidor, sin llamada de red |
| Dos usuarios de la misma tablet, cada uno con su propia passkey | El autenticador de plataforma soporta múltiples credenciales resident-key por dispositivo — cada quien ve/elige la suya en el selector nativo del navegador (F-03 no se rompe) |
| Usuario pierde el dispositivo con la passkey | Revoca desde `/settings/passkeys` **usando su contraseña normal** (`RequirePassword` en la ruta) — no depende de tener el dispositivo perdido a mano |
| Confirmación de contraseña expira mientras el usuario sigue en `/settings/passkeys` (>3h) | El registro/borrado devuelve 423/redirect a confirmar contraseña de nuevo — el frontend muestra un error genérico pidiendo recargar la página |
| Cuenta desactivada (`is_active=false`) intenta login por passkey | `AuthorizePasskeyLogin` deniega igual que el login por contraseña (mismo criterio, ver `FortifyServiceProvider`) |
| Passkey registrada en el tenant A, usada desde el subdominio del tenant B | Bloqueado en dos capas independientes — ver "F-01/F-05" arriba y "Security Considerations" |
| Intentar registrar la misma passkey física dos veces (mismo `credential_id`) | El paquete ya lo rechaza (`ensureCredentialIsUnique` en `StorePasskey`) con un error de validación genérico |

## Error States
| Error | Mensaje al usuario | Acción de recuperación |
|-------|-------------------|----------------------|
| Passkey no reconocida / cross-tenant / cuenta inactiva | "No fue posible iniciar sesión con esta passkey." (mensaje genérico del paquete, sin distinguir causa — F-09) | Usar el form de contraseña |
| Ceremonia cancelada por el usuario o timeout | "Operación cancelada." | Reintentar o usar contraseña |
| Confirmación de contraseña expirada al registrar/borrar | "Tu sesión de confirmación expiró. Vuelve a confirmar tu contraseña." | Recargar `/settings/passkeys` |
| Navegador sin soporte WebAuthn | (sección oculta, sin mensaje de error — ver Edge Cases) | — |

## Security Considerations
- [x] ¿Requiere autenticación? Login: no (es un método de autenticación).
      Gestión (`/settings/passkeys`, `/user/passkeys/*`): sí, usuario
      autenticado + `password.confirm` reciente.
- [x] ¿Reglas de autorización? Cada usuario solo ve/borra sus propias
      passkeys (`DeletePasskey`/`PasskeyRegistrationController::destroy`
      compara `passkey.user_id === $user->getKey()` contra el usuario
      autenticado actual, ya scoped al tenant vía sesión — F-02).
- [x] ¿Validación de inputs? El paquete valida la estructura de la
      credencial WebAuthn (`PasskeyRegistrationRequest`/
      `PasskeyVerificationRequest`) y la firma criptográfica
      (`web-auth/webauthn-lib`) — no se confía en nada del cliente más allá
      de eso.
- [x] ¿Rate limiting? Hallazgo en runtime: el bridge de Fortify
      (`configurePasskeys()`) deja `passkeys.throttle` en `null` (sin
      límite) salvo que exista un limiter Fortify llamado `'passkeys'`
      (`config('fortify.limiters.passkeys')`) — el default `throttle:6,1`
      del paquete crudo NO sobrevive una vez que Fortify toma el control.
      Se registró explícitamente (`RateLimiter::for('passkeys', ...)`,
      6/min por IP — sin username disponible, login por passkey es
      discoverable) para no perder ese límite.
- [x] ¿Datos sensibles en logs? El `credential` almacenado es la clave
      pública + metadatos del autenticador (no secretos), pero no se loggea
      en ningún punto nuevo de esta implementación; los mensajes de error
      del paquete son genéricos por diseño (no distinguen "no existe" de
      "pertenece a otro tenant" — F-09).
- [x] **Aislamiento entre tenants (obligatorio en TODA feature)**: cubierto
      en dos capas — ver "F-01/F-05" arriba. Test dedicado F-05 (ver Test
      Cases) prueba ambas capas sin depender de una ceremonia WebAuthn real
      (ver limitación documentada en Test Cases/DoD).
- [x] **Mass assignment**: `Passkey.$fillable` = `name`, `credential_id`,
      `credential` — ninguno controlado libremente por un usuario que no sea
      el dueño (el `user_id` se asigna vía `$user->passkeys()->create()`,
      nunca desde el request).

## Performance Requirements
- Sin presupuesto especial — mismo orden de magnitud que el login por
  contraseña (una query + una verificación criptográfica in-process, sin
  llamadas a servicios externos).

## Test Cases

### Limitación documentada (leída antes de escribir tests)
El ceremonial real de WebAuthn (`navigator.credentials.create()/get()`)
requiere un autenticador real o un "virtual authenticator" del Chrome
DevTools Protocol para producir una firma criptográfica válida contra el
`web-auth/webauthn-lib` del servidor. `laravel/passkeys` no expone ningún
helper de testing para fabricar una ceremonia falsa (confirmado: sin
namespace `Testing`, sin fixtures, sin mención en su README más allá de
`composer test` para sus propios tests internos). Reproducir la ceremonia
completa en PHP (par de claves + firma ECDSA/RSA + estructuras CBOR de
`authenticatorData`/`attestationObject`) está fuera del alcance razonable
de esta tarea. La cobertura automatizada se apoya, en cambio, en probar
**el mecanismo real que cierra F-01/F-05** en las capas que sí se pueden
probar sin criptografía de un autenticador físico:
1. Wiring de middleware de las rutas del paquete (contrato de routing).
2. El Global Scope de tenant sobre la relación `Passkey::user()` (la pieza
   de datos que `AuthorizePasskeyLogin` depende).
3. `AuthorizePasskeyLogin` en aislamiento (Unit).
4. El flujo de registro/borrado SÍ se puede probar end-to-end sustituyendo
   la verificación criptográfica: `VerifyPasskey`/`StorePasskey` son
   bindeables — para efectos de negocio (no de la ceremonia en sí) no hace
   falta, así que estos flujos se prueban vía Feature tests contra las
   Actions bindeadas normales del paquete, insertando registros `Passkey`
   directamente cuando el escenario lo requiere (ej. F-05 relación).

### Unit Tests
- [x] `AuthorizePasskeyLogin`: usuario `null` (cross-tenant) → `false`.
- [x] `AuthorizePasskeyLogin`: usuario `is_active=false` → `false`.
- [x] `AuthorizePasskeyLogin`: usuario activo del tenant correcto → `true`.
- [x] F-05 (Global Scope): con `tenancy()->initialize($tenantA)`, una
      `Passkey` de un usuario de `$tenantA` resuelve `->user` con éxito;
      con `tenancy()->initialize($tenantB)`, la misma `Passkey` resuelve
      `->user` como `null`.

### Integration/Feature Tests
- [x] `/settings/passkeys` requiere autenticación + `password.confirm`
      (redirect si no hay confirmación reciente, igual que
      `settings/security`).
- [x] Registro de una passkey vía `POST /user/passkeys` (Actions bindeadas
      del paquete) asocia el registro al usuario autenticado, con el nombre
      dado.
- [x] Un usuario no puede borrar la passkey de otro usuario del mismo tenant
      (`abort_unless` del paquete, `403`).
- [x] **F-05 (obligatorio, rutas)**: todas las rutas de passkeys
      (`passkey.login`, `passkey.login-options`, `passkey.store`,
      `passkey.registration-options`, `passkey.destroy`) llevan
      `InitializeTenancyByDomain`, `PreventAccessFromCentralDomains`,
      `ScopeSessions` y `ScopePasskeysToTenantDomain` en su middleware —
      mismo stack que `/login`.
- [x] `ScopePasskeysToTenantDomain` sobreescribe `passkeys.relying_party_id`/
      `allowed_origins` al host/origin de la petición actual (no al de
      `config('app.url')`).
- [x] Login normal por contraseña sigue funcionando sin cambios (regresión
      explícita — no se tocó `app/Actions/Fortify/` más de lo necesario).
- [x] Redirect post-login por rol: `App\Http\Responses\LoginResponse`
      implementa ambos contratos y está bindeado para los dos — test directo
      de que el mismo binding resuelve para `Laravel\Fortify\Contracts\LoginResponse`
      y `Laravel\Passkeys\Contracts\PasskeyLoginResponse`.

### E2E Tests
- [ ] Bloqueado por el límite documentado arriba — ver "Definition of Done"
      para lo que sí se verificó manualmente en browser real.

## Definition of Done
- [x] Spec completo (este documento).
- [x] Todos los test cases automatizables (Unit + Feature) pasando —
      12 tests nuevos (3 Unit `AuthorizePasskeyLoginTest`, 1 Unit
      `PasskeyCrossTenantScopeTest`, 8 Feature `PasskeysTest`), todos verdes.
- [x] Suite completa sin regresiones sobre el baseline (`_ai/CONTEXT.md`):
      baseline 250/246/4/0 → ahora 262/258/4/0 (12 tests nuevos, mismos 4
      skipped de siempre — 2FA deshabilitado, ver `.ai/rules/factories.md`).
- [x] `vendor/bin/pint --dirty --format agent`, `npm run lint:check`,
      `npm run types:check` limpios.
- [x] `ADR-003` actualizado: sección "Pendiente — Passkeys" ya no está sin
      decidir.
- [x] `_ai/CONTEXT.md`: backlog punto 6 marcado resuelto.
- [x] `_ai/docs/decision-log.md`: entrada nueva con el detalle del fix
      F-01/F-05 específico de WebAuthn (RP ID dinámico) — es un hallazgo de
      seguridad no trivial que merece quedar documentado, no solo en este
      spec.
- [x] Verificación en browser real hasta donde el entorno lo permite.
      **Verificado**: `/login` renderiza el botón "Ingresar con passkey"
      (feature-detected, Chrome soporta WebAuthn), dark y light mode
      correctos; clic dispara `GET /passkeys/login/options` (200, confirma
      `ScopePasskeysToTenantDomain` corriendo — RP ID atado al subdominio
      real) y luego `navigator.credentials.get()` sin error de JS, quedando
      en espera de la ceremonia nativa. Login por contraseña funciona
      end-to-end (incluyendo el gate `RequirePassword` de
      `/settings/passkeys` → `/user/confirm-password`). La página
      `/settings/passkeys` renderiza correcta en dark y light (nav lateral,
      estado vacío, formulario de registro); clic en "Registrar passkey"
      dispara `GET /user/passkeys/options` (200, confirma
      auth+password.confirm+RP-ID scoping en la ruta de gestión) y
      `navigator.credentials.create()` sin error de JS.
      **No verificado** (límite documentado de antemano): una ceremonia
      WebAuthn completa de principio a fin — este entorno de Chrome
      automatizado no expone un autenticador de plataforma real ni un
      "virtual authenticator" de CDP, así que `navigator.credentials
      .create()/get()` queda pendiente/rechazado antes de producir una
      credencial válida. Nota de herramienta: `computer type` no depositó
      texto de forma confiable en algunos inputs de esta sesión (afectó
      `/user/confirm-password` y el campo de nombre de la passkey) —
      `form_input` y `element.click()`/`form.requestSubmit()` vía
      `javascript_tool` sí funcionaron consistentemente; no es un bug de la
      aplicación, es una particularidad de esta sesión de automatización.
