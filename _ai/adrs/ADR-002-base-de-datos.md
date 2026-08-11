# ADR-002: SQLite en desarrollo, MySQL/PostgreSQL en producción

## Status
Accepted

## Date
2026-08-10

## Context
El starter kit trae SQLite configurado por defecto (`database/database.sqlite`,
ver `.env.example`). Eso es razonable para desarrollo local, pero restaurante-os va
a operar pagos y comandas en tiempo real para un negocio real — escrituras
concurrentes de varios meseros y cocina al mismo tiempo, en horas pico.

SQLite serializa escrituras a nivel de archivo (un solo writer a la vez). Con 2-4
dispositivos escribiendo simultáneamente en hora pico (varios meseros agregando
ítems, cocina marcando listos), el riesgo de contención de escritura es real y
justamente en el momento en que el KR2 (cero caídas durante servicio) más importa.

## Decision
SQLite se mantiene solo para desarrollo local y tests (Pest ya corre contra
`:memory:` o el archivo local — ver `phpunit.xml`). Producción usa MySQL o
PostgreSQL. Se elige **PostgreSQL** por manejo más robusto de concurrencia de
escritura y porque Laravel Cloud (la ruta de despliegue documentada en este repo,
ver reglas de deployment) lo soporta de forma nativa.

> **Nota (ADR-006):** el multi-tenancy se resolvió en modo **single-database** —
> una sola base PostgreSQL compartida por todos los restaurantes, aislados por
> columna `tenant_id`. Esta decisión de ADR-002 no cambia: sigue siendo una sola
> base PostgreSQL en producción, y el razonamiento sobre contención de escritura
> de SQLite aplica igual.

## Options Considered

### Opción A: PostgreSQL en producción ← ELEGIDA
**Pros:**
- Mejor manejo de escrituras concurrentes que SQLite
- Soporte nativo en Laravel Cloud
- Tipos de datos más estrictos (útil para columnas monetarias — `numeric` en vez de
  `float`)
**Cons:**
- Requiere provisionar una instancia de base de datos (no es "un archivo", como
  SQLite) — complejidad operativa adicional, pero manejable a esta escala

### Opción B: MySQL en producción
**Pros:**
- Igual de válido que PostgreSQL para esta carga; más común en hosting compartido
**Cons:**
- Ninguna ventaja concreta sobre PostgreSQL para este dominio
**Rechazada porque:** no hay razón de negocio para preferirlo sobre PostgreSQL;
se documenta como alternativa válida si el hosting final lo requiere.

### Opción C: SQLite también en producción
**Pros:**
- Cero infraestructura adicional — un archivo
**Cons:**
- Serialización de escrituras es un riesgo directo contra KR2 (cero caídas en
  servicio) en el escenario real de uso (POS + cocina escribiendo a la vez)
**Rechazada porque:** el ahorro operativo no compensa el riesgo contra el requisito
no funcional más crítico del producto.

## Consequences

### Positive
- Reduce el riesgo de contención de escritura en hora pico, que es exactamente
  cuando el sistema no puede fallar

### Negative
- Un desarrollador solo ahora debe operar/monitorear una base de datos real en
  producción, no solo un archivo

### Neutral
- Las migraciones (`database/migrations/`) deben evitar tipos específicos de
  SQLite; usar los tipos portables de Laravel (`decimal`, no `float`, para
  columnas de dinero)

## Related
- ADR-001: Monolito — la app sigue siendo un solo despliegue con una sola base
