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
**Estado:** 🔴 Abierta — **bloqueante**
**Contexto:** las rutas de Fortify (`/login`, `/logout`, `/forgot-password`,
`/settings/*`) no tienen middleware de tenancy (verificado con `route:list`).
Cuando `users.tenant_id` exista, esto permite que un usuario del restaurante B
se autentique en el subdominio del restaurante A. Ver F-01 en
`_ai/docs/threat-model.md`.
**Opciones:** (a) mover las rutas de Fortify a `routes/tenant.php` con
`InitializeTenancyByDomain`; (b) configurar `config/fortify.php` →
`'middleware'` para incluir el middleware de identificación; (c) un guard de
autenticación explícitamente tenant-aware.
**Bloquea:** `onboarding-tenant.spec.md` (#0) y, por transitividad, todo lo
demás. Decidir antes de escribir código de dominio.

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

### 2026-08-10 — Passkeys / WebAuthn (Fortify)
**Estado:** 🟡 Abierta
**Contexto:** `laravel/fortify` instala `laravel/passkeys` como dependencia
directa — está en el proyecto sin que nadie lo haya pedido. Podría reducir la
fricción de onboarding de staff (el diferenciador central del producto) con
login sin contraseña, o podría ser una feature sin usuario real que la pida.
**Bloquea:** `_ai/specs/gestion-staff.spec.md` no lo contempla todavía —
decidir antes de implementar esa feature.
**Ver:** `_ai/adrs/ADR-003-autenticacion-y-roles.md`, sección "Pendiente —
Passkeys"
