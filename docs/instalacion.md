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

La plantilla local usa puertos alternativos (`18080`, `18083`, `13308`, `13311` y `15174`), activa el perfil `dev` para Mailpit y establece `APP_ENV=development` y `FCT_CLAMAV_ENABLED=false`. Ese último valor omite explícitamente el escaneo para facilitar pruebas locales; el bypass sólo se acepta con `APP_ENV=development`, `test` o `testing`. Si no se definen variables, Compose usa `APP_ENV=production` y mantiene el análisis habilitado (`FCT_CLAMAV_ENABLED=true`).

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
| Aplicación PHP | `http://localhost:18080` (configurable con `APP_PORT`) |
| phpMyAdmin | `http://localhost:18083` (configurable con `PMA_PORT`, sólo loopback) |
| Mailpit (correo local) | `http://localhost:8026` (perfil `dev`, configurable con `MAILPIT_UI_PORT`; SMTP interno en `mailpit:1025`) |
| MariaDB desde el host | `127.0.0.1:13308` (sólo loopback) |
| ClamAV desde el host, para pruebas | `127.0.0.1:13311` (sólo loopback) |
| Vista previa de disponibilidad | `http://localhost:18080/index.php?action=preview-disponibilidad` |
| Vista previa de oferta | `http://localhost:18080/dev/preview-publicar-oferta` |
| Vite HMR (opcional) | `http://localhost:15174` (`npm run dev` o perfil `frontend-dev`) |
| Portal VitePress (opcional) | `http://localhost:5174` (`npm run docs:dev -- --port 5174`) |

La tabla refleja los puertos seguros de la plantilla local; el archivo ignorado `docker-compose.override.yml` puede sustituir los mapeos. Configura `FCT_APP_URL` y `FCT_PUBLIC_BASE_URL` con la URL efectiva de la aplicación. Las vistas previas (`preview-disponibilidad` y `preview-publicar-oferta`) sólo se registran cuando `APP_ENV` es `development` o `local` (`DevelopmentPreviewController::isEnabled()`); con cualquier otro valor, incluido el `production` de reserva de Compose, las rutas no existen. No confundas este criterio con el bypass de ClamAV, que sólo acepta `development`, `test` o `testing` (no `local`). Consulta [Disponibilidad](/componentes/disponibilidad) para el flujo de guardado.

Para instalar dependencias Node y servir el portal de documentación:

```powershell
npm ci
npm run docs:dev -- --port 5174
```

La documentación se compila con `npm run docs:build`. VitePress puede escoger otro puerto si el solicitado está ocupado.

## Cambios de esquema y migraciones

En un volumen nuevo, MariaDB importa únicamente `database/sistema_fct.sql`. Cuando el servicio `app` inicia, `scripts/migrate.php` aplica y registra en `schema_migrations` cada archivo pendiente, en orden alfabético por nombre completo; los scripts SQL de migración no se ejecutan directamente durante la inicialización de MariaDB.

En una base ya inicializada no reimportes el esquema ni ejecutes scripts SQL manualmente: al iniciar la aplicación, el ejecutor comprueba las versiones y checksums y aplica sólo las pendientes. Varias migraciones tienen prefijos numéricos repetidos; identifica cada archivo por su nombre completo:

- `009_desarrollo_aplicaciones_multiplataforma.sql` añade DAM si aún no existe.
- `010_detalles_ofertas.sql` añade los campos persistidos de título, descripción, modalidad, ubicación, requisitos y tecnologías.
- `012_alta_alumnos_personal.sql` habilita altas de alumnos por personal, guardando ciclo, curso y observaciones administrativas.
- `013_activacion_cuentas_alumno.sql` almacena hashes de enlaces de activación de un solo uso.
- `014_identificador_fiscal_personal.sql` añade un NIF único para las cuentas de Administrador, Coordinador y Observador.
- `015_alta_personal_institucional.sql` añade nombre y apellidos del personal y los tokens de activación por correo para sus cuentas.
- `016_solicitudes_reenvio_activacion.sql` crea la bandeja de solicitudes pendientes de reenvío supervisado de enlaces.
- `017_trazabilidad_administrativa.sql` crea `audit_logs` con el actor, nombre completo, acción, entidad, registro afectado, IP y fecha/hora.
- `019_unificar_audit_logs.sql` armoniza el esquema de auditoría existente.
- `020_nullable_audit_record.sql` permite auditar accesos denegados sin un registro afectado.
- `007_webhooks.sql` crea las bandejas `webhook_inbox` y `webhook_outbox`; `008_api_access_tokens.sql` crea la tabla de tokens API; `009_webhook_responses.sql` añade las columnas de respuesta HTTP y se ejecuta una sola vez.

`scripts/migrate.php` registra cada archivo una sola vez y verifica su checksum en los arranques posteriores. No edites una migración ya aplicada: añade una nueva. La migración `017_trazabilidad_administrativa.sql` se adopta cuando el esquema `audit_logs` compatible ya existe y se armoniza después. Para las migraciones de webhook y tokens, consulta [Integraciones y webhooks](/webhooks).

`012_alta_alumnos_personal.sql` permite que los expedientes creados por el centro no incluyan un número de Seguridad Social, y añade curso y observaciones administrativas; los expedientes existentes se conservan.

Tras aplicar automáticamente `014_identificador_fiscal_personal.sql`, asigna en **Usuarios** el NIF/DNI/NIE del administrador y del resto del personal. Las cuentas antiguas no tienen ese dato. En esa misma pantalla el administrador puede corregir el NIF (Persona Jurídica) de empresas existentes. El inicio de sesión usa el identificador fiscal; el correo no es un identificador de acceso.

El alumnado no dispone de autorregistro público: Administradores y Coordinadores aprovisionan las cuentas desde **Alumnado**. El alta requiere NIF/DNI/NIE, nombre, apellidos, correo, ciclo, curso y observaciones administrativas; crea una cuenta inactiva y envía un token de activación de un solo uso, válido 24 horas, para que el alumno establezca su contraseña. Solo se guarda el hash del token.

Para aprovisionar desde **Usuarios** cuentas de Administrador, Coordinador u Observador, el Administrador debe indicar correo, NIF/DNI/NIE (obligatorio), nombre y apellidos. Las cuentas creadas quedan inactivas hasta que su titular establece la contraseña mediante el enlace de activación, de un solo uso y válido 24 horas.

Las URL de activación deben usar HTTPS. La única excepción es el circuito local de Mailpit: `FCT_MAIL_TRANSPORT=mailpit` con `APP_ENV=development` y una URL HTTP de loopback (`localhost`, `127.0.0.1` o `[::1]`). Mailpit se limita a ese entorno local; no uses esa excepción para correo real ni para enlaces accesibles desde otros dispositivos.

La migración `016_solicitudes_reenvio_activacion.sql` habilita las solicitudes de reenvío después de las migraciones de activación de alumnado `013` y personal `015`. Para formatos válidos, la respuesta pública es genérica y no confirma si el correo y el identificador corresponden a una cuenta. El límite se aplica por cuenta: no se duplican solicitudes pendientes ni se admite una nueva durante las 24 horas posteriores a la solicitud. El Administrador revisa la bandeja **Reenvíos de activación** y aprueba manualmente cada envío; al aprobar, se invalida el enlace anterior y se emite otro válido 24 horas. Si falla el correo, la solicitud queda pendiente y el enlace anterior no se invalida.

La migración `017_trazabilidad_administrativa.sql` crea `audit_logs`; si ya existe un esquema compatible, el ejecutor registra la migración y la armoniza posteriormente. `AuditLogService`, a través de `Auditoria`, registra en esa tabla los eventos instrumentados y mantiene también la tabla histórica `auditoria`. Los campos de trazabilidad incluyen ID y nombre completo del actor, acción, entidad afectada, ID del registro modificado, IP, evento, detalle y fecha/hora.

Para revisar el estado de las migraciones dentro del contenedor:

```powershell
docker compose exec app php scripts/migrate.php --status
```

Si se perdió la sesión administrativa, en desarrollo local asigna el NIF con el asistente interactivo: `docker compose exec -it app php scripts/asignar_nif_personal.php`. Introduce el correo existente y el NIF real de la persona; el asistente solo funciona por CLI en `APP_ENV=development` o `local`.

Para pruebas locales sin enviar correo externo, configura `FCT_MAIL_TRANSPORT=mailpit`, `FCT_MAIL_HOST=mailpit`, `FCT_MAIL_PORT=1025`, `FCT_MAIL_ENCRYPTION=none`, `FCT_MAIL_FROM` y `FCT_APP_URL` con la URL local efectiva de la aplicación; los valores predeterminados son `http://localhost:18080` y `http://localhost:8026` para consultar los mensajes. Mailpit y los enlaces HTTP de loopback solo se aceptan con `APP_ENV=development`; el enlace local funciona únicamente en el equipo que ejecuta el proyecto.

Para enviar correo real, usa `FCT_MAIL_TRANSPORT=smtp` y configura `FCT_MAIL_USERNAME`, `FCT_MAIL_PASSWORD` (contraseña de aplicación), `FCT_MAIL_FROM` y una `FCT_APP_URL` HTTPS accesible desde los dispositivos del alumnado. Gmail necesita verificación en dos pasos; no uses la contraseña normal de la cuenta. Recrea el servicio `app` después de editar `.env`. No guardes secretos en Git ni en el chat.

Si se necesita reinicializar completamente el entorno local, primero respalda cualquier dato que quieras conservar. **`docker compose down -v` es destructivo:** elimina la base de datos, los documentos privados, las firmas ClamAV y los volúmenes de dependencias Node del proyecto. Después:

```powershell
docker compose down -v
docker compose up -d --build
docker compose exec -e FCT_ENABLE_DEMO_SEED=1 -e FCT_DEMO_SEED_ALLOW_DOCKER=1 app php scripts/crear_cuentas_demo.php
```

La inicialización completa sólo debe usarse en una instalación local desechable. Para reiniciar únicamente MariaDB y conservar otros volúmenes, sigue la alternativa más segura de [restablecimiento de base de datos en el README](https://github.com/campusedx/Practicas_Desarrollo/blob/main/README.md#restablecimiento-de-base-de-datos-por-cambios-de-esquema-o-migraciones).
