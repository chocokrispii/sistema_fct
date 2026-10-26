# Guía completa del proyecto Sistema FCT

## 1. Qué es este documento

Este documento describe el estado del proyecto a partir de la lectura de sus
archivos de aplicación, vistas, configuración, SQL, pruebas y documentación.
Explica qué hace cada parte, cómo se conectan los módulos y qué límites o
diferencias entre el código y la documentación conviene conocer.

La guía complementa, pero no sustituye, el [README.md](README.md), que contiene
los pasos breves de puesta en marcha. El informe
[docs/informe_jornada_2026-10-07.md](docs/informe_jornada_2026-10-07.md) es un
registro de distintas etapas de trabajo y conserva secciones históricas que no
siempre describen el estado actual.

## 2. Resumen

Sistema FCT es una aplicación web PHP organizada según MVC (Modelo–Vista–
Controlador). El objetivo funcional es coordinar la relación entre centro
educativo, empresas y alumnado: registrar perfiles, gestionar sedes y
convenios, publicar y revisar ofertas de prácticas, registrar disponibilidad,
proponer candidatos, asignar plazas y consultar el estado de las prácticas.

La aplicación usa PHP 8.2, PDO, MariaDB/MySQL compatible con MariaDB 10.4 y
XAMPP. No usa un framework PHP ni dependencias de Composer; el frontend sí usa
Vue, Vite y dependencias npm. El árbol conserva una maqueta HTML heredada y su
JavaScript auxiliar, pero las rutas actuales usan vistas PHP y no dependen de
esos archivos.

Los roles reconocidos por la base de datos son:

| Rol | Uso previsto |
|---|---|
| `Administrador` | Gestiona las cuentas internas y tiene acceso a revisión, consultas y asignaciones. |
| `Coordinador` | Revisa convenios y ofertas, empareja estudiantes, gestiona asignaciones y consulta datos. |
| `Observador` | Accede a vistas de consulta sin acciones de edición desde las rutas disponibles. |
| `Empresa` | Mantiene sedes, registra convenios y crea ofertas. |
| `Alumno` | Mantiene su disponibilidad y consulta sus propias asignaciones. |

## 3. Conceptos de arquitectura

- **MVC**: separa la lógica de entrada y coordinación (controladores), la
  persistencia y consultas de datos (modelos) y la presentación HTML (vistas).
- **Front Controller**: punto de entrada único para las peticiones web. Aquí es
  `public/index.php`; carga las dependencias comunes, consulta la ruta y delega
  al controlador que corresponda.
- **Ruta**: combinación de una acción de URL, un método HTTP permitido y, cuando
  aplica, una lista de roles. Las rutas se centralizan en `routes/web.php`.
- **PDO**: interfaz de PHP para comunicarse con la base de datos mediante
  consultas preparadas.
- **Transacción**: grupo de cambios a la base de datos que se confirma como una
  unidad o se revierte si una parte falla.
- **CSRF**: falsificación de peticiones entre sitios. La aplicación añade un
  token de sesión a los formularios POST y comprueba el token antes de procesar
  cualquier petición POST.
- **Hash de contraseña**: representación no reversible almacenada en la base;
  el sistema compara la contraseña introducida mediante `password_verify()`.
- **Migración**: cambio incremental y versionado del esquema de la base de
  datos, aplicado sobre tablas ya existentes.

## 4. Estructura completa del repositorio

```text
app/
  Controllers/       Coordinan una petición, validan entrada y deciden qué vista devolver.
  Core/              Infraestructura pequeña compartida por la aplicación.
  Middleware/        Validación de acceso y de formularios.
  Models/            Consultas, inserciones y reglas de persistencia.
  Views/             HTML generado por PHP, separado por funcionalidad.
config/              Configuración de conexión PDO.
database/
  migrations/        Cambios incrementales que necesita el código actual.
  seeds/             Datos de muestra alternativos.
  sistema_fct.sql    Esquema y datos iniciales del volcado principal.
docs/                Informe histórico de la jornada.
public/
  index.php          Entrada web pública.
  assets/css/        Hoja de estilo.
  assets/img/        Espacio reservado para imágenes.
  assets/js/         Script heredado de maquetas; no se usa en las rutas actuales.
routes/              Catálogo de acciones, métodos y roles.
storage/
  uploads/convenios/ PDFs de convenio recibidos.
  uploads/expedientes/ Espacio reservado; no hay flujo de carga de expedientes.
  logs/              Espacio reservado para logs.
tests/               Prueba de integración PHP.
README.md            Puesta en marcha y estructura resumida.
documentacion/       Guías funcionales y de pruebas.
```

Los archivos `.gitkeep` mantienen en Git carpetas que aún están vacías. No
contienen lógica de aplicación. Las páginas activas se renderizan mediante
`app/Views/layouts/main.php`. Los archivos HTML antiguos no implementan rutas
ni operaciones de escritura: `app/Views/layouts/index.html` redirige al front
controller; la antigua maqueta de registro de alumnos se eliminó. La página
heredada de asignaciones es informativa y enlaza a las rutas oficiales, que
aplican autenticación y permisos. Apache debe exponer únicamente `public/` como
raíz web y mantener `app/`, `config/`, `database/` y `storage/` fuera del acceso
web directo.

## 5. Ciclo de una petición web

1. El navegador solicita `public/index.php`, con `action` opcional en la query,
   por ejemplo `?action=ofertas`.
2. El front controller carga la conexión, las clases compartidas y los
   controladores, activa la sesión y establece cabeceras HTTP de seguridad.
3. Busca la acción en `routes/web.php`. Una acción desconocida produce 404; un
   método HTTP no permitido produce 405 y la cabecera `Allow`.
4. Si la ruta requiere rol, `AuthMiddleware` verifica que la sesión contenga un
   identificador válido, vuelve a comprobar en la base si el usuario existe y
   está activo, y toma de la base el rol vigente. Si no hay sesión, redirige al
   login; si el rol no está autorizado, responde con 403.
5. Para cualquier ruta POST, `CsrfMiddleware` valida el token antes de llamar
   al controlador.
6. El controlador lee y valida entradas, usa uno o varios modelos y renderiza
   una vista con `View::render()`. Cuando una operación modifica datos,
   normalmente redirige después del POST para evitar repetirla al recargar.
7. La salida se captura en un buffer y se inserta en el layout principal. Los
   errores inesperados quedan en el log de PHP y se presenta una vista genérica.

El parámetro `action` selecciona la ruta; no se usa para componer directamente
un nombre de archivo de vista o una consulta SQL.

## 6. Rutas y permisos actuales

| Acción | Método | Acceso | Función |
|---|---|---|---|
| `inicio` | GET | Público | Página de bienvenida. |
| `login` | GET, POST | Público | Formulario y autenticación. |
| `session` | GET | Público | Consulta el estado de la sesión para el cliente. |
| `recuperar` | GET, POST | Público | Solicita la recuperación de una cuenta. |
| `restablecer` | GET, POST | Público | Valida el enlace y actualiza la contraseña. |
| `recuperaciones` | GET | Administrador, Coordinador | Lista las solicitudes de recuperación pendientes. |
| `verificar-recuperacion` | POST | Administrador, Coordinador | Marca como verificada una solicitud de recuperación. |
| `logout` | POST | Cualquier rol autenticado | Finaliza la sesión. |
| `registro-alumno` | GET, POST | Público | Alta del expediente y usuario de alumno. |
| `registro-empresa` | GET, POST | Público | Alta del perfil de empresa y usuario. |
| `portal-alumno` | GET | Alumno | Consulta el expediente propio. |
| `perfil-alumno` | GET, POST | Alumno | Consulta y actualiza el perfil propio. |
| `ofertas` | GET | Administrador, Coordinador, Observador, Empresa | Lista ofertas según el rol autenticado. |
| `empresa-asignada` | GET | Alumno | Consulta la empresa de la práctica propia asignada. |
| `publicar-oferta` | GET, POST | Empresa | Consulta y crea ofertas propias. |
| `nueva-oferta` | GET, POST | Administrador, Coordinador | Crea una oferta para una empresa. |
| `sedes` | GET, POST | Empresa | Consulta y registra sedes de la empresa autenticada. |
| `revisar-ofertas` | GET | Administrador, Coordinador | Lista ofertas pendientes. |
| `revisar-oferta` | POST | Administrador | Aprueba o rechaza una oferta. |
| `disponibilidad` | GET, POST | Alumno | Muestra y reemplaza la disponibilidad propia. |
| `asignaciones` | GET | Administrador, Coordinador, Observador | Lista asignaciones. |
| `emparejar` | GET | Administrador, Coordinador | Ofertas vigentes aprobadas y candidatos compatibles. |
| `mis-asignaciones` | GET | Alumno | Lista solo las prácticas vinculadas al usuario. |
| `calificaciones` | GET | Administrador, Coordinador, Observador | Consulta las calificaciones de prácticas. |
| `mis-calificaciones` | GET | Alumno | Consulta las calificaciones propias. |
| `guardar-calificacion` | POST | Administrador, Coordinador | Registra o actualiza una calificación. |
| `asignar-alumno` | POST | Administrador, Coordinador | Crea una asignación sujeta a ciclo, disponibilidad y cupo. |
| `cerrar-practica` | POST | Administrador, Coordinador | Cierra una asignación activa. |
| `convenios` | GET, POST | Empresa | Registra un convenio y consulta el historial propio. |
| `modificar-convenios` | GET | Administrador, Coordinador | Consulta convenios que puede modificar el centro. |
| `editar-convenio` | GET, POST | Empresa, Administrador, Coordinador | Consulta o actualiza un convenio con las restricciones del rol y su estado. |
| `revisar-convenios` | GET | Administrador, Coordinador | Lista convenios pendientes. |
| `auditar-convenios` | GET | Observador | Consulta la auditoría de convenios. |
| `resolver-convenio` | POST | Administrador | Aprueba o rechaza un convenio. |
| `descargar-convenio` | GET | Administrador, Coordinador, Empresa | Descarga auditada; la empresa solo puede descargar el suyo. |
| `usuarios` | GET, POST | Administrador | Lista usuarios internos y crea cuentas de personal. |
| `api-tokens` | GET, POST | Administrador | Crea y revoca tokens de API. |
| `integraciones` | GET | Administrador | Abre el panel de integraciones. |
| `pruebas-integracion` | GET, POST | Administrador | Ejecuta pruebas manuales de recepción y envío de webhooks. |
| `webhook-inbox` | GET | Administrador | Alias de compatibilidad para consultar webhooks recibidos. |
| `webhooks-recibidos` | GET | Administrador | Consulta los webhooks recibidos. |
| `webhooks-enviados` | GET | Administrador | Consulta los webhooks enviados. |
| `webhooks-empresa` | GET | Administrador | Consulta la actividad de webhooks por empresa. |
| `estado-usuario` | POST | Administrador | Activa o desactiva una cuenta con restricciones. |
| `eliminar-usuario` | POST | Administrador | Elimina una cuenta respetando las restricciones del sistema. |
| `consultas` | GET | Administrador, Coordinador, Observador | Filtra ofertas y asignaciones relacionadas. |

Las rutas son la autoridad del acceso del servidor. Ocultar o mostrar un enlace
en el menú no sustituye esta comprobación.

Las vistas previas `preview-disponibilidad` y `preview-publicar-oferta` se
añaden al mapa únicamente cuando `APP_ENV` es `development` o `local`.

## 7. Recorrido de los módulos

### 7.1 Login, sesiones y cuentas

`AuthController` presenta un formulario en GET y procesa credenciales en POST.
`Usuario::obtenerPorEmail()` localiza el usuario; el controlador verifica el
hash, rechaza una cuenta desactivada cuando existe la columna `activo`, regenera
el identificador de sesión y guarda ID, correo y rol en la sesión. Las empresas
son redirigidas a publicación de ofertas, el alumnado a su expediente, los
observadores a asignaciones y el personal administrador/coordinador al inicio.

El cierre se realiza mediante POST, vacía los datos de sesión, invalida la
cookie y destruye la sesión. El formulario lleva CSRF.

`UsuarioAdminController` permite que un administrador cree cuentas internas con
roles Administrador, Coordinador u Observador. Para Coordinador también crea la
fila de perfil en `coordinadores`. Puede cambiar el estado de una cuenta, pero
no desactivarse a sí mismo ni dejar desactivado al último administrador activo.
Los cambios se escriben en `auditoria`.

Las contraseñas que se crean por los formularios y el modelo deben tener de 12
a 72 bytes. Las cuentas de ejemplo conservan `password123`; esa contraseña es
solo para desarrollo.

### 7.2 Registro de alumnado

El controlador valida los campos básicos, el formato de fecha, que la fecha no
sea futura, la longitud de campos, la edad mínima de 16 años y que el ciclo
exista. Si el alumno tiene menos de 18 años, requiere nombre, identificación,
seguridad social, correo, dirección y teléfono del tutor.

En una transacción se crea la cuenta, el expediente, la dirección/teléfono y,
cuando procede, el tutor legal. Un fallo revierte las filas relacionadas. La
vista de ciclo se carga desde la tabla `especialidades`, no desde un número
introducido manualmente.

### 7.3 Registro de empresas y sedes

El registro público valida correo, contraseña, CIF, razón social, contacto,
teléfono y dirección. Una transacción crea el usuario, la empresa y su dirección
principal. Los errores de duplicidad se muestran con un mensaje general.

Una empresa autenticada puede añadir sedes. La consulta del perfil de empresa
se hace usando el usuario de la sesión: los formularios no eligen el ID de
empresa al que escribir.

### 7.4 Convenios

La empresa registra número, ciclo formativo, fechas, sede opcional y archivo.
Solo se admite un convenio por empresa y ciclo formativo. El servidor
comprueba que las fechas sean válidas y estén en orden, que la sede pertenezca
a esa empresa, y que el archivo cargado sea un PDF de hasta 10 MB. El nombre
guardado es aleatorio y el fichero se ubica en `storage/uploads/convenios/`,
fuera del directorio público. La base conserva ese nombre de almacenamiento,
no una ruta proporcionada por el navegador.

Los convenios creados antes de la migración `004` conservan el ciclo como
`NULL`; no habilitan ofertas hasta que el centro les asigne manualmente la
especialidad correcta.

El registro del convenio y su evento de auditoría se confirman en una
transacción. Si falla esa fase, se revierte la base y se intenta borrar el
archivo recién movido. Un administrador o coordinador puede aprobar o rechazar
convenios pendientes; el rechazo requiere motivo. Una empresa solo descarga
documentos que pertenecen a su propio perfil; personal del centro y observador
pueden descargarlos según la ruta actual.

`storage/.htaccess` deniega solicitudes web directas al almacenamiento bajo
Apache. La aplicación sirve la descarga mediante un controlador que valida ID,
rol, titularidad de la empresa y ubicación física del archivo.

### 7.5 Ofertas

La empresa solo puede crear ofertas asociadas a su perfil de sesión. La entrada
requiere un ciclo existente, de 1 a 100 plazas, turno `M`, `T` o `Indiferente`,
fechas válidas y fecha de inicio no pasada. El fin debe ser posterior al
inicio. Se exige un convenio aprobado que abarque el periodo entero.

Las nuevas ofertas empiezan en estado `Pendiente`. Un administrador o
coordinador puede aprobarlas o rechazarlas; un rechazo necesita observación. La
aprobación vuelve a comprobar, dentro de una transacción y bloqueando la oferta,
que un convenio aprobado de la misma empresa y ciclo cubra todas las fechas. La
decisión queda auditada.

La tabla pública filtra por `Aprobada` y por `fecha_fin >= CURDATE()`. No limita
explícitamente `fecha_inicio <= CURDATE()`: por tanto también puede listar una
oferta futura aprobada. El formulario permite proponer un periodo futuro; al
enviarlo, el servidor comprueba que un convenio aprobado cubra todo el periodo.

### 7.6 Disponibilidad y matching

El alumno registra una única ventana continua con fecha inicial, final y turno.
Al guardar, el modelo bloquea la fila del alumno, elimina sus ventanas
anteriores e inserta la nueva dentro de una transacción.

La lista de candidatos compara:

1. La especialidad del alumno con la de la oferta.
2. Una ventana de disponibilidad que cubra todo el intervalo de la oferta.
3. Turnos iguales o al menos uno de los dos en modalidad `Indiferente`.
4. Que el alumno no tenga otra asignación en estado `Asignada`.

La consulta compara las etiquetas de turno como valores binarios para evitar
incompatibilidades de collation entre las tablas antiguas y las nuevas.

### 7.7 Asignaciones

Para asignar, el modelo bloquea la oferta, verifica que esté aprobada y cuenta
como ocupadas las asignaciones `Asignada` y `Cerrada`. Bloquea después el
expediente del alumno y vuelve a comprobar compatibilidad y disponibilidad
antes de insertar. Esto evita que dos peticiones simultáneas asignen más
alumnos que plazas.

La creación y el evento de auditoría comparten transacción. Al cerrar, solo se
permite el cambio de `Asignada` a `Cerrada`, y el evento se registra en la misma
transacción.

Una asignación cerrada sigue contando como plaza ocupada para esa oferta. Sin
embargo, las consultas de candidatos solo excluyen alumnos con asignación
activa (`Asignada`), no cerrada; el código permite por tanto volver a proponer
un alumno con prácticas cerradas para otra oferta. El comportamiento responde
a las condiciones actuales del modelo y puede requerir una decisión funcional
si el proyecto quiere impedirlo.

### 7.8 Consultas

El informe permite filtrar por ciclo, estado de oferta y límites de fechas.
Las entradas se validan y las fechas incorrectas muestran un mensaje; los
resultados se limitan a 500 filas. La consulta une ofertas con empresas,
especialidades y asignaciones. Una oferta con varias asignaciones puede aparecer
en varias filas, una por asignación asociada.

El filtro `desde` exige que la fecha de inicio de la oferta sea igual o posterior
a la indicada, y `hasta` exige que el fin de la oferta sea igual o anterior.
No es un filtro general de solapamiento de periodos.

## 8. Qué hace cada archivo de código

### Controladores (`app/Controllers/`)

- [AuthController.php](app/Controllers/AuthController.php): procesa login y
  logout; valida tipos básicos de entrada, contraseña, hash y estado de cuenta;
  crea y limpia la sesión.
- [AlumnoController.php](app/Controllers/AlumnoController.php): valida los datos
  del alumno y del tutor, comprueba ciclo/edad y coordina la transacción de alta.
- [EmpresaController.php](app/Controllers/EmpresaController.php): registra
  empresas y crea/lista sedes de la empresa autenticada.
- [OfertaController.php](app/Controllers/OfertaController.php): lista ofertas,
  presenta el alta, valida ofertas y ejecuta su revisión con comprobación de
  convenio y auditoría.
- [ConvenioController.php](app/Controllers/ConvenioController.php): registra
  archivos y datos de convenio, presenta pendientes, ejecuta decisiones y sirve
  descargas autorizadas.
- [DisponibilidadController.php](app/Controllers/DisponibilidadController.php):
  valida fechas/turno y delega el reemplazo de la disponibilidad propia.
- [AsignacionController.php](app/Controllers/AsignacionController.php): lista
  asignaciones, presenta candidatos, procesa la asignación y el cierre.
- [ConsultaController.php](app/Controllers/ConsultaController.php): valida
  filtros, construye una consulta preparada y pasa filas a la vista de consultas.
- [UsuarioAdminController.php](app/Controllers/UsuarioAdminController.php):
  crea cuentas internas, perfiles de coordinador y cambios de activación con
  auditoría y protecciones para administradores.

### Modelos (`app/Models/`)

- [Usuario.php](app/Models/Usuario.php): inserta usuarios, busca por correo,
  lista cuentas internas y cambia `activo`. Limita creación a contraseñas de
  12–72 bytes y usa `password_hash()`.
- [Alumno.php](app/Models/Alumno.php): inserta expediente y dirección/teléfono;
  crea la fila de tutor legal. Registra errores PDO en el log y comunica fallos
  al controlador mediante un ID cero o `false`.
- [Empresa.php](app/Models/Empresa.php): inserta perfil/dirección, encuentra una
  empresa por usuario, comprueba cobertura de convenio y gestiona sedes.
- [Especialidad.php](app/Models/Especialidad.php): consulta la lista de ciclos y
  verifica su existencia.
- [Oferta.php](app/Models/Oferta.php): inserta y lista ofertas, devuelve
  pendientes, actualiza una decisión y localiza alumnos compatibles.
- [Convenio.php](app/Models/Convenio.php): inserta, lista, busca y resuelve
  convenios pendientes.
- [Disponibilidad.php](app/Models/Disponibilidad.php): consulta ventanas por
  usuario y reemplaza las del alumno en transacción.
- [Asignacion.php](app/Models/Asignacion.php): lista asignaciones, crea una con
  bloqueo/cupo/compatibilidad y la cierra con auditoría.
- [Auditoria.php](app/Models/Auditoria.php): inserta eventos con usuario,
  entidad, ID y detalle opcional.

Los modelos nuevos suelen recibir un `PDO` en el constructor para compartir
conexión y transacción. Los modelos anteriores de usuario, alumno y empresa
también aceptan conexión opcional y, de otro modo, llaman al singleton de PDO.

### Infraestructura, rutas y vistas

- [View.php](app/Core/View.php): resuelve una plantilla bajo `app/Views`,
  extrae los datos como variables evitando sobrescribir variables existentes y
  requiere el archivo. Lanza excepción si la plantilla no existe.
- [AuthMiddleware.php](app/Middleware/AuthMiddleware.php): renueva la autoridad
  de rol consultando `usuarios` y rechaza sesión ausente, inexistente o
  desactivada.
- [CsrfMiddleware.php](app/Middleware/CsrfMiddleware.php): genera un token
  hexadecimal aleatorio en sesión y valida el valor POST usando comparación
  constante `hash_equals()`.
- [web.php](routes/web.php): matriz declarativa de acciones, métodos HTTP,
  roles, clase de controlador y método destino.
- [index.php](public/index.php): entrada única, sesión, cabeceras, lookup de
  ruta, middleware, manejo de 404/405/500 y composición del layout.
- [main.php](app/Views/layouts/main.php): documento HTML común, menú dependiente
  del rol y formulario POST de logout.
- [select_especialidades.php](app/Views/partials/select_especialidades.php):
  selector reutilizable con IDs numéricos y nombres escapados.
- [app/Views/home/index.php](app/Views/home/index.php): bienvenida breve.
- [app/Views/auth/login.php](app/Views/auth/login.php): formulario de acceso,
  mensajes y token CSRF.
- [app/Views/alumnos/registro.php](app/Views/alumnos/registro.php): datos
  personales, ciclo y bloque de tutor.
- [app/Views/alumnos/disponibilidad.php](app/Views/alumnos/disponibilidad.php):
  formulario de disponibilidad y lista de ventanas registradas.
- [app/Views/empresas/registro.php](app/Views/empresas/registro.php): alta de
  empresa.
- [app/Views/empresas/sedes.php](app/Views/empresas/sedes.php): formulario y
  lista de sedes.
- [app/Views/convenios/empresa.php](app/Views/convenios/empresa.php): carga de
  PDF, ciclo, fechas, sede y tabla histórica de convenios.
- [app/Views/convenios/revisar.php](app/Views/convenios/revisar.php): documento,
  datos del convenio y controles aprobar/rechazar.
- [app/Views/ofertas/listado.php](app/Views/ofertas/listado.php): tabla pública
  de ofertas aprobadas.
- [app/Views/ofertas/publicar.php](app/Views/ofertas/publicar.php): formulario
  de oferta condicionado por la existencia de convenio, más historial propio.
- [app/Views/ofertas/revisar.php](app/Views/ofertas/revisar.php): tarjetas de
  ofertas pendientes y acciones de revisión.
- [app/Views/asignaciones/matching.php](app/Views/asignaciones/matching.php):
  ofertas, contador de plazas, candidatos y formulario de asignación.
- [app/Views/asignaciones/listado.php](app/Views/asignaciones/listado.php):
  detalle de prácticas y botón para cerrarlas cuando el rol lo permite.
- [app/Views/consultas/index.php](app/Views/consultas/index.php): filtros y
  tabla de seguimiento.
- [app/Views/admin/usuarios.php](app/Views/admin/usuarios.php): alta y
  activación/desactivación de cuentas.
- [app/Views/errors/404.php](app/Views/errors/404.php): acción o página no
  encontrada.
- [app/Views/errors/405.php](app/Views/errors/405.php): método no permitido.
- [app/Views/errors/generic.php](app/Views/errors/generic.php): respuesta
  genérica para error interno o acceso no autorizado.
- [style.css](public/assets/css/style.css): colores mediante variables CSS,
  estilos de navegación, formularios, tablas, tarjetas, mensajes y regla
  responsive para pantallas estrechas.

## 9. Base de datos y SQL

### 9.1 Tablas del volcado base

El volcado `database/sistema_fct.sql` contiene el esquema legado y filas de
ejemplo para:

- `usuarios`: correo único, hash, rol ENUM y fecha de creación.
- `alumnos`: perfil, datos de contacto, ciclo y estado de solicitud; la fecha
  de nacimiento se añade mediante migración.
- `coordinadores`: perfil asociado a un usuario.
- `empresas`: identidad y datos básicos de empresa.
- `especialidades`: ciclos formativos. En el esquema base el campo se llama
  `nombre_specialidad` por una errata histórica.
- `empresa_especialidades_plazas`: ofertas, ciclo, número de plazas y fechas.
- `tutores_legales`: un tutor como máximo por alumno, con clave única en
  `alumno_id`.
- `direcciones_telefonos`: teléfonos y direcciones relacionados con empresa,
  alumno o tutor.

Las tablas usan claves primarias, índices, claves únicas y claves foráneas. La
eliminación de usuario elimina perfiles dependientes; la eliminación de
especialidad se refleja poniendo a `NULL` la especialidad del alumno, y las
ofertas base están relacionadas con empresa y especialidad. El dump configura
`utf8mb4`, crea tablas InnoDB y restaura claves/indexes, pero el nombre de base
se selecciona previamente: el fichero no crea por sí solo la base de datos.

### 9.2 Migraciones

- [001_renombrar_nombre_especialidad.sql](../database/migrations/001_renombrar_nombre_especialidad.sql):
  cambia `nombre_specialidad` a `nombre_especialidad` y vuelve a crear el índice
  único. Usa `CHANGE`, compatible con MariaDB 10.4. Se aplica solo si la columna
  todavía tiene el nombre antiguo. No tiene control de idempotencia.
- [002_fct_workflows.sql](../database/migrations/002_fct_workflows.sql): añade
  `usuarios.activo`, `empresas.persona_contacto`, fecha de nacimiento,
  información del tutor y estado/turno/datos de revisión de oferta. Crea
  `empresa_sedes`, `convenios`, `alumno_disponibilidad`, `asignaciones_fct` y
  `auditoria`, con índices, ENUM y relaciones.
- [003_login_y_recuperacion.sql](../database/migrations/003_login_y_recuperacion.sql):
  crea las tablas de intentos de inicio de sesión y recuperación de cuentas.
- [004_convenios_por_especialidad.sql](../database/migrations/004_convenios_por_especialidad.sql):
  vincula convenios a ciclos formativos y añade la restricción única por empresa
  y ciclo.
- [005_horarios_y_calificaciones.sql](../database/migrations/005_horarios_y_calificaciones.sql):
  crea las tablas de disponibilidad semanal y calificaciones finales.
- [006_alumno_codigo_postal.sql](../database/migrations/006_alumno_codigo_postal.sql):
  añade el código postal opcional para permitir completar perfiles existentes.
- [007_webhooks.sql](../database/migrations/007_webhooks.sql): crea las bandejas
  de eventos recibidos y pendientes de envío.
- [008_api_access_tokens.sql](../database/migrations/008_api_access_tokens.sql):
  crea el almacenamiento de tokens de acceso de la API; solo conserva sus
  hashes.
- [009_webhook_responses.sql](../database/migrations/009_webhook_responses.sql):
  añade las columnas para conservar respuestas HTTP de recepción y envío.
- [015_solicitudes_reset_password.sql](../database/migrations/015_solicitudes_reset_password.sql):
  crea solicitudes de restablecimiento de contraseña con aprobación y migra
  las solicitudes pendientes del flujo anterior.
- [016_audit_logs_aprobacion_empresas.sql](../database/migrations/016_audit_logs_aprobacion_empresas.sql):
  crea `audit_logs` para conservar el administrador, la entidad y el registro
  afectados, la IP y la fecha de las aprobaciones de empresa.

Ninguna migración elige la base de datos mediante `USE`; quien las ejecuta debe
seleccionar explícitamente la base correcta en phpMyAdmin o en el cliente SQL.
La migración `001` debe omitirse cuando la columna ya tenga el nombre corregido.
Las migraciones `002`–`009` admiten volver a ejecutarse tras completarse
correctamente, al igual que `015` y `016`; no reparan objetos preexistentes con
definiciones distintas.

### 9.3 Seeds

[001_sample_data.sql](../database/seeds/001_sample_data.sql) inserta ciclos,
cuentas, perfil de coordinador y empresa, una oferta, dos alumnos y tutor y
direcciones. Sus cuentas de ejemplo usan hashes compatibles con la contraseña
`password123`. Este archivo presupone que las tablas existen y que la columna de
especialidad ya se llama `nombre_especialidad`. Como también inserta IDs y
correos fijos presentes en el dump, no se debe cargar encima del dump completo:
son alternativas de inicialización de datos, no scripts para ejecutar ambos en
serie sobre la misma base.

### 9.4 Datos de prueba en el dump

En el estado del repositorio inspeccionado, el dump ahora almacena hashes bcrypt
para las cuentas de ejemplo y ya no incluye una inserción adicional de dirección
para `alumno_id = 3`. La dirección restante en el bloque de ejemplos corresponde
a alumno `2`, que sí existe. La URL de ejemplo de la empresa en el dump base
todavía tiene el valor mal formado `://techsolutions.com`; ese campo no
interviene en los flujos actuales.

## 10. Seguridad y tratamiento de datos

- PDO se configura para lanzar excepciones, devolver filas asociativas y
  desactivar prepares emulados. Los valores variables se pasan como parámetros
  preparados en las consultas.
- Las contraseñas nuevas se hashean; el login verifica el hash y no conserva la
  contraseña en sesión.
- `session.use_strict_mode`, cookie `HttpOnly`, `SameSite=Lax`, `Secure` cuando
  HTTPS está activo y regeneración de sesión tras login reducen riesgos de
  fijación y exposición de sesión.
- Se envían cabeceras `X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy`, `Cache-Control` y una Content Security Policy básica.
- Toda ruta POST exige CSRF; las rutas de gestión requieren rol y cuenta activa.
- Los convenios se almacenan fuera de `public/`, con permisos privados, y la
  descarga audita accesos permitidos y denegados. Administradores y
  Coordinadores pueden leer cualquier convenio; Empresa solo los propios;
  Observador y Alumno no pueden descargarlos.
- La carga comprueba límites efectivos de PHP, el límite de cuerpo de Apache,
  MIME/extensión y un análisis ClamAV obligatorio antes de guardar. Un fallo,
  timeout o base de firmas con más de tres días impide la carga.
- Las vistas escapan los datos dinámicos con `htmlspecialchars()`; IDs y
  recuentos numéricos se convierten a enteros.
- La subida limita tamaño, MIME y extensión, valida que PHP la haya recibido y
  asigna un nombre aleatorio. El acceso pasa por autorización y verificación de
  ruta real para impedir salir del directorio de convenios.
- Las transacciones protegen registros compuestos, revisiones auditadas y
  asignaciones con plazas.
- Los mensajes de error generales evitan mostrar excepciones completas en el
  navegador. Los detalles de algunas operaciones se envían a `error_log`.

La base contiene datos personales (identificación, correo, dirección,
teléfono y, en algunos formularios, números de seguridad social) y convenios.
El repositorio está configurado para ejemplos y desarrollo. Para desplegarlo,
sirve únicamente `public/`, configura límites de Apache/PHP y establece la ACL
del directorio externo definido por `FCT_STORAGE_PATH`. Configura `FCT_CLAMSCAN`
con un antivirus disponible y firmas actualizadas. También se requieren
protección de copias de seguridad, política de retención y revisión de
privacidad.

## 11. Pruebas existentes y alcance de validación

`tests/run.php` es una prueba de integración en PHP nativo, no una suite
PHPUnit. Rechaza el arranque si `FCT_TEST_DB_NAME` no empieza por `fct_test_` o
si `FCT_DB_NAME` apunta a otro esquema. Después comprueba columnas esenciales y
los hashes de las cuentas de muestra, crea datos con sufijos aleatorios,
verifica revisión de convenio/oferta, compatibilidad de alumnos, límite de
plazas, cierre de asignación y aceptación/rechazo de CSRF, y limpia sus filas en
un bloque `finally`.

La prueba debe ejecutarse contra una base desechable que ya tenga el esquema y
las migraciones. El script verifica el prefijo, pero no crea ni elimina por sí
mismo la base completa. No apuntar el entorno a la base de trabajo normal.

Las pruebas registradas en el [informe histórico](../docs/informe_jornada_2026-10-07.md)
corresponden a la revisión de aquella fecha; no son una garantía de que las
pruebas se hayan ejecutado con cada cambio posterior. Ninguna prueba local
sustituye las pruebas de carga, una auditoría de seguridad externa ni la
validación del despliegue productivo.

## 12. Puesta en marcha resumida

1. Instalar/iniciar Apache y MariaDB con XAMPP; usar PHP 8.2 o compatible con el
   código actual.
2. Configurar Apache para que el `DocumentRoot` sea exclusivamente
   `public/`, no la raíz del repositorio.
3. Crear la base vacía `sistema_fct` desde phpMyAdmin.
4. Importar `database/sistema_fct.sql`.
5. Aplicar las migraciones `001`–`009` en orden; omitir la `001` solo si la
   columna ya se llama `nombre_especialidad`.
6. No cargar también el seed con los mismos registros.
7. Ajustar `FCT_DB_HOST`, `FCT_DB_USER`, `FCT_DB_PASS` y `FCT_DB_NAME` si los
   valores XAMPP predeterminados no aplican. En un entorno real no usar root sin
   contraseña.
8. Abrir la aplicación por Apache. Para pruebas locales, el servidor integrado
   de PHP debe arrancarse con `-t public public\dev-router.php` para aplicar las
   mismas restricciones de archivos ocultos; no es un servidor de producción.

La secuencia inicial debe ejecutarse sobre una base vacía. Para una base
existente, comprueba primero su esquema y aplica las migraciones pendientes; la
`001` requiere atención especial porque no se puede volver a ejecutar después
de que la columna haya cambiado de nombre.

## 13. Límites, decisiones pendientes y diferencias documentales

1. **Informe histórico.** Las secciones 1–10 del informe describen una etapa
   previa: enumeran módulos como pendientes y anotan el estado de Git y pruebas
   de aquel momento. La sección 11 intenta actualizar algunos puntos, pero
   conserva la afirmación antigua de que el dump no se modificó y que la fila
   inválida sigue ahí. Debe leerse como registro histórico, no como inventario
   infalible del árbol actual.
2. **Configuración Apache.** El repositorio no incluye un VirtualHost listo para
   cada entorno. Sus `.htaccess` bloquean el árbol privado y habilitan `public/`;
   el despliegue debe servir solo `public/`, permitir las directivas necesarias
   y verificar la configuración efectiva del servidor.
3. **JavaScript, imágenes y expedientes.** El JavaScript solo sirve a maquetas
   heredadas, limpia claves antiguas de `localStorage` y no participa en las
   rutas MVC. Hay directorios reservados y no existe un flujo de carga de
   expedientes ni interfaz de imágenes.
4. **Sedes.** El código puede listarlas y añadirlas, pero no incluye editar,
   eliminar ni desactivar sedes desde la interfaz.
5. **Disponibilidad.** La interfaz conserva una única ventana continua porque
   reemplaza todo el historial al guardar; confirmar si el negocio necesita
   permitir varios intervalos.
6. **Cierre y reutilización de alumnos.** La asignación cerrada consume plaza en
   su oferta pero no excluye al alumno de ofertas posteriores. Confirmar si se
   permite cursar más de una práctica.
7. **Cobertura de convenios.** El formulario permite indicar fechas futuras;
   el servidor valida al enviarlo que un convenio aprobado cubra el periodo
   completo. No se confía en una comprobación de vigencia del lado del cliente.
8. **Consultas.** Los filtros de fecha se aplican a fechas de inicio/fin como
   cotas de contenido, no mediante solapamiento de periodos. El listado puede
   repetir una oferta si hay más de una asignación.
9. **Errores.** `storage/logs/` es un directorio placeholder; el proyecto usa
    `error_log()` pero no configura explícitamente que PHP escriba ahí. El
    destino depende de la configuración de PHP/XAMPP.
10. **Servicios de revisión y permisos.** Administrador y Coordinador revisan
    convenios/ofertas y pueden descargar cualquier convenio. Empresa solo
    descarga documentos de su propio perfil; Observador y Alumno no acceden a
    convenios. Las descargas permitidas y denegadas quedan auditadas.
11. **Despliegue productivo.** Aún no se documentan HTTPS obligatorio,
    credenciales de base dedicadas, backups, rotación de logs, límites de
    `php.ini` para carga, recuperación de contraseña ni política formal de
    privacidad.
12. **Control de versiones.** El estado consultado de la rama tiene cambios sin
    commit; esta guía se añade como documento nuevo y no crea un commit ni
    publica cambios.

Estas observaciones describen el comportamiento encontrado, no indican que
haya que cambiarlo automáticamente. Las decisiones funcionales deben
confirmarse antes de alterar el modelo.

## 14. Inventario de documentación y archivos auxiliares

- [README.md](../README.md): instalación, migraciones, uso de cuentas de ejemplo y
  ejecución de pruebas.
- [docs/informe_jornada_2026-10-07.md](docs/informe_jornada_2026-10-07.md):
  cronología y decisiones históricas, incluida una lista antigua de pendientes
  y una sección de ampliación posterior.
- `app/Controllers/.gitkeep`, `app/Core/.gitkeep`,
  `app/Middleware/.gitkeep`, `app/Models/.gitkeep`,
  subdirectorios vacíos de `app/Views`, y placeholders bajo `config/`,
  `database/`, `public/assets/`, `routes/`, `storage/` y `tests/`: mantienen la
  estructura de carpetas en Git, sin lógica ejecutable.
- [storage/.htaccess](storage/.htaccess): directiva de Apache que niega acceso
  web directo al árbol de almacenamiento.
