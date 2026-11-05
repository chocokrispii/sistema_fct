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

La función está desactivada por defecto para la entrega al cliente. Con
`FCT_INTEGRATIONS_ENABLED=false` no aparece en los menús, sus rutas de
administración no están disponibles y la API REST tokenizada responde 404.

- Aplicación local XAMPP: `http://127.0.0.1:8002/index.php?action=integraciones`
- Aplicación Docker: `http://localhost:8080/index.php?action=integraciones`

Una persona técnica puede activarla en `.env` con
`FCT_INTEGRATIONS_ENABLED=true` y recrear el servicio `app`. Cuando está
habilitada, la pestaña queda reservada al rol Administrador y el servidor
vuelve a comprobar la autorización.

**Importante:** `GET` consulta datos. `POST` y `PATCH` pueden guardar cambios permanentes en la base de datos compartida; comprueba el JSON y marca la confirmación antes de enviarlos. No introduzcas datos personales ni uses escrituras como si fueran pruebas reversibles.

La especificación completa de rutas, permisos y formatos está en [API REST](./api-rest.md).

## Firma de convenios con AutoFirma

Administradores y Coordinadores pueden firmar los convenios pendientes con el
cliente de escritorio AutoFirma. El navegador envía el PDF original a AutoFirma
para una firma PAdES; el backend comprueba que el PDF corresponde al original y
valida la integridad de la firma, la cadena de confianza y la revocación OCSP
antes de aprobarlo. Una cancelación, un certificado no confiable o un fallo de
validación deja el convenio pendiente.

El modo se configura con `FCT_CONVENIO_SIGNATURE_MODE`:

- `test` es el valor predeterminado en desarrollo. La ruta
  `index.php?action=probar-autofirma` firma un PDF ficticio y valida el PAdES
  contra el almacén de confianza local configurado, sin consulta OCSP. No guarda
  el resultado ni cambia convenios. Para pruebas reproducibles puede instalarse
  un certificado y una CA autofirmados exclusivamente de prueba; no son
  certificados cualificados ni deben usarse para documentos reales.
- `autofirma` habilita las aprobaciones PAdES. El cliente AutoFirma debe estar
  instalado en el equipo del firmante.
- `disabled` deshabilita la firma.
- `simple` conserva el sello visual heredado únicamente para desarrollo y
  pruebas; está rechazado en producción.

Para habilitar `autofirma`, el servidor Docker necesita `pdfsig`, un almacén NSS
con las CA que deban considerarse confiables y acceso a los servicios OCSP/AIA
necesarios. Por defecto, la carpeta del host
`storage/autofirma-nssdb` se monta como solo lectura en
`/var/lib/fct/autofirma-nssdb`; se puede cambiar el origen con
`FCT_AUTOFIRMA_NSS_PATH`. Prepara allí la base NSS y sus CA raíz/intermedias
con el procedimiento de confianza de la organización antes de habilitar firmas.
Si el almacén falta o no confía en el certificado, el backend no aprueba el
convenio.

En un convenio pendiente, el firmante puede usar el flujo automático del
navegador o descargar el original, firmarlo desde AutoFirma y subir el PDF
resultante. Ambos flujos pasan por la misma validación del servidor. La
notificación a la empresa contiene un enlace de descarga privado.

El cliente `AutoScript` se distribuye desde el repositorio oficial
[ctt-gob-es/clienteafirma](https://github.com/ctt-gob-es/clienteafirma), fijado
al commit `bd9254be70004be095b1251b5a4af15b9d392dd9`, bajo sus licencias
incluidas junto al asset. Una firma PAdES verificada no implica por sí sola que
sea una firma cualificada eIDAS; esa exigencia requiere una política y un
prestador cualificado definidos por la organización.

## Seguridad y resolución de problemas

- No incluyas tokens o secretos en URLs, capturas, archivos versionados ni mensajes.
- Revoca los tokens de prueba cuando ya no se necesiten.
- El acceso a Integraciones está reservado a Administrador.

## API REST tokenizada

La API tokenizada se implementa de forma independiente en `public/api.php`.
En Apache se accede directamente con `api.php?path=...`; el alias `/api/v1`
solo está configurado por el lanzador local y no por el Apache Docker incluido.
Consulta [API REST](/api-rest) para permisos y contratos de respuesta.
