# Integración de API y webhooks

## Alcance de esta primera versión

Esta integración proporciona dos sentidos de comunicación entre Sistema FCT y
un sistema externo:

- **Recepción:** `POST /webhook.php` recibe un sobre JSON firmado, lo valida y
  guarda en `webhook_inbox`. Es un buzón de entrada; no crea ni modifica
  alumnos, empresas, cursos, ofertas, convenios, prácticas o calificaciones.
- **Envío:** cuando se configura `FCT_WEBHOOK_OUTBOUND_URL`, los cambios de
  ofertas, convenios y prácticas añaden un evento JSON a `webhook_outbox` en
  la misma transacción que el cambio. Un proceso CLI separado entrega la cola
  y programa reintentos si el receptor no está disponible.

El proyecto no tiene un módulo independiente de cursos ni un endpoint REST
para consultar expedientes. Por eso los eventos incluidos notifican los
cambios del flujo FCT existente. Los sobres salientes solo incluyen
identificadores internos y los datos de proceso necesarios; no incluyen
nombre, correo, DNI, número de Seguridad Social, dirección, tutor ni el PDF de
un convenio. No uses este buzón para enviar información personal o documentos.

## Migración

En las bases existentes selecciona la base correcta y ejecuta una vez:

```powershell
& 'C:\xampp\mysql\bin\mysql.exe' -h 127.0.0.1 -uroot --database=sistema_fct < database\migrations\007_webhooks.sql
& 'C:\xampp\mysql\bin\mysql.exe' -h 127.0.0.1 -uroot --database=fct_test_local < database\migrations\007_webhooks.sql
```

Sustituye los nombres por las bases de desarrollo y pruebas que uses. La
migración crea `webhook_inbox` y `webhook_outbox` mediante `IF NOT EXISTS`; no
borra datos existentes. Para nuevas instalaciones Docker Compose, la migración
está enlazada en el orden de arranque inicial de MariaDB. Los volúmenes ya
inicializados no vuelven a ejecutar scripts de inicialización automáticamente.

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
entrada y salida porque el worker local entregará al propio endpoint:

```powershell
$secret = 'PEGA_AQUI_EL_SECRETO_GENERADO'
@"
FCT_DB_HOST=127.0.0.1
FCT_DB_NAME=sistema_fct
FCT_WEBHOOK_INBOUND_SECRET=$secret
FCT_WEBHOOK_OUTBOUND_SECRET=$secret
FCT_WEBHOOK_OUTBOUND_URL=http://127.0.0.1:8001/webhook.php
"@ | Set-Content -LiteralPath .env.local -Encoding ascii
```

El lanzador local lee el archivo sin imprimir el secreto. Inicia un servidor
adicional en el puerto `8001`; el servidor XAMPP actual y sus funciones quedan
intactos:

```powershell
Set-Location C:\xampp\htdocs\Practicas_Desarrollo
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts\local_webhooks.ps1 -Mode Server
```

Usa la aplicación en `http://127.0.0.1:8001` para crear o revisar ofertas,
convenios o prácticas, que encolan los eventos. En otra ventana procesa la cola:

```powershell
Set-Location C:\xampp\htdocs\Practicas_Desarrollo
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts\local_webhooks.ps1 -Mode Dispatch
```

El worker debe indicar `Entregado` y el endpoint registrará el evento en
`webhook_inbox`. Repite `Dispatch` para procesar eventos posteriores. El servidor
solo escucha en `127.0.0.1`, por lo que no es accesible desde otros equipos.
No compartas `.env.local` ni incluyas el secreto en capturas o logs. Esta
configuración no altera Apache ni variables globales de Windows.

Para usar la interfaz visual y probar también el envío saliente, deja el
receptor en `8001` y abre otra ventana de PowerShell para iniciar la interfaz
aislada en `8003`:

```powershell
Set-Location C:\xampp\htdocs\Practicas_Desarrollo
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts\local_webhooks.ps1 -Mode Interface
```

Abre `http://127.0.0.1:8003/webhook_test.php`. Los dos servidores son
necesarios: la interfaz en `8003` despacha el evento y el receptor de `8001`
lo recibe sin bloquear la petición. La sección saliente solo acepta el destino
local exacto `http://127.0.0.1:8001/webhook.php` y despacha únicamente el
identificador sintético que acaba de crear.

Para Docker, añade las variables requeridas a un `.env` local ignorado por Git
y reinicia el servicio de aplicación. No copies secretos en
`.env.docker.example`.

## Endpoint entrante

URL local con el servidor de desarrollo PHP:

```text
http://127.0.0.1:8000/webhook.php
```

URL del entorno público (solo después de configurar TLS y el servidor):

```text
https://tu-dominio.example/webhook.php
```

Solo acepta `POST`, `Content-Type: application/json` y un cuerpo de hasta
64 KiB. El sobre requerido es:

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
aplicación y arranca el servidor. En otra ventana define la misma clave como
`$secret`, prepara un evento sintético y firma exactamente el cuerpo enviado:

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
    -Uri 'http://127.0.0.1:8000/webhook.php' `
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

Con el servidor local de webhooks escuchando en `127.0.0.1:8001`, abre estas
direcciones en dos pestañas:

- Aplicación FCT: `http://127.0.0.1:8001/`
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

El worker requiere CLI, una confirmación `FCT_WEBHOOK_DISPATCH_ALLOW=1`, URL
válida y un secreto con al menos 32 caracteres. Solo permite HTTPS, salvo
HTTP contra localhost para desarrollo. No sigue redirecciones, valida TLS y
limita conexión/solicitud a tres/diez segundos. Procesa hasta 25 eventos por
ejecución; se puede ajustar con `FCT_WEBHOOK_DISPATCH_LIMIT` entre 1 y 100.
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

- No hay pantalla administrativa para configurar destinos, rotar secretos o
  consultar el buzón.
- No hay endpoint para importar o consultar registros académicos. La recepción
  valida y archiva la notificación, pero un flujo de importación necesitaría un
  contrato de datos, mapeo de identidades, permisos y reglas de validación
  específicos.
- La cola saliente usa una URL única para esta instalación y entrega
  exactamente una vez como máximo no está garantizada; los receptores deben
  deduplicar por `event_id`.
- La entrega del outbox no se inicia sola. Debe configurarse el proceso
  periódico descrito arriba.
