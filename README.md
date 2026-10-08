# Sistema FCT

Aplicación web para gestionar prácticas de Formación en Centros de Trabajo
(FCT): cuentas y perfiles, sedes y convenios de empresa, ofertas, disponibilidad
del alumnado, asignaciones y consultas. Está desarrollada en PHP con una
arquitectura MVC propia, sin framework ni dependencias de Composer.

## Requisitos

- XAMPP con Apache, MariaDB/MySQL y PHP 8.2 o compatible.
- Extensiones PHP `PDO_MYSQL`, `fileinfo` y `mbstring` habilitadas. Se usan
  sesiones y la función de carga de archivos de PHP.
- Git, si se va a clonar el repositorio.

## Instalación local con XAMPP

### 1. Configurar Apache

La opción recomendada es configurar un VirtualHost cuyo `DocumentRoot` sea
exclusivamente la carpeta `public/` del proyecto. Así `app/`, `config/`,
`database/` y `storage/` quedan fuera del acceso web directo.

Para una prueba local rápida con la configuración habitual de XAMPP, también
se puede abrir:

```text
http://localhost/proyecto-practicas/public/
```

Este modo es para desarrollo local. No expongas la raíz del repositorio en
Internet ni uses esta configuración como despliegue productivo. Asegúrate
además de que Apache permite las reglas `.htaccess` para que
`storage/.htaccess` deniegue el acceso web directo a los archivos almacenados.

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
5. Ejecuta una sola vez `database/migrations/002_fct_workflows.sql`. Añade
   estados de cuenta y ofertas, datos de tutor y empresa, y las tablas de sedes,
   convenios, disponibilidad, asignaciones y auditoría.
6. Revisa que ambas migraciones terminen sin error. No las ejecutes de nuevo:
   no son idempotentes. Si la base ya tiene `nombre_especialidad`, omite la
   migración `001`; aplica la `002` solo si aún no se ha aplicado.

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

## Funcionalidades y flujos

- **Acceso y seguridad:** login/logout, sesiones, control por rol y token CSRF
  en todas las peticiones POST.
- **Alumno:** registro con expediente y tutor legal para menores de edad;
  actualización de disponibilidad por periodo y turno; consulta de prácticas
  propias.
- **Empresa:** registro, gestión de sedes, carga de convenios PDF y publicación
  de ofertas asociadas al perfil autenticado.
- **Revisión del centro:** Administradores y Coordinadores revisan convenios y
  ofertas. Una oferta solo puede aprobarse cuando un convenio aprobado cubre
  todo su periodo.
- **Matching y asignaciones:** búsqueda de alumnos compatibles por ciclo,
  fechas, disponibilidad y turno; asignación transaccional con control de
  plazas; cierre de prácticas.
- **Administración:** gestión de cuentas internas y activación/desactivación.
  No se permite desactivar la propia cuenta ni dejar el sistema sin un
  Administrador activo.
- **Consultas:** filtros por ciclo, estado y fechas, con un máximo de 500 filas.
  El rol Observador dispone de acceso de lectura a las consultas y asignaciones.
- **Auditoría:** registro de algunas operaciones de revisión, asignación y
  administración.

Los documentos PDF se guardan en `storage/uploads/convenios/`, fuera de
`public/`, y se descargan por una ruta autenticada. El acceso de una Empresa se
limita a sus propios convenios. El proyecto no implementa carga de documentos
de expedientes ni recuperación de contraseñas.

## Prueba de integración

La prueba `tests/run.php` modifica datos y exige una base desechable cuyo nombre
empiece por `fct_test_`. **No la ejecutes contra `sistema_fct` ni contra una
base con datos que quieras conservar.**

Una forma sencilla de prepararla es crear `fct_test_local` en phpMyAdmin,
importar en ella el dump base y aplicar las migraciones `001` y `002` en ese
orden. Al importar el dump, selecciona explícitamente `fct_test_local`. Después,
desde PowerShell, ejecuta:

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
app/
  Controllers/       Coordinación de peticiones y validación de entrada
  Core/              Renderizado de vistas
  Middleware/        Autenticación, autorización y protección CSRF
  Models/            Consultas y persistencia
  Views/             Plantillas PHP por módulo
config/              Conexión PDO
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
storage/             Archivos privados y carpetas reservadas
tests/               Prueba de integración
```

La aplicación web usa `public/index.php` como front controller y define sus
rutas en `routes/web.php`. Las vistas se renderizan con el layout común de
`app/Views/layouts/main.php`. La conexión se obtiene desde
`config/database.php`.

Las direcciones antiguas `app/Views/layouts/index.html`,
`app/Views/alumnos/alumnos.html` y `app/Views/asignaciones/asignaciones.html`
se conservan como redirecciones a las páginas correspondientes del front
controller. No contienen formularios funcionales ni crean rutas nuevas. El
registro real de alumnado usa `registro-alumno`, y el flujo de asignación usa
`emparejar`.

`public/assets/js/alumnos_js.js` es un recurso JavaScript heredado, no utilizado
por las vistas MVC actuales. Su comportamiento de `localStorage` no guarda
información en el servidor y no debe usarse como flujo de registro.

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
