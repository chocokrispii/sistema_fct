---
title: Instalación local
---

# Instalación local

## Requisitos

- Git.
- Docker Desktop con Docker Compose v2.
- Node.js 22–24 y npm para compilar el frontend y VitePress.

El contenedor de aplicación incluye PHP 8.2, Apache, PDO MySQL y mbstring. Docker Compose es el entorno recomendado para desarrollo; los valores de ejemplo no son credenciales de producción.

## Inicio rápido

Desde PowerShell:

```powershell
git clone https://github.com/campusedx/Practicas_Desarrollo.git
Set-Location .\Practicas_Desarrollo
Copy-Item .env.example .env
```

La plantilla define `PMA_PORT=8082`, `APP_ENV=development` y `FCT_CLAMAV_ENABLED=false`. El último valor omite explícitamente el escaneo para facilitar pruebas locales y no es válido fuera de desarrollo. Para probar el antivirus, cámbialo a `true`.

Inicia los servicios y genera cuentas demo:

```powershell
docker compose up -d --build
docker compose ps
docker compose exec -e FCT_ENABLE_DEMO_SEED=1 -e FCT_DEMO_SEED_ALLOW_DOCKER=1 app php scripts/crear_cuentas_demo.php
```

El generador imprime contraseñas aleatorias una sola vez y renueva las anteriores en cada ejecución. Guárdalas de forma segura. No ejecutes la semilla en producción.

## Direcciones locales

| Servicio | URL |
|---|---|
| Aplicación PHP | `http://localhost:8080` |
| phpMyAdmin | `http://localhost:8082` |
| Vista previa de disponibilidad | `http://localhost:8080/index.php?action=preview-disponibilidad` |
| Vista previa de oferta | `http://localhost:8080/dev/preview-publicar-oferta` |
| Vite HMR (opcional) | `http://localhost:5173` |

La vista previa sólo se registra en `APP_ENV=development`. Consulta [Disponibilidad](/componentes/disponibilidad) para el flujo de guardado.

## Cambios de esquema y migraciones

En un volumen nuevo, MariaDB aplica automáticamente el esquema y las migraciones numeradas desde `database/migrations/`. En una base existente no repitas las migraciones antiguas indiscriminadamente. Para añadir DAM, ejecuta `009_desarrollo_aplicaciones_multiplataforma.sql` una vez; para añadir los nuevos campos persistidos de oferta (título, descripción, turno, modalidad, ubicación, requisitos y tecnologías), aplica `010_detalles_ofertas.sql`. Ambas migraciones son idempotentes.

Si se necesita reinicializar completamente el entorno local, primero guarda cualquier dato que quieras conservar. `docker compose down -v` elimina la base de datos, los documentos privados, las firmas ClamAV y otros volúmenes del proyecto. Después:

```powershell
docker compose down -v
docker compose up -d --build
docker compose exec -e FCT_ENABLE_DEMO_SEED=1 -e FCT_DEMO_SEED_ALLOW_DOCKER=1 app php scripts/crear_cuentas_demo.php
```

Para el detalle de las migraciones y la alternativa que conserva otros volúmenes, consulta la sección de restablecimiento de base de datos en `README.md`.
