# Feature: Gestión de Staff

## Status
[x] Draft  [ ] Review  [ ] Approved  [ ] Implemented

## PRD Reference
User Story: US-6.2 "Como admin, quiero crear cuentas de staff con un rol
(mesero/cocina), para que cada quien vea solo lo que necesita."
Épica: Épica 6 — Datos Base
Prioridad: Must

## Overview
CRUD de cuentas de staff con asignación de rol. Es el mecanismo detrás del
diferenciador central del producto: onboarding sin entrenamiento — cada rol solo
ve lo que necesita, no hay nada más que aprender.

## Users Affected
- **Admin**: crea cuentas y asigna rol.
- **Mesero / Cocina**: usan la cuenta creada para iniciar sesión (vía Fortify,
  ver ADR-003); no gestionan cuentas.

## Inputs & Outputs
**Input:** admin en `/staff` crea una cuenta con `name`, `email`, `password`,
`role`.
**Output:** la cuenta puede iniciar sesión y accede solo a las rutas de su rol.

## Happy Path
1. Admin abre `/staff`.
2. Ve la lista de cuentas de staff existentes (el admin mismo no aparece aquí —
   esta pantalla es para mesero/cocina).
3. Admin toca "Nueva cuenta", ingresa nombre, email, contraseña temporal y
   selecciona rol (`mesero` o `cocina`).
4. Al guardar, la cuenta puede iniciar sesión de inmediato con esas credenciales.
5. Admin puede editar el rol de una cuenta existente (ej. alguien pasó de mesero
   a cocina).

## Edge Cases
| Escenario | Comportamiento esperado |
|-----------|------------------------|
| Email duplicado | Rechazado — `email` es único en la tabla `users` (constraint existente del starter kit) |
| Cambiar el rol de una cuenta con sesión activa | El cambio aplica en la siguiente request autenticada — no hay invalidación forzada de sesión en el MVP (riesgo aceptado: ventana corta donde el usuario conserva acceso del rol anterior hasta su próxima navegación) |
| Admin intenta crear una cuenta con `role=admin` desde esta pantalla | Bloqueado — esta pantalla solo crea `mesero`/`cocina`; cuentas admin se gestionan fuera de este flujo (fuera de alcance de este spec) |
| Eliminar una cuenta que abrió órdenes en el pasado (`Order.opened_by`) | No se permite eliminación dura — desactivar la cuenta (deshabilitar login) en vez de eliminarla, para no romper la FK del historial |
| Contraseña débil | Rechazada por las reglas de validación estándar de Fortify ya configuradas en el proyecto |

## Error States
| Error | Mensaje al usuario | Acción de recuperación |
|-------|-------------------|----------------------|
| Email ya registrado | "Ya existe una cuenta con este correo." | Usar otro correo o editar la cuenta existente |
| Contraseña no cumple los requisitos | Mensaje estándar de validación de Fortify | Ajustar la contraseña |
| Intento de crear `role=admin` | "No se pueden crear cuentas de administrador desde aquí." | N/A — no es un flujo soportado en esta pantalla |

## Security Considerations
- [x] ¿Requiere autenticación? Sí — solo `role=admin`.
- [x] ¿Reglas de autorización? Exclusivamente admin; el propio endpoint rechaza
      explícitamente la creación de cuentas `role=admin` para evitar escalación
      de privilegios por error de UI.
- [x] ¿Validación de inputs? `email` único y con formato válido; `password` según
      reglas de Fortify ya configuradas; `role` restringido a `mesero`/`cocina`
      en este flujo.
- [x] ¿Rate limiting? Hereda el throttling estándar de Fortify en el login; esta
      pantalla de creación no necesita uno propio (uso interno, solo admin).
- [x] ¿Datos sensibles en logs? **La contraseña nunca debe aparecer en logs** —
      Laravel ya la excluye por defecto de logs de excepción estándar; verificar
      que no se agregue logging custom que la exponga.
- [ ] **F-04 (MEDIO) — `role` y `tenant_id` NUNCA en `$fillable`.** Hoy
      `app/Models/User.php` declara
      `#[Fillable(['name', 'email', 'password'])]`, que es seguro. La forma
      "obvia" de implementar este spec —agregar `role` a Fillable y hacer
      `User::create($request->validated())`— abre escalación de privilegios por
      mass assignment: bastaría inyectar `role=admin` en el request.
      Asignar `role` explícitamente en la Action, tras validar contra una lista
      blanca. Rechazar el valor `admin` (ya especificado abajo) es una defensa
      distinta y complementaria: protege contra el valor, no contra el vector.

## Performance Requirements
- Max response time: 500ms (p95).
- Expected load: uso esporádico (alta rotación de personal es el problema que
  el producto resuelve, pero crear cuentas en sí no es un flujo de alta
  frecuencia).
- Data volume: decenas de cuentas de staff por restaurante.

## Test Cases

### Unit Tests
- [ ] `CreateStaffAccountAction`: crea una cuenta con `role` en (`mesero`,
      `cocina`)
- [ ] `CreateStaffAccountAction`: intentar `role=admin` lanza excepción de
      dominio
- [ ] `CreateStaffAccountAction`: email duplicado lanza excepción de validación
- [ ] **F-04 — mass assignment**: enviar `role=admin` o un `tenant_id` de otro
      restaurante dentro del payload NO se refleja en el usuario creado (el
      valor del request se ignora, gana el asignado por la Action)
- [ ] `DeactivateStaffAccountAction`: desactiva sin eliminar el registro (FK de
      `Order.opened_by` se mantiene íntegra)

### Integration Tests
- [ ] `POST /staff` con datos válidos y `role=mesero` → 200, cuenta creada
- [ ] `POST /staff` con `role=admin` → 422 (o 403 si se trata como intento no
      autorizado)
- [ ] `POST /staff` con email duplicado → 422
- [ ] Usuario con `role=mesero` o `role=cocina` accede a `/staff` → 403

### E2E Tests
- [ ] Happy path: admin crea una cuenta `role=mesero` → esa cuenta inicia sesión
      y ve únicamente `/mesas`, `/mesas/*/pedido`, `/mesas/*/cobro`, `/reservas`
      (no `/cocina`, no `/staff`, no `/menu`)
- [ ] Cuenta `role=cocina` inicia sesión y ve únicamente `/cocina` (no el resto)

## Definition of Done
- [ ] Todos los test cases de este spec pasando (Pest)
- [ ] Code review completado y aprobado
- [ ] Spec actualizado con comportamiento real implementado
- [ ] Desplegado en staging y verificado manualmente
- [ ] Sin errores en consola / logs
- [ ] Contraseñas ausentes de cualquier log de aplicación (verificado)
