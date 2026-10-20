# Integración de API y webhooks

## Alcance de esta primera versión

Esta integración proporciona dos sentidos de comunicación entre Sistema FCT y
un sistema externo:

- **Recepción:** `POST /webhook.php` recibe un sobre JSON firmado, lo valida y
  guarda en `webhook_inbox`. Es un buzón de entrada; no crea ni modifica
  alumnos, empresas, cursos, ofertas, convenios, prácticas o calificaciones.
  También conserva la respuesta HTTP más reciente para inspeccionar si el
  emisor recibió `202` o una respuesta de duplicado `200`.
- **Envío:** cuando se configura `FCT_WEBHOOK_OUTBOUND_URL`, los cambios de
  ofertas, convenios, prácticas y disponibilidad añaden un evento JSON a
  `webhook_outbox` en la misma transacción que el cambio. Un proceso CLI separado entrega la cola
  y programa reintentos si el receptor no está disponible. Conserva el código
  HTTP y el cuerpo de la respuesta del receptor, con un límite de 16 KiB.

El proyecto no tiene un módulo independiente de cursos ni un endpoint REST
para consultar expedientes. Por eso los eventos incluidos notifican los
cambios del flujo FCT existente. Los sobres salientes solo incluyen
identificadores internos y los datos de proceso necesarios; no incluyen
nombre, correo, DNI, número de Seguridad Social, dirección, tutor ni el PDF de
un convenio. No uses este buzón para enviar información personal o documentos.

## Migración

En las bases existentes selecciona la base correcta y ejecuta una vez:

```powershell
Get-Content -Raw database\migrations\007_webhooks.sql | & 'C:\xampp\mysql\bin\mysql.exe' -h 127.0.0.1 -uroot --database=sistema_fct
Get-Content -Raw database\migrations\007_webhooks.sql | & 'C:\xampp\mysql\bin\mysql.exe' -h 127.0.0.1 -uroot --database=fct_test_local
Get-Content -Raw database\migrations\009_webhook_responses.sql | & 'C:\xampp\mysql\bin\mysql.exe' -h 127.0.0.1 -uroot --database=sistema_fct
```

Sustituye los nombres por las bases de desarrollo y pruebas que uses. La
migración crea `webhook_inbox` y `webhook_outbox` mediante `IF NOT EXISTS`; no
borra datos existentes. Ejecuta la migración `009` una sola vez en cada base
existente; añade las columnas donde se guardan las respuestas HTTP. Para nuevas
instalaciones Docker Compose, las migraciones están enlazadas en el orden de
arranque inicial de MariaDB. Los volúmenes ya inicializados no vuelven a
ejecutar scripts de inicialización automáticamente. Hay otros archivos con
prefijo `009`; selecciona siempre `009_webhook_responses.sql` por su nombre
completo. Este `ALTER TABLE` no debe repetirse si ya existen sus columnas.

## Configuración de secretos

Genera dos secretos independientes de al menos 32 bytes aleatorios:

- `FCT_WEBHOOK_INBOUND_SECRET`: firma exigida al sistema externo cuando llama
  al endpoint entrante.
- `FCT_WEBHOOK_OUTBOUND_SECRET`: firma incluida en las llamadas que Sistema
  FCT envía.
- `FCT_WEBHOOK_OUTBOUND_URL`: endpoint HTTPS del receptor de eventos salientes.

No reutilices contraseñas de usuarios, no incluyas los secretos en el
repositorio, SQL, URL, capturas o logs. Configúralos en el entorno protegido de
Apache/PHP y del proceso de envío; reinicia Apache al cambiar su configuración.
HTTP sin TLS solo se permite para pruebas contra `localhost` en el worker.

### Preparar la ejecución local en XAMPP/Windows

Genera un secreto aleatorio desde PowerShell:

```powershell
$rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
$bytes = New-Object byte[] 32
$rng.GetBytes($bytes)
$secret = [Convert]::ToBase64String($bytes)
$rng.Dispose()
```

Guárdalo en `.env.local`, archivo excluido de Git. Usa el mismo valor para
entrada y salida porque el worker local entregará al propio endpoint. Para usar
la base de XAMPP, conserva `FCT_DB_HOST=127.0.0.1`; si Docker debe ser la base
compartida por la aplicación local de `8002` y Docker, usa
`FCT_DB_HOST=127.0.0.1;port=3307` y copia también `FCT_DB_NAME`, `FCT_DB_USER` y
`FCT_DB_PASS` de `.env`:

```powershell
$envLines = Get-Content -LiteralPath .env
foreach ($line in $envLines) {
    if ($line -match '^\s*([A-Za-z_][A-Za-z0-9_]*)=(.*)$') {
        [Environment]::SetEnvironmentVariable($Matches[1], $Matches[2], 'Process')
    }
}

$secret = 'PEGA_AQUI_EL_SECRETO_GENERADO'
@"
FCT_DB_HOST=127.0.0.1;port=3307
FCT_DB_NAME=$env:FCT_DB_NAME
FCT_DB_USER=$env:FCT_DB_USER
FCT_DB_PASS=$env:FCT_DB_PASS
FCT_WEBHOOK_INBOUND_SECRET=$secret
FCT_WEBHOOK_OUTBOUND_SECRET=$secret
FCT_WEBHOOK_OUTBOUND_URL=http://127.0.0.1:8001/webhook.php
"@ | Set-Content -LiteralPath .env.local -Encoding ascii
```

El bloque carga las variables de conexión de `.env` sin mostrarlas y crea
`.env.local` para que la aplicación XAMPP use la base de Docker. No publiques
ni compartas `.env.local`.

El lanzador local lee el archivo sin imprimir el secreto. Inicia de una vez
el receptor, la aplicación y la interfaz local:

```powershell
Set-Location C:\xampp\htdocs\Practicas_Desarrollo
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts\local_webhooks.ps1 -Mode All
```

Abre la aplicación en `http://127.0.0.1:8002/index.php`, la interfaz de prueba
en `http://127.0.0.1:8003/webhook_test.php` y el receptor en
`http://127.0.0.1:8001/webhook.php`. La aplicación local usa la base indicada
por `.env.local`; con `127.0.0.1;port=3307` comparte en tiempo real la base de
Docker. El modo `All` verifica los puertos y las páginas antes de informar que
el entorno está listo.

Para detener los servidores iniciados por el lanzador:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts\local_webhooks.ps1 -Mode Stop
```

También puedes iniciar cada servicio por separado con `-Mode Server` (8001),
`-Mode App` (8002) y `-Mode Interface` (8003). Para procesar eventos de la
cola de negocio manualmente, ejecuta:

```powershell
Set-Location C:\xampp\htdocs\Practicas_Desarrollo
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts\local_webhooks.ps1 -Mode Dispatch
```

El worker debe indicar `Entregado` y el endpoint registrará el evento en
`webhook_inbox`. Repite `Dispatch` para procesar eventos posteriores. Los
servidores locales escuchan solo en `127.0.0.1`. No compartas `.env.local` ni
incluyas el secreto en capturas o logs; esta configuración no modifica Apache
ni variables globales de Windows.

Para probar sin un receptor externo, inicia el lanzador y, con sesión de
Administrador, abre `http://127.0.0.1:8002/index.php?action=pruebas-integracion`.
La sección de webhooks recibe un evento sintético firmado y puede encolar y
entregar otro a `127.0.0.1:8001`; ambos recorridos usan datos ficticios y las
respuestas indican el estado HTTP. Para validar la API local, el mismo panel
permite enviar `GET` con un token creado en **Tokens API**. Revoca ese token al
terminar las pruebas. Las solicitudes `POST` y `PATCH` escriben en la base
compartida y no se revierten automáticamente.

Para Docker, añade las variables requeridas a un `.env` local ignorado por Git
y reinicia el servicio de aplicación. No copies secretos en
`.env.docker.example`.

### Panel manual de integración en Docker

Con la sesión de Administrador, abre **Pruebas API y webhooks** en la
aplicación. La recepción manual firma un evento ficticio y lo envía por HTTP al
endpoint local; se archiva en `webhook_inbox` y no cambia datos académicos.
El envío saliente crea una fila sintética en `webhook_outbox`, ejecuta el
despachador real y la entrega únicamente al receptor del mismo contenedor, sin
usar el destino externo configurado. Ambas pruebas muestran la respuesta HTTP
y el identificador del evento. En el lanzador local de XAMPP, la aplicación
de `8002` envía las pruebas al receptor de `8001`; en Docker se usa el endpoint
local del contenedor.

Estas pruebas del servidor y la ejecución del despachador requieren que PHP
tenga habilitada la extensión cURL. En la imagen Docker actual está disponible:
`docker compose exec -T app php -m` lista `curl` (la imagen base
`php:8.2-apache` la incluye). Si cambias la imagen base, repite `php -m` dentro
del contenedor antes de ejecutar las pruebas; en el lanzador local de XAMPP,
comprueba la extensión del PHP de XAMPP. El panel de API que envía
solicitudes directamente desde el navegador no depende de esa extensión.

El panel de API de esa página envía las solicitudes desde el navegador y borra
el token del campo después de cada petición. Las operaciones POST/PATCH sí
escriben en la base actual y requieren confirmación en pantalla; no tienen
borrado automático. No uses datos personales ni ejecutes escrituras que no
quieras conservar. Ese probador usa el alias `/api/v1/`: funciona con el
servidor local del lanzador, pero el Apache incluido en Docker no configura
esa reescritura. En Docker, usa `public/api.php?path=...` o configura la regla
antes de usar el probador. Consulta [API REST](/api-rest) para las rutas y el
contrato de respuestas.

Con sesión de Administrador, usa las páginas **Webhooks recibidos** y
**Webhooks enviados** para consultar por separado los últimos 50 eventos de
cada dirección, sus JSON, estados y respuestas HTTP. Los datos se muestran
escapados como texto y ambas páginas son de solo lectura. Las respuestas de
eventos anteriores a la migración 009 no se conservan y se indican como tales.

La pestaña **Integraciones** del menú de Administrador presenta la API propia,
los webhooks recibidos y los enviados en secciones separadas. Los dos
historiales se agrupan y ordenan por empresa; los eventos sin un `empresa_id`
válido aparecen en **Sin empresa identificada**. Dentro de cada empresa, los
eventos más recientes se muestran primero.
La [Guía de Integraciones](./integraciones.md) describe el uso del panel y
también está disponible en [PDF](./guia-integraciones.pdf).

La página **Webhooks por empresa** reúne por separado los eventos enviados y
recibidos de una empresa seleccionada. Los eventos salientes de convenios,
ofertas y prácticas incluyen `data.empresa_id`, resuelto desde el registro
asociado. En los eventos entrantes, el emisor debe incluir `empresa_id` dentro
de `data` con el ID de empresa que usa esta base de datos. Los eventos sin ese
campo, o con un ID no numérico, aparecen bajo **Sin empresa identificada**;
no se asocian automáticamente por nombre, correo ni otro dato ambiguo. El ID
interno debe comunicarse al integrador de forma controlada si se acuerda usarlo
como referencia.

Abre **Webhooks por empresa** con la cuenta de Administrador y selecciona la
empresa. La ficha mantiene secciones separadas de enviados y recibidos, pero
reúne los distintos tipos de evento de esa empresa. Para consultar la misma
actividad mediante un enlace directo:

```text
http://127.0.0.1:8002/index.php?action=webhooks-empresa&empresa_id=3
```

Sustituye `3` por el ID interno mostrado en el selector. La ficha es de solo
lectura; no procesa el contenido de los webhooks entrantes.

## Endpoint entrante

Con el lanzador local `scripts/local_webhooks.ps1 -Mode All`, el receptor
escucha en loopback en el puerto 8001; la aplicación MVC usa el puerto 8002 y
la interfaz manual el 8003:

```text
http://127.0.0.1:8001/webhook.php
```

Si se ejecuta un servidor PHP local distinto, usa la URL y el puerto en que
publique `public/webhook.php`. URL del entorno público (solo después de
configurar TLS y el servidor):

```text
https://tu-dominio.example/webhook.php
```

Solo acepta `POST`, `Content-Type: application/json` y un cuerpo de hasta
64 KiB. El cuerpo debe incluir `event_id`, `event`, `version` y `data`; el
campo `occurred_at` es opcional (se muestra en el ejemplo como metadato del
emisor):

```json
{
  "event_id": "sistema-externo-evt-001",
  "event": "curso.matricula_actualizada",
  "version": 1,
  "occurred_at": "2026-10-15T10:00:00Z",
  "data": {
    "referencia_externa": "matricula-902"
  }
}
```

El emisor incluye tres cabeceras. La firma se calcula con el cuerpo JSON exacto
en bytes, sin volver a serializarlo:

```text
X-FCT-Event-Id: sistema-externo-evt-001
X-FCT-Timestamp: 1792058400
X-FCT-Signature: sha256=<HMAC hexadecimal>
```

La fórmula es:

```text
HMAC-SHA256(secret, timestamp + "." + cuerpo_JSON_exacto)
```

Se acepta una diferencia máxima de cinco minutos entre la hora del emisor y el
servidor. El identificador debe coincidir entre la cabecera y el JSON. La clave
única de `webhook_inbox` hace idempotente la recepción: el primer evento válido
responde `202`; una repetición válida con el mismo identificador responde `200`
y `duplicate: true`. Una firma inválida responde `401`; JSON inválido `400`;
método no admitido `405`; tipo de contenido incorrecto `415`; cuerpo demasiado
grande `413`. Si falta el secreto, responde `503`. Reutilizar un `event_id`
con un cuerpo distinto responde `409`.

Respuesta de primera recepción:

```json
{
  "received": true,
  "duplicate": false,
  "event_id": "sistema-externo-evt-001"
}
```

La aplicación conserva el JSON firmado en `webhook_inbox` para que el equipo
pueda consultar la notificación. Define con el sistema integrador qué campos
están permitidos y evita transmitir datos de menores o categorías especiales
sin una necesidad y base legal aprobadas. El buzón no ejecuta el contenido como
una orden.

### Probar recepción mediante PowerShell

Primero configura `FCT_WEBHOOK_INBOUND_SECRET` en el proceso PHP que sirve la
aplicación y arranca el servidor o el lanzador local. En otra ventana define
la misma clave como `$secret`, prepara un evento sintético y firma exactamente
el cuerpo enviado:

```powershell
$secret = 'PEGA_AQUI_EL_MISMO_SECRETO_LOCAL_DE_32_BYTES_O_MAS'
$eventId = 'prueba-' + [guid]::NewGuid().ToString()
$timestamp = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds().ToString()
$payload = [ordered]@{
    event_id = $eventId
    event = 'prueba.integracion'
    version = 1
    occurred_at = [DateTimeOffset]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')
    data = [ordered]@{ referencia = 'dato-sintetico' }
}
$body = $payload | ConvertTo-Json -Depth 5 -Compress
$hmac = [System.Security.Cryptography.HMACSHA256]::new(
    [System.Text.Encoding]::UTF8.GetBytes($secret)
)
$message = [System.Text.Encoding]::UTF8.GetBytes("$timestamp.$body")
$signature = 'sha256=' + [BitConverter]::ToString($hmac.ComputeHash($message)).Replace('-', '').ToLowerInvariant()
$hmac.Dispose()

$headers = @{
    'X-FCT-Event-Id' = $eventId
    'X-FCT-Timestamp' = $timestamp
    'X-FCT-Signature' = $signature
}
Invoke-RestMethod -Method Post `
    -Uri 'http://127.0.0.1:8001/webhook.php' `
    -ContentType 'application/json' `
    -Headers $headers `
    -Body $body
```

Ejecuta exactamente la misma llamada una segunda vez con los mismos `$body`,
`$headers` y `$eventId`: debe responder `duplicate: true`. Para confirmar el
registro sin mostrar el JSON:

```powershell
& 'C:\xampp\mysql\bin\mysql.exe' -h 127.0.0.1 -uroot --database=sistema_fct `
    -e "SELECT event_id, event_type, received_at FROM webhook_inbox ORDER BY received_at DESC LIMIT 5;"
```

### Probar recepción desde Chrome (solo local)

Con el lanzador local activo, abre estas direcciones en dos pestañas:

- Aplicación FCT: `http://127.0.0.1:8002/index.php`
- Herramienta visual de recepción y envío: `http://127.0.0.1:8003/webhook_test.php`

En la herramienta, deja el tipo `prueba.integracion` y la referencia
`dato-sintetico`, marca la confirmación y pulsa **Enviar evento de prueba**.
Debe responder HTTP `202`, con `received: true` y `duplicate: false`. Pulsa
**Repetir el mismo envío** para comprobar la deduplicación: la respuesta debe
ser HTTP `200` y `duplicate: true`. Repite el primer botón para generar un
evento nuevo.

Para probar el envío de la aplicación, usa la sección **Probar envío saliente**.
Confirma la creación del evento sintético y pulsa **Enviar webhook de prueba**.
La herramienta encola un evento `prueba.integracion`, ejecuta el despachador
real de `scripts/dispatch_webhooks.php` limitado a ese identificador y muestra
el resultado. Debe confirmar que se envió al receptor local con HTTP `202`.
Por seguridad, el botón solo funciona si el destino configurado es
`http://127.0.0.1:8001/webhook.php`; si la cola ya contiene otro evento sin
procesar, primero usa el flujo normal de despacho para evitar mezclar pruebas.
El evento sintético queda registrado en `webhook_outbox` y, al ser entregado al
receptor local, también en `webhook_inbox`.

La herramienta solo sirve solicitudes desde `127.0.0.1` o `::1` cuyo host sea
`127.0.0.1:8001`. Las firmas se calculan en PHP con los secretos ya cargados en
el servidor local; los secretos no se envían al navegador, no se muestran y no
se guardan en almacenamiento web. Cada primer envío de recepción añade un
evento sintético a `webhook_inbox`; la repetición no añade otra fila. La prueba
saliente añade un evento sintético a cada bandeja local al completarse. No
introduzcas datos personales o reales.

## Eventos salientes

La primera versión encola estos cambios:

| Evento | Cuándo se encola | Datos principales |
|---|---|---|
| `convenio.creado` | Una empresa registra un convenio | `convenio_id`, `empresa_id`, `especialidad_id`, estado y periodo |
| `convenio.revisado` | El centro aprueba o rechaza el convenio | `convenio_id`, estado |
| `oferta.creada` | Una empresa registra una oferta | IDs de oferta/empresa/especialidad, estado, plazas, fechas y turno |
| `oferta.revisada` | El centro aprueba o rechaza una oferta | `oferta_id`, estado |
| `practica.asignada` | Se confirma una asignación | `asignacion_id`, `oferta_id`, `alumno_id` |
| `practica.cerrada` | Se cierra una práctica | `asignacion_id` |
| `student.availability_updated` | El alumno guarda su disponibilidad | `student_id`, `fecha_inicio`, `fecha_fin`, `turno`, `es_indiferente`, `horarios` |

La aplicación guarda cada evento saliente en la misma transacción que el
cambio de negocio. Si el cambio se revierte, el evento tampoco queda encolado.
No realiza llamadas HTTP durante una petición del usuario, por lo que una
caída del receptor no retrasa ni revierte el guardado local.

Ejemplo de JSON enviado:

```json
{
  "event_id": "uuid-generado-por-sistema-fct",
  "event": "practica.asignada",
  "version": 1,
  "occurred_at": "2026-10-15T10:00:00Z",
  "data": {
    "asignacion_id": 123,
    "oferta_id": 45,
    "alumno_id": 92
  }
}
```

Al receptor se envían `Content-Type: application/json`,
`X-FCT-Event-Id`, `X-FCT-Timestamp` y `X-FCT-Signature`. El receptor debe
verificar la firma y recordar los identificadores procesados: una respuesta
puede perderse después de que el receptor haya guardado el evento, y el worker
podría repetir la entrega.

### Configurar y ejecutar el worker

Configura en el entorno del proceso PHP:

```text
FCT_WEBHOOK_OUTBOUND_URL=https://sistema-receptor.example/api/webhooks/fct
FCT_WEBHOOK_OUTBOUND_SECRET=<secreto distinto, aleatorio y privado>
```

Si la aplicación se ejecuta desde una terminal local, inicia el servidor PHP
con las variables definidas en esa terminal. Para entregar la cola en una
ventana PowerShell separada:

```powershell
Set-Location C:\xampp\htdocs\Practicas_Desarrollo
$env:FCT_DB_HOST = '127.0.0.1'
$env:FCT_DB_NAME = 'sistema_fct'
$env:FCT_WEBHOOK_OUTBOUND_URL = 'https://sistema-receptor.example/api/webhooks/fct'
$env:FCT_WEBHOOK_OUTBOUND_SECRET = 'SECRETO_ALEATORIO_CONFIGURADO_TAMBIEN_EN_EL_RECEPTOR'
$env:FCT_WEBHOOK_DISPATCH_ALLOW = '1'
& 'C:\xampp\php\php.exe' scripts\dispatch_webhooks.php
Remove-Item Env:FCT_WEBHOOK_DISPATCH_ALLOW
```

El worker requiere CLI, la extensión PHP cURL, una confirmación
`FCT_WEBHOOK_DISPATCH_ALLOW=1`, URL válida y un secreto con al menos 32
caracteres. Solo permite HTTPS, salvo HTTP contra localhost para desarrollo.
No sigue redirecciones, valida TLS y limita conexión/solicitud a tres/diez
segundos. Procesa hasta 25 eventos por ejecución; se puede ajustar con
`FCT_WEBHOOK_DISPATCH_LIMIT` entre 1 y 100. En la imagen Docker actual cURL
está disponible (`docker compose exec -T app php -m` lista `curl`; la imagen base
`php:8.2-apache` la incluye). Si cambias la imagen base, repite `php -m`; con un
PHP de XAMPP u otro runtime, comprueba que la extensión esté habilitada.
Una respuesta `2xx` marca la entrega como correcta; los errores temporales y
de red se reintentan con espera exponencial, hasta 12 intentos. Errores
permanentes `3xx` y `4xx` (excepto `408`, `425` y `429`) quedan en la cola como
fallidos permanentes y requieren intervención.

Programa el comando en el Programador de tareas de Windows o en cron para
ejecutarlo periódicamente. No lo dejes como servidor HTTP ni lo publiques.
Supervisa la salida de cada ejecución y los campos `attempts`, `http_status`,
`last_error`, `delivered_at` y `failed_at` en `webhook_outbox`. Para volver a
intentar un evento fallido después de corregir el receptor, un administrador
de base puede revisar y reprogramar su fila manualmente; automatizar una
consola de reintentos con permisos aún no forma parte de esta versión.

## Pruebas automatizadas

La suite requiere una base desechable llamada `fct_test_*` que ya tenga el
volcado y las migraciones `001`–`007`. Configúrala antes de ejecutar:

```powershell
$env:FCT_DB_HOST = '127.0.0.1'
$env:FCT_DB_NAME = 'fct_test_local'
$env:FCT_TEST_DB_NAME = 'fct_test_local'
& 'C:\xampp\php\php.exe' tests\run.php
```

La suite comprueba recepción firmada, rechazo de una firma incorrecta,
deduplicación por `event_id` y almacenamiento transaccional de una notificación
saliente. No envía ninguna llamada a Internet; los registros de prueba se
eliminan o se revierten.

## Límites conocidos

- No hay pantalla administrativa para configurar destinos ni rotar secretos:
  la URL y las claves se gestionan mediante variables de entorno. Sí existen
  páginas de solo lectura **Webhooks recibidos**, **Webhooks enviados** y
  **Webhooks por empresa** para consultar eventos y respuestas.
- No hay endpoint para importar o consultar registros académicos. La recepción
  valida y archiva la notificación, pero un flujo de importación necesitaría un
  contrato de datos, mapeo de identidades, permisos y reglas de validación
  específicos.
- La cola saliente usa una URL única para esta instalación y entrega
  exactamente una vez como máximo no está garantizada; los receptores deben
  deduplicar por `event_id`.
- La entrega del outbox no se inicia sola. Debe configurarse el proceso
  periódico descrito arriba.
