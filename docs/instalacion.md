---
title: Instalación local
---

# Instalación local

## Requisitos

- Git.
- Docker Desktop con Docker Compose v2.
- Node.js 22–24 y npm para compilar el frontend y VitePress.

El contenedor de aplicación incluye PHP 8.2, Apache, PDO MySQL y mbstring. Docker Compose es el entorno recomendado para desarrollo; los valores de ejemplo no son credenciales de producción.

## Inicio rápido

Desde PowerShell:

```powershell
git clone https://github.com/campusedx/Practicas_Desarrollo.git
Set-Location .\Practicas_Desarrollo
Copy-Item .env.example .env
```

La plantilla local define `PMA_PORT=8082`, `APP_ENV=development` y `FCT_CLAMAV_ENABLED=false`. Ese último valor omite explícitamente el escaneo para facilitar pruebas locales; el bypass sólo se acepta con `APP_ENV=development`, `test` o `testing`. Si no se definen variables, Compose usa `APP_ENV=production` y mantiene el análisis habilitado (`FCT_CLAMAV_ENABLED=true`).

Inicia los servicios y genera cuentas demo:

```powershell
docker compose up -d --build
docker compose ps
docker compose exec -e FCT_ENABLE_DEMO_SEED=1 -e FCT_DEMO_SEED_ALLOW_DOCKER=1 app php scripts/crear_cuentas_demo.php
```

El generador imprime identificadores fiscales y contraseñas aleatorias una sola vez y renueva las contraseñas anteriores en cada ejecución. Guárdalos de forma segura. No ejecutes la semilla en producción.

## Antivirus local

Compose inicia el servicio ClamAV y la aplicación se conecta a `clamav:3310` dentro de la red Docker. Para que las cargas se analicen, establece `FCT_CLAMAV_ENABLED=true` en `.env` y espera a que `docker compose ps` muestre ClamAV como `healthy`. El primer arranque puede tardar mientras se descargan las firmas. Si se habilita el análisis y ClamAV no responde, las cargas se rechazan.

Para desarrollo sin antivirus, define ambos valores `APP_ENV=development` y `FCT_CLAMAV_ENABLED=false`; el servidor registra que omitió el análisis. El mismo bypass se acepta para los entornos `test` y `testing`, pero no en entornos compartidos o de producción. Compose publica el puerto del scanner sólo en `127.0.0.1:3310` para pruebas locales; no lo expongas en una interfaz de red pública.

## Direcciones locales

| Servicio | URL |
|---|---|
| Aplicación PHP | `http://localhost:8080` (configurable con `APP_PORT`) |
| phpMyAdmin | `http://localhost:8082` (configurable con `PMA_PORT`, sólo loopback) |
| MariaDB desde el host | `127.0.0.1:3307` (mapeo fijo en `docker-compose.yml`) |
| ClamAV desde el host, para pruebas | `127.0.0.1:3310` (sólo loopback) |
| Vista previa de disponibilidad | `http://localhost:8080/index.php?action=preview-disponibilidad` |
| Vista previa de oferta | `http://localhost:8080/dev/preview-publicar-oferta` |
| Vite HMR (opcional) | `http://localhost:5173` (`npm run dev` o perfil `frontend-dev`) |
| Portal VitePress (opcional) | `http://localhost:5174` (`npm run docs:dev -- --port 5174`) |

Las vistas previas (`preview-disponibilidad` y `preview-publicar-oferta`) sólo se registran cuando `APP_ENV` es `development` o `local` (`DevelopmentPreviewController::isEnabled()`); con cualquier otro valor, incluido el `production` de reserva de Compose, las rutas no existen. No confundas este criterio con el bypass de ClamAV, que sólo acepta `development`, `test` o `testing` (no `local`). El puerto de MariaDB no tiene variable `.env`; para cambiarlo, edita el mapeo `127.0.0.1:3307:3306` en Compose. Consulta [Disponibilidad](/componentes/disponibilidad) para el flujo de guardado.

Para instalar dependencias Node y servir el portal de documentación:

```powershell
npm ci
npm run docs:dev -- --port 5174
```

La documentación se compila con `npm run docs:build`. VitePress puede escoger otro puerto si el solicitado está ocupado.

## Cambios de esquema y migraciones

En un volumen nuevo, MariaDB importa `database/sistema_fct.sql` y Compose ejecuta las migraciones listadas explícitamente en `docker-compose.yml`. Varias líneas de migración tienen prefijos numéricos repetidos; identifica cada archivo por su nombre completo, no sólo por el número.

En una base ya inicializada no repitas migraciones indiscriminadamente ni reimportes el esquema. Aplica sólo las pendientes y en el orden que corresponda a esa base:

- `009_desarrollo_aplicaciones_multiplataforma.sql` añade DAM si aún no existe.
- `010_detalles_ofertas.sql` añade los campos persistidos de título, descripción, modalidad, ubicación, requisitos y tecnologías.
- `012_alta_alumnos_personal.sql` habilita altas de alumnos por personal, guardando ciclo, curso y observaciones administrativas.
- `013_activacion_cuentas_alumno.sql` almacena hashes de enlaces de activación de un solo uso.
- `014_identificador_fiscal_personal.sql` añade un NIF único para las cuentas de Administrador, Coordinador y Observador.
- `007_webhooks.sql` crea las bandejas `webhook_inbox` y `webhook_outbox`; `008_api_access_tokens.sql` crea la tabla de tokens API; `009_webhook_responses.sql` añade las columnas de respuesta HTTP y se ejecuta una sola vez.

Las dos primeras son idempotentes; `009_webhook_responses.sql` contiene `ALTER TABLE` no condicional y no debe repetirse cuando las columnas ya existen. Para las migraciones de webhook y tokens, consulta [Integraciones y webhooks](/webhooks). Los scripts de inicialización de Compose no vuelven a ejecutarse al arrancar un volumen existente.

En una base existente, aplica `012_alta_alumnos_personal.sql` una sola vez con el procedimiento `docker compose cp` descrito en el README. La migración permite que los expedientes creados por el centro no incluyan un número de Seguridad Social, y añade curso y observaciones administrativas; los expedientes existentes se conservan.

En una base existente, aplica `014_identificador_fiscal_personal.sql` una sola vez antes de usar el nuevo login. Conserva abierta la sesión del administrador y, después de aplicar la migración, asigna en **Usuarios** el NIF/DNI/NIE del administrador y del resto del personal. Las cuentas antiguas no tienen ese dato. En esa misma pantalla el administrador puede corregir los CIF de empresas existentes. El acceso usa NIF/DNI/NIE para Alumno y personal, y CIF para Empresa; el correo no es un identificador de acceso.

Si se perdió la sesión administrativa, en desarrollo local asigna el NIF con el asistente interactivo: `docker compose exec -it app php scripts/asignar_nif_personal.php`. Introduce el correo existente y el NIF real de la persona; el asistente solo funciona por CLI en `APP_ENV=development` o `local`.

Para pruebas locales sin enviar correo externo, configura `FCT_MAIL_TRANSPORT=mailpit`, `FCT_MAIL_HOST=mailpit`, `FCT_MAIL_PORT=1025`, `FCT_MAIL_ENCRYPTION=none`, `FCT_MAIL_FROM` y `FCT_APP_URL=http://localhost:8080` en `.env`; los mensajes se consultan en `http://localhost:8025`. Mailpit solo se acepta con `APP_ENV=development`, y el enlace localhost funciona únicamente en el ordenador que ejecuta el proyecto.

Para enviar correo real, usa `FCT_MAIL_TRANSPORT=smtp` y configura `FCT_MAIL_USERNAME`, `FCT_MAIL_PASSWORD` (contraseña de aplicación), `FCT_MAIL_FROM` y una `FCT_APP_URL` HTTPS accesible desde los dispositivos del alumnado. Gmail necesita verificación en dos pasos; no uses la contraseña normal de la cuenta. Recrea el servicio `app` después de editar `.env`. No guardes secretos en Git ni en el chat.

Si se necesita reinicializar completamente el entorno local, primero respalda cualquier dato que quieras conservar. **`docker compose down -v` es destructivo:** elimina la base de datos, los documentos privados, las firmas ClamAV y los volúmenes de dependencias Node del proyecto. Después:

```powershell
docker compose down -v
docker compose up -d --build
docker compose exec -e FCT_ENABLE_DEMO_SEED=1 -e FCT_DEMO_SEED_ALLOW_DOCKER=1 app php scripts/crear_cuentas_demo.php
```

La inicialización completa sólo debe usarse en una instalación local desechable. Para reiniciar únicamente MariaDB y conservar otros volúmenes, sigue la alternativa más segura de [restablecimiento de base de datos en el README](https://github.com/campusedx/Practicas_Desarrollo/blob/main/README.md#restablecimiento-de-base-de-datos-por-cambios-de-esquema-o-migraciones).
