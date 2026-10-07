# Informe técnico de la jornada — Sistema FCT (7 de octubre de 2026)

---

## INSTRUCCIONES PARA EL ANALISTA (Gemini Pro)

Eres un revisor técnico senior de PHP y bases de datos. A continuación tienes el
registro completo del trabajo realizado hoy en el proyecto **Practicas_Desarrollo
(Sistema FCT)**. Con esta información, redacta un **documento formal en español**
que explique lo que se ha hecho, con esta estructura:

1. Resumen ejecutivo (máximo 10 líneas).
2. Contexto del proyecto y tecnologías.
3. Cronología de la jornada (qué se hizo, en qué orden y por qué).
4. Problemas detectados y cómo se resolvió cada uno.
5. Arquitectura final (capas MVC, flujo de una petición, base de datos).
6. Descripción de cada archivo creado o modificado.
7. Medidas de seguridad y de integridad de datos aplicadas.
8. Pruebas realizadas y resultados.
9. Decisiones de diseño y su justificación.
10. Tareas pendientes y recomendaciones.

Reglas: no inventes funcionalidades que no aparezcan aquí; si algo es ambiguo,
indícalo como "a confirmar". Explica los términos técnicos la primera vez que
aparezcan (por ejemplo: Front Controller, transacción, CSRF), porque el documento
lo leerán también personas no técnicas.

---

## 1. Contexto del proyecto

| Elemento | Valor |
|---|---|
| Proyecto | Sistema de gestión de Formación en Centros de Trabajo (FCT) |
| Repositorio | `campusedx/Practicas_Desarrollo` (GitHub), trabajo en equipo con ramas por persona |
| Lenguaje | PHP 8.2 nativo (sin Composer, sin frameworks, sin namespaces) |
| Base de datos | MariaDB 10.4 (XAMPP), base `sistema_fct`, codificación `utf8mb4` |
| Acceso a datos | PDO con sentencias preparadas |
| Patrón | MVC (Modelo–Vista–Controlador) |
| Entorno | Windows + XAMPP |

**Roles del sistema** (ENUM de la tabla `usuarios`): `Administrador`, `Coordinador`,
`Observador`, `Empresa`, `Alumno`.

**Estructura de carpetas relevante:**

```text
app/
  Controllers/   AuthController, AlumnoController, EmpresaController, OfertaController
  Models/        Usuario, Alumno, Empresa, Especialidad, Oferta
  Views/
    layouts/     main.php (layout activo), index.html (plantilla estática antigua)
    partials/    select_especialidades.php
config/          database.php (conexión PDO)
database/
  sistema_fct.sql                         Esquema
  seeds/001_sample_data.sql               Datos de prueba
  migrations/001_renombrar_nombre_especialidad.sql
public/          index.php (Front Controller) + assets/css/style.css
```

---

## 2. Cronología de la jornada

| Hora | Actividad | Autor |
|---|---|---|
| 11:32 – 13:56 | Estructura inicial, SQL, CSS e `index.html`; primera versión de `Usuario`, `AuthController`, `Empresa`, `EmpresaController`, `Alumno`, `AlumnoController` | Equipo (varios commits) |
| ~12:43 | Configuración del editor Cursor en modo oscuro (`Default Dark Modern`) | Asistente IA |
| ~15:06 | **Primer análisis** de viabilidad de la arquitectura MVC | Asistente IA |
| 15:22 | Commit `e44fd3b` *"fix: alinea el esquema FCT con la aplicación MVC"*: corrige la mayoría de los problemas del primer análisis | Cristian Fotache |
| ~15:33 | **Segundo análisis**: verificación de las correcciones | Asistente IA |
| ~15:39 – 15:45 | **Fase de desarrollo**: ajustes menores, Front Controller, módulo Especialidades, módulo Ofertas FCT y pruebas | Asistente IA |

---

## 3. Primer análisis (estado inicial)

Conclusión: **la estructura de carpetas y el esquema eran viables, pero el código
PHP no podía funcionar**. Problemas detectados:

### 3.1 Base de datos
1. Un `INSERT` al final de `sistema_fct.sql` referenciaba `alumno_id = 3`, que no
   existía → la importación fallaba por clave foránea.
2. Las contraseñas de prueba estaban en texto plano (`password123`) →
   `password_verify()` nunca validaría a esos usuarios.
3. Errata en la columna `especialidades.nombre_specialidad` (faltaba una "e").
4. El código enviaba roles en minúscula (`'empresa'`) y el ENUM los define con
   mayúscula (`'Empresa'`).
5. URL de prueba mal formada (`://techsolutions.com`) y falta de `CREATE DATABASE`.

### 3.2 Configuración
6. `config/database.php` creaba una variable global `$pdo`, pero todos los modelos
   llamaban a `getPDOConnection()`, que **no existía** → error fatal.
7. Usaba `die()` mostrando el mensaje de error de PDO al usuario (fuga de información).

### 3.3 Modelos y controladores
8. `Usuario.php` insertaba en la columna `password`; la real es `password_hash`.
9. `AuthController` leía `$usuario['password']`; no regeneraba el ID de sesión.
10. `Empresa.php` usaba columnas inexistentes (`cif`, `nombre_comercial`,
    `telefono`, `direccion`, `persona_contacto`).
11. `EmpresaController` no usaba transacción → posible usuario huérfano sin empresa.
12. `Alumno.php`: ruta de `require_once` incorrecta; columnas inexistentes
    (`num_seguridad_social`, `telefono`, `direccion`, `fecha_nacimiento`); omitía
    campos obligatorios (`nombre`, `apellidos`, `dni`, `correo`); escribía en una
    tabla inexistente (`tutores_alumnos`); sin `try/catch`.
13. `AlumnoController`: rutas en minúscula (`models/`, falla en Linux); validaba el
    tutor **después** de crear el alumno; fecha vacía calculaba edad 0.
14. Faltaban `Oferta.php`, `OfertaController.php`, `Especialidad.php` y
    `public/index.php`.

---

## 4. Correcciones del equipo (commit `e44fd3b`) y segundo análisis

El segundo análisis confirmó que los puntos 1 a 13 estaban resueltos:

- Esquema unificado en `sistema_fct.sql` con `CREATE DATABASE`; datos separados en
  `seeds/001_sample_data.sql`; eliminados `estructura_fct.sql` y `datos_prueba.sql`.
- Nuevas columnas: `alumnos.fecha_nacimiento`, `empresas.persona_contacto`,
  `tutores_legales.dni`, `tutores_legales.numero_seguridad_social`, `tutores_legales.correo`.
- Contraseñas de prueba guardadas como hash BCRYPT (verificado: corresponde a `password123`).
- `getPDOConnection()` implementada como Singleton (una única conexión reutilizada)
  y credenciales configurables por variables de entorno (`FCT_DB_HOST`,
  `FCT_DB_USER`, `FCT_DB_PASS`, `FCT_DB_NAME`).
- Modelos y controladores alineados con las columnas reales; transacciones en los
  registros de alumno y empresa; validación del tutor antes de escribir en la base de datos.

**Pendientes detectados en el segundo análisis** (se resolvieron en la fase siguiente):
faltaban los 4 archivos del punto 14, sin `session_regenerate_id`, sin
redirecciones tras login, `Alumno.php` sin `try/catch`, ruta CSS incorrecta, seeds
sin `USE`, formulario de empresa inexistente en GET, especialidad como campo
numérico manual y la errata `nombre_specialidad`.

---

## 5. Fase de desarrollo (cambios realizados por el asistente)

### 5.1 Ajustes menores

| Archivo | Cambio |
|---|---|
| `AuthController.php` | `session_regenerate_id(true)` tras login correcto; redirección según rol (`Empresa` → `publicar-oferta`, resto → `ofertas`); formulario de login en GET; `logout()` vacía `$_SESSION`, borra la cookie de sesión, destruye la sesión y redirige a `login` |
| `Alumno.php` | `try/catch (PDOException)` con `error_log` en `crear()` y `registrarTutor()` |
| `layouts/index.html` | Ruta del CSS → `assets/css/style.css` |
| `seeds/001_sample_data.sql` | Añadido `USE sistema_fct;` al inicio |
| `EmpresaController.php` | Muestra el formulario en GET y lo vuelve a mostrar si el registro falla |
| `sistema_fct.sql`, seeds, consultas | Columna renombrada a `nombre_especialidad` |
| `migrations/001_renombrar_nombre_especialidad.sql` (nuevo) | Migración para bases ya importadas; usa `ALTER TABLE ... CHANGE` porque MariaDB 10.4 no admite `RENAME COLUMN`; renombra también el índice único |

### 5.2 Front Controller — `public/index.php` (nuevo)

Punto de entrada único: todas las peticiones pasan por este archivo, que decide qué
controlador ejecutar según el parámetro `?action=`.

| `action` | Destino | Acceso |
|---|---|---|
| `inicio` (por defecto) | Página de bienvenida | Público |
| `login` | `AuthController::login()` | Público |
| `logout` | `AuthController::logout()` | Sesión iniciada |
| `registro-alumno` | `AlumnoController::registrar()` | Público |
| `registro-empresa` | `EmpresaController::registrar()` | Público |
| `ofertas` | `OfertaController::listar()` | Público |
| `publicar-oferta` | `OfertaController::publicar()` | Solo rol `Empresa` |
| Otro valor | Respuesta 404 | — |

Funcionamiento:
1. Carga `config/database.php` y los controladores; inicia la sesión.
2. Abre un buffer de salida (`ob_start`) para que los controladores puedan
   redirigir con `header()` aunque ya hayan generado HTML.
3. Ejecuta la acción dentro de `try/catch`: un error no controlado se registra con
   `error_log` y se muestra un mensaje genérico con código 500.
4. Inserta el contenido generado en el layout `app/Views/layouts/main.php`
   (nuevo), cuyo menú cambia según la sesión: "Publicar oferta" solo para
   `Empresa`; "Cerrar sesión (email)" si hay sesión; enlaces de registro y login si no la hay.

### 5.3 Módulo de Especialidades

- **`app/Models/Especialidad.php`** (nuevo):
  - `obtenerTodas()`: `SELECT id, nombre_especialidad FROM especialidades ORDER BY nombre_especialidad`.
  - `existe(int $id)`: comprobación en el servidor de que la especialidad enviada existe.
- **`app/Views/partials/select_especialidades.php`** (nuevo): `<select name="especialidad_id">`
  generado dinámicamente, con los textos escapados con `htmlspecialchars`.
- Usado en el formulario de **registro de alumno** (sustituye al campo numérico) y en
  el formulario de **publicación de ofertas**.

**Decisión de diseño:** no se añadió el `<select>` al *registro* de empresa porque la
tabla `empresas` no tiene columna para guardar una especialidad y el dato se
perdería. La relación empresa–especialidad se registra al publicar una oferta
(tabla `empresa_especialidades_plazas`). Si se quisiera que la empresa declare
especialidades al registrarse, haría falta una tabla nueva (a confirmar con el equipo).

### 5.4 Módulo de Ofertas FCT

- **`app/Models/Oferta.php`** (nuevo), sobre la tabla `empresa_especialidades_plazas`:
  - `crearOferta($empresaId, $especialidadId, $plazas, $fechaInicio, $fechaFin)`.
  - `obtenerPorEmpresa($empresaId)`: ofertas de una empresa, de la más reciente a la más antigua.
  - `obtenerTodas(bool $soloVigentes = false)`: todas las ofertas; con `true`, solo
    las que no han terminado (`fecha_fin >= CURDATE()`).
  - Las consultas de lectura hacen `JOIN` con `empresas` y `especialidades` para
    devolver los nombres legibles.
- **`app/Models/Empresa.php`**: nuevo método `obtenerPorUsuarioId()` para saber qué
  empresa corresponde al usuario conectado.
- **`app/Controllers/OfertaController.php`** (nuevo):
  - `listar()`: tabla pública con las ofertas vigentes.
  - `publicar()`: exige sesión con rol `Empresa` (si no, redirige a login); obtiene la
    empresa desde la sesión, nunca desde el formulario (una empresa no puede publicar
    en nombre de otra); muestra el formulario y la lista de sus propias ofertas.
  - Validaciones en el servidor:
    - especialidad: entero positivo que exista en la base de datos;
    - plazas: entero entre 1 y 100;
    - fechas: formato estricto `AAAA-MM-DD` y fecha real (rechaza, por ejemplo, 31 de febrero);
    - la fecha de inicio no puede ser anterior a hoy;
    - la fecha de fin debe ser posterior a la de inicio.
  - Tras publicar, redirige a la misma página (patrón Post/Redirect/Get) para que
    recargar el navegador no duplique la oferta.

### 5.5 Inyección de dependencias

- Todos los modelos aceptan un `?PDO` opcional en el constructor; si no se pasa,
  usan el Singleton `getPDOConnection()`.
- Los controladores crean una sola conexión y la comparten con todos sus modelos.
  Esto garantiza que la transacción del registro (usuario + empresa/alumno + dirección
  + tutor) se aplique sobre la misma conexión y se deshaga completa si algo falla.
- Facilita las pruebas: se puede inyectar una conexión a una base de datos de pruebas.

### 5.6 Cambios en `AlumnoController.php`

- Formulario movido a un método `mostrarFormulario()` con el `<select>` dinámico.
- Validación de que la especialidad existe antes de abrir la transacción.
- Comprobación del resultado de `Alumno::crear()` (antes no se comprobaba).
- Si hay errores, se muestra el mensaje junto al formulario (antes solo el mensaje).

---

## 6. Medidas de seguridad e integridad

| Medida | Dónde |
|---|---|
| Sentencias preparadas PDO (contra inyección SQL) y `ATTR_EMULATE_PREPARES = false` | Todos los modelos, `config/database.php` |
| Contraseñas con `password_hash` (BCRYPT) y `password_verify` | `Usuario.php`, `AuthController.php` |
| Regeneración del ID de sesión tras login (contra fijación de sesión) | `AuthController::login()` |
| Cierre de sesión completo (datos, cookie y sesión) | `AuthController::logout()` |
| Control de acceso por rol en el servidor | `OfertaController::publicar()` |
| Empresa obtenida de la sesión, no del formulario | `OfertaController::publicar()` |
| Escape de salida con `htmlspecialchars` (contra XSS) | Vistas, tablas, mensajes |
| Errores internos solo en `error_log`; al usuario, mensajes genéricos | Modelos, controladores, `index.php` |
| Transacciones con `rollBack` en registros de varias tablas | `AlumnoController`, `EmpresaController` |
| Validación en el servidor de fechas, rangos, emails y existencia de claves | Controladores |

---

## 7. Pruebas realizadas

**Método:** se importaron el esquema y los seeds en una base de datos temporal
(`sistema_fct_test`), se levantó el servidor integrado de PHP apuntando a `public/`
y se ejecutó un script de pruebas HTTP con sesión (cookies). Al terminar se
eliminaron la base temporal y los scripts. La base real `sistema_fct` no se modificó.

| # | Prueba | Resultado |
|---|---|---|
| 1 | Página de inicio responde 200 y carga el CSS | OK |
| 2 | Acción desconocida responde 404 | OK |
| 3 | Formulario de alumno muestra el `<select>` con las especialidades | OK |
| 4 | Formulario de empresa se muestra en GET | OK |
| 5 | Publicar oferta sin sesión redirige a login | OK |
| 6 | Login con contraseña incorrecta muestra error | OK |
| 7 | Login de empresa redirige a publicar oferta | OK |
| 8 | Página de publicación muestra empresa y "Mis ofertas" | OK |
| 9 | Fecha de fin anterior a la de inicio: rechazada | OK |
| 10 | Fecha de inicio en el pasado: rechazada | OK |
| 11 | Especialidad inexistente: rechazada | OK |
| 12 | Oferta válida: guardada y redirección | OK |
| 13 | El listado público muestra la nueva oferta | OK |
| 14 | Logout redirige a login | OK |
| 15 | Tras logout no se puede publicar | OK |
| 16 | Registro de empresa nueva | OK |
| 17 | CIF duplicado: registro rechazado y deshecho | OK |
| 18 | Alumno menor sin tutor: rechazado | OK |
| 19 | Alumno menor con tutor: registrado | OK |
| 20 | Login de alumno redirige a ofertas | OK |
| 21 | En la base de datos no quedan usuarios huérfanos tras el CIF duplicado | OK |
| 22 | El tutor legal queda guardado y vinculado al alumno | OK |

Adicionalmente:
- `php -l` sin errores de sintaxis en los 13 archivos PHP.
- La migración se probó sobre una tabla con el nombre antiguo: renombra la columna
  y el índice y conserva los datos.

**Incidencia durante las pruebas (no es un fallo del código):** al importar el SQL
mediante una tubería de PowerShell (`|`), las tildes se convirtieron en `?`. Al
importar desde un archivo UTF-8 el problema desapareció. Recomendación: importar
siempre desde phpMyAdmin o con `mysql ... -e "source archivo.sql"`.

---

## 8. Inventario de archivos

**Nuevos (7):**
- `public/index.php`
- `app/Controllers/OfertaController.php`
- `app/Models/Especialidad.php`
- `app/Models/Oferta.php`
- `app/Views/layouts/main.php`
- `app/Views/partials/select_especialidades.php`
- `database/migrations/001_renombrar_nombre_especialidad.sql`

**Modificados (9):**
- `app/Controllers/AlumnoController.php`
- `app/Controllers/AuthController.php`
- `app/Controllers/EmpresaController.php`
- `app/Models/Alumno.php`
- `app/Models/Empresa.php`
- `app/Models/Usuario.php`
- `app/Views/layouts/index.html`
- `database/seeds/001_sample_data.sql`
- `database/sistema_fct.sql`

Estado en git: cambios **sin commit** en la rama `jefferson`.

---

## 9. Cómo ponerlo en marcha

1. Importar `database/sistema_fct.sql` y luego `database/seeds/001_sample_data.sql`
   en phpMyAdmin. Si la base ya existía con la columna antigua, ejecutar
   `database/migrations/001_renombrar_nombre_especialidad.sql`.
2. Configurar el servidor web con `public/` como raíz.
3. Cuentas de prueba (contraseña `password123`): `admin@centro.com`,
   `coordinador@centro.com`, `empresa.tech@gmail.com`, `alumno.menor@alumno.com`,
   `alumno.mayor@alumno.com`.

---

## 10. Pendientes y recomendaciones

1. **Protección CSRF** en todos los formularios (token por sesión que impide que otra
   web envíe formularios en nombre del usuario). Prioridad alta.
2. Separar el HTML de los controladores en archivos de `app/Views/` (ahora los
   formularios se generan dentro de los controladores).
3. Conservar los valores introducidos cuando un formulario falla la validación.
4. Mensaje específico cuando el email, DNI o CIF ya existen (ahora es genérico).
5. Middleware de autenticación por rol reutilizable en `app/Middleware/`.
6. Módulos aún sin implementar: convenios, asignaciones de alumnos a plazas (no hay
   tabla), consultas, panel de coordinador/administrador.
7. Eliminar `app/Views/layouts/index.html`, sustituido por `main.php`.
8. Hacer commit de los cambios y abrir un Pull Request para revisión del equipo.
