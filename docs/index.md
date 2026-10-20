# Sistema FCT

## Portal de arquitectura y desarrollo

Sistema FCT es una aplicación web para gestionar prácticas de Formación en Centros de Trabajo. Este portal reúne una introducción operativa y la documentación técnica del recorrido de los datos en el sistema.

<div class="doc-actions">
  <a class="VPButton medium brand" href="/flujo-de-datos">Explorar la arquitectura</a>
  <a class="VPButton medium alt" href="/flujo-de-datos#catalogo-de-rutas-http">Consultar rutas y contratos</a>
</div>

## Pila tecnológica

| Capa | Tecnologías y responsabilidades |
| --- | --- |
| Presentación | PHP genera páginas y formularios HTML; islas progresivas Vue 3, TypeScript y Pinia añaden interacciones sin sustituir la navegación MVC por una SPA. |
| Aplicación | PHP MVC propio: `routes/web.php`, middleware, controladores, servicios y validación del servidor. |
| Datos y archivos | MariaDB mediante PDO y consultas preparadas; documentos fuera de `public/`, analizados por ClamAV antes de guardarse. |
| Desarrollo | Docker Compose para PHP/Apache, MariaDB, ClamAV y, opcionalmente, Vite con HMR. |

Es una arquitectura web de tres capas —presentación, aplicación y datos—: la navegación y las decisiones de autorización siguen ocurriendo en el servidor PHP.

## Requisitos

- Git.
- Docker Desktop con Docker Compose v2.
- Para frontend o documentación sin Docker: Node.js 22–24 y npm.

Docker Compose facilita el entorno local; la configuración del repositorio no está pensada como despliegue de producción.

## Incorporación y puesta en marcha

Consulta la [guía de instalación](/instalacion) para los pasos detallados, las variables locales y la política de reinicio de base de datos.

Desde PowerShell, clona el repositorio, entra en la carpeta y crea el archivo local de configuración a partir de la plantilla:

```powershell
git clone https://github.com/campusedx/Practicas_Desarrollo.git
Set-Location .\Practicas_Desarrollo
if (-not (Test-Path .env)) { Copy-Item .env.docker.example .env }
```

No compartas `.env` ni copies valores locales a un entorno compartido. Los valores de ejemplo sirven sólo para desarrollo.

Construye e inicia los servicios desde la raíz del proyecto:

```powershell
docker compose up -d --build
docker compose ps
```

ClamAV puede tardar varios minutos en descargar sus firmas iniciales. Espera a que MariaDB y ClamAV aparezcan saludables antes de probar la aplicación o subir archivos.

## Direcciones locales

- Aplicación PHP: `http://localhost:8080`
- phpMyAdmin: `http://localhost:8082`
- Vista previa de oferta (sólo desarrollo): `http://localhost:8080/dev/preview-publicar-oferta`
- Servidor Vite HMR, si lo inicias: `http://localhost:5173`
- Este portal VitePress: `http://localhost:5174` con `npm run docs:dev -- --port 5174`

Los puertos pueden cambiar en la configuración local. La URL por sí sola no concede acceso: PHP y `routes/web.php` comprueban la sesión y el rol en cada acción protegida.

Para parar el entorno sin borrar la información persistida, usa `docker compose down`. **No uses `docker compose down -v` como una operación rutinaria:** elimina todos los volúmenes del proyecto, incluida la base de datos, los archivos privados, las firmas antivirus y las dependencias Docker. Si de verdad se requiere una inicialización desde cero, guarda primero lo que necesites y sigue el procedimiento destructivo y la alternativa sólo-DB de la sección «Restablecimiento de Base de Datos por Cambios de Esquema o Migraciones» del `README.md`.

## Funciones clave de FCT

- **Disponibilidad del alumno:** elige periodo, define franjas exactas por día o activa la opción indiferente para aceptar cualquier turno. El guardado explícito muestra su estado y errores de forma reactiva; sin JavaScript permanece el formulario PHP.
- **Matching:** requiere ciclo compatible (por ejemplo, DAW con DAW o DAM con DAM), fechas que cubran toda la oferta, oferta aprobada con franjas semanales y plazas. Con franjas exactas se comprueba la cobertura día a día; el indicador indiferente omite sólo esa comprobación horaria. El servidor revalida antes de crear la asignación.
- **Calificaciones:** Administrador y Coordinador pueden registrar una nota de 0 a 10 con hasta dos decimales; el alumno consulta las suyas.
- **Roles:** las acciones y permisos se declaran en `routes/web.php`. El perfil Observador es de solo lectura, con anonimización y sin documentos privados. Consulta [Roles y permisos](/roles_y_permisos).
- **Tema:** el selector claro/oscuro respeta la preferencia del sistema hasta que se elige un tema, y guarda la elección manual en el navegador.
- **Documentos:** el escaneo ClamAV es fail-closed cuando está habilitado. Compose usa `APP_ENV=production` y escaneo habilitado por defecto. El bypass exige definir `FCT_CLAMAV_ENABLED=false` y un `APP_ENV` de `development`, `test` o `testing`; se registra en el log. Consulta [seguridad y antivirus](/seguridad_y_antivirus).

## Navegación de la documentación

Empieza por [arquitectura y ciclo de vida](/flujo-de-datos), explora el [diagrama completo](/diagrama-completo), consulta [roles y permisos](/roles_y_permisos), la [API REST tokenizada](/api-rest), la [disponibilidad](/componentes/disponibilidad), [seguridad y antivirus](/seguridad_y_antivirus), o la [guía del código para no técnicos](/guia-del-codigo-para-no-tecnicos). El `README.md` de la raíz contiene instalación, desarrollo, pruebas y solución de problemas en mayor detalle.

## Desarrollo del frontend y documentación

Instala las dependencias Node si aún no lo has hecho:

```powershell
npm ci
```

Para servir el portal en el puerto 5174 y no competir con Vite HMR:

```powershell
npm run docs:dev -- --port 5174
```

Compila el portal con `npm run docs:build`. Para desarrollar componentes Vue, ejecuta `npm run typecheck` y `npm run build`. Consulta `README.md` en la raíz para HMR, diagnóstico del frontend, pruebas y requisitos de despliegue.
