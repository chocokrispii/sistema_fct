# Sistema FCT

Aplicación web para gestionar prácticas de Formación en Centros de Trabajo
(FCT): cuentas y perfiles, sedes y convenios de empresa, ofertas, disponibilidad
del alumnado, asignaciones y consultas. Está desarrollada en PHP con una
arquitectura MVC propia, sin framework ni dependencias de Composer.

## Requisitos

- XAMPP con Apache, MariaDB/MySQL y PHP 8.2 o compatible.
- Extensiones PHP `PDO_MYSQL`, `fileinfo` y `mbstring` habilitadas. Se usan
  sesiones, la función de carga de archivos y `proc_open` para el análisis.
- ClamAV con `clamscan` configurado para permitir cargas de convenios; si no
  está disponible, la aplicación rechaza las cargas.
- Git, si se va a clonar el repositorio.

## Inicio con Docker Compose

Docker Compose ejecuta PHP 8.2 con Apache, MariaDB 10.4.32 y el servicio oficial
ClamAV. La versión de MariaDB coincide con la instalación XAMPP local observada;
10.4 ya no recibe soporte, por lo que esta composición es exclusivamente para
desarrollo local y no debe desplegarse en producción. El Dockerfile instala
`pdo_mysql` y `mbstring`; `fileinfo` viene con la imagen PHP. Composer está
disponible en el contenedor, aunque esta aplicación no tiene dependencias
Composer. Los datos de MariaDB, las firmas de ClamAV y los archivos privados se
guardan en volúmenes Docker.

1. Copia la plantilla de entorno:

   ```powershell
   Copy-Item .env.docker.example .env
   ```

2. Construye y arranca los servicios:

   ```powershell
   docker compose up -d --build
   ```

3. Las migraciones se aplican automáticamente al crear por primera vez el
   volumen de la base de datos. Para generar cuentas de demostración, ejecuta
   el seed explícitamente y guarda las contraseñas aleatorias que imprima:

   ```powershell
   docker compose exec -e FCT_ENABLE_DEMO_SEED=1 -e FCT_DEMO_SEED_ALLOW_DOCKER=1 app php scripts/crear_cuentas_demo.php
   docker compose ps
   docker compose exec app php -v
   ```

   Espera a que `db` y `clamav` estén saludables; en el primer arranque, ClamAV
   puede tardar en descargar sus firmas. El dump base y las migraciones 001–006
   se importan una sola vez al inicializar un volumen de base de datos vacío.
   El seed crea cuentas ausentes con contraseñas aleatorias. Para establecer
   contraseñas elegidas explícitamente, pásalas mediante las variables
   `FCT_DEMO_PASSWORD_ADMINISTRADOR`, `FCT_DEMO_PASSWORD_COORDINADOR`,
   `FCT_DEMO_PASSWORD_OBSERVADOR`, `FCT_DEMO_PASSWORD_EMPRESA` y
   `FCT_DEMO_PASSWORD_ALUMNO`; para reemplazar contraseñas de cuentas demo ya
   existentes, habilita además `FCT_DEMO_SEED_RESET_PASSWORDS=1`. Las
   contraseñas proporcionadas por entorno nunca se imprimen. No guardes estos
   valores en archivos versionados.
   Abre `http://localhost:8080` (o el puerto definido por `APP_PORT` en `.env`).
   Las contraseñas de desarrollo son configurables en `.env`; no reutilices
   esos valores fuera del entorno local.

## Instalación local con XAMPP

### 1. Configurar Apache

La opción requerida para un despliegue es configurar un VirtualHost cuyo
`DocumentRoot` sea exclusivamente la carpeta `public/` del proyecto. Así
`app/`, `config/`, `database/` y `storage/` quedan fuera del acceso web directo.
La carpeta `storage/` también deniega el acceso mediante `.htaccess` como
defensa adicional; no uses esa regla como sustituto de servir solo `public/`.
La raíz del proyecto deniega el acceso directo a todo; solo `public/` lo
rehabilita.

Para una prueba local rápida con la configuración habitual de XAMPP, también
se puede abrir:

```text
http://localhost/proyecto-practicas/public/
```

Este modo es solo para desarrollo local. No expongas la raíz del repositorio en
Internet ni uses esta configuración como despliegue productivo. Apache debe
permitir `AllowOverride AuthConfig Options` en la raíz del proyecto y
`AllowOverride AuthConfig FileInfo Limit Options` en `public/` para procesar las
denegaciones, la concesión exclusiva de `public/`, el límite de cuerpo de
petición y la desactivación del listado de directorios.
`storage/.htaccess` deniega además el acceso directo cuando el proyecto se
sirve desde un directorio superior.

Para el VirtualHost, concede acceso únicamente a `public/` y establece
`LimitRequestBody 12582912` en su bloque `<Directory>` (12 MiB, compatible con
el máximo de carga PHP de 10 MiB). No crees un alias web para `storage/`.
Configura en Apache `FCT_STORAGE_PATH`, `FCT_CLAMSCAN` y
`FCT_CLAMAV_DATABASE`, por ejemplo:

```apache
DocumentRoot "C:/xampp/htdocs/proyecto-practicas/public"
<Directory "C:/xampp/htdocs/proyecto-practicas">
    Require all denied
    AllowOverride AuthConfig Options
    Options -Indexes
</Directory>
<Directory "C:/xampp/htdocs/proyecto-practicas/public">
    Require all granted
    AllowOverride AuthConfig FileInfo Limit Options
    Options -Indexes
    LimitRequestBody 12582912
</Directory>
SetEnv FCT_STORAGE_PATH "C:/xampp/private/sistema-fct"
SetEnv FCT_CLAMSCAN "C:/Program Files/ClamAV/clamscan.exe"
SetEnv FCT_CLAMAV_DATABASE "C:/ProgramData/ClamAV/db"
```

En XAMPP, crea previamente el directorio de almacenamiento fuera del
`DocumentRoot` y configura `FCT_STORAGE_PATH`; no existe una ruta
predeterminada. La aplicación
resuelve ambas rutas y rechaza directorios inexistentes, rutas relativas,
almacenamientos dentro del `DocumentRoot` o directorios que lo contengan. Mantén
el almacenamiento fuera del árbol del proyecto y de `htdocs`. En XAMPP local,
`public/.htaccess` configura los límites PHP por directorio y el límite de
Apache, siempre que esté habilitado `mod_php` y las opciones `AllowOverride`
indicadas. Define las variables de entorno en Apache o en el VirtualHost, no en
`.htaccess`. La configuración comprobada para Apache queda limitada a esta
aplicación.

En Windows, instala la distribución oficial de Cisco Talos desde WinGet:

```powershell
winget install --id Cisco.ClamAV --exact --source winget
New-Item -ItemType Directory -Force -Path "C:\ProgramData\ClamAV\db"
Copy-Item config\clamav\freshclam.conf.example "C:\ProgramData\ClamAV\freshclam.conf"
& "C:\Program Files\ClamAV\freshclam.exe" --config-file="C:\ProgramData\ClamAV\freshclam.conf"
```

El `public/.htaccess` de desarrollo ya apunta a `clamscan.exe` y a ese
directorio de firmas. Mantén el almacenamiento privado con permisos de
modificación solo para la identidad que ejecuta Apache, y lectura restringida;
no concedas escritura a `Usuarios autentificados`. Programa `freshclam` para
actualizar las firmas al menos cada dos horas. En la instalación local se usa
una tarea del usuario que ejecuta Apache, con actualización disponible al
iniciar sesión:

```powershell
$action = New-ScheduledTaskAction -Execute "C:\Program Files\ClamAV\freshclam.exe" -Argument '--config-file="C:\ProgramData\ClamAV\freshclam.conf"' -WorkingDirectory "C:\ProgramData\ClamAV"
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddHours(2) -RepetitionInterval (New-TimeSpan -Hours 2) -RepetitionDuration (New-TimeSpan -Days 3650)
$principal = New-ScheduledTaskPrincipal -UserId ([System.Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 20)
Register-ScheduledTask -TaskName "FCT-ClamAV-Freshclam" -Action $action -Trigger $trigger -Principal $principal -Settings $settings
```

`FileScanner` rechaza cargas si el análisis falla o las firmas tienen más de
tres días; no desactives esta protección para resolver problemas de instalación.

### Límites y permisos de archivos

Combina los valores de `config/php-upload.ini` con el `php.ini` que carga Apache
(en XAMPP suele ser `C:\xampp\php\php.ini`) y reinicia Apache:

```ini
upload_max_filesize = 10M
post_max_size = 12M
max_file_uploads = 1
max_input_time = 60
```

La aplicación vuelve a comprobar los límites PHP antes de aceptar una carga,
limita cada PDF a 10 MiB y rechaza cargas truncadas por PHP. Apache aplica
además `LimitRequestBody 12582912` mediante `public/.htaccess` o el VirtualHost.
No aumentes uno de estos límites sin revisar los otros. Verifica los valores
efectivos con `phpinfo()` solo en un entorno local y elimina esa página al
terminar; en XAMPP, el PHP de consola puede cargar un `php.ini` diferente al de
Apache.

En Windows, concede lectura/escritura solo a la identidad real del servicio
Apache y control total a Administradores/SYSTEM; retira herencia y no concedas
permisos a `Everyone` o `Users`. En Linux, usa un propietario del servicio web,
directorios `0700` y archivos `0600`. La aplicación establece esos modos para
los directorios y PDFs nuevos; las ACL NTFS deben prepararse y revisarse en el
servidor, ya que no se sustituyen con `chmod()`.

Ejemplo de ACL NTFS (sustituye `EQUIPO\ApacheSvc` por la cuenta que ejecuta
Apache; conserva solo los principales realmente necesarios):

```powershell
New-Item -ItemType Directory -Path "C:\xampp\private\sistema-fct" -Force
icacls "C:\xampp\private\sistema-fct" /inheritance:r
icacls "C:\xampp\private\sistema-fct" /grant:r "BUILTIN\Administrators:(OI)(CI)F" "NT AUTHORITY\SYSTEM:(OI)(CI)F" "EQUIPO\ApacheSvc:(OI)(CI)M"
```

Los PDFs pasan por validación de extensión, MIME y tamaño y, antes de persistir,
por ClamAV. En XAMPP, `FCT_CLAMSCAN` debe señalar al ejecutable confiable con
ruta absoluta; en Docker se usa `FCT_CLAMD_HOST` y el protocolo TCP de ClamD.
Si el antivirus no está configurado, no se puede contactar, tiene firmas con
más de tres días o no puede completar el análisis, la carga falla cerrada.
Mantén ClamAV instalado y sus firmas actualizadas, supervisa los fallos del
análisis y examina el almacenamiento existente antes de habilitar este flujo.
Si se detecta malware, no publiques el documento: aíslalo, registra el
incidente y elimínalo según la política del centro.

### 2. Crear e importar la base

1. Inicia Apache y MySQL desde el panel de XAMPP.
2. En phpMyAdmin, crea una base de datos vacía llamada `sistema_fct` con
   codificación `utf8mb4` (por ejemplo, collation
   `utf8mb4_unicode_ci`).
3. Selecciona esa base e importa `database/sistema_fct.sql`. El volcado crea
   las tablas base y contiene registros de demostración; no crea la base de
   datos por sí solo.
4. En phpMyAdmin, selecciona `sistema_fct` y ejecuta
   `database/migrations/001_renombrar_nombre_especialidad.sql`. Esta migración
   corrige el nombre antiguo `nombre_specialidad` a
   `nombre_especialidad`.
5. Ejecuta `database/migrations/002_fct_workflows.sql`. Añade estados de cuenta
   y ofertas, datos de tutor y empresa, y las tablas de sedes, convenios,
   disponibilidad, asignaciones y auditoría. La migración omite columnas,
   índices y tablas ya existentes, para admitir volcados que incluyan parte de
   esos cambios.
6. Ejecuta `database/migrations/003_login_y_recuperacion.sql` para añadir el
   control de intentos y las solicitudes de recuperación de cuentas.
7. Ejecuta `database/migrations/004_convenios_por_especialidad.sql` para asociar
   cada convenio a un ciclo formativo y limitarlo a uno por empresa y ciclo.
   Los convenios preexistentes quedan sin clasificar (`especialidad_id = NULL`)
   y no cubren ofertas; hay que asignarles manualmente el ciclo correcto antes
   de volver a usarlos.
8. Ejecuta `database/migrations/005_horarios_y_calificaciones.sql` para guardar
   franjas semanales (día y horas) y notas finales asociadas a prácticas
   cerradas.
9. Ejecuta `database/migrations/006_alumno_codigo_postal.sql` para incorporar
   el código postal necesario para editar y validar los datos personales del
   alumnado.
10. Comprueba que las migraciones terminen sin error. La migración `001` no es
   idempotente: omítela si la columna ya se llama `nombre_especialidad`. La
   migración `002`, `004`, `005` y `006` pueden volver a ejecutarse para
   completar un esquema parcial. Ejecuta la migración `003` una sola vez.

El volcado no selecciona la base con `USE`; elige explícitamente la base destino
antes de importarlo. Las migraciones tampoco seleccionan una base. El dump
actual contiene dos expedientes de alumno y direcciones que apuntan a registros
existentes. No es necesario aplicar el seed para completar la instalación.

### 3. Configurar la conexión

La conexión PDO se configura en `config/database.php`. Los valores locales
predeterminados de XAMPP son:

| Variable | Valor predeterminado |
|---|---|
| `FCT_DB_HOST` | `localhost` |
| `FCT_DB_USER` | `root` |
| `FCT_DB_PASS` | Cadena vacía |
| `FCT_DB_NAME` | `sistema_fct` |

Si se usan otros datos, define las variables de entorno para el proceso que
ejecuta PHP/Apache. Configurarlas solo en una terminal no garantiza que el
servicio Apache de XAMPP las herede. En un entorno real crea un usuario MariaDB
con permisos limitados y no uses `root` sin contraseña.

## Cuentas de demostración

El volcado incluye estas cuentas, todas con la contraseña `password123`:

| Rol | Correo |
|---|---|
| Administrador | `admin@centro.com` |
| Coordinador | `coordinador@centro.com` |
| Empresa | `empresa.tech@gmail.com` |
| Alumno | `alumno.menor@alumno.com` |
| Alumno | `alumno.mayor@alumno.com` |

Úsalas únicamente en desarrollo. Las cuentas creadas desde los formularios
requieren una contraseña de 12 a 72 caracteres. La oferta que viene en el dump no
tiene estado aprobado hasta que se gestione conforme al esquema migrado. Para
probar el flujo de empresa, registra y aprueba un convenio que cubra las fechas
de la oferta antes de aprobarla.

El dump base no trae una cuenta Observador y las contraseñas de las cuentas
antiguas pueden no coincidir con el hash importado. Para crear cuentas locales
operativas para los cinco roles, ejecuta desde PowerShell después de aplicar
todas las migraciones:

```powershell
$env:FCT_ENABLE_DEMO_SEED = "1"
& "C:\xampp\php\php.exe" scripts\crear_cuentas_demo.php
Remove-Item Env:FCT_ENABLE_DEMO_SEED
```

El script solo funciona con PHP CLI y la base `sistema_fct` en `localhost`; en
Docker requiere la confirmación adicional `FCT_DEMO_SEED_ALLOW_DOCKER=1` y solo
admite el host de servicio `db`. Crea perfiles de prueba para Coordinador,
Empresa y Alumno; imprime las contraseñas aleatorias de las cuentas nuevas una
sola vez y no cambia cuentas ya existentes, salvo que se habilite
explícitamente `FCT_DEMO_SEED_RESET_PASSWORDS=1` y se pase una nueva contraseña
por variable de entorno. No lo ejecutes en una instalación publicada y elimina
o cambia estas cuentas antes de desplegar.
Al iniciar sesión, cada rol aterriza en un módulo propio; el menú ofrece el
resto de pantallas autorizadas. El alumnado puede gestionar su horario semanal
y consultar las notas de prácticas finalizadas; Administradores y Coordinadores
registran o actualizan notas de 0 a 10 tras cerrar una práctica; Observadores
solo las consultan.

Las cuentas `admin@centro.com`, `coordinador@centro.com`,
`empresa.tech@gmail.com`, `alumno.mayor@alumno.com` y
`alumno.menor@alumno.com` pertenecen al conjunto heredado del dump, no a las
cuentas `demo.*@fct.local`. No las borres automáticamente ni ejecutes el seed
de ejemplo sobre una base que ya contiene datos: las cuentas antiguas pueden
tener ofertas, asignaciones u otras referencias históricas. Desactiva cuentas
que no deban iniciar sesión; usa el borrado permanente solo para cuentas sin
historial y conserva el registro de auditoría.

## Funcionalidades y flujos

- **Acceso y seguridad:** login/logout, sesiones, control por rol y token CSRF
  en todas las peticiones POST.
- **Alumno:** registro con expediente y tutor legal para menores de edad;
  portal privado con sus datos de expediente, disponibilidad y prácticas;
  edición del perfil con validación de DNI/NIE, teléfono, correo y código postal;
  el perfil permite completar o corregir los datos del tutor legal de menores,
  incluso en expedientes heredados incompletos;
  actualización de disponibilidad por periodo, turno y franja semanal por día
  en intervalos de diez minutos;
  consulta de prácticas propias y sus calificaciones finales.
- **Empresa:** registro, gestión de sedes, carga de convenios PDF y publicación
  de ofertas asociadas al perfil autenticado. Se registra como máximo un
  convenio por ciclo formativo, y solo cubre ofertas del mismo ciclo.
- **Revisión del centro:** Administradores y Coordinadores revisan convenios y
  ofertas. Una oferta solo puede aprobarse cuando un convenio aprobado cubre
  todo su periodo.
- **Matching y asignaciones:** búsqueda de alumnos compatibles por ciclo,
  fechas, turno y horario semanal compatible; asignación transaccional con control de
  plazas; cierre de prácticas. Las pantallas heredadas de asignaciones y
  portada redirigen a sus equivalentes MVC; no guardan ni muestran datos de
  ejemplo.
- **Calificaciones:** calificación final de prácticas cerradas con nota de
  0–10, observaciones y auditoría del usuario evaluador; acceso de lectura para
  el propio alumno y para el Observador.
- **Administración:** gestión de cuentas internas, activación/desactivación y
  borrado permanente con confirmación. Se impide borrar la propia cuenta, las
  cuentas con historial operativo y las referencias que la base de datos deba
  conservar; cada borrado se registra en auditoría.
- **Consultas:** filtros por ciclo, estado y fechas, con un máximo de 500 filas.
  El rol Observador dispone de acceso de lectura a las consultas y asignaciones.
- **Auditoría:** registro de operaciones de revisión, asignación,
  administración y descargas de convenios permitidas o denegadas.

### Matriz de acceso aplicada

El middleware de rutas es el control de acceso del servidor; los enlaces de la
interfaz no conceden permisos. Las rutas de descarga se autorizan así:

| Rol | Convenios PDF |
|---|---|
| Administrador | Lectura de cualquier convenio |
| Coordinador | Lectura de cualquier convenio |
| Empresa | Solo convenios de su propio perfil |
| Observador | Sin acceso a convenios |
| Alumno | Sin acceso a convenios |

Observador conserva únicamente el acceso de lectura establecido para consultas,
asignaciones y calificaciones. Las denegaciones y descargas autorizadas de convenios se anotan
en `auditoria` con el usuario, convenio y resultado; no se guardan rutas físicas
ni contenido del documento en el detalle.

Los PDFs se guardan fuera de `public/` y se sirven como adjuntos desde la ruta
autenticada; no se exponen rutas ni nombres físicos. El proyecto no implementa
cargas de documentos de expedientes. La recuperación de contraseñas requiere
verificación manual del centro y no envía correos automáticamente.

La disponibilidad semanal se persiste por día con una franja horaria por día,
vinculada al periodo y turno de disponibilidad. La calificación final solo se
puede registrar para una práctica cerrada; se persisten la nota, observaciones,
evaluador y marcas de tiempo.

## Prueba de integración

La prueba `tests/file_security.php` comprueba la matriz de acceso, el almacenamiento
privado y que la carga se rechaza cuando falta el antivirus; no necesita una
base de datos y puede ejecutarse con:

```powershell
& "C:\xampp\php\php.exe" tests\file_security.php
```

`tests/run.php` modifica datos y exige una base desechable cuyo nombre
empiece por `fct_test_`. **No la ejecutes contra `sistema_fct` ni contra una
base con datos que quieras conservar.**

Una forma sencilla de prepararla es crear `fct_test_local` en phpMyAdmin,
importar en ella el dump base y aplicar las migraciones `001` a `006` en orden,
teniendo en cuenta que la `001` solo se ejecuta si la columna conserva el nombre
antiguo y la `003` se ejecuta una sola vez. Al importar el dump, selecciona
explícitamente `fct_test_local`. Después, desde PowerShell, ejecuta:

```powershell
$env:FCT_TEST_DB_NAME = "fct_test_local"
$env:FCT_DB_NAME = $env:FCT_TEST_DB_NAME
& "C:\xampp\php\php.exe" tests\run.php
```

El script comprueba que las variables apunten al mismo nombre con prefijo
permitido, valida datos de demostración y crea y elimina sus fixtures durante
la prueba. No crea ni elimina la base completa. Al terminar, elimina
`fct_test_local` desde phpMyAdmin si ya no la necesitas.

## Estructura del proyecto

```text
.htaccess           Bloquea el acceso web directo fuera de public/
app/
  Controllers/       Coordinación de peticiones y validación de entrada
  Core/              Renderizado de vistas
  Middleware/        Autenticación, autorización y protección CSRF
  Models/            Consultas y persistencia
  Views/             Plantillas PHP por módulo
config/              Conexión PDO y configuración privada de archivos
database/
  migrations/        Cambios incrementales del esquema
  seeds/             Datos de muestra alternativos
  sistema_fct.sql    Esquema base y datos de demostración
docs/                Informe técnico histórico
public/
  index.php          Front controller y entrada web
  assets/css/        Estilos
  assets/js/         JavaScript auxiliar heredado
routes/              Rutas, métodos HTTP y roles permitidos
storage/             Reglas heredadas de denegación; no usar para guardar archivos
tests/               Prueba de integración
```

La aplicación web usa `public/index.php` como front controller y define sus
rutas en `routes/web.php`. Las vistas se renderizan con el layout común de
`app/Views/layouts/main.php`. La conexión se obtiene desde
`config/database.php`.

Las páginas HTML heredadas de portada, registro de alumno y asignaciones no
crean rutas ni guardan datos directamente. La portada y el registro redirigen
al front controller; la página de asignaciones ofrece enlaces a sus rutas
oficiales. El registro activo está en `app/Views/alumnos/registro.php`, y las
asignaciones se gestionan mediante las rutas de `public/index.php`.

`public/assets/js/alumnos_js.js` es un recurso heredado, separado de las vistas
MVC actuales. La maqueta de alumno no envía ni conserva datos personales y el
script elimina del navegador las claves antiguas de sus campos en
`localStorage`; no debe usarse como flujo de registro.

## Dump y seed: diferencia

- `database/sistema_fct.sql` crea el esquema base y carga los datos de ejemplo.
  Es el camino recomendado para una instalación nueva, seguido por las
  migraciones.
- `database/seeds/001_sample_data.sql` contiene registros de muestra para un
  esquema ya migrado, pero empieza con `USE sistema_fct`. No lo importes además
  del dump completo ni lo uses sin comprobar cuidadosamente la base seleccionada:
  puede duplicar IDs/cuentas y dirigir las inserciones a `sistema_fct`.

## Documentación relacionada

- [documentacion/explicacion.md](documentacion/explicacion.md): descripción extensa de arquitectura,
  comportamiento de módulos, tablas, seguridad y limitaciones conocidas.
- [documentacion/Guía Exhaustiva de Pruebas - Sistema FCT.pdf](documentacion/Guía%20Exhaustiva%20de%20Pruebas%20-%20Sistema%20FCT.pdf):
  guía detallada de pruebas funcionales y técnicas.
- [docs/informe_jornada_2026-10-07.md](docs/informe_jornada_2026-10-07.md):
  informe de trabajo con notas históricas; algunas secciones describen fases
  anteriores y no deben tomarse como estado actual del proyecto.
