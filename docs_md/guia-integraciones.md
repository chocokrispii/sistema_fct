# Integraciones con la API REST

Sistema FCT incluye una API REST tokenizada para que las integraciones autorizadas consulten y gestionen recursos permitidos por el rol del usuario. La capacidad está desactivada por defecto en la entrega al cliente; solo personal técnico debe activarla cuando exista un sistema consumidor.

## Crear y proteger un token

1. Inicia sesión con una cuenta autorizada y abre **Tokens API**.
2. Crea el token y guarda el valor completo de inmediato: sólo se muestra una vez.
3. Envíalo en la cabecera `Authorization: Bearer <token>`; nunca lo incluyas en la URL, capturas, código versionado o logs.
4. Revoca el token cuando deje de ser necesario.

El servidor conserva el hash del token y aplica los permisos del rol. No compartas tokens ni datos personales en pruebas.

## Usar la API

Con `FCT_INTEGRATIONS_ENABLED=true`, la consola **Integraciones** permite seleccionar el método y el recurso, introducir un ID opcional y configurar la paginación. Con el valor predeterminado `false`, el menú y las rutas quedan desactivados, incluida la API REST.

Las solicitudes `GET` consultan datos. Las solicitudes de escritura pueden modificar permanentemente la base de datos; revisa el contenido antes de enviarlas y usa datos de prueba autorizados.

La consola administrativa enlaza la [especificación OpenAPI](../public/openapi.json), compatible con Swagger UI, además de la documentación de [API REST](./api-rest.md) con endpoints, parámetros, respuestas y permisos. La consola se encuentra en la ruta `integraciones` definida por el MVC; su acceso está sujeto a la autorización del servidor. También permite suscribir endpoints HTTPS públicos a eventos salientes firmados. Consulta la sección Webhooks en la [API REST](./api-rest.md) para el payload, firma, reintentos y configuración.

## Buenas prácticas

- Usa un token separado para cada integración y limita su acceso al rol necesario.
- No registres cabeceras `Authorization` ni cuerpos con información personal.
- Revoca tokens de prueba cuando termines.
- Ante errores, consulta el sobre JSON de respuesta y los permisos asignados; no intentes eludir controles de acceso.
