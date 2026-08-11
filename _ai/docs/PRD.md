# PRD — restaurante-os

> v1 — Fase 02. Alimenta Architecture, SDD y Design.
> Fuente: Discovery (Fase 01), sesión del 2026-08-10.

## Resumen Ejecutivo

restaurante-os es una plataforma todo-en-uno (POS + reservas + inventario + cocina)
para restaurantes independientes pequeños. Nace de la petición directa de un cliente
real que busca reemplazar su software actual, cuyo problema no es de funcionalidad
sino de usabilidad: la interfaz es difícil de manejar.

El MVP se enfoca deliberadamente en restaurantes independientes de una sola sede —no
cadenas— para ganar experiencia real con clientes de escala manejable antes de
expandir el alcance. El diferenciador del producto no es tener más funciones que la
competencia: es que cualquier miembro del staff sea productivo desde su primer turno,
sin entrenamiento formal.

El éxito del MVP se mide con un solo restaurante real (el "ancla") operando el 100%
de sus turnos sin caídas, y con la capacidad de incorporar un segundo restaurante de
perfil similar sin soporte manual extendido.

## Contexto y Motivación

El cliente ancla usa hoy un software de terceros que le falla por complejidad de
interfaz, no por falta de funciones. Esto se traduce en fricción operativa medible:
personal nuevo tarda en volverse productivo, y en un sector con rotación de staff
alta, ese costo de entrenamiento se paga una y otra vez.

La decisión de negocio es empezar con nichos pequeños (restaurantes independientes)
en vez de perseguir el mercado completo ("cualquier tipo de restaurante") desde el
día uno — construir para un restaurante independiente de 15 mesas y una cadena de 40
sucursales son productos distintos, y intentar servir ambos diluiría el foco del MVP.

## Usuarios Objetivo

**Operador Ancla** — cliente real que solicitó el proyecto. Dueño/gerente de un
restaurante independiente. JTBD: operar el restaurante sin perder tiempo ni dinero
entrenando personal en un sistema confuso. Éxito = un empleado nuevo toma su primer
pedido sin que alguien más lo guíe paso a paso.

**Independiente pequeño** — perfil de los restaurantes piloto que seguirán al ancla.
El dueño suele operar el POS él mismo en horas pico y no tiene tiempo de aprender
software complejo. Éxito = cero llamadas a soporte durante la primera semana de uso.

*(Cadenas multi-sucursal quedan fuera del MVP — ver Out-of-Scope.)*

## Requerimientos Funcionales

### Épica 1 — Toma de Pedidos (POS)

**US-1.1** Como mesero, quiero ver el mapa de mesas y su estado, para saber
inmediatamente cuáles están libres, ocupadas o listas para cobrar.
- Given el mesero abre la vista de mesas, When hay mesas activas, Then cada mesa
  muestra su estado (libre/ocupada/por cobrar) sin necesitar un segundo clic.
- Prioridad: **Must**

**US-1.2** Como mesero, quiero agregar ítems del menú a la cuenta de una mesa, para
registrar el pedido del cliente.
- Given una mesa ocupada, When el mesero selecciona ítems del menú, Then los ítems
  quedan agregados a la cuenta de esa mesa y visibles en su total.
- Given un ítem no está disponible, When el mesero intenta agregarlo, Then el sistema
  lo muestra deshabilitado y no permite agregarlo.
- Prioridad: **Must**

**US-1.3** Como mesero, quiero enviar el pedido a cocina, para que empiecen a
prepararlo sin que yo tenga que avisar en persona.
- Given una cuenta con ítems agregados, When el mesero confirma el envío, Then el
  pedido aparece en la vista de cocina en tiempo real (o con retraso máximo aceptable
  definido en Performance Requirements).
- Prioridad: **Must**

### Épica 2 — Cocina (KDS)

**US-2.1** Como cocinero, quiero ver los pedidos entrantes en orden, para saber qué
preparar y en qué secuencia.
- Given un pedido enviado desde una mesa, When llega a cocina, Then aparece en la
  lista de pendientes con los ítems y la mesa de origen.
- Prioridad: **Must**

**US-2.2** Como cocinero, quiero marcar un pedido (o ítem) como listo, para que el
mesero sepa que puede servirlo.
- Given un pedido en preparación, When el cocinero lo marca como listo, Then el
  mesero ve el cambio de estado sin tener que preguntar en cocina.
- Prioridad: **Must**

### Épica 3 — Cobro y Cierre de Cuenta

**US-3.1** Como mesero, quiero cobrar la cuenta de una mesa, para cerrarla y
liberarla para el siguiente cliente.
- Given una cuenta con ítems servidos, When el mesero aplica un pago, Then la cuenta
  queda marcada como pagada y la mesa vuelve a estado libre.
- Prioridad: **Must**

**US-3.2** Como mesero, quiero dividir una cuenta entre varios pagos, para atender
grupos que pagan por separado.
- Given una cuenta con múltiples ítems, When el mesero elige dividir, Then puede
  asignar ítems o montos a pagos independientes que suman el total original.
- Prioridad: **Could** (no bloquea al restaurante ancla operar; se valida en piloto)

### Épica 4 — Reservas

**US-4.1** Como staff, quiero registrar una reserva con nombre, teléfono, hora y
número de personas, para tener control de las mesas comprometidas.
- Given un horario disponible, When el staff crea la reserva, Then queda visible en
  el calendario de reservas del día correspondiente.
- Prioridad: **Must**

**US-4.2** Como staff, quiero ver las reservas del día, para anticipar qué mesas
estarán ocupadas y cuándo.
- Given reservas creadas para hoy, When el staff abre el calendario, Then las ve
  ordenadas por hora con la mesa asignada.
- Prioridad: **Must**

*(Reservas públicas/online para clientes finales: fuera de alcance v1 — ver
Out-of-Scope.)*

### Épica 5 — Inventario

**US-5.1** Como admin, quiero ver la cantidad actual de cada insumo, para saber qué
necesito reponer.
- Given insumos registrados, When el admin abre inventario, Then ve cantidad actual
  de cada uno y cuáles están bajo el umbral de alerta.
- Prioridad: **Should**

**US-5.2** Como admin, quiero registrar manualmente una entrada o salida de un
insumo, para mantener el conteo actualizado.
- Given un insumo existente, When el admin registra un ajuste (entrada o salida),
  Then la cantidad actual se actualiza y queda un registro del movimiento.
- Prioridad: **Should**

*(Descuento automático de inventario por venta y costeo de receta: fuera de alcance
v1 — ver Out-of-Scope.)*

### Épica 6 — Datos Base (Menú, Staff y Mesas)

**US-6.1** Como admin, quiero crear y editar platillos del menú (nombre, precio,
categoría, disponibilidad), para que el POS tenga qué ofrecer.
- Given el admin en gestión de menú, When crea un platillo con nombre y precio,
  Then el platillo queda disponible para agregarse a pedidos.
- Prioridad: **Must**

**US-6.2** Como admin, quiero crear cuentas de staff con un rol (mesero/cocina),
para que cada quien vea solo lo que necesita.
- Given el admin en gestión de staff, When crea una cuenta y asigna un rol, Then esa
  persona solo ve las pantallas correspondientes a su rol al iniciar sesión.
- Prioridad: **Must**

**US-6.3** Como admin, quiero crear y editar las mesas del restaurante (nombre,
capacidad), para que el mapa de mesas y la toma de pedidos tengan sobre qué operar.
- Given el admin en gestión de mesas, When crea una mesa con nombre y capacidad,
  Then la mesa aparece en el mapa de mesas con estado `libre`.
- Prioridad: **Must** — *agregada en Fase 05: sin esto, ninguna otra pantalla de
  POS tiene datos con qué funcionar. Ausente en el PRD original; es un gap de
  cobertura, no una decisión consciente previa.*

## Requerimientos No Funcionales

- **Dispositivo:** operable en tablet táctil tanto en mesa (toma de pedido) como en
  cocina (KDS) — objetivos táctiles grandes, sin depender de mouse/teclado.
- **Disponibilidad:** cero caídas durante horario de servicio activo del restaurante
  ancla (KR2 de OKRs); es el requisito no funcional más crítico del MVP.
- **Rendimiento:** acciones críticas del flujo de servicio (agregar ítem, enviar a
  cocina, marcar listo, cobrar) responden en <500ms p95 — cualquier lentitud aquí
  rompe el flujo de un turno de servicio real.
- **Seguridad:** autenticación por rol; un mesero no debe poder acceder a gestión de
  staff ni reportes financieros; ver Security Considerations detallado por feature
  en cada `_ai/specs/{feature}.spec.md`.
- **Datos sensibles:** reservas capturan nombre y teléfono de clientes — dato
  personal mínimo, sin requisito regulatorio conocido todavía (pendiente confirmar
  con el cliente si aplica alguna normativa local).
- **Usabilidad (el requisito diferenciador):** un mesero nuevo debe poder tomar su
  primer pedido sin ayuda en menos de 10 minutos de exposición al sistema — es la
  métrica central del producto, no un nice-to-have.

## Out-of-Scope (explícito para el MVP)

1. **Multi-sucursal / reporte consolidado entre sedes** — decisión de negocio: el
   MVP se enfoca en restaurantes de una sola sede, ver Contexto y Motivación.
2. **Integración con apps de delivery** (Uber Eats, Rappi, etc.) — v1 es solo
   operación en sitio.
3. **Programa de lealtad, cupones o marketing** — no es parte del dolor original del
   cliente ancla.
4. **Nómina y gestión de turnos/pago a empleados** — es un dominio de producto
   distinto.
5. **Costeo de recetas y márgenes por platillo** — inventario v1 es conteo simple de
   stock, no contabilidad de costos.
6. **Reservaciones públicas online** (widget para clientes finales) — v1 solo
   gestiona reservas el staff internamente.
7. **Facturación electrónica / cumplimiento fiscal** — depende de jurisdicción;
   pendiente confirmar si el cliente ancla lo requiere antes de considerarlo para
   una v2.
8. **App móvil nativa** — v1 es web responsive (Inertia + Vue), no apps de tienda.

## Screen Inventory

Ver `_ai/design/screen-inventory.md` para el detalle completo (13 pantallas,
prioridad MoSCoW heredada de las épicas anteriores). Alimenta directamente la Fase
03 (Design vía Stitch).

## Definition of Done (global)

- [ ] La feature cumple su `_ai/specs/{feature}.spec.md`, con los Given/When/Then
      correspondientes cubiertos por tests Pest.
- [ ] Operable en viewport de tablet táctil (referencia de Non-Functional
      Requirements).
- [ ] Sin lógica de negocio en controllers — vive en Actions (ver CONTEXT.md).
- [ ] Sin errores en consola ni en logs de Laravel.
- [ ] Acciones críticas de servicio dentro del budget de rendimiento (<500ms p95).
- [ ] Revisado contra al menos un KR de los OKRs de Discovery — si una feature Must
      Have no traza a ningún KR, se cuestiona su prioridad.

## Trazabilidad Must Have → OKR

| Feature (Must) | KR que sirve |
|---|---|
| Toma de pedido, KDS, cobro | KR2 — ancla opera 100% de turnos sin caídas |
| Mapa de mesas, gestión de menú/staff | KR1 — nuevo empleado productivo en <10 min |
| Todo lo anterior en conjunto | KR3 — 2 pilotos onboarded en <7 días |
