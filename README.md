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
  Models/             Modelos y acceso a datos
  Views/
    layouts/          Plantillas compartidas
    auth/
    empresas/
    convenios/
    alumnos/
    ofertas/
    asignaciones/
    consultas/
config/               Configuración de la aplicación y MySQL
database/
  migrations/         Esquema y cambios de la base de datos
  seeds/              Datos iniciales, como roles y permisos
public/               Única raíz accesible desde el servidor web
  assets/
    css/
    img/
    js/
routes/               Definición de rutas de la aplicación
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

La estructura crea las carpetas de organización; la lógica de negocio, las rutas
y el esquema de MySQL se implementarán posteriormente.
