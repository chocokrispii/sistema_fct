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
| MariaDB desde el host | `127.0.0.1:3307` (mapeo predeterminado en `docker-compose.yml`) |
| ClamAV desde el host, para pruebas | `127.0.0.1:3310` (sólo loopback) |
| Vista previa de disponibilidad | `http://localhost:8080/index.php?action=preview-disponibilidad` |
| Vista previa de oferta | `http://localhost:8080/dev/preview-publicar-oferta` |
| Vite HMR (opcional) | `http://localhost:5173` (`npm run dev` o perfil `frontend-dev`) |
| Portal VitePress (opcional) | `http://localhost:5174` (`npm run docs:dev -- --port 5174`) |

La tabla muestra los puertos predeterminados de Compose. Un archivo `docker-compose.override.yml` puede reemplazar los mapeos para una instalación local; consulta ese archivo y usa la dirección resultante al configurar `FCT_APP_URL`. En este working tree, el override local redefine varios puertos y `FCT_APP_URL`; esos valores no son predeterminados compartidos. Las vistas previas (`preview-disponibilidad` y `preview-publicar-oferta`) sólo se registran cuando `APP_ENV` es `development` o `local` (`DevelopmentPreviewController::isEnabled()`); con cualquier otro valor, incluido el `production` de reserva de Compose, las rutas no existen. No confundas este criterio con el bypass de ClamAV, que sólo acepta `development`, `test` o `testing` (no `local`). El puerto de MariaDB predeterminado no tiene variable `.env`; para cambiarlo sin un override, edita el mapeo de host en Compose. Consulta [Disponibilidad](/componentes/disponibilidad) para el flujo de guardado.

Para instalar dependencias Node y servir el portal de documentación:

```powershell
npm ci
npm run docs:dev -- --port 5174
```

La documentación se compila con `npm run docs:build`. VitePress puede escoger otro puerto si el solicitado está ocupado.

## Cambios de esquema y migraciones

En un volumen nuevo, MariaDB importa `database/sistema_fct.sql` y Compose ejecuta las migraciones listadas explícitamente en `docker-compose.yml`. Varias líneas de migración tienen prefijos numéricos repetidos; identifica cada archivo por su nombre completo, no sólo por el número.

En una base ya inicializada no reimportes el esquema ni repitas migraciones indiscriminadamente. Aplica sólo las que falten, una sola vez y respetando el orden y las dependencias de esa base. En particular, para activar el aprovisionamiento y la trazabilidad, el orden es `012`, `013`, `014`, `015`, `016` y `017`; puede haber otras migraciones intercaladas según la historia de la base:

- `009_desarrollo_aplicaciones_multiplataforma.sql` añade DAM si aún no existe.
- `010_detalles_ofertas.sql` añade los campos persistidos de título, descripción, modalidad, ubicación, requisitos y tecnologías.
- `012_alta_alumnos_personal.sql` habilita altas de alumnos por personal, guardando ciclo, curso y observaciones administrativas.
- `013_activacion_cuentas_alumno.sql` almacena hashes de enlaces de activación de un solo uso.
- `014_identificador_fiscal_personal.sql` añade un NIF único para las cuentas de Administrador, Coordinador y Observador.
- `015_alta_personal_institucional.sql` añade nombre y apellidos del personal y los tokens de activación por correo para sus cuentas.
- `016_solicitudes_reenvio_activacion.sql` crea la bandeja de solicitudes pendientes de reenvío supervisado de enlaces.
- `017_trazabilidad_administrativa.sql` crea `audit_logs` con el actor, nombre completo, acción, entidad, registro afectado, IP y fecha/hora.
- `018_tipo_documento_alumno.sql` añade el tipo de documento del alumnado y conserva los registros existentes como NIF / NIE / DNI.
- `019_activacion_cuentas_empresa.sql` crea la tabla de tokens de activación para los registros de empresa.
- `008_api_access_tokens.sql` crea la tabla de tokens para la API REST.

`009_desarrollo_aplicaciones_multiplataforma.sql` y `010_detalles_ofertas.sql` son idempotentes. Trata también `012`–`019` como migraciones de aplicación única y comprueba cuáles ya están en la base antes de ejecutar nada. Los scripts de inicialización de Compose no vuelven a ejecutarse al arrancar un volumen existente.

En una base existente, aplica `012_alta_alumnos_personal.sql` una sola vez con el procedimiento `docker compose cp` descrito en el README. La migración permite que los expedientes creados por el centro no incluyan un número de Seguridad Social, y añade curso y observaciones administrativas; los expedientes existentes se conservan.

En una base existente, aplica `014_identificador_fiscal_personal.sql` una sola vez antes de usar el nuevo login. Conserva abierta la sesión del administrador y, después de aplicar la migración, asigna en **Usuarios** el NIF / NIE / DNI del administrador y del resto del personal. Las cuentas antiguas no tienen ese dato. En esa misma pantalla el administrador puede corregir el NIF (Persona Jurídica) de empresas existentes. El acceso usa NIF / NIE / DNI para personal y alumnado, pasaporte para el alumnado cuando corresponda, y NIF (Persona Jurídica) para empresas; el correo no es un identificador de acceso.

El alumnado no dispone de autorregistro público: Administradores y Coordinadores aprovisionan las cuentas desde **Alumnado**. El alta requiere NIF / NIE / DNI o pasaporte, nombre, apellidos, correo, ciclo, curso y observaciones administrativas; crea una cuenta inactiva y envía un token de activación de un solo uso, válido 24 horas, para que el alumno establezca su contraseña. Solo se guarda el hash del token. Para una base existente, aplica una sola vez `012_alta_alumnos_personal.sql` y después `013_activacion_cuentas_alumno.sql`.

Para aprovisionar desde **Usuarios** cuentas de Administrador, Coordinador u Observador, el Administrador debe indicar correo, NIF / NIE / DNI (obligatorio), nombre y apellidos. Aplica `014_identificador_fiscal_personal.sql` y luego `015_alta_personal_institucional.sql`, una sola vez cada una y solo si aún no se han aplicado. Las cuentas creadas quedan inactivas hasta que su titular establece la contraseña mediante el enlace de activación, de un solo uso y válido 24 horas.

El registro público de empresas también crea la cuenta inactiva y envía un enlace de activación de un solo uso válido 24 horas. Para bases existentes, aplica `019_activacion_cuentas_empresa.sql` una sola vez antes de habilitar ese registro. El formulario no solicita la contraseña inicial: se establece al activar la cuenta.

Las URL de activación deben usar HTTPS. La única excepción es el circuito local de Mailpit: `FCT_MAIL_TRANSPORT=mailpit` con `APP_ENV=development` y una URL HTTP de loopback (`localhost`, `127.0.0.1` o `[::1]`). Mailpit se limita a ese entorno local; no uses esa excepción para correo real ni para enlaces accesibles desde otros dispositivos.

Aplica también `016_solicitudes_reenvio_activacion.sql` una sola vez, después de las migraciones de activación de alumnado `013` y personal `015`, para habilitar las solicitudes de reenvío. Para formatos válidos, la respuesta pública es genérica y no confirma si el correo y el identificador corresponden a una cuenta. El límite se aplica por cuenta: no se duplican solicitudes pendientes ni se admite una nueva durante las 24 horas posteriores a la solicitud. El Administrador revisa la bandeja **Reenvíos de activación** y aprueba manualmente cada envío; al aprobar, se invalida el enlace anterior y se emite otro válido 24 horas. Si falla el correo, la solicitud queda pendiente y el enlace anterior no se invalida.

La migración `017_trazabilidad_administrativa.sql` crea `audit_logs`; en una base existente aplícala una sola vez después de `016`. `AuditLogService`, a través de `Auditoria`, registra en esa tabla los eventos instrumentados y mantiene también la tabla histórica `auditoria`. Los campos de trazabilidad incluyen ID y nombre completo del actor, acción, entidad afectada, ID del registro modificado, IP, evento, detalle y fecha/hora.

La migración `018_tipo_documento_alumno.sql` añade el tipo de documento del expediente. En bases existentes, aplícala una sola vez antes de desplegar esta versión; los documentos ya guardados se clasifican como NIF / NIE / DNI. El alta y la edición permiten seleccionar Pasaporte para casos especiales de alumnado no residente; se validan de 6 a 15 caracteres alfanuméricos.

Para aplicar una migración pendiente en una base existente, usa el procedimiento `docker compose cp` del README, sustituyendo el nombre del archivo. Por ejemplo, para `017`:

```powershell
docker compose cp (Resolve-Path .\database\migrations\017_trazabilidad_administrativa.sql).Path db:/tmp/migracion.sql
'mariadb --user="$MARIADB_USER" --password="$MARIADB_PASSWORD" "$MARIADB_DATABASE" < /tmp/migracion.sql #' | docker compose exec -T db sh -s
```

Si se perdió la sesión administrativa, en desarrollo local asigna el NIF con el asistente interactivo: `docker compose exec -it app php scripts/asignar_nif_personal.php`. Introduce el correo existente y el NIF real de la persona; el asistente solo funciona por CLI en `APP_ENV=development` o `local`.

Para pruebas locales sin enviar correo externo, configura `FCT_MAIL_TRANSPORT=mailpit`, `FCT_MAIL_HOST=mailpit`, `FCT_MAIL_PORT=1025`, `FCT_MAIL_ENCRYPTION=none`, `FCT_MAIL_FROM` y `FCT_APP_URL` con la URL local efectiva de la aplicación; los valores predeterminados son `http://localhost:8080` y `http://localhost:8025` para consultar los mensajes. Mailpit y los enlaces HTTP de loopback solo se aceptan con `APP_ENV=development`; el enlace local funciona únicamente en el equipo que ejecuta el proyecto.

Para enviar correo real, usa `FCT_MAIL_TRANSPORT=smtp` y configura `FCT_MAIL_USERNAME`, `FCT_MAIL_PASSWORD` (contraseña de aplicación), `FCT_MAIL_FROM` y una `FCT_APP_URL` HTTPS accesible desde los dispositivos del alumnado. Gmail necesita verificación en dos pasos; no uses la contraseña normal de la cuenta. Recrea el servicio `app` después de editar `.env`. No guardes secretos en Git ni en el chat.

Si se necesita reinicializar completamente el entorno local, primero respalda cualquier dato que quieras conservar. **`docker compose down -v` es destructivo:** elimina la base de datos, los documentos privados, las firmas ClamAV y los volúmenes de dependencias Node del proyecto. Después:

```powershell
docker compose down -v
docker compose up -d --build
docker compose exec -e FCT_ENABLE_DEMO_SEED=1 -e FCT_DEMO_SEED_ALLOW_DOCKER=1 app php scripts/crear_cuentas_demo.php
```

La inicialización completa sólo debe usarse en una instalación local desechable. Para reiniciar únicamente MariaDB y conservar otros volúmenes, sigue la alternativa más segura de [restablecimiento de base de datos en el README](https://github.com/campusedx/Practicas_Desarrollo/blob/main/README.md#restablecimiento-de-base-de-datos-por-cambios-de-esquema-o-migraciones).
