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

**Estado:** 🔴 Abierto · **Verificado**

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

**Estado:** 🔴 Abierto · **Verificado**

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

**Estado:** 🔴 Abierto · **Verificado**

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

Es correcto por diseño, pero **ningún spec lo prueba**. Es la clase de
protección que se rompe silenciosamente si alguien olvida el trait en un
modelo nuevo, y nadie se entera hasta que un cliente ve datos de otro.

**Mitigación:** cada spec con rutas parametrizadas debe incluir un test que
pida explícitamente un recurso de otro tenant y espere 404.

---

## F-06 — MEDIO: No existe spec del middleware de autorización por rol

**Estado:** 🟡 Abierto

Los 9 specs afirman cosas como "`role=cocina` recibe 403", pero **ningún spec
define el middleware que lo implementa**. Es el mismo tipo de hueco que ya
apareció dos veces en este proyecto (US-6.3 gestión de mesas, y
onboarding-tenant): un prerequisito que todos asumen y nadie especifica.

Sin él, cada feature implementaría su propio chequeo de rol a mano —
inconsistente y fácil de omitir en una ruta nueva.

**Mitigación:** escribir el spec del middleware de roles antes de implementar
la primera feature con restricción por rol.

---

## F-07 — MEDIO: Tablet compartida y sesión sin bloqueo por inactividad

**Estado:** 🟡 Abierto

Riesgo específico de este producto: las tablets viven en el piso del
restaurante y en cocina, compartidas, frecuentemente desatendidas.
`SESSION_LIFETIME=120` (2 horas) sin bloqueo por inactividad significa que
cualquiera que tome una tablet desatendida opera como el mesero que la dejó —
puede tomar pedidos, **cobrar cuentas** y (con F-03 sin resolver) sin dejar
rastro de quién fue.

Tensión real: el diferenciador del producto es "cero fricción" — un bloqueo
agresivo con contraseña contradice directamente ese objetivo. Un PIN corto por
usuario, o bloqueo solo para acciones sensibles (cobro, anulación), es el tipo
de balance a decidir con el cliente ancla, no unilateralmente.

**Mitigación:** decisión de producto pendiente. Registrada en
`decision-log.md`.

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
| F-01 | 🔴 Crítico | Auth sin contexto de tenant → bypass entre tenants | **Sí** |
| F-02 | 🟠 Alto | Sesiones no acotadas por tenant | **Sí** |
| F-03 | 🟠 Alto | Pagos sin atribución de usuario | Sí (cambio de esquema) |
| F-04 | 🟡 Medio | Mass assignment de `role`/`tenant_id` | No (preventivo) |
| F-05 | 🟡 Medio | IDOR entre tenants sin cobertura de tests | No (preventivo) |
| F-06 | 🟡 Medio | Sin spec del middleware de roles | Sí, para features con rol |
| F-07 | 🟡 Medio | Tablet compartida sin bloqueo | No (decisión de producto) |
| F-08 | 🟢 Bajo | Endurecimiento de despliegue | No (previo a producción) |
| F-09 | 🟢 Bajo | Enumeración de cuentas entre tenants | No |
| F-10 | ⚪ Info | `withoutTenancy()` disponible | No |

**F-01, F-02 y F-03 deben resolverse antes de escribir código de dominio.** Los
tres son más baratos ahora que después: F-01 y F-02 son cambios de routing y
middleware sin datos de por medio, y F-03 es una columna en una tabla vacía.
