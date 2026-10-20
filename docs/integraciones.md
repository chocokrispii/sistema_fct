# Guía de Integraciones

**Sistema FCT · Uso administrativo**

[Descargar esta guía en PDF](/guia-integraciones.pdf)

Esta guía explica cómo usar la pestaña **Integraciones** para consultar la API REST propia y revisar los webhooks recibidos y enviados, agrupados por empresa.

## Acceso

La pestaña está disponible exclusivamente para cuentas con rol **Administrador**. Inicia sesión y selecciónala en el menú principal, o abre directamente:

- Aplicación local XAMPP: `http://127.0.0.1:8002/index.php?action=integraciones`
- Aplicación Docker: `http://localhost:8080/index.php?action=integraciones`

La autorización se comprueba también en el servidor: conocer o copiar la dirección no permite el acceso a otros roles.

## Secciones de la pestaña

La pantalla **Integraciones** tiene tres secciones independientes:

| Sección | Contenido |
| --- | --- |
| **API propia** | Consola para enviar solicitudes autenticadas a los recursos REST. |
| **Webhooks recibidos** | Historial de eventos entrantes, agrupado por empresa. |
| **Webhooks enviados** | Historial de eventos salientes, agrupado por empresa. |

### API propia

La consola permite seleccionar el método, el recurso, un ID opcional y la paginación de las consultas. Los recursos disponibles son `alumnos`, `empresas`, `ofertas`, `convenios` y `asignaciones`.

1. Crea un token desde el enlace **Tokens API** de la consola o desde la opción correspondiente de administración.
2. Elige la cuenta y asígnale un nombre que permita reconocer el uso del token.
3. Copia el token cuando se muestre y guárdalo de forma segura. El valor completo se muestra una sola vez.
4. Introduce el token en la consola, selecciona método y recurso y envía la petición.
5. Revoca el token desde **Tokens API** cuando terminen las pruebas.

La consola borra el token del campo después de cada solicitud. La API aplica los permisos del rol asociado al token y restringe los datos al usuario o empresa cuando corresponda. El servidor conserva el hash del token, no el valor original.

**Importante:** `GET` consulta datos. `POST` y `PATCH` pueden guardar cambios permanentes en la base de datos compartida; comprueba el JSON y marca la confirmación antes de enviarlos. No introduzcas datos personales ni uses escrituras como si fueran pruebas reversibles.

La especificación completa de rutas, permisos y formatos está en [API REST](./api-rest.md).

### Webhooks recibidos

Muestra los eventos que otros sistemas enviaron al endpoint de entrada. Cada evento permite consultar el tipo, la fecha, el identificador, el JSON recibido y, cuando se registró, el estado y cuerpo de la respuesta HTTP.

Los eventos se agrupan por el nombre de la empresa. Para asociar un evento entrante, el JSON debe incluir un `data.empresa_id` válido, correspondiente a una empresa existente en esta base de datos. Si falta el identificador, no es válido o no corresponde a una empresa existente, el evento queda en **Sin empresa identificada**. No se intenta deducir la empresa usando nombres, correos u otros datos.

Recibir y archivar un webhook **no crea ni actualiza** alumnos, ofertas, convenios, prácticas o calificaciones. El historial es de consulta.

### Webhooks enviados

Muestra los eventos que Sistema FCT encoló para enviar a otros sistemas, agrupados por empresa. Dentro de cada grupo, los eventos aparecen del más reciente al más antiguo. Se pueden revisar el tipo, la fecha, los intentos, el estado de entrega, el código HTTP, el JSON enviado, la respuesta recibida y el último error disponible.

La empresa se determina con la información relacionada del evento:

- Si el evento contiene un `data.empresa_id` válido, se usa esa empresa.
- En eventos de convenios u ofertas, se puede obtener la empresa desde el convenio u oferta relacionado.
- En eventos de prácticas, se sigue la asignación hasta la oferta correspondiente.
- Si no se consigue identificar una empresa existente, el evento se muestra bajo **Sin empresa identificada**.

La pestaña muestra como máximo los **500 eventos más recientes de cada historial**. Es una pantalla de seguimiento y consulta; no reintenta ni vuelve a enviar eventos.

## Pruebas locales de webhook

Las pruebas manuales de recepción y envío están en **Pruebas API y webhooks**, una página administrativa aparte. En el entorno XAMPP, se abre desde:

`http://127.0.0.1:8002/index.php?action=pruebas-integracion`

Las pruebas utilizan eventos ficticios y el receptor local. No configuran un servicio externo. Los eventos pueden quedar archivados en las bandejas de entrada y salida; evita introducir información personal. Para detalles de firma, configuración y ejecución del worker, consulta [Webhooks](./webhooks.md).

## Seguridad y resolución de problemas

- No incluyas tokens o secretos en URLs, capturas, archivos versionados ni mensajes.
- Revoca los tokens de prueba cuando ya no se necesiten.
- El acceso de Integraciones y sus listados es sólo para Administrador.
- Los historiales reflejan el `empresa_id` y las relaciones existentes en la base compartida. Un evento antiguo sin identificador de empresa no se asociará automáticamente.
- Si los listados no cargan, comprueba que MariaDB esté activa y que la base tenga las migraciones de webhook aplicadas.
- Una llamada `GET` al endpoint receptor puede responder `405 Método no permitido`: el endpoint espera una solicitud `POST`.

Para configurar la base compartida, los secretos y el worker local, sigue las instrucciones de [Integración de API y webhooks](./webhooks.md).
