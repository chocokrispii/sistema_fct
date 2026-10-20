# Integraciones y pipeline CSS

## PostCSS y estilos compartidos

El punto de entrada Vue `frontend/src/main.ts` importa la hoja activa `public/assets/css/style.css`. Vite procesa esa hoja con la configuración raíz de PostCSS: `postcss-nested` habilita reglas anidadas y Autoprefixer agrega prefijos según la compatibilidad de navegadores configurada por Browserslist.

En producción, el manifiesto de Vite permite que el layout PHP cargue el CSS compilado junto al bundle de Vue. En la imagen Docker, la etapa `frontend-build` del `Dockerfile` copia `postcss.config.js` y `public/assets/css` (además de `tsconfig.json`, `vite.config.ts` y `frontend`) antes de ejecutar `npm run build`; sin `postcss.config.js` en esa etapa, Vite omite PostCSS sin error y el CSS del bundle se generaría sin resolver el anidado ni añadir prefijos. En desarrollo, Vite sirve el mismo import con HMR. Si no hay manifiesto ni servidor de desarrollo habilitado, el layout PHP conserva un enlace directo a la hoja original para mantener el fallback. No se mantienen copias diferentes de tokens para el CSS PHP y el de las islas.

Vite minifica los assets de producción. El proyecto no usa Tailwind ni CSSnano: no se añaden esas capas porque no hay utilidades Tailwind ni una necesidad de minificación PostCSS adicional. Comprueba el pipeline con:

```powershell
npm run typecheck
npm run build
npm run docs:build
```

## Eventos webhook

La integración saliente configura un destino por despliegue mediante `FCT_WEBHOOK_OUTBOUND_URL`; no existe una API para que usuarios registren varios destinos. El secreto de firma saliente es `FCT_WEBHOOK_OUTBOUND_SECRET`. Las llamadas entrantes verifican de forma independiente `FCT_WEBHOOK_INBOUND_SECRET`. En Docker Compose, define estas variables en `.env`; mantenlas fuera del repositorio.

Las tablas `webhook_outbox` y `webhook_inbox` se crean mediante migraciones. Cuando hay URL saliente configurada, la outbox guarda eventos en la transacción de negocio y un proceso CLI los entrega después con HMAC-SHA256 y reintentos; si la URL no está configurada, no se encolan eventos. La inbox registra eventos entrantes con protección frente a repetición. El receptor remoto debe validar el cuerpo sin transformarlo, la marca `X-FCT-Timestamp` y `X-FCT-Signature`.

Los eventos actuales son `oferta.creada`, `oferta.revisada`, `convenio.creado`, `convenio.revisado`, `practica.asignada`, `practica.cerrada` y `student.availability_updated`. Las operaciones de ofertas, convenios y prácticas pueden encolarlos desde los flujos MVC y API; `student.availability_updated` se encola al guardar disponibilidad desde el MVC, porque la API tokenizada actual no tiene una ruta de disponibilidad. Este último incluye `student_id`, `fecha_inicio`, `fecha_fin`, `turno`, `es_indiferente` y `horarios`; no incluye nombre ni correo.

El worker CLI requiere PHP con la extensión cURL habilitada, además de
`FCT_WEBHOOK_DISPATCH_ALLOW=1`. En la imagen Docker actual está disponible:
`docker compose exec -T app php -m` lista `curl` (la imagen base `php:8.2-apache`
la incluye; el `Dockerfile` no la instala por separado). Si cambias la imagen
base, repite `php -m` dentro del contenedor antes de ejecutar el worker. El worker
procesa 25 eventos por ejecución de forma predeterminada; configura
`FCT_WEBHOOK_DISPATCH_LIMIT` entre 1 y 100 para cambiar el límite.

Para ejecutar el worker bajo demanda en el contenedor `app`:

```powershell
docker compose exec -e FCT_WEBHOOK_DISPATCH_ALLOW=1 app php scripts/dispatch_webhooks.php
```

En un despliegue, programa el worker periódicamente. El destino remoto debe usar HTTPS y resolver únicamente a direcciones IP públicas. HTTP se admite sólo para los receptores de loopback usados en pruebas locales.

Consulta la [guía de configuración, firma y pruebas locales de webhooks](/webhooks) para el procedimiento completo. Tras añadir migraciones, una base existente no las ejecuta por sí sola: aplica únicamente las pendientes según la guía de instalación. `docker compose down -v` reinicializa todos los volúmenes del proyecto y elimina también archivos privados; úsalo sólo si aceptas perderlos.

La API tokenizada se implementa de forma independiente en `public/api.php`.
En Apache se accede directamente con `api.php?path=...`; el alias `/api/v1`
solo está configurado por el lanzador local y no por el Apache Docker incluido.
Consulta [API REST](/api-rest) para permisos y contratos de respuesta.
