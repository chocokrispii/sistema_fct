# Guía de Integraciones y pipeline CSS

## PostCSS y estilos compartidos

El punto de entrada Vue `frontend/src/main.ts` importa la hoja activa `public/assets/css/style.css`. Vite procesa esa hoja con la configuración raíz de PostCSS: `postcss-nested` habilita reglas anidadas y Autoprefixer agrega prefijos según la compatibilidad de navegadores configurada por Browserslist.

En producción, el manifiesto de Vite permite que el layout PHP cargue el CSS compilado junto al bundle de Vue. En la imagen Docker, la etapa `frontend-build` del `Dockerfile` copia `postcss.config.js` y `public/assets/css` (además de `tsconfig.json`, `vite.config.ts` y `frontend`) antes de ejecutar `npm run build`; sin `postcss.config.js` en esa etapa, Vite omite PostCSS sin error y el CSS del bundle se generaría sin resolver el anidado ni añadir prefijos. En desarrollo, Vite sirve el mismo import con HMR. Si no hay manifiesto ni servidor de desarrollo habilitado, el layout PHP conserva un enlace directo a la hoja original para mantener el fallback. No se mantienen copias diferentes de tokens para el CSS PHP y el de las islas.

Vite minifica los assets de producción. El proyecto no usa Tailwind ni CSSnano: no se añaden esas capas porque no hay utilidades Tailwind ni una necesidad de minificación PostCSS adicional. Comprueba el pipeline con:

```powershell
npm run typecheck
npm run build
npm run docs:build
```

## Acceso a Integraciones

- Aplicación local XAMPP: `http://127.0.0.1:8002/index.php?action=integraciones`
- Aplicación Docker: `http://localhost:8080/index.php?action=integraciones`

La pestaña está reservada para el rol Administrador. La autorización se
comprueba también en el servidor.

**Importante:** `GET` consulta datos. `POST` y `PATCH` pueden guardar cambios permanentes en la base de datos compartida; comprueba el JSON y marca la confirmación antes de enviarlos. No introduzcas datos personales ni uses escrituras como si fueran pruebas reversibles.

La especificación completa de rutas, permisos y formatos está en [API REST](./api-rest.md).

## Seguridad y resolución de problemas

- No incluyas tokens o secretos en URLs, capturas, archivos versionados ni mensajes.
- Revoca los tokens de prueba cuando ya no se necesiten.
- El acceso a Integraciones está reservado a Administrador.

## API REST tokenizada

La API tokenizada se implementa de forma independiente en `public/api.php`.
En Apache se accede directamente con `api.php?path=...`; el alias `/api/v1`
solo está configurado por el lanzador local y no por el Apache Docker incluido.
Consulta [API REST](/api-rest) para permisos y contratos de respuesta.
