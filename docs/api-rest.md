# API REST del Sistema FCT

## URL y autenticación

La API v1 está disponible bajo `/api/v1/`. En XAMPP, con el proyecto dentro de
`htdocs`, la URL base es:

```text
http://localhost/Practicas_Desarrollo/public/api/v1/
```

Con el lanzador local de webhooks (`scripts\local_webhooks.ps1 -Mode All`), la
URL base es `http://127.0.0.1:8002/api/v1/`.

Por defecto, el lanzador local usa MariaDB de XAMPP. Para que las solicitudes
hechas desde esta API y desde la aplicación Docker vean los mismos datos,
configura `.env.local` para conectarse a MariaDB Docker por
`127.0.0.1;port=3307` y copia `FCT_DB_NAME`, `FCT_DB_USER` y `FCT_DB_PASS` de
`.env`. Después inicia el lanzador con `-Mode All`. Ambas aplicaciones
compartirán la misma base en tiempo real; esto no copia ni fusiona los registros
que ya existan únicamente en la otra base de datos.

Antes de usarla en una base ya existente, importa la migración:

```powershell
Get-Content -Raw database\migrations\008_api_access_tokens.sql |
    & 'C:\xampp\mysql\bin\mysql.exe' -h 127.0.0.1 -uroot --database=sistema_fct
```

En el panel de administración abre **Tokens API**, crea un token para la cuenta
que corresponda y guárdalo en un gestor seguro: el token completo solo se
muestra una vez. La API aplica el rol actual de esa cuenta, limita los recursos
de Empresa a su empresa y los de Alumno a su propio expediente. Desactivar o
eliminar la cuenta invalida sus tokens; el administrador también puede
revocarlos explícitamente. En la base solo se guarda el hash SHA-256.

La pestaña **Integraciones** del menú de Administrador reúne la consola para
probar esta API y los historiales separados de webhooks recibidos y enviados.
El panel de tokens sigue disponible desde la propia consola.
Consulta también la [Guía de Integraciones](./integraciones.md) o su
[versión PDF](/guia-integraciones.pdf).

Con la sesión de Administrador, el panel **Pruebas API y webhooks** está
disponible tanto en Docker (`http://localhost:8080/index.php?action=pruebas-integracion`)
como en la aplicación local (`http://127.0.0.1:8002/index.php?action=pruebas-integracion`).
Para una prueba local de lectura:

1. Abre **Tokens API** en `http://127.0.0.1:8002/index.php?action=api-tokens`,
   crea un token de prueba para una cuenta activa y guárdalo temporalmente de
   forma segura. El token completo sólo se muestra una vez.
2. Abre el panel de pruebas, introduce el token, selecciona `GET` y un recurso
   (`alumnos`, `empresas`, `ofertas`, `convenios` o `asignaciones`) y envía la
   solicitud. No hace falta confirmar escrituras para `GET`; la respuesta
   muestra el estado HTTP y el JSON paginado.
3. Cuando termines, revoca el token desde **Tokens API**.

El token sólo se usa en la solicitud del navegador y se borra del campo al
enviarla; no se guarda en el servidor. Las operaciones `POST` y `PATCH`
modifican la base compartida actual, de modo que usa únicamente datos ficticios
y confirma el contenido antes de enviarlas.

Envía el token en cada solicitud:

```text
Authorization: Bearer fct_<token>
```

## Recursos y permisos

Todas las rutas requieren token. Las listas se paginan con `page` y
`per_page` (por defecto 1 y 50; máximo 100). Las respuestas de listas tienen
`data` y `meta`; los errores tienen `error.code` y `error.message`.

| Método | Ruta | Permiso y acción |
|---|---|---|
| GET | `/alumnos` y `/alumnos/{id}` | Administrador, Coordinador y Observador consultan; Alumno solo su propio expediente. No devuelve DNI ni número de Seguridad Social. |
| POST | `/alumnos` | Administrador o Coordinador crea una cuenta y expediente. Un alumno menor de edad requiere el objeto `tutor` completo. |
| PATCH | `/alumnos/{id}` | Alumno modifica nombre, apellidos, correo y código postal de su propio expediente. |
| GET | `/empresas` y `/empresas/{id}` | Administrador, Coordinador y Observador consultan; Empresa solo ve su propia empresa. |
| POST | `/empresas` | Administrador o Coordinador crea una cuenta y perfil de empresa. |
| PATCH | `/empresas/{id}` | Empresa modifica nombre, web, categoría y pertenencia a grupo de su propia empresa. |
| GET | `/ofertas` y `/ofertas/{id}` | Consulta; Alumno ve ofertas aprobadas vigentes y Empresa solo sus ofertas. |
| POST | `/ofertas` | Empresa crea una oferta pendiente asociada a un convenio aprobado. |
| PATCH | `/ofertas/{id}` | Administrador o Coordinador aprueba o rechaza una oferta pendiente. |
| GET | `/convenios` y `/convenios/{id}` | Administrador, Coordinador y Observador consultan; Empresa solo sus convenios. No expone la ruta del PDF. |
| POST | `/convenios` | Empresa registra un convenio mediante `multipart/form-data`; el PDF se analiza con ClamAV y se almacena fuera de la carpeta pública. |
| PATCH | `/convenios/{id}` | Administrador o Coordinador revisa un convenio pendiente. |
| GET | `/asignaciones` y `/asignaciones/{id}` | Administrador, Coordinador y Observador consultan; Alumno ve sus asignaciones y Empresa las de sus ofertas. |
| POST | `/asignaciones` | Administrador o Coordinador asigna un alumno que cumpla las reglas actuales de disponibilidad, ciclo y cupo. |
| PATCH | `/asignaciones/{id}` | Administrador o Coordinador cierra una asignación activa. |

No hay endpoints DELETE. La gestión de sedes permanece en los formularios
actuales. Las cuentas se crean con contraseña inicial y los datos de alumnos se
validan con las reglas de registro del sistema. Todas las escrituras registran
auditoría; las ofertas, convenios y asignaciones también generan los webhooks
de sus flujos correspondientes.

## Ejemplos

Consulta paginada de ofertas:

```powershell
$headers = @{ Authorization = "Bearer $env:FCT_API_TOKEN" }
Invoke-RestMethod -Uri 'http://localhost/Practicas_Desarrollo/public/api/v1/ofertas?page=1&per_page=20' -Headers $headers
```

Crear oferta como Empresa:

```powershell
$body = @{
    especialidad_id = 1
    plazas = 2
    fecha_inicio = '2027-03-01'
    fecha_fin = '2027-06-15'
    turno = 'Indiferente'
} | ConvertTo-Json

Invoke-RestMethod -Method Post `
    -Uri 'http://localhost/Practicas_Desarrollo/public/api/v1/ofertas' `
    -Headers $headers -ContentType 'application/json' -Body $body
```

Crear una cuenta de empresa como Administrador o Coordinador:

```powershell
$company = @{
    email = 'contacto@empresa.example'
    password = 'ContraseñaInicialSegura'
    cif = 'B12345678'
    nombre_comercial = 'Empresa FCT'
    telefono = '612345678'
    direccion = 'Calle Ejemplo 1, Madrid'
    persona_contacto = 'Persona de contacto'
} | ConvertTo-Json
Invoke-RestMethod -Method Post `
    -Uri 'http://localhost/Practicas_Desarrollo/public/api/v1/empresas' `
    -Headers $headers -ContentType 'application/json' -Body $company
```

Crear un expediente de alumno como Administrador o Coordinador requiere
`email`, `password`, `nombre`, `apellidos`, `fecha_nacimiento`, `dni`, `nss`,
`telefono`, `direccion`, `codigo_postal` y `especialidad_id`. Para menores de
18 años añade `tutor` como objeto con `nombre`, `dni`, `nss`, `direccion`,
`telefono` y `correo`.

Registrar convenio con PDF aprobado por ClamAV:

```powershell
curl.exe -X POST `
    -H "Authorization: Bearer $env:FCT_API_TOKEN" `
    -F "numero_convenio=CONV-2027-001" `
    -F "especialidad_id=1" `
    -F "fecha_firma=2027-01-15" `
    -F "fecha_inicio=2027-03-01" `
    -F "fecha_fin=2027-06-30" `
    -F "archivo=@convenio.pdf;type=application/pdf" `
    'http://localhost/Practicas_Desarrollo/public/api/v1/convenios'
```

Asignar alumno y cerrar la práctica:

```powershell
$assignment = @{
    oferta_id = 1
    alumno_id = 2
    tutor_empresa = 'Tutor de prácticas'
} | ConvertTo-Json
$created = Invoke-RestMethod -Method Post `
    -Uri 'http://localhost/Practicas_Desarrollo/public/api/v1/asignaciones' `
    -Headers $headers -ContentType 'application/json' -Body $assignment

$close = @{ estado = 'Cerrada' } | ConvertTo-Json
Invoke-RestMethod -Method Patch `
    -Uri "http://localhost/Practicas_Desarrollo/public/api/v1/asignaciones/$($created.data.id)" `
    -Headers $headers -ContentType 'application/json' -Body $close
```

Actualizar el perfil de empresa autenticado:

```powershell
$companyUpdate = @{
    nombre_empresa = 'Empresa FCT'
    pagina_web = 'https://empresa.example'
    categoria = 'Tecnología'
} | ConvertTo-Json
Invoke-RestMethod -Method Patch `
    -Uri 'http://localhost/Practicas_Desarrollo/public/api/v1/empresas/1' `
    -Headers $headers -ContentType 'application/json' -Body $companyUpdate
```

Para aprobar una oferta o convenio, usa `PATCH` sobre su ruta de detalle con
`{"estado":"Aprobada"}` o `{"estado":"Aprobado"}`. Para rechazar, el estado
debe ser `Rechazada` o `Rechazado` y debe incluir `motivo`.

## Códigos de respuesta y despliegue

La API usa `200` para consultas y actualizaciones, `201` para creaciones,
`401` para tokens ausentes o inválidos, `403` para roles no autorizados,
`404` para recursos inexistentes, `405` para métodos no admitidos, `409` para
convenios duplicados, `413` para cuerpos demasiado grandes y `422` para datos
que no cumplen las reglas del dominio. Si ClamAV no está disponible, registrar
un convenio responde `503`. Errores inesperados se registran en el log del
servidor y se responden con `500`.

La carga de PDF necesita los límites Apache/PHP documentados en el README,
ClamAV operativo y `FCT_STORAGE_PATH` configurado fuera del document root. En
producción, publica la API únicamente mediante HTTPS. No incluyas tokens en
URLs, código fuente, repositorio, logs o capturas. Trata también contraseñas y
datos personales enviados al crear expedientes como información confidencial.
