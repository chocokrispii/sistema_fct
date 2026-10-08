# Sistema FCT

Aplicación MVC para gestionar empresas, convenios, ofertas FCT, disponibilidad
del alumnado, asignaciones y consultas con PHP 8.2, MariaDB y XAMPP.

## Puesta en marcha en XAMPP

1. Configura Apache para servir el directorio `public/` como raíz web.
2. Crea o selecciona la base de datos `sistema_fct` en phpMyAdmin.
3. Importa `database/sistema_fct.sql` únicamente en una base vacía.
4. La base antigua usa `nombre_specialidad`: ejecuta
   `database/migrations/001_renombrar_nombre_especialidad.sql` para convertirla
   en `nombre_especialidad`.
5. Ejecuta una sola vez `database/migrations/002_fct_workflows.sql` para activar
   convenios, revisión, sedes, disponibilidad, asignaciones y cuentas activas.
6. El volcado `sistema_fct.sql` ya incluye datos de ejemplo. No importes también
   `database/seeds/001_sample_data.sql` sobre esos mismos datos, pues se
   duplicarían identificadores y cuentas.
7. En XAMPP, los valores predeterminados están en `config/database.php`
   (`localhost`, `root`, contraseña vacía, base `sistema_fct`). En otros
   entornos configura `FCT_DB_HOST`, `FCT_DB_USER`, `FCT_DB_PASS` y `FCT_DB_NAME`.
8. Las cuentas de demostración usan la contraseña `password123`; no las uses en
   un entorno real. La cuenta de empresa de ejemplo requiere que un coordinador
   registre y apruebe primero un convenio.

### Atención al volcado inicial

Por indicación del propietario del proyecto, `database/sistema_fct.sql` se ha
mantenido intacto. El volcado contiene una fila al final que referencia al
alumno `3`, aunque sus datos de ejemplo solo incluyen los alumnos `1` y `2`.
Esa inserción puede fallar por clave foránea durante una importación nueva. Si
phpMyAdmin informa de ese error, no des por completada la inicialización:
comprueba que se crearon las tablas y que se ejecutaron correctamente las
migraciones. Para dejar la importación limpia, habrá que corregir el volcado en
un cambio autorizado posterior.

Las migraciones son incrementales y de ejecución única. Si la base de datos ya
tiene la columna `nombre_especialidad`, omite la migración `001`; ejecuta la
`002` después de comprobar que existen las tablas base. No ejecutes la `002`
dos veces.

## Flujos implementados

- Roles de servidor: Administrador, Coordinador, Observador, Empresa y Alumno.
- Sesiones endurecidas, cierre mediante POST, tokens CSRF para todas las
  operaciones POST y control de acceso por ruta.
- Altas de alumno y empresa; tutor obligatorio para el alumnado menor de edad.
- Registro de sedes y convenios PDF privados, con aprobación/rechazo del centro.
- Publicación de ofertas sujeta a convenio vigente y aprobación de coordinación.
- Disponibilidad por periodo y turno, emparejamiento por ciclo/fechas/turno,
  asignación dentro del cupo y cierre de prácticas.
- Gestión administrativa de cuentas internas, bloqueo de auto-baja y auditoría
  de cambios relevantes.
- Consultas filtrables por ciclo, estado de oferta y fechas; acceso de solo
  lectura para Observador.

Las cuentas nuevas requieren contraseñas de 12 a 72 bytes; las cuentas de
demostración ya incluidas conservan su contraseña `password123`.

Los documentos cargados se guardan fuera de `public/` y `storage/.htaccess`
impide el acceso web directo. Deben descargarse por la ruta autenticada de la
aplicación.

## Pruebas de integración

No ejecutes las pruebas contra `sistema_fct`: usan datos y crean registros.
Prepara una base desechable con prefijo `fct_test_`, con las tablas base y ambas
migraciones aplicadas. Por ejemplo, para copiar solo la estructura de una base
local existente:

```powershell
$mysql = "C:\xampp\mysql\bin\mysql.exe"
$dump = "C:\xampp\mysql\bin\mysqldump.exe"
$schema = Join-Path $env:TEMP "fct_test_schema.sql"
$migration1 = (Resolve-Path "database\migrations\001_renombrar_nombre_especialidad.sql").Path -replace "\\", "/"
$migration2 = (Resolve-Path "database\migrations\002_fct_workflows.sql").Path -replace "\\", "/"
& $mysql --user=root --execute="CREATE DATABASE fct_test_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
& $dump --user=root --no-data --result-file=$schema sistema_fct
$schemaPath = $schema -replace "\\", "/"
& $mysql --user=root --database=fct_test_local --execute="source $schemaPath"
& $mysql --user=root --database=fct_test_local --execute="source $migration1"
& $mysql --user=root --database=fct_test_local --execute="source $migration2"
$env:FCT_TEST_DB_NAME = "fct_test_local"
$env:FCT_DB_NAME = $env:FCT_TEST_DB_NAME
& "C:\xampp\php\php.exe" tests\run.php
```

Omite la migración `001` si el esquema de origen ya tiene la columna corregida.
El volcado de solo estructura evita copiar registros reales; las pruebas crean
y eliminan sus propios datos. Elimina la base de prueba y el archivo temporal
desde phpMyAdmin/PowerShell al finalizar. El script de pruebas rechaza nombres
que no empiecen por `fct_test_`.

## Estructura

```text
app/Controllers/       Controladores MVC
app/Core/              Renderizado de vistas
app/Middleware/        Autenticación por rol y protección CSRF
app/Models/            Acceso a datos y reglas de persistencia
app/Views/              Vistas por módulo y plantillas
config/                 Configuración PDO
database/
  migrations/           Cambios incrementales del esquema
  seeds/                Datos de prueba
public/                 Front controller y recursos estáticos
routes/                 Definición de rutas y roles
storage/                Archivos privados y logs
tests/                  Pruebas de integración
docs/                   Documentación técnica
```
