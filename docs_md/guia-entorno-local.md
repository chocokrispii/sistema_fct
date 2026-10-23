# Usar el sistema en local

## Iniciar el entorno Docker

Desde la raíz del repositorio, crea la configuración local y arranca los servicios:

```powershell
Copy-Item .env.example .env
docker compose up -d --build
docker compose ps
```

Abre la aplicación en el puerto indicado por `APP_PORT` en `.env` (por defecto, `http://localhost:8080`). La base MariaDB, el almacenamiento privado y los servicios auxiliares se administran mediante Docker Compose.

Para iniciar la documentación VitePress en el puerto 5173:

```powershell
npm run docs:dev -- --host 0.0.0.0 --port 5173
```

Detén los servicios con `docker compose down`. No añadas `-v` salvo que realmente quieras borrar los volúmenes locales y sus datos.

## Usar la API REST

Las rutas de la API requieren un token Bearer; una respuesta `401` sin token es parte de la protección esperada. Inicia sesión con una cuenta autorizada, crea un token desde **Tokens API** y guarda el valor completo de forma segura, ya que sólo se muestra una vez.

Envía el token en la cabecera `Authorization`. No lo incluyas en la URL, capturas, código versionado o logs. La API aplica los permisos del rol de la cuenta; consulta [API REST](./api-rest.md) para endpoints, parámetros y ejemplos.

Las solicitudes `GET` consultan datos. Las operaciones de escritura pueden persistir cambios en la base local; usa datos de prueba y confirma sólo acciones que quieras conservar.

## Datos y servicios locales

La base de datos local es independiente de cualquier servidor remoto: cuentas y registros no se sincronizan automáticamente. Los cambios locales tampoco se publican por sí solos; el despliegue y las migraciones de producción deben hacerse mediante un procedimiento controlado.

No copies datos personales o credenciales reales a un entorno de desarrollo sin autorización y medidas adecuadas. No compartas el archivo `.env`.

## Más información

- El `README.md` de la raíz: requisitos, instalación y solución de problemas.
- [Guía de integraciones](./guia-integraciones.md): uso seguro de la API REST.
- [API REST](./api-rest.md): recursos, permisos y formato de respuestas.
