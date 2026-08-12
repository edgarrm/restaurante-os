# Screen Inventory

> Generado en el PRD (Fase 02). Alimenta Google Stitch para generar UI (Fase 03).
> Actualizar cuando se agreguen pantallas.

**Proyecto Stitch:** `projects/14991819598693147696` ("restaurante-os")
**Design system:** `assets/4d55f3c4dae2452583b02110c6f66fcf` — light, acento
terracota #C2622B, Work Sans + JetBrains Mono, paleta semántica de estado
(verde=listo/pagado, ámbar=pendiente, rojo=error/anular), layout de dos columnas
para tablet landscape. Generado y refinado en Stitch a partir del brief de
`_ai/CONTEXT.md` + esta tabla.

**Estado (2026-08-12):** Stitch abandonado para generación de pantallas — al
retomar Fase 03, se confirmó que seguía sin conexión (sin reintento exitoso
desde el fallo del 2026-08-10). Decisión: construir directo en Vue +
Tailwind v4 + reka-ui, traduciendo a mano los tokens del design-md ya
guardado (`assets/4d55f3c4dae2452583b02110c6f66fcf`) a
`resources/css/app.css` (colores, Work Sans, JetBrains Mono, radios) — ver
`decision-log.md`. El design system en sí sigue siendo la referencia de
tokens, solo se abandonó `generate_screen_from_text` como mecanismo de
generación de código.

| # | Pantalla | Ruta/Path | Descripción | Persona | Prioridad | Stitch | Stack | Figma |
|---|----------|-----------|-------------|---------|-----------|--------|-------|-------|
| 1 | Login | `/login` | Autenticación por rol (admin/mesero/cocina). Sin fricción — el diferenciador del producto empieza aquí. | Todas | Must | ⬜ | ✅ | ⬜ |
| 2 | Mapa de mesas | `/mesas` | Vista de todas las mesas y su estado (libre/ocupada/por cobrar). Punto de entrada del mesero. | Ancla, Independiente | Must | ⬜ (abandonado) | ✅ | ⬜ |
| 3 | Toma de pedido | `/mesas/{id}/pedido` | Agregar ítems del menú a la cuenta de una mesa. Debe ser operable sin entrenamiento previo. | Ancla, Independiente | Must | ⬜ | ✅ | ⬜ |
| 4 | Cocina (KDS) | `/cocina` | Lista de pedidos entrantes en tiempo real, con acción de marcar ítem/orden como listo. | Ancla, Independiente | Must | ⬜ | ✅ | ⬜ |
| 5 | Cobro / cierre de cuenta | `/mesas/{id}/cobro` | Aplica pago a la cuenta de una mesa y la libera. Un solo método de pago en v1. | Ancla, Independiente | Must | ⬜ | ✅ | ⬜ |
| 6 | Calendario de reservas | `/reservas` | Vista de reservas del día, gestionada por staff (no pública). Incluye "Nueva reserva" como diálogo (ver nota) — no hay ruta/pantalla separada. | Ancla, Independiente | Must | ⬜ | ✅ | ⬜ |
| 7 | Nueva reserva | *(fusionada en #6)* | El backend real (`ReservationController`: solo `index`+`store`, ambos renderizan `reservas/Index`) es una sola pantalla — no `/reservas/nueva`. El formulario vive en un diálogo dentro del calendario, mismo patrón que "Nuevo platillo" (#8). | Ancla, Independiente | Must | ⬜ | ✅ (ver #6) | ⬜ |
| 8 | Gestión de menú | `/menu` | CRUD de platillos: nombre, precio, categoría, disponibilidad. Dato base para que el POS funcione. | Ancla, Independiente | Must | ⬜ | ✅ | ⬜ |
| 9 | Gestión de staff | `/staff` | Admin crea/edita cuentas de mesero y cocina, asigna rol. | Ancla, Independiente | Must | ⬜ | ✅ | ⬜ |
| 9b | Gestión de mesas | `/mesas/gestion` | Admin crea/edita mesas (nombre, capacidad). Agregada en Fase 05 (US-6.3) — gap del PRD original. | Ancla, Independiente | Must | ⬜ | ✅ | ⬜ |
| 10 | Inventario (stock) | `/inventario` | Lista de insumos con cantidad actual y umbral de alerta, resaltado ámbar/rojo. Incluye "Nuevo insumo" como diálogo (US-5.1, gap agregado en PASO 0) — no hay ruta/pantalla separada. | Ancla, Independiente | Should | ⬜ | ✅ | ⬜ |
| 11 | Ajuste de inventario | *(fusionada en #10)* | El backend real (`InventarioController`: `index`+`store`+`adjust`, todos renderizan `Inertia/Index`) es una sola pantalla — no `/inventario/{id}/ajustar` como ruta navegable. El formulario de "Registrar movimiento" vive en un diálogo dentro del índice, mismo patrón que "Nueva reserva" (#6) y "Nuevo platillo" (#8). | Ancla, Independiente | Should | ⬜ | ✅ (ver #10) | ⬜ |
| 12 | División de cuenta (split bill) | *(fusionada en #5)* | No es una pantalla separada — extiende `/mesas/{id}/cobro` (#5) con saldo pendiente, historial de pagos y un endpoint nuevo para pagos parciales, mismo patrón que #7→#6. Implementado como split por monto libre (`_ai/specs/division-de-cuenta.spec.md`); split por ítems queda documentado como brecha. | Independiente | Could | ⬜ | ✅ (ver #5) | ⬜ |
| 13 | Dashboard del día | `/dashboard` | Resumen de ventas, mesas activas y reservas del día para el admin. | Ancla | Could | ⬜ | ⬜ | ⬜ |

**Leyenda**: ⬜ Pendiente → ✅ Listo
