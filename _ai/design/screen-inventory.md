# Screen Inventory

> Generado en el PRD (Fase 02). Alimenta Google Stitch para generar UI (Fase 03).
> Actualizar cuando se agreguen pantallas.

**Proyecto Stitch:** `projects/14991819598693147696` ("restaurante-os")
**Design system:** `assets/4d55f3c4dae2452583b02110c6f66fcf` — light, acento
terracota #C2622B, Work Sans + JetBrains Mono, paleta semántica de estado
(verde=listo/pagado, ámbar=pendiente, rojo=error/anular), layout de dos columnas
para tablet landscape. Generado y refinado en Stitch a partir del brief de
`_ai/CONTEXT.md` + esta tabla.

**Estado (2026-08-10):** generación de pantallas fallando del lado del servicio
(timeouts + "invalid argument" en `generate_screen_from_text`, 3 intentos). El
design system sí quedó guardado. Ninguna de las 13 pantallas de abajo tiene código
generado todavía — retomar antes de Implementation.

| # | Pantalla | Ruta/Path | Descripción | Persona | Prioridad | Stitch | Stack | Figma |
|---|----------|-----------|-------------|---------|-----------|--------|-------|-------|
| 1 | Login | `/login` | Autenticación por rol (admin/mesero/cocina). Sin fricción — el diferenciador del producto empieza aquí. | Todas | Must | ⬜ | ⬜ | ⬜ |
| 2 | Mapa de mesas | `/mesas` | Vista de todas las mesas y su estado (libre/ocupada/por cobrar). Punto de entrada del mesero. | Ancla, Independiente | Must | ⬜ | ⬜ | ⬜ |
| 3 | Toma de pedido | `/mesas/{id}/pedido` | Agregar ítems del menú a la cuenta de una mesa. Debe ser operable sin entrenamiento previo. | Ancla, Independiente | Must | ⬜ | ⬜ | ⬜ |
| 4 | Cocina (KDS) | `/cocina` | Lista de pedidos entrantes en tiempo real, con acción de marcar ítem/orden como listo. | Ancla, Independiente | Must | ⬜ | ⬜ | ⬜ |
| 5 | Cobro / cierre de cuenta | `/mesas/{id}/cobro` | Aplica pago a la cuenta de una mesa y la libera. Un solo método de pago en v1. | Ancla, Independiente | Must | ⬜ | ⬜ | ⬜ |
| 6 | Calendario de reservas | `/reservas` | Vista de reservas del día/semana, gestionada por staff (no pública). | Ancla, Independiente | Must | ⬜ | ⬜ | ⬜ |
| 7 | Nueva reserva | `/reservas/nueva` | Formulario simple: nombre, teléfono, hora, número de personas, mesa asignada. | Ancla, Independiente | Must | ⬜ | ⬜ | ⬜ |
| 8 | Gestión de menú | `/menu` | CRUD de platillos: nombre, precio, categoría, disponibilidad. Dato base para que el POS funcione. | Ancla, Independiente | Must | ⬜ | ⬜ | ⬜ |
| 9 | Gestión de staff | `/staff` | Admin crea/edita cuentas de mesero y cocina, asigna rol. | Ancla, Independiente | Must | ⬜ | ⬜ | ⬜ |
| 9b | Gestión de mesas | `/mesas/gestion` | Admin crea/edita mesas (nombre, capacidad). Agregada en Fase 05 (US-6.3) — gap del PRD original. | Ancla, Independiente | Must | ⬜ | ⬜ | ⬜ |
| 10 | Inventario (stock) | `/inventario` | Lista de insumos con cantidad actual y umbral de alerta. Conteo simple, no costeo de receta. | Ancla, Independiente | Should | ⬜ | ⬜ | ⬜ |
| 11 | Ajuste de inventario | `/inventario/{id}/ajustar` | Registrar entrada/salida manual de un insumo. | Ancla, Independiente | Should | ⬜ | ⬜ | ⬜ |
| 12 | División de cuenta (split bill) | `/mesas/{id}/cobro/dividir` | Dividir una cuenta entre varios pagos. | Independiente | Could | ⬜ | ⬜ | ⬜ |
| 13 | Dashboard del día | `/dashboard` | Resumen de ventas, mesas activas y reservas del día para el admin. | Ancla | Could | ⬜ | ⬜ | ⬜ |

**Leyenda**: ⬜ Pendiente → ✅ Listo
