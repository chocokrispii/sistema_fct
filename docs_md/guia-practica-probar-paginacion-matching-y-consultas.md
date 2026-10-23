Sistema FCT · Guía de pruebas

# Cómo comprobar la paginación, el matching y las consultas

Instrucciones paso a paso para probar la aplicación en el navegador, repetir el benchmark local y ejecutar las pruebas automatizadas sin mezclar sus bases de datos.

**Entorno documentado:** Windows, XAMPP, PHP CLI y MariaDB local.

**Duración aproximada:** 10–15 minutos para la prueba visual; 2–5 minutos para repetir la medición.

**Base actual de sintéticos distribuidos:** `fct_bench_distribucion_local`.

**Base para pruebas automatizadas:** `fct_test_local`.

**Regla importante:** no pulses botones de asignar, aprobar, cerrar o guardar durante la prueba visual. Esas acciones sí modifican datos. Esta guía usa enlaces de navegación y una base local de desarrollo, nunca producción.

## 1. Qué vas a verificar

La mejora limita la cantidad de filas que se cargan en los listados y reduce consultas repetidas en la pantalla de matching. La comprobación tiene tres partes:

1.  **Navegación web:** comprobar que se muestran páginas de tamaño limitado y que «Siguiente» cambia los registros.
2.  **Benchmark:** medir recuentos de consultas y latencias en una base sintética preparada para este fin.
3.  **Pruebas automatizadas:** ejecutar la suite sobre su propia base aislada, sin usar la base de benchmark ni la de desarrollo normal.

| Base | Uso | Qué evitar |
|----|----|----|
| `sistema_fct` | Desarrollo habitual y datos demo del volcado. | No usarla para generar la muestra ni para fixtures. |
| `fct_bench_distribucion_local` | Prueba visual y benchmark con convenios y datos sintéticos distribuidos. | No enviar formularios que asignen o revisen datos. |
| `fct_test_local` | Suite automatizada; inserta y limpia fixtures de prueba. | No introducir datos personales o reales. |

La muestra actual crea 10 empresas sintéticas, cada una con un convenio aprobado y 25 ofertas de 2 plazas. De los 500 alumnos, 400 están repartidos en 200 ofertas (20 ofertas por empresa) y 100 quedan disponibles para matching. Las primeras 10 ofertas están libres para comprobar los candidatos. También se crean 500 disponibilidades y 1.000 franjas semanales. Los correos sintéticos usan el dominio reservado `bench.invalid`; los nombres de archivo de los convenios son marcadores, no PDFs descargables.

Para ver a qué convenio está vinculada cada empresa y cuántas ofertas/asignaciones tiene, ejecuta en la base `fct_bench_distribucion_local`:

    & 'C:\xampp\mysql\bin\mysql.exe' -h 127.0.0.1 -uroot --database=fct_bench_distribucion_local `
      -e "SELECT e.nombre_empresa,c.numero_convenio,c.estado,c.fecha_inicio,c.fecha_fin,
                 COUNT(DISTINCT p.id) AS ofertas,COUNT(DISTINCT af.alumno_id) AS alumnos_asignados
          FROM empresas e
          JOIN usuarios u ON u.id=e.usuario_id
          JOIN convenios c ON c.empresa_id=e.id
          LEFT JOIN empresa_especialidades_plazas p ON p.empresa_id=e.id
          LEFT JOIN asignaciones_fct af ON af.oferta_id=p.id AND af.estado='Asignada'
          WHERE u.email LIKE 'bench.company.%@bench.invalid'
          GROUP BY e.id,c.id ORDER BY e.nombre_empresa;"

## 2. Preparar el servidor local

1.  Comprueba que MariaDB de XAMPP está en ejecución. Si utilizas el servidor PHP integrado, no necesitas iniciar Apache para esta guía.
2.  Abre una ventana nueva de PowerShell. Ejecuta cada línea:

<!-- -->

    Set-Location 'C:\ruta\al\repositorio'
    $env:FCT_DB_HOST = '127.0.0.1'
    $env:FCT_DB_NAME = 'fct_bench_distribucion_local'
    & 'C:\xampp\php\php.exe' -S 127.0.0.1:8002 -t public

3.  Deja esta ventana abierta. Es el servidor; si la cierras o pulsas Ctrl+C, la web deja de estar disponible.
4. El mensaje de PHP indicará la dirección local en la que escucha. Usa un puerto libre para no interferir con otros servicios de desarrollo.

El proceso de PHP hereda las variables de entorno de esta ventana. Por eso se configuran *antes* de iniciar el servidor. No cambies el nombre de la base a `sistema_fct` para estas pruebas.

### Confirmación opcional de la base

En otra ventana de PowerShell, verifica que existe y tiene la muestra:

            & 'C:\xampp\mysql\bin\mysql.exe' -h 127.0.0.1 -uroot -N -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='fct_bench_distribucion_local';"

Si tu MariaDB tiene contraseña para root, agrega `-p` y escribe la contraseña cuando la solicite; no la pongas directamente en el comando.

## 3. Iniciar sesión en la aplicación

1.  Abre esta dirección: <http://127.0.0.1:8002/index.php?action=login>.
2.  Si la base se cargó con `database/seeds/001_sample_data.sql`, introduce la cuenta de Coordinador de prueba. El login usa NIF, no correo:

|            |                          |
|------------|--------------------------|
| NIF        | `87654321X`               |
| Contraseña | `password123`            |

3.  Tras iniciar sesión, confirma que aparece el menú de coordinación. Estas credenciales son únicamente para desarrollo local y solo existen si cargaste esos datos de ejemplo.

Si no puedes acceder, revisa primero la sección «Problemas frecuentes». La prueba automatizada confirma que el hash de esta cuenta demo corresponde a esa contraseña en la base aislada de pruebas.

### Consultar los convenios sintéticos

Para ver que las empresas de la muestra tienen convenios asociados, cierra la sesión de coordinación e inicia sesión con la cuenta de empresa:

|                 |                                  |
|-----------------|----------------------------------|
| NIF (Persona Jurídica) | `B90000001`                |
| Contraseña      | `Benchmark data only`            |

El script de benchmark asigna a la primera empresa el correo `bench.company.01@bench.invalid`, pero para iniciar sesión usa el NIF (Persona Jurídica) `B90000001`, no el correo.

1.  Abre [la página de convenios](http://127.0.0.1:8002/index.php?action=convenios).
2.  En «Historial de convenios», comprueba el convenio `BENCH-CNV-01` y su estado aprobado.
3.  No pulses «Descargar PDF»: el nombre de archivo sintético solo permite satisfacer el esquema y no corresponde a un documento real.

## 4. Probar ofertas y asignaciones

### Listado público de ofertas

1.  Abre [la página de ofertas](http://127.0.0.1:8002/index.php?action=ofertas).
2.  Cuenta las filas de ofertas: la primera página debe mostrar como máximo 20.
3.  Pulsa **Siguiente**. Debe cambiar la página y la dirección debe contener `pagina=2`.
4.  Pulsa **Anterior** para volver a la primera página.

### Listado de asignaciones

1.  Abre [la página de asignaciones](http://127.0.0.1:8002/index.php?action=asignaciones).
2.  Verifica que se muestran como máximo 20 filas por página.
3.  Usa Siguiente y Anterior. Debe conservarse la ruta de asignaciones y cambiar la página.

La muestra sintética tiene 400 asignaciones. Por eso se puede comprobar que no se presentan todas en una sola página. Un cambio de página es correcto aunque algunos registros del volcado aparezcan junto a los sintéticos.

## 5. Probar matching y páginas de candidatos

1.  Abre [Emparejamiento y asignación](http://127.0.0.1:8002/index.php?action=emparejar). Debes iniciar sesión como Coordinador o Administrador.
2.  Cuenta las tarjetas de oferta: se muestran hasta 10 por página.
3.  En una oferta con plazas, comprueba que la lista contiene hasta 10 candidatos.
4.  Usa la paginación de candidatos dentro de esa tarjeta. Cambia la página de candidatos y comprueba que la página principal de ofertas se mantiene.
5.  Usa la paginación general de ofertas al final. Debe cambiar la página principal; las páginas de candidatos son independientes para cada oferta.

Las primeras 10 ofertas sintéticas están libres y hay 100 alumnos sin asignar, compatibles por ciclo, fechas, turno y disponibilidad semanal. Debe aparecer más de una página de candidatos. **No pulses «Asignar a esta oferta».** Aunque se trate de datos sintéticos, ese botón guarda una asignación adicional. Para esta prueba basta con navegar y observar.

## 6. Repetir el benchmark

El benchmark mide en la conexión PDO el número de sentencias `SELECT` y el tiempo de cada operación. El script rechaza ejecutarse si no se activa expresamente o si la base no comienza por `fct_bench_`.

1.  Deja abierta la ventana del servidor si quieres conservar también la prueba web.
2.  Abre una segunda ventana PowerShell y ejecuta:

<!-- -->

    Set-Location 'C:\ruta\al\repositorio'
    $env:FCT_DB_HOST = '127.0.0.1'
    $env:FCT_DB_NAME = 'fct_bench_distribucion_local'
    $env:FCT_BENCH_ALLOW = '1'
    $env:FCT_BENCH_REPETITIONS = '7'
    & 'C:\xampp\php\php.exe' scripts\benchmark_listados.php
    Remove-Item Env:FCT_BENCH_ALLOW
    Remove-Item Env:FCT_BENCH_REPETITIONS

3.  Espera la salida JSON. Comprueba que menciona `fct_bench_distribucion_local`, MariaDB 10.4.32 y siete repeticiones.
4.  Busca los resultados `matching.pagina_1.implementacion_anterior` y `matching.pagina_1.implementacion_paginada`.

Los otros dos nombres útiles son `ofertas.aprobadas.pagina_1` y `asignaciones.pagina_1`. El campo `filas_visibles` indica las filas que devuelve cada método; `consultas_select_mediana` es la mediana del número de consultas y `tiempo_mediana_ms` la mediana de latencia en milisegundos.

El benchmark es de solo lectura. No vuelvas a ejecutar `seed_benchmark.php` en esta base: la muestra ya está creada y el generador protege contra duplicarla. Para sembrar desde cero necesitarías otra base de benchmark nueva.

## 7. Resultados de referencia

Medición actual en `fct_bench_distribucion_local`, MariaDB 10.4.32, siete repeticiones. El total incluye los pocos registros del volcado además de los datos sintéticos. Los resultados son locales y no garantizan esos tiempos en producción:

| Operación                | Filas | SELECT mediana | Tiempo mediano |
|--------------------------|-------|----------------|----------------|
| Ofertas sin paginar      | 250   | 1              | 2,283 ms       |
| Ofertas, página 1        | 20    | 2              | 2,189 ms       |
| Asignaciones sin paginar | 400   | 1              | 4,861 ms       |
| Asignaciones, página 1   | 20    | 2              | 1,749 ms       |
| Matching anterior        | 100   | 20             | 41,224 ms      |
| Matching paginado        | 100   | 2              | 18,402 ms      |

En esa ejecución el matching redujo las consultas medidas de 20 a 2 (90 % menos) y la mediana local de unos 72 ms a 61 ms (aproximadamente 15 % menos). Las latencias dependen del equipo, la caché y los datos; no son una garantía para producción.

Las páginas de ofertas y asignaciones cargan menos filas que el método sin paginar. Eso reduce los resultados transferidos y dibujados, aunque en esta muestra pequeña no necesariamente reduce el tiempo de ida y vuelta a la base.

No se añadieron índices nuevos. El análisis de planes mostró índices existentes elegidos para ofertas, asignaciones y franjas semanales. En 500 disponibilidades MariaDB recorrió la tabla, pero esa muestra no bastó para justificar otro índice.

## 8. Ejecutar la suite automatizada (opcional)

Las pruebas de integración insertan datos temporales y los limpian. Ejecuta la suite únicamente contra la base dedicada `fct_test_local`, nunca contra `sistema_fct` o `fct_bench_distribucion_local`.

1.  Abre una tercera ventana de PowerShell (o termina el servidor local con Ctrl+C).
2.  Configura ambas variables para apuntar al mismo nombre de prueba y ejecuta:

<!-- -->

    Set-Location 'C:\ruta\al\repositorio'
    $env:FCT_DB_HOST = '127.0.0.1'
    $env:FCT_DB_NAME = 'fct_test_local'
    $env:FCT_TEST_DB_NAME = 'fct_test_local'
    & 'C:\xampp\php\php.exe' tests\run.php

La prueba correcta termina con mensajes `OK` sobre validadores, rutas, almacenamiento, migraciones, perfiles, ofertas, matching, disponibilidad, calificaciones, usuarios y CSRF. Si la suite indica que el nombre de base debe empezar por `fct_test_`, confirma que las dos variables se escribieron correctamente.

## 9. Problemas frecuentes

| Qué ocurre | Qué comprobar |
|----|----|
| El navegador dice que no puede conectarse. | Confirma que la ventana de PHP sigue abierta y muestra el puerto 8002. Arranca de nuevo el comando del paso 2 si la cerraste. |
| Error de conexión a MariaDB. | Comprueba MySQL/MariaDB en XAMPP y que `FCT_DB_HOST` y `FCT_DB_NAME` se definieron antes de arrancar el servidor. |
| No aparece Siguiente. | Confirma que estás en `fct_bench_distribucion_local` y en la ruta correcta. La página no necesita un enlace si no hay más registros. |
| El matching no muestra candidatos. | Revisa que estás en las ofertas sintéticas aprobadas y vigentes y que la fecha del equipo sea correcta. |
| El login rechaza las credenciales. | Revisa el NIF / NIE / DNI del personal o alumnado, o el NIF (Persona Jurídica) de la empresa, junto con la contraseña. El correo no es identificador de acceso. Comprueba también que el servidor PHP apunta a `fct_bench_distribucion_local`. No alteres el volcado para resolver un problema de sesión. |
| El benchmark exige una variable de permiso. | En esa misma ventana define `$env:FCT_BENCH_ALLOW = '1'` antes de ejecutar el script. |
| El benchmark dice que faltan filas. | Confirma el nombre de la base y que ya se ejecutó una vez `seed_benchmark.php` en ella. No repitas el seed si ya hay 500 alumnos `bench.student.*@bench.invalid`. |
| La suite automatizada rechaza la base. | Define `FCT_DB_NAME` y `FCT_TEST_DB_NAME` ambos como `fct_test_local`. |

## 10. Terminar la prueba y conservar los resultados

1.  Si el servidor PHP sigue activo, vuelve a su ventana y pulsa **Ctrl+C**.
2.  Cierra las ventanas PowerShell de prueba. Las variables de entorno usadas ahí no afectan a otros procesos de Windows.
3.  Guarda la salida JSON del benchmark si necesitas comparar otra versión; anota también la versión de MariaDB, el número de repeticiones y el tamaño de la muestra.
4.  Consulta `./optimizacion_consultas.md` para el análisis de consultas e índices y el README del proyecto para instalación y configuración general.

- MariaDB local estaba activa.
- El servidor web se conectó a `fct_bench_distribucion_local`.
- Ofertas y asignaciones navegaron entre páginas.
- Matching permitió paginar las ofertas y candidatos de forma independiente.
- El benchmark informó consultas y latencias.
- La suite automatizada, si se ejecutó, usó `fct_test_local`.
- No se pulsaron acciones que cambian ofertas o asignaciones.

Sistema FCT · Pruebas locales de paginación, matching y consultas · Guía para desarrollo, no para producción.
