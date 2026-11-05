<?php
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$previewErrors = is_array($preview) ? $preview['errores'] : [];
$summaryErrors = is_array($summary)
    ? array_values(array_filter($summary['resultados'], static fn(array $row): bool => $row['estado'] === 'Error'))
    : [];
?>
<section class="student-bulk-import" aria-labelledby="student-bulk-import-title">
    <header class="student-bulk-import__intro">
        <span class="student-registration__eyebrow">Altas · Alumnado</span>
        <h2 id="student-bulk-import-title">Importación masiva</h2>
        <p>Prepara las cuentas de varios alumnos en una hoja Excel. Revisaremos los datos antes de crear ninguna cuenta.</p>
        <a class="student-bulk-import__back" href="index.php?action=alta-alumno&tab=altas">Volver al alta individual</a>
    </header>

    <?php if ($error !== ''): ?>
        <p class="message message-error" role="alert"><?= $escape($error) ?></p>
    <?php endif; ?>

    <?php if (is_array($summary)): ?>
        <section class="student-bulk-import__result" aria-live="polite">
            <h3>Resultado del procesamiento</h3>
            <div class="student-bulk-import__counts">
                <p><strong><?= (int)$summary['total'] ?></strong><span>filas revisadas</span></p>
                <p><strong><?= (int)$summary['insertados'] ?></strong><span>cuentas creadas</span></p>
                <p><strong><?= (int)$summary['emails_enviados'] ?></strong><span>correos de activación enviados</span></p>
                <p><strong><?= (int)$summary['fallidos'] ?></strong><span>filas con error</span></p>
            </div>
            <?php if ($summaryErrors !== []): ?>
                <div class="student-bulk-import__errors">
                    <h4>Filas que requieren revisión</h4>
                    <div class="student-bulk-import__table-wrap">
                        <table>
                            <thead><tr><th>Fila</th><th>Correo</th><th>Motivo</th></tr></thead>
                            <tbody>
                                <?php foreach (array_slice($summaryErrors, 0, 100) as $row): ?>
                                    <tr>
                                        <td><?= (int)$row['linea'] ?></td>
                                        <td><?= $escape($row['email']) ?></td>
                                        <td><?= $escape($row['motivo']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (count($summaryErrors) > 100): ?>
                        <p>Se muestran los primeros 100 errores; descarga el informe para consultar todos.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ((int)$summary['emails_enviados'] > 0): ?>
                <p class="message message-success" role="status">
                    Se enviaron <?= (int)$summary['emails_enviados'] ?> correos de activación. Cada alumno puede crear su contraseña desde el enlace recibido.
                </p>
            <?php elseif ($summaryErrors === []): ?>
                <p class="message message-success" role="status">No había cuentas pendientes de activación para enviar por correo.</p>
            <?php endif; ?>
            <a class="student-bulk-import__report" href="index.php?action=informe-importacion-alumnos">Descargar informe CSV</a>
        </section>
    <?php endif; ?>

    <?php if (is_array($preview)): ?>
        <section class="student-bulk-import__preview" aria-labelledby="student-bulk-preview-title">
            <h3 id="student-bulk-preview-title">Revisión previa</h3>
            <div class="student-bulk-import__counts">
                <p><strong><?= (int)$preview['total'] ?></strong><span>filas encontradas</span></p>
                <p><strong><?= (int)$preview['validas'] ?></strong><span>listas para importar</span></p>
                <p><strong><?= (int)$preview['activaciones'] ?></strong><span>cuentas pendientes de activación</span></p>
                <p><strong><?= count($previewErrors) ?></strong><span>con errores</span></p>
            </div>

            <?php if ($preview['muestra'] !== []): ?>
                <h4>Primeras filas válidas</h4>
                <div class="student-bulk-import__table-wrap">
                    <table>
                        <thead><tr><th>Fila</th><th>Alumno</th><th>Correo</th><th>Ciclo</th><th>Curso</th></tr></thead>
                        <tbody>
                            <?php foreach ($preview['muestra'] as $row): ?>
                                <tr>
                                    <td><?= (int)$row['linea'] ?></td>
                                    <td><?= $escape($row['nombre'] . ' ' . $row['apellidos']) ?></td>
                                    <td><?= $escape($row['email']) ?></td>
                                    <td><?= $escape($row['ciclo']) ?></td>
                                    <td><?= (int)$row['curso'] ?>.º</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($preview['muestra_activaciones'] !== []): ?>
                <h4>Alumnos ya creados que pueden recibir un nuevo enlace</h4>
                <div class="student-bulk-import__table-wrap">
                    <table>
                        <thead><tr><th>Fila</th><th>Alumno</th><th>Correo</th></tr></thead>
                        <tbody>
                            <?php foreach ($preview['muestra_activaciones'] as $row): ?>
                                <tr>
                                    <td><?= (int)$row['linea'] ?></td>
                                    <td><?= $escape($row['nombre'] . ' ' . $row['apellidos']) ?></td>
                                    <td><?= $escape($row['email']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p>Al enviar un nuevo enlace, los enlaces anteriores de estas cuentas dejarán de funcionar.</p>
            <?php endif; ?>

            <?php if ($previewErrors !== []): ?>
                <div class="student-bulk-import__errors">
                    <h4>Errores por fila</h4>
                    <div class="student-bulk-import__table-wrap">
                        <table>
                            <thead><tr><th>Fila</th><th>Correo</th><th>Motivo</th></tr></thead>
                            <tbody>
                                <?php foreach (array_slice($previewErrors, 0, 100) as $row): ?>
                                    <tr>
                                        <td><?= (int)$row['linea'] ?></td>
                                        <td><?= $escape($row['email']) ?></td>
                                        <td><?= $escape($row['motivo']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (count($previewErrors) > 100): ?>
                        <p>Se muestran los primeros 100 errores; descarga el informe para consultar todos.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="student-bulk-import__preview-actions">
                <a class="student-bulk-import__report" href="index.php?action=informe-importacion-alumnos">Descargar informe de revisión CSV</a>
                <?php if ((int)$preview['validas'] > 0 || (int)$preview['activaciones'] > 0): ?>
                    <form method="POST" action="index.php?action=importar-alumnos&tab=altas" class="student-bulk-import__confirm">
                        <input type="hidden" name="_csrf" value="<?= $escape(CsrfMiddleware::token()) ?>">
                        <input type="hidden" name="paso" value="confirmar">
                        <input type="hidden" name="importacion_id" value="<?= $escape($preview['token']) ?>">
                        <p>
                            <?php if ((int)$preview['validas'] > 0 && (int)$preview['activaciones'] > 0): ?>
                                Se crearán <?= (int)$preview['validas'] ?> cuentas nuevas y se enviarán correos a <?= (int)$preview['activaciones'] ?> cuentas pendientes. Las filas con errores no se procesarán.
                            <?php elseif ((int)$preview['validas'] > 0): ?>
                                Se crearán <?= (int)$preview['validas'] ?> cuentas nuevas y se enviará a cada alumno un correo para crear su contraseña.
                            <?php else: ?>
                                Se enviará un nuevo enlace de activación a <?= (int)$preview['activaciones'] ?> cuentas ya creadas que siguen pendientes.
                            <?php endif; ?>
                        </p>
                        <button type="submit">
                            <?php if ((int)$preview['validas'] > 0): ?>
                                Importar alumnos y enviar correos
                            <?php else: ?>
                                Enviar correos de activación
                            <?php endif; ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
            <?php if ((int)$preview['validas'] === 0 && (int)$preview['activaciones'] === 0): ?>
                <p class="message message-error" role="alert">No hay filas válidas para importar. Corrige el Excel y vuelve a subirlo.</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <div class="student-bulk-import__steps">
        <section class="student-bulk-import__step" aria-labelledby="student-bulk-prepare-title">
            <div class="student-bulk-import__step-heading">
                <span class="student-bulk-import__step-number" aria-hidden="true">01</span>
                <div>
                    <h3 id="student-bulk-prepare-title">Prepara el Excel</h3>
                    <p>Empieza con la plantilla para que los datos se importen en el formato correcto.</p>
                </div>
            </div>
            <a class="student-bulk-import__template" href="index.php?action=verificacion-importacion-alumnos">Descargar plantilla <span aria-hidden="true">(.xlsx)</span></a>
            <div class="student-bulk-import__requirements">
                <strong>Columnas necesarias</strong>
                <ul>
                    <li>Email</li>
                    <li>DNI/NIE</li>
                    <li>Nombre</li>
                    <li>Apellidos</li>
                    <li>Ciclo formativo</li>
                    <li>Curso</li>
                </ul>
                <small>Ciclo activo con su nombre exacto; curso 1 o 2. Hasta 5.000 alumnos y 5 MB.</small>
            </div>
        </section>

        <section class="student-bulk-import__step student-bulk-import__step--upload" aria-labelledby="student-bulk-upload-title">
            <div class="student-bulk-import__step-heading">
                <span class="student-bulk-import__step-number" aria-hidden="true">02</span>
                <div>
                    <h3 id="student-bulk-upload-title">Sube y revisa</h3>
                    <p>Validaremos las filas antes de crear cualquier cuenta.</p>
                </div>
            </div>
            <form method="POST" action="index.php?action=importar-alumnos&tab=altas" enctype="multipart/form-data">
                <input type="hidden" name="_csrf" value="<?= $escape(CsrfMiddleware::token()) ?>">
                <input type="hidden" name="paso" value="previsualizar">
                <input type="hidden" name="MAX_FILE_SIZE" value="<?= StudentImportParser::MAX_FILE_BYTES ?>">
                <label for="student-bulk-file">Archivo Excel (.xlsx)
                    <input id="student-bulk-file" type="file" name="archivo_alumnado" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                </label>
                <button type="submit">Previsualizar importación</button>
            </form>
        </section>
    </div>
</section>
