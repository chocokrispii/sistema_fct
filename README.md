# Sistema FCT

Estructura inicial para desarrollar el sistema de gestión de Formación en Centros
de Trabajo con PHP, HTML, CSS, JavaScript y MySQL, siguiendo el patrón MVC.

## Estructura

```text
app/
  Controllers/       Controladores de autenticación, empresas, convenios,
                     alumnos, ofertas, asignaciones y consultas
  Core/              Componentes comunes: enrutamiento, vistas y base de datos
  Middleware/        Autenticación y permisos por rol
  Models/            Modelos y acceso a datos
  Views/
    layouts/         Plantillas compartidas
    auth/
    empresas/
    convenios/
    alumnos/
    ofertas/
    asignaciones/
    consultas/
config/              Configuración de la aplicación y MySQL
database/
  sistema_fct.sql    Esquema inicial y creación de la base de datos
  migrations/        Cambios futuros del esquema
  seeds/
    001_sample_data.sql  Datos de ejemplo para el esquema
public/              Única raíz accesible desde el servidor web
  assets/
    css/
    img/
    js/
routes/              Definición de rutas de la aplicación
storage/
  logs/
  uploads/
    convenios/
    expedientes/
tests/
```

## Convenciones

- Configura el servidor web para que la raíz pública sea `public/`.
- Las rutas reciben las peticiones y las derivan a un controlador. Los
  controladores coordinan modelos y vistas; los modelos gestionan los datos.
- Aplica los permisos de los roles Administrador, Coordinador FCT, Observador,
  Empresa y Alumno mediante middleware y valida también los permisos en el
  servidor.
- Mantén los documentos de convenios y expedientes en `storage/uploads/`, fuera
  de `public/`, y entrégalos solo tras comprobar la autorización.
- Guarda credenciales y configuración sensible fuera del control de versiones.
- En la ficha del alumno, exige los datos del tutor legal cuando se indique que
  el alumno es menor de edad.

## Base de datos

Importa primero `database/sistema_fct.sql` para crear la base de datos y sus
tablas. Después, selecciona `sistema_fct` en phpMyAdmin e importa
`database/seeds/001_sample_data.sql` para cargar los datos de ejemplo.

Las cuentas de ejemplo usan la contraseña `password123`; el archivo almacena
su hash, no la contraseña en texto plano. La configuración de conexión PHP está
en `config/database.php`; por defecto usa MySQL de XAMPP (`localhost`, usuario
`root`, contraseña vacía y base `sistema_fct`). Puedes sobrescribir esos valores
con las variables de entorno `FCT_DB_HOST`, `FCT_DB_USER`, `FCT_DB_PASS` y
`FCT_DB_NAME`.

Las carpetas `database/migrations/` y `database/seeds/` quedan disponibles para
cambios de esquema posteriores y datos iniciales adicionales. La aplicación,
sus rutas y parte de la lógica MVC todavía están pendientes de implementación.
