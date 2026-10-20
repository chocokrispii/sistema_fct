---
title: Seguridad y antivirus
---

# Seguridad y antivirus

## Carga de convenios

La carga sigue este orden: validación de extensión, MIME y tamaño; análisis mediante `FileScanner` y ClamAV TCP INSTREAM; movimiento del archivo limpio a `FCT_STORAGE_PATH`; y persistencia de metadatos. Los archivos permanecen fuera de `public/`. La descarga pasa por una ruta PHP con control de acceso.

Cuando el análisis está habilitado, ClamAV no disponible, configuración inválida, timeout, firmas con más de tres días o respuesta `FOUND` hacen que la carga se rechace. No se debe convertir un error del antivirus en una carga aceptada.

## Desarrollo local

El bypass es explícito y sólo se permite si ambas condiciones se cumplen:

```dotenv
APP_ENV=development
FCT_CLAMAV_ENABLED=false
```

En ese caso `FileScanner` registra una advertencia y omite el escaneo. No existe fallback automático ante un fallo de conexión. Para probar la integración real, usa `FCT_CLAMAV_ENABLED=true` y espera a que `docker compose ps` muestre ClamAV saludable.

Fuera de `APP_ENV=development`, el valor `false`, un valor inválido o la ausencia de un escáner funcional rechazan la carga. Mantén ClamAV habilitado en entornos compartidos y producción; no publiques el puerto TCP del scanner.

## Otras protecciones

- Los archivos se guardan en almacenamiento privado separado del document root.
- Las mutaciones HTML requieren un campo CSRF `_csrf`; las solicitudes JSON usan `X-CSRF-Token`.
- Los permisos se aplican por acción desde `routes/web.php`, antes de invocar el controlador.
- El rol Observador no puede ejecutar mutaciones de negocio ni descargar documentos privados.

Consulta [Roles y permisos](/roles_y_permisos) y el [flujo de archivos](/flujo-de-datos#recorridos-principales).
