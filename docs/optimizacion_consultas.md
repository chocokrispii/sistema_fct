# Paginación y medición de consultas

## Qué se optimizó

- Los listados públicos, de empresa y de revisión de ofertas usan páginas de 20 registros.
- Los listados de asignaciones (del centro y del alumno) usan páginas de 20 registros.
- El panel del alumno calcula totales y medias en SQL y solo carga ofertas recientes y los próximos eventos. El portal del alumno pagina también su lista de prácticas y mantiene el total para las estadísticas.
- Matching limita la página a 10 ofertas y muestra hasta 10 candidatos por oferta. La navegación de candidatos es independiente para cada oferta.
- Matching carga los cupos de todas las ofertas visibles con un `GROUP BY` y obtiene los candidatos de esas ofertas en una sola consulta. Para seleccionar filas por oferta se usa `ROW_NUMBER()`, disponible en MariaDB 10.4.
- Los enlaces mantienen la página actual de ofertas y las páginas de candidatos mediante parámetros de consulta; la asignación conserva la validación transaccional existente.

Una página de ofertas o asignaciones ejecuta dos `SELECT`: el total para construir la navegación y las filas de esa página. Matching ejecuta, como máximo, cuatro `SELECT`: total y ofertas, cupos agrupados y candidatos agrupados. Si ninguna oferta de la página tiene cupo, se omite la consulta de candidatos. Antes del cambio, el matching hacía un conteo por oferta y una consulta de candidatos por cada oferta con cupo.

## Índices

No se añade ningún índice nuevo sin medir primero. El esquema ya incluye, entre otros, `idx_ofertas_estado_fechas`, `idx_asignaciones_estado`, `idx_asignaciones_alumno`, `uq_asignacion_oferta_alumno`, `idx_disponibilidad_alumno_fechas` y `uq_disponibilidad_semanal_dia`. Algunos pueden servir a las consultas actuales, pero que exista un índice no demuestra que MariaDB lo elija o que reduzca el tiempo.

Tras ejecutar el benchmark con una copia representativa, revisa el `EXPLAIN` de las consultas más costosas y compara lecturas, filas examinadas y latencia antes de proponer un índice. Evita duplicar índices con el mismo prefijo útil y considera también el coste de escritura y almacenamiento.

## Benchmark reproducible

`scripts/benchmark_listados.php` solo acepta una base cuyo nombre empiece por `fct_bench_`; solo lee datos. Mide medianas y recuentos reales de sentencias `SELECT` de la sesión de PDO, e incluye listados sin paginar frente a paginados, el matching anterior frente al consolidado y las consultas agregadas del panel. En la comparación de matching la página de ofertas se fija antes de medir, para comparar ambos métodos con el mismo conjunto de ofertas. La comprobación inicial exige una muestra que permita medir más de una página de candidatos.

Para generar una muestra sintética reproducible, crea una base nueva, por ejemplo `fct_bench_distribucion_local`, e importa el volcado y las migraciones como se indica en el README. Después, en PowerShell:

   ```powershell
   $env:FCT_DB_NAME = 'fct_bench_distribucion_local'
   $env:FCT_BENCH_SEED_ALLOW = '1'
   & 'C:\xampp\php\php.exe' scripts\seed_benchmark.php
   Remove-Item Env:FCT_BENCH_SEED_ALLOW

   $env:FCT_BENCH_ALLOW = '1'
   $env:FCT_BENCH_REPETITIONS = '7'
   & 'C:\xampp\php\php.exe' scripts\benchmark_listados.php
   Remove-Item Env:FCT_BENCH_ALLOW
   Remove-Item Env:FCT_BENCH_REPETITIONS
   ```

El generador solo escribe si `FCT_BENCH_SEED_ALLOW=1` y la conexión apunta a una base `fct_bench_*`. En una base nueva añade 10 empresas completamente sintéticas, un convenio aprobado por empresa para el ciclo de la muestra, 250 ofertas con 2 plazas cada una, 500 alumnos, 500 disponibilidades, 1000 franjas y 400 asignaciones. Las asignaciones se reparten en 200 ofertas, 20 por empresa; las primeras 10 ofertas quedan libres para garantizar candidatos en la primera página de matching. Quedan 100 alumnos sin asignar y disponibles como candidatos. Los convenios y ofertas comparten el mismo ciclo y periodo. Los nombres de archivo de convenio son marcadores sintéticos; no son documentos PDF descargables. Rechaza volver a sembrar una base que ya tenga esos alumnos. No ejecutes estas pruebas sobre producción. Para usar datos reales, emplea solo una copia anonimizada.

Para comprobar visualmente la relación y la distribución desde MariaDB:

```sql
SELECT e.nombre_empresa, c.numero_convenio, c.estado,
       c.fecha_inicio AS convenio_inicio, c.fecha_fin AS convenio_fin,
       COUNT(DISTINCT p.id) AS ofertas,
       COUNT(DISTINCT af.alumno_id) AS alumnos_asignados
FROM empresas e
INNER JOIN usuarios u ON u.id = e.usuario_id
INNER JOIN convenios c ON c.empresa_id = e.id
LEFT JOIN empresa_especialidades_plazas p ON p.empresa_id = e.id
LEFT JOIN asignaciones_fct af ON af.oferta_id = p.id AND af.estado = 'Asignada'
WHERE u.email LIKE 'bench.company.%@bench.invalid'
GROUP BY e.id, c.id
ORDER BY e.nombre_empresa;
```

En la base verificada `fct_bench_distribucion_local`, cada una de las 10 empresas sintéticas tiene 25 ofertas, un convenio aprobado que cubre sus fechas y 40 asignaciones. La base también contiene los pocos registros demo del volcado: el benchmark informa 251 ofertas y 502 alumnos totales. La primera página tiene 10 ofertas con plazas disponibles y 110 candidatos compatibles en la muestra de hasta 11 por oferta.

Por defecto el benchmark exige al menos 250 ofertas, 10 convenios, 500 alumnos, 500 disponibilidades, 1000 franjas semanales, 400 asignaciones y 11 candidatos compatibles en la primera página del matching. Los mínimos se pueden ajustar con `FCT_BENCH_MIN_OFFERS`, `FCT_BENCH_MIN_AGREEMENTS`, `FCT_BENCH_MIN_STUDENTS`, `FCT_BENCH_MIN_AVAILABILITIES`, `FCT_BENCH_MIN_WEEKLY_SLOTS` y `FCT_BENCH_MIN_ASSIGNMENTS`; no conviene reducirlos para describir una carga mayor de lo que realmente se probó.

El resultado JSON informa las filas por tabla, filas que se mostrarían, mediana de `SELECT` por operación y latencias mínima, mediana y máxima. Para comparar versiones, conserva la misma copia de base de datos, hardware, versión de MariaDB y número de repeticiones. Los tiempos de una base sintética pequeña no predicen por sí solos el rendimiento en producción.

## Medición local de la muestra distribuida

Medición en `fct_bench_distribucion_local`, MariaDB 10.4.32 de XAMPP, siete repeticiones y caché local. Hay 250 ofertas sintéticas (251 en total contando una del volcado), 500 alumnos sintéticos (502 en total), 500 disponibilidades, 1000 franjas, 400 asignaciones y 10 convenios. Las 10 ofertas de la primera página tienen plazas; se encontraron 110 candidatos en la muestra de hasta 11 por oferta.

| Operación | Filas mostradas | SELECT mediana | Latencia mediana |
|---|---:|---:|---:|
| Ofertas aprobadas, sin paginar | 250 | 1 | 2.283 ms |
| Ofertas aprobadas, página 1 | 20 | 2 | 2.189 ms |
| Asignaciones, sin paginar | 400 | 1 | 4.861 ms |
| Asignaciones, página 1 | 20 | 2 | 1.749 ms |
| Matching anterior, misma página | 100 | 20 | 41.224 ms |
| Matching paginado, misma página | 100 | 2 | 18.402 ms |

En esta ejecución de matching, el recuento de `SELECT` bajó un 90% y la mediana local fue aproximadamente un 55.4% menor. Los tiempos son una observación de siete repeticiones en una máquina local, no una garantía de rendimiento en producción. La base antigua `fct_bench_local` conserva la muestra anterior y sus mediciones históricas; no se actualiza ni se sobrescribe.

El `EXPLAIN` confirmó el uso de `idx_ofertas_estado_fechas` en la lista de ofertas, `uq_asignacion_oferta_alumno` en el recuento de asignaciones y `uq_disponibilidad_semanal_dia` al comprobar franjas. En cambio, la tabla de disponibilidades se recorrió completa en la muestra de 500 filas. No se añadió un índice: ese recorrido no produjo evidencia suficiente de que un índice adicional compense su coste, y hace falta repetir la prueba con volúmenes de disponibilidad mayores y una distribución representativa antes de cambiar el esquema.
