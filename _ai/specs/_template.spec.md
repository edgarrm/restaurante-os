# Feature: [Nombre de la Feature]

## Status
[ ] Draft  [ ] Review  [ ] Approved  [ ] Implemented

## PRD Reference
User Story: "Como [rol], quiero [acción], para [beneficio]"
Épica: [nombre de la épica]
Prioridad: [Must / Should / Could]

## Overview
[1-2 oraciones. Qué hace esta feature y por qué existe.]

## Users Affected
- [Tipo de usuario 1]: [cómo interactúa con esta feature]
- [Tipo de usuario 2]: [cómo interactúa]

## Inputs & Outputs
**Input:** [Qué dispara esta feature / qué datos entran]
**Output:** [Qué ve el usuario / qué datos salen / qué efectos produce]

## Happy Path
1. [Paso 1 — acción del usuario]
2. [Paso 2 — respuesta del sistema]
3. [Paso 3]
...

## Edge Cases
| Escenario | Comportamiento esperado |
|-----------|------------------------|
| [caso 1 — no obvio] | [comportamiento] |
| [caso 2] | [comportamiento] |
| [caso 3] | [comportamiento] |
| [caso 4] | [comportamiento] |
| [caso 5] | [comportamiento] |

## Error States
| Error | Mensaje al usuario | Acción de recuperación |
|-------|-------------------|----------------------|
| [error técnico 1] | "[texto exacto que ve el usuario]" | [qué puede hacer] |
| [error técnico 2] | "[texto]" | [acción] |

## Security Considerations
- [ ] ¿Requiere autenticación? [sí/no — qué rol]
- [ ] ¿Reglas de autorización? [quién puede y quién no]
- [ ] ¿Validación de inputs? [qué se valida]
- [ ] ¿Rate limiting? [si aplica]
- [ ] ¿Datos sensibles en logs? [qué NO loggear]

## Performance Requirements
- Max response time: [X]ms (p95)
- Expected load: [N] requests/segundo
- Data volume: [estimado de registros]

## Test Cases

### Unit Tests
- [ ] [Test: qué función verifica + qué condición]
- [ ] [Test del edge case 1]
- [ ] [Test del edge case 2]
- [ ] [Test del error state 1]

### Integration Tests
- [ ] [Test del flujo completo happy path]
- [ ] [Test de integración con servicio externo]

### E2E Tests
- [ ] [Happy path completo desde UI]
- [ ] [Error state crítico desde UI]

## Definition of Done
- [ ] Todos los test cases de este spec pasando
- [ ] Code review completado y aprobado
- [ ] Spec actualizado con comportamiento real implementado
- [ ] Desplegado en staging y verificado manualmente
- [ ] Sin errores en consola / logs
- [ ] Performance dentro del budget definido arriba
