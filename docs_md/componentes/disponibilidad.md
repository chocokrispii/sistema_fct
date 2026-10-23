---
title: Disponibilidad y matching
---

# Disponibilidad y matching

La navegación permanece en el MVC PHP; Vue mejora progresivamente los controles y el formulario HTML sigue disponible como fallback.

## Flujo de disponibilidad en tres capas

1. **Horario general:** actualiza las horas de la tabla diaria como una operación de interfaz; no guarda por sí solo.
2. **Preferencia indiferente:** al activarla acepta cualquier franja horaria ofertada y deshabilita la edición de horarios diarios. La preferencia se envía junto a la disponibilidad.
3. **Tabla detallada y horario guardado:** el alumno ajusta días y franjas y pulsa guardar. Tras una respuesta correcta, la sección de horario guardado refleja el periodo y las horas persistidas sin recargar la página.

Las peticiones JSON usan el cliente frontend y el token CSRF. Si JavaScript no se monta, el formulario PHP tradicional conserva el guardado y la protección CSRF.

## Reglas de disponibilidad y matching

- Cada alumno y oferta deben tener el mismo ciclo formativo; DAW coincide con DAW y DAM con DAM.
- La disponibilidad debe cubrir el periodo completo de la oferta.
- La oferta debe estar aprobada, tener franjas semanales y plazas libres.
- Con franjas específicas, el sistema compara hora de inicio y fin en el mismo día; los valores `HH:MM` y `HH:MM:SS` se normalizan en el servidor.
- La opción indiferente omite únicamente la comparación horaria, no las fechas, el ciclo, la aprobación, las franjas ofertadas ni el control de plazas.
- El backend vuelve a validar los criterios al crear una asignación.

Las horas de interfaz usan incrementos de cinco minutos. Las ofertas históricas sin franjas semanales exactas no participan en el matching hasta que se actualicen con un horario explícito.

Para rutas y contratos JSON consulta [Flujo de datos](/flujo-de-datos). Los permisos de lectura y escritura se describen en [Roles y permisos](/roles_y_permisos).
