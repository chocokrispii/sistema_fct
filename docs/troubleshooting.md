---
title: Solución de problemas
---

# Solución de problemas

## Conflicto de puertos

La aplicación usa `8080`, phpMyAdmin `8082`, MariaDB `3307`, ClamAV `3310`, Vite `5173` y VitePress `5174` como puertos locales habituales. `APP_PORT`, `PMA_PORT` y `VITE_PORT` se configuran en `.env`; el mapeo de MariaDB y ClamAV está fijado en `docker-compose.yml` y vinculado a `127.0.0.1`. VitePress recibe el puerto mediante `npm run docs:dev -- --port 5174` y busca otro si está ocupado. Consulta `docker compose ps` para ver las asignaciones de Docker activas.

## Base de datos o esquema desactualizado

MariaDB procesa los scripts de `docker-entrypoint-initdb.d` sólo al crear el volumen vacío. Si la base ya existe, las modificaciones de esquema no se aplican automáticamente. Para DAM, ejecuta `database/migrations/009_desarrollo_aplicaciones_multiplataforma.sql`; para los nuevos campos de oferta, aplica `database/migrations/010_detalles_ofertas.sql`. Las tablas de webhooks y tokens API usan los archivos `007_webhooks.sql`, `008_api_access_tokens.sql` y `009_webhook_responses.sql`. Hay prefijos numéricos repetidos: verifica el nombre completo y aplica sólo lo que falte. `009_webhook_responses.sql` no debe repetirse una vez creadas sus columnas.

`docker compose down -v` elimina la base de datos, los archivos privados, las firmas antivirus y las dependencias Node de los volúmenes del proyecto. Respalda lo que necesites y úsalo sólo en un entorno local desechable. Consulta [instalación y migraciones](/instalacion#cambios-de-esquema-y-migraciones) y el procedimiento de reinicio selectivo del [README](https://github.com/campusedx/Practicas_Desarrollo/blob/main/README.md#restablecimiento-de-base-de-datos-por-cambios-de-esquema-o-migraciones).

## Convenios y ClamAV

ClamAV puede tardar varios minutos en descargar las firmas iniciales. Comprueba `docker compose ps` y `docker compose logs --tail=100 clamav`. Compose usa `APP_ENV=production` y escaneo habilitado como valores de reserva. Para un análisis real, configura `FCT_CLAMAV_ENABLED=true`; la aplicación Docker accede a `clamav:3310` y el scanner comprueba la antigüedad de las firmas. Para cargas de prueba locales sin análisis, configura simultáneamente `APP_ENV=development` y `FCT_CLAMAV_ENABLED=false`; el scanner registra una advertencia. El bypass también se acepta si el entorno es `test` o `testing`. Una conexión fallida con el análisis habilitado debe rechazar la carga. El puerto host `3310` sólo se publica en loopback; no lo expongas a una red compartida.

## API v1 no responde en Apache

El archivo de entrada es `public/api.php` y recibe la ruta lógica en el parámetro `path`, por ejemplo `public/api.php?path=ofertas`. El prefijo `/api/v1/...` se traduce mediante `public/dev-router.php`, usado por el lanzador local de webhooks; la configuración Apache incluida no contiene esa regla de reescritura. Si `/api/v1/ofertas` devuelve 404 en XAMPP o Docker Apache, usa la entrada directa `api.php?path=...` o un servidor que configure el alias. Consulta [API REST](/api-rest) para las bases URL y los contratos.

## Guardado y sincronización de disponibilidad

Las horas del navegador suelen llegar como `HH:MM`; el backend también acepta `HH:MM:SS`. Comprueba la respuesta JSON, el token `X-CSRF-Token`, la clave de los días y el booleano `es_indiferente`. Un error de red o validación debe mostrarse como fallo y no actualizar el horario guardado. Consulta [Disponibilidad](/componentes/disponibilidad).

## Botón de visibilidad de contraseña

El control crea un único SVG y alterna sus trazos al pulsarlo. Si no alterna la visibilidad, comprueba que `public/assets/js/password-toggle.js` esté servido y que el botón generado sea `type="button"` para no enviar el formulario.
