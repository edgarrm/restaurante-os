# ADR-006: stancl/tenancy en modo single-database, identificación por subdominio

## Status
Accepted

## Date
2026-08-10

## Context
restaurante-os es un SaaS: el restaurante ancla y los pilotos del KR3 (PRD) van a
compartir un solo despliegue de la aplicación, no una instalación por restaurante
cada uno. Esto es una decisión distinta del "no multi-sucursal" del PRD (ese
Out-of-Scope es sobre que UN restaurante no tenga reportes consolidados entre
varias sedes propias; esto es sobre que la plataforma sirva a VARIOS
restaurantes-cliente distintos desde una sola instancia).

Sin aislamiento explícito, cualquier tabla del data model (mesas, órdenes, menú,
staff) mezclaría datos de restaurantes distintos — un mesero del restaurante A
podría ver mesas del restaurante B.

`stancl/tenancy` ofrece **dos ejes de configuración independientes**, que es
importante no confundir (se confundieron en la primera versión de este ADR):

1. **Estrategia de datos**: multi-database (vía `DatabaseTenancyBootstrapper`,
   que cambia la conexión por tenant) **o** single-database (vía el trait
   `BelongsToTenant` + `TenantScope`, un Global Scope de Eloquent que inyecta
   `where tenant_id = ?` en toda query). Ambos son código de primera clase del
   paquete.
2. **Identificación del tenant**: `InitializeTenancyByDomain`, `...BySubdomain`,
   `...ByDomainOrSubdomain`, `...ByPath`, `...ByRequestData`.

Cualquier combinación de los dos ejes es válida.

## Decision
Se adopta `stancl/tenancy` v3.10 con:

- **Single-database tenancy**: una sola base de datos PostgreSQL para todos los
  restaurantes. Cada tabla del dominio lleva una columna `tenant_id`, y cada
  modelo Eloquent del dominio usa el trait
  `Stancl\Tenancy\Database\Concerns\BelongsToTenant`, que:
  - aplica `TenantScope` como Global Scope (filtra por `tenant_id` en toda query)
  - rellena `tenant_id` automáticamente al crear un registro
- **Identificación por subdominio**: `elancla.restaurante-os.com`,
  `elpiloto.restaurante-os.com`.
- `DatabaseTenancyBootstrapper` **desactivado** en `config/tenancy.php` (activarlo
  rompería el modelo single-DB).
- `QueueTenancyBootstrapper` **activo** — crítico en single-DB: reinyecta el
  contexto del tenant dentro de los Jobs. Sin él, un Job encolado corre sin
  tenancy inicializada y `TenantScope` no filtra nada.
- `TenantCreated`/`TenantDeleted` **sin** los jobs `CreateDatabase`/
  `MigrateDatabase`/`DeleteDatabase` del scaffolding por defecto — no hay base
  que crear ni borrar por tenant.

Las rutas del dominio de restaurante viven en `routes/tenant.php` (con el
middleware de identificación), no en `routes/web.php`.

## Options Considered

### Opción A: Single-DB (`tenant_id` + `TenantScope`) + subdominio ← ELEGIDA
**Pros:**
- Una sola base de datos que respaldar, migrar y monitorear — a la escala real
  (2-3 restaurantes, un solo desarrollador), la carga operativa de N bases no se
  justifica
- Las migraciones corren una vez, no una por restaurante
- El aislamiento lo aplica el paquete automáticamente vía Global Scope; no es
  código propio que haya que mantener
**Cons:**
- El aislamiento es lógico, no físico: un `withoutTenancy()`, una query cruda con
  `DB::table()`, o un Job corriendo fuera del contexto del tenant pueden evadir
  el scope y filtrar datos entre restaurantes
- Exportar o borrar los datos de un restaurante es un `DELETE WHERE tenant_id`,
  no un `DROP DATABASE` — más delicado si un cliente pide su baja

### Opción B: Multi-DB (una base por restaurante) + subdominio
**Pros:**
- Aislamiento físico: es estructuralmente imposible que una query cruce datos de
  dos restaurantes
- Baja de un cliente = `DROP DATABASE`; backup por restaurante es trivial
**Cons:**
- N bases de datos que respaldar, migrar y monitorear — crece linealmente con
  cada restaurante nuevo, sobre un solo desarrollador
- Cada despliegue con migraciones debe correrlas sobre todas las bases
**Rechazada porque:** el costo operativo es real y recurrente, mientras que el
riesgo que mitiga (evadir el Global Scope) es acotado y se puede cubrir con tests
específicos — ver Consequences.

### Opción C: `restaurant_id` + Global Scope escrito a mano, sin el paquete
**Pros:**
- Cero dependencias nuevas
**Cons:**
- Reimplementa exactamente lo que `BelongsToTenant` + `TenantScope` ya hacen,
  incluyendo los casos molestos (rellenar el id al crear, contexto en Jobs,
  aislamiento de cache y filesystem)
**Rechazada porque:** habiendo decidido adoptar el paquete, escribir a mano su
funcionalidad central no aporta nada.

## Consequences

### Positive
- Infraestructura sin cambios respecto a lo ya decidido en ADR-002: una sola base
  PostgreSQL en producción
- Onboardear un restaurante piloto (KR3) no requiere provisión de
  infraestructura (sin bases de datos que crear) — sigue siendo trabajo manual
  vía comando, no automático: crear `Tenant` + `Domain` + la primera cuenta
  admin de ese restaurante (ver `_ai/specs/onboarding-tenant.spec.md`)

### Negative
- **`_ai/docs/data-model.md` cambia**: cada tabla del dominio necesita
  `tenant_id`. Es un cambio de esquema real, no cosmético.
- **Riesgo de fuga entre tenants que exige disciplina activa.** Los tres vectores
  concretos a vigilar en code review y tests:
  1. `withoutTenancy()` — el macro que desactiva el scope a propósito
  2. Queries crudas (`DB::table(...)`, `DB::select(...)`) que no pasan por Eloquent
     y por lo tanto ignoran el Global Scope por completo
  3. Jobs o comandos Artisan que corren sin tenancy inicializada
- Todo spec de feature debe incluir al menos un test que verifique que un usuario
  del tenant A no ve datos del tenant B (ver Related).

### Neutral
- Los 8 specs de `_ai/specs/` ya escritos no cambian en su lógica de negocio; al
  implementarlos, sus rutas van en `routes/tenant.php` y sus modelos llevan el
  trait `BelongsToTenant`
- Si el negocio crece a un punto donde el aislamiento físico sea un requisito
  (cliente enterprise, auditoría, regulación), migrar a multi-DB es posible: el
  primer lugar a tocar es `config/tenancy.php` (bootstrappers) y
  `TenancyServiceProvider` (jobs de creación de base)

## Nota sobre la primera versión de este ADR
La versión original de este ADR afirmaba que el modo nativo del paquete era
multi-DB y que single-DB era "nadar contra la corriente". **Eso era incorrecto** —
se basó en una lectura superficial del README en vez de verificar el código, que
muestra `BelongsToTenant` y `TenantScope` como parte del core. La decisión se
retomó con la información correcta y cambió de multi-DB a single-DB.

## Auditoría del 2026-08-10 — tres bugs encontrados y corregidos
Una auditoría del setup (motivada por la preocupación de que decisiones se
estuvieran perdiendo sin quedar registradas) encontró que el scaffolding
generado por `php artisan tenancy:install` estaba **completamente inerte**:

1. **`TenancyServiceProvider` nunca se registró** en `bootstrap/providers.php`
   — el comando lo crea en disco pero no lo conecta. Ningún middleware, event
   listener ni bootstrapper corría. Confirmado reproduciendo
   `tenancy()->initialize()` en Tinker y viendo que `app('cache')` seguía
   resolviendo la clase base de Laravel, no la de tenancy.
2. **`CacheTenancyBootstrapper` crashea con la config por defecto**: fuerza
   `Cache::tags(...)` en cada llamada, pero `CACHE_STORE=database` no soporta
   tags (`BadMethodCallException`). Los tests no lo detectaban porque
   `phpunit.xml` fuerza `CACHE_STORE=array` (sí taggable) solo para tests —
   divergencia entre entorno de test y de dev/producción. Desactivado hasta que
   una feature real necesite `Cache::` en código de dominio.
3. **La ruta demo de `routes/tenant.php` (`GET /`) colisionaba con `home`** de
   `routes/web.php` y la sobrescribía por completo (no solo la ensombrecía) —
   rompió 3 tests (`route('home')` dejaba de resolver). `tenant.php` se carga
   después vía `$app->booted()`, así que gana el conflicto. Ruta demo removida.

Los tres bugs eran reproducibles de forma determinista y quedaron verificados
con `php artisan test --compact` (26/26 relevantes pasando, antes 23 con 3
fallando) + `composer types:check` (0 errores) antes de darlos por resueltos.

## Related
- ADR-002: Base de datos — sigue vigente sin cambios (una sola base PostgreSQL)
- ADR-003: Autenticación y roles — `users` ahora también lleva `tenant_id`
- `_ai/docs/data-model.md`: sección de tenancy
- Todo `_ai/specs/*.spec.md`: debe incluir un test de aislamiento entre tenants
