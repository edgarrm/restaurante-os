# Feature: PIN de Re-autenticación para Cobro (Bloqueo de Tablet)

## Status
[x] Draft  [ ] Review  [ ] Approved  [x] Implemented

## PRD Reference
Origen: `_ai/docs/threat-model.md`, hallazgo **F-07 — MEDIO: Tablet compartida
y sesión sin bloqueo por inactividad**. No corresponde a una User Story del
PRD original — es mitigación de seguridad, mismo tipo de brecha que F-01/F-02/
F-06 (ya resueltos).
Prioridad: Medio (según severidad del threat model).

## Overview
Las tablets del piso/cocina son compartidas y frecuentemente desatendidas,
con `SESSION_LIFETIME=120` (2 horas) y sin bloqueo por inactividad. Quien
tome una tablet desatendida hoy puede cobrar cuentas como el mesero que la
dejó abierta. El threat model descarta un bloqueo agresivo de sesión completa
(contraseña, logout) por romper el diferenciador "cero fricción" del
producto, y sugiere textualmente "un PIN corto por usuario, o bloqueo solo
para acciones sensibles" como balance correcto.

Esta feature implementa exactamente eso: un PIN corto (4 dígitos) por
usuario, autoconfigurado desde Settings, que gatea únicamente el momento en
que se **envía** un pago (no la navegación a la pantalla de Cobro, no tomar
pedidos, no enviar a cocina). Si pasaron más de 5 minutos desde la última
verificación del PIN en la sesión de este navegador, el siguiente intento de
cobro pide el PIN antes de procesarse.

## Users Affected
- **Mesero / Admin**: únicos roles que pueden llegar a los endpoints de
  cobro (`role:admin,mesero` en `routes/tenant.php`, ya vigente) — son los
  únicos para quienes el gate se ejecuta en la práctica.
- **Cocina**: puede configurar un PIN en Settings (autoservicio, sin
  restricción de rol en la pantalla — mismo criterio que Profile/Security/
  Appearance, ninguna de las tres oculta su nav item por rol), pero nunca lo
  necesita: no tiene acceso a ninguna ruta de cobro.

## Inputs & Outputs
**Input:** el staff configura su PIN en `/settings/pin` (`pin` + confirmación,
4 dígitos). Al intentar cobrar (`POST /mesas/{table}/cobro`,
`.../pagos`, `.../pagos/por-items`) sin verificación reciente, el servidor
devuelve un error de validación con una key específica; el frontend abre un
modal, el usuario ingresa el PIN, se verifica contra el hash guardado.
**Output:** verificación correcta → `pin_verified_at` se refresca en la
sesión del navegador actual y el pago puede reintentarse; verificación
incorrecta → rechazado, sin filtrar si el PIN existe o no, con rate limiting
tras varios intentos fallidos.

## PASO 0 — Decisiones de mecánica (documentadas, sin `AskUserQuestion`
disponible en esta sesión — ver nota en el encargo)

### 1. ¿PIN obligatorio para todos los roles, o solo para quienes cobran?
**Decisión:** la pantalla de configuración (`/settings/pin`) está disponible
para los 3 roles — mismo criterio de autoservicio que Profile/Security/
Appearance (ninguna se oculta por rol hoy). El **gate real** (bloqueo del
submit de pago) solo puede dispararse para `admin`/`mesero`, porque
`role:admin,mesero` ya restringe por completo el acceso a los tres endpoints
de cobro (`routes/tenant.php`) — `cocina` nunca llega a ese código, con o sin
PIN configurado. No se agregó una restricción de rol adicional a la pantalla
de Settings: hubiera sido complejidad extra (nav item condicional, chequeo de
rol en el controller) sin beneficio real, ya que un PIN configurado por
`cocina` simplemente nunca se usa.

**PIN obligatorio para cobrar, no solo "si ya lo configuraste":** si un
usuario con rol admin/mesero **no** tiene PIN configurado, el gate lo
bloquea igual (con un mensaje distinto — "configura tu PIN" en vez de
"ingresa tu PIN") en vez de dejarlo cobrar sin protección. La alternativa
(gate solo aplica si ya configuraste un PIN) deja la mitigación
completamente opcional — el riesgo original de F-07 seguiría abierto para
cualquier cuenta que simplemente nunca configure un PIN, que es el
comportamiento por defecto de cualquier cuenta nueva. Esto significa que,
tras esta feature, "nunca abrir Settings" ya no es una forma de evitar la
verificación — es la única manera de que la mitigación cierre el hallazgo de
verdad en vez de ser una opción que nadie activa.

### 2. Mecanismo de "última verificación"
**Decisión:** `session(['pin_verified_at' => now()->timestamp])` — timestamp
Unix entero, no un objeto `Carbon` (evita depender de cómo el driver de
sesión serializa objetos; un entero es trivial de comparar y trivial de
serializar en cualquier driver — `database`, el configurado en
`.env.example`, incluido). Vive en la sesión del navegador actual, no en la
base de datos: es intencional que verificar el PIN en la tablet A no
"cuente" para la tablet B — cada sesión de navegador es su propio
dispositivo físico, que es exactamente el vector que F-07 describe.
Umbral: 5 minutos (300 segundos) desde la última verificación, decidido por
el encargo.

### 3. Cómo se comunica al frontend que hace falta pedir el PIN
**Decisión: combinación de las dos opciones mencionadas en el encargo.**
- El **gate** (middleware `EnsurePaymentPinVerified`, aplicado solo a los 3
  endpoints POST de cobro) lanza `ValidationException::withMessages([...])`
  con una key específica — mismo patrón ya establecido en todo el repo
  (`PaymentController`, `StaffController`: nunca `abort()`, porque un
  `abort()` plano no trae el header `X-Inertia` y el cliente real lo muestra
  como modal con HTML crudo, ver comentarios existentes en ambos
  controllers). Dos keys distintas para dos situaciones distintas:
  - `pin_not_set`: el usuario no tiene PIN configurado — el frontend muestra
    un banner con link a Settings, no tiene sentido abrir un modal para
    verificar un PIN que no existe.
  - `pin`: hay PIN configurado pero la verificación de esta sesión expiró (o
    nunca ocurrió) — el frontend abre el modal de PIN.
- Un **endpoint de verificación separado**, `POST /pin/verificar`
  (`PaymentController::verifyPin`), que el modal llama al confirmar el PIN
  ingresado. Éxito → refresca `pin_verified_at` en la sesión y el frontend
  reintenta automáticamente el pago original (mismo `amount`/`method` o
  `item_ids`/`method` que ya tenía en estado local — no se pierden al volver
  de la redirección con el error `pin`). Fallo → mismo endpoint, mismo error
  `pin`, mostrado dentro del modal vía `useForm().errors.pin` (scope propio
  de esa petición, no interfiere con los errores de la petición de pago
  original).

No se usa un único mecanismo (solo 422 gate, o solo endpoint de verificación
previo) porque el gate por sí solo no tiene forma de decirle al frontend
"ahora sí, reintenta" sin una segunda petición explícita, y un endpoint de
verificación por sí solo requeriría que el frontend supiera de antemano que
hace falta verificar (round-trip extra en el caso común de que la
verificación siga vigente).

### 4. Rate limiting de intentos fallidos
**Decisión:** `RateLimiter` de Laravel (`Illuminate\Support\Facades\RateLimiter`),
mismo mecanismo que usa Fortify para login (`config/fortify.php` limita a 5
intentos/minuto por `email|ip`, ver `FortifyServiceProvider::configureRateLimiting()`).
Para el PIN: 5 intentos por minuto, key `payment-pin:{user->id}` — por
usuario, no por IP, porque el vector real es "alguien con la tablet física
intenta adivinar el PIN de la cuenta ya autenticada", no fuerza bruta
distribuida; la IP de una tablet compartida es la misma para todos los
intentos, así que una key por IP+usuario no añade nada aquí. Implementado
dentro de `VerifyPaymentPinAction` (no como named limiter + middleware
`throttle:`, a diferencia de login) porque necesita convivir con la lógica
de comparación de hash en la misma unidad, y por user-id requiere el usuario
autenticado, que un named limiter registrado en un ServiceProvider no tiene
tan directo como el objeto `$user` ya inyectado en la Action.

### 5. Nombre y ubicación de los Actions
**Decisión:** `app/Actions/Staff/SetPaymentPinAction.php` y
`app/Actions/Staff/VerifyPaymentPinAction.php` — mismo directorio que
`CreateStaffAccountAction`/`UpdateStaffRoleAction`/`DeactivateStaffAccountAction`:
conceptualmente esto es una propiedad de seguridad de la cuenta de un
usuario de staff, mismo dominio que esas tres, no un concepto nuevo. No se
usó `app/Actions/Fortify/` porque ese directorio es exclusivamente para los
puntos de extensión que Fortify invoca directamente (`ResetUserPassword`,
etc.) — el PIN no es parte del pipeline de Fortify, es lógica de dominio
propia invocada desde controllers propios.

## Happy Path
1. Un mesero/admin abre `/settings/pin` desde el menú de Settings y
   configura un PIN de 4 dígitos (con confirmación).
2. Más tarde, abre `/mesas/{table}/cobro` normalmente (sin gate — navegar a
   la pantalla nunca requiere PIN).
3. Intenta cobrar. Como no ha verificado su PIN en los últimos 5 minutos en
   esta sesión de navegador, el servidor rechaza el submit con la key
   `pin`; el frontend abre el modal de verificación.
4. Ingresa su PIN correcto. El modal lo verifica contra `POST
   /pin/verificar`, la sesión guarda `pin_verified_at = now()`, el modal se
   cierra y el pago original se reintenta automáticamente con los mismos
   datos.
5. El pago se procesa normalmente (mismo comportamiento que hoy).
6. Si el mesero cobra una segunda mesa dentro de los siguientes 5 minutos,
   no se le vuelve a pedir el PIN — el submit pasa directo.

## Edge Cases
| Escenario | Comportamiento esperado |
|-----------|------------------------|
| Usuario admin/mesero sin PIN configurado intenta cobrar | Rechazado con key `pin_not_set` — banner con link a Settings, no se abre el modal (no hay nada que verificar) |
| PIN correcto ingresado en el modal | `pin_verified_at` se refresca, modal se cierra, pago original se reintenta automáticamente con los mismos datos que tenía en estado local |
| PIN incorrecto | Rechazado — mensaje genérico "PIN incorrecto", nunca revela si el usuario tiene o no un PIN configurado (ese caso ya se filtró antes en el gate, ver arriba) |
| 5 intentos fallidos en 1 minuto | Rate limited — mensaje con segundos restantes, mismo criterio que el lockout de login |
| Verificación de hace 4 minutos, cobra de nuevo | Pasa sin pedir PIN — todavía dentro del umbral de 5 minutos |
| Verificación de hace 6 minutos | Vuelve a pedir PIN |
| Usuario cambia su PIN en Settings mientras tenía `pin_verified_at` vigente en otra pestaña | La sesión sigue vigente hasta expirar por umbral — cambiar el PIN no invalida verificaciones ya hechas en la misma sesión (no es una revocación de sesión, es un chequeo de frescura de verificación; ver Security Considerations sobre por qué esto es aceptable) |
| PIN correcto de **otro usuario del mismo tenant** | Rechazado — la verificación siempre compara contra `$request->user()->pin_hash`, nunca busca por PIN, así que el PIN de otro usuario simplemente no hace match |
| PIN correcto de un usuario de **otro tenant** (F-05) | Rechazado — mismo motivo: nunca hay una query "buscar quién tiene este PIN" que pudiera cruzar tenants; solo se compara el hash del usuario ya autenticado en la sesión actual, y esa sesión ya está acotada a un tenant por `ScopeSessions` (F-02) |
| Doble tap en "Confirmar pago" mientras el modal está abierto | El botón de pago original ya está deshabilitado por `processing` durante el primer intento; el segundo submit real solo ocurre tras verificar el PIN |

## Error States
| Error | Mensaje al usuario | Acción de recuperación |
|-------|-------------------|----------------------|
| PIN no configurado | "Configura tu PIN de cobro en Ajustes antes de cobrar." | Ir a `/settings/pin` |
| Verificación expirada/ausente | "Verifica tu PIN para continuar con el cobro." | Modal de PIN se abre automáticamente |
| PIN incorrecto | "PIN incorrecto." | Reintentar en el mismo modal |
| Demasiados intentos | "Demasiados intentos. Intenta de nuevo en {n} segundos." | Esperar y reintentar |
| PIN nuevo no coincide con la confirmación | "Los PIN no coinciden." (validación `confirmed`) | Corregir en el formulario de Settings |

## Security Considerations
- [x] ¿Requiere autenticación? Sí — igual que hoy, `role:admin,mesero` en
      las rutas de cobro; `/settings/pin` requiere solo `auth` (cualquier
      rol autenticado).
- [x] ¿Reglas de autorización? Ninguna nueva — el PIN es siempre sobre el
      propio usuario autenticado (`$request->user()`), nunca sobre otro
      usuario por ID. No hay endpoint que reciba un `user_id`.
- [x] ¿Validación de inputs? `pin`/`pin_confirmation`: `required`,
      `digits:4`, `confirmed`. El endpoint de verificación: `required`,
      `digits:4`.
- [x] **Rate limiting**: 5 intentos fallidos por minuto por usuario (ver
      PASO 0.4). No aplica a fijar el PIN (`throttle:6,1` heredado del
      mismo patrón que `PUT /settings/password`, ya en `routes/settings.php`).
- [x] **Hash, nunca texto plano**: `pin_hash` se guarda con `Hash::make()`
      (mismo hasher que password, bcrypt). Nunca se loggea el PIN en texto
      plano — ni en logs de aplicación ni en excepciones (los mensajes de
      error son genéricos, nunca incluyen el valor ingresado).
- [x] **Mass assignment**: `pin_hash` **no** está en `#[Fillable]` de
      `User` — se fija exclusivamente vía `forceFill(['pin_hash' =>
      ...])->save()` dentro de `SetPaymentPinAction`, mismo trap ya
      documentado en `.ai/rules/actions.md` para `Table.status`/
      `MenuItem.available`. Verificado con un test que intenta
      `User::create(['pin_hash' => ...])`/`update(['pin_hash' => ...])` vía
      mass assignment y confirma que la columna queda `null`.
- [x] **F-05 — aislamiento entre tenants**: la verificación de PIN nunca
      hace una query "buscar usuario por PIN" — solo compara el hash del
      usuario ya resuelto por la sesión autenticada actual
      (`$request->user()`), que ya está acotada a un tenant por
      `ScopeSessions` (F-02). Un PIN correcto de un usuario de otro tenant
      no puede pasar la verificación de la sesión actual porque nunca se
      compara contra otro usuario que no sea `$request->user()`. Test
      explícito: usuario del tenant B configura PIN "1234"; usuario del
      tenant A (autenticado en su propio tenant) intenta verificar "1234"
      contra su propia sesión → rechazado, como cualquier PIN incorrecto.
- [x] **No revela si un PIN existe**: el endpoint de verificación
      (`pin.verify`) siempre responde "PIN incorrecto" ante un hash nulo o
      un hash que no matchea — nunca distingue "no tienes PIN" de "tu PIN
      está mal" en esa respuesta (esa distinción solo ocurre en el *gate*,
      antes de que el modal exista, donde de todas formas el usuario ya
      sabe si configuró un PIN o no).
- [x] **No reemplaza contraseña/login** (explícito en el encargo): no hay
      `RequirePassword` ni reconfirmación de contraseña en `/settings/pin`
      — es intencionalmente más liviano que cambiar la contraseña, porque
      es una segunda verificación corta para el momento de cobrar, no una
      acción de la misma sensibilidad que rotar credenciales completas.

## Performance Requirements
- Max response time: sin cambio — el gate es una comparación de sesión en
  memoria (sin query) cuando ya está verificado; la verificación en sí es
  un `Hash::check()` (bcrypt, ~100ms, mismo costo que login).
- Expected load: bajo — como mucho una verificación cada 5 minutos por
  mesero activo.
- Data volume: sin impacto — una columna nueva en `users`, sin tabla nueva.

## Test Cases

### Unit Tests
- [x] `SetPaymentPinAction`: PIN válido con confirmación correcta → guarda
      `pin_hash` (hasheado, no en texto plano)
- [x] `SetPaymentPinAction`: PIN sin confirmación coincidente → lanza
      `ValidationException`
- [x] `SetPaymentPinAction`: PIN que no son 4 dígitos → lanza
      `ValidationException`
- [x] `VerifyPaymentPinAction`: PIN correcto → devuelve `true`, limpia el
      rate limiter
- [x] `VerifyPaymentPinAction`: PIN incorrecto → devuelve `false`, registra
      un intento fallido
- [x] `VerifyPaymentPinAction`: usuario sin `pin_hash` → devuelve `false`
      (nunca lanza un error distinto)
- [x] `VerifyPaymentPinAction`: 5 intentos fallidos seguidos → el sexto
      lanza `TooManyPinAttemptsException`
- [x] **Mass assignment**: `pin_hash` no es asignable vía
      `User::create()`/`update()` con un array arbitrario

### Integration Tests
- [x] `POST /mesas/{table}/cobro` sin `pin_verified_at` en sesión y sin PIN
      configurado → 422/redirect con error `pin_not_set`
- [x] `POST /mesas/{table}/cobro` sin `pin_verified_at` en sesión, con PIN
      configurado → error `pin`
- [x] `POST /mesas/{table}/cobro` con `pin_verified_at` de hace 4 minutos →
      pasa sin pedir PIN
- [x] `POST /mesas/{table}/cobro` con `pin_verified_at` de hace 6 minutos →
      vuelve a pedir PIN
- [x] Mismo gate verificado en `POST /mesas/{table}/cobro/pagos` y
      `.../pagos/por-items`
- [x] `POST /pin/verificar` con PIN correcto → `pin_verified_at` se
      refresca en la sesión
- [x] `POST /pin/verificar` con PIN incorrecto → error `pin`, sesión sin
      cambios
- [x] `PUT /settings/pin` con PIN + confirmación válidos → `pin_hash`
      actualizado
- [x] **F-05**: usuario del tenant A no puede verificar con el PIN de un
      usuario del tenant B
- [x] **Aislamiento de rol**: `role=cocina` nunca llega al gate (ya
      bloqueado en 403 por `role:admin,mesero`, sin cambios de este spec)

### E2E Tests
- [x] Happy path completo en browser real: configurar PIN en Settings,
      intentar cobrar sin verificar (modal aparece), verificar con PIN
      correcto (pago pasa), PIN incorrecto (rechazado sin filtrar info)
- [x] Light/dark mode del modal y de la pantalla de Settings, sin errores
      de consola

## Definition of Done
- [x] Todos los test cases de Unit + Integration de este spec pasando (Pest)
- [ ] Code review completado y aprobado
- [x] Spec actualizado con comportamiento real implementado
- [ ] Desplegado en staging y verificado manualmente
- [x] Sin errores en consola / logs
- [x] PIN nunca en texto plano en logs ni mensajes de error
- [x] `_ai/docs/threat-model.md` F-07 pasa a 🟢 Resuelto
