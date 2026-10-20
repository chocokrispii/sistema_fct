---
title: Solución de problemas
---

# Solución de problemas

## Conflicto de puertos

La aplicación usa `8080`, phpMyAdmin `8082`, MariaDB `3307`, Vite `5173` y VitePress `5174` por defecto. Cambia `APP_PORT`, `PMA_PORT` o `VITE_PORT` en `.env` si el puerto del host está ocupado. Por ejemplo, Jenkins suele usar `8081`; no configures phpMyAdmin en un puerto ocupado, conserva `PMA_PORT=8082` o selecciona otro libre. Consulta `docker compose ps` para ver las asignaciones activas.

## Base de datos o esquema desactualizado

MariaDB procesa los scripts de `docker-entrypoint-initdb.d` sólo al crear el volumen vacío. Si la base ya existe, las modificaciones de esquema no se aplican automáticamente. Para DAM, ejecuta la migración idempotente `database/migrations/009_desarrollo_aplicaciones_multiplataforma.sql` una vez; para los detalles de ofertas, aplica `database/migrations/010_detalles_ofertas.sql`. No reapliques a ciegas migraciones no idempotentes.

Para restablecer todos los volúmenes locales usa `docker compose down -v` únicamente después de respaldar lo necesario: también se perderán los archivos privados y las firmas antivirus. Consulta [instalación y migraciones](/instalacion#cambios-de-esquema-y-migraciones).

## Convenios y ClamAV

ClamAV puede tardar varios minutos en descargar las firmas iniciales. Comprueba `docker compose ps` y `docker compose logs --tail=100 clamav`. Para un análisis real, configura `FCT_CLAMAV_ENABLED=true`. Si necesitas permitir cargas sólo para pruebas locales, configura simultáneamente `APP_ENV=development` y `FCT_CLAMAV_ENABLED=false`; el scanner escribe una advertencia. Una conexión fallida con el análisis habilitado debe rechazar la carga.

## Guardado y sincronización de disponibilidad

Las horas del navegador suelen llegar como `HH:MM`; el backend también acepta `HH:MM:SS`. Comprueba la respuesta JSON, el token `X-CSRF-Token`, la clave de los días y el booleano `es_indiferente`. Un error de red o validación debe mostrarse como fallo y no actualizar el horario guardado. Consulta [Disponibilidad](/componentes/disponibilidad).

## Botón de visibilidad de contraseña

El control crea un único SVG y alterna sus trazos al pulsarlo. Si no alterna la visibilidad, comprueba que `public/assets/js/password-toggle.js` esté servido y que el botón generado sea `type="button"` para no enviar el formulario.
