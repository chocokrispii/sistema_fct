<?php
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$aviso = is_string($_GET['aviso'] ?? null) ? trim((string)$_GET['aviso']) : '';
?>
<section class="company-evaluations" aria-labelledby="company-evaluations-title">
    <header class="company-evaluations__header">
        <p class="company-hub__eyebrow">Cierre de FCT</p>
        <h1 id="company-evaluations-title">Documentación y evaluación final</h1>
        <p>Consulta los alumnos asignados y entrega el informe firmado cuando finalice su periodo de prácticas.</p>
    </header>
    <?php if ($error !== ''): ?><p class="message message-error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
    <?php if ($guardada): ?><p class="message message-success" role="status">La evaluación se ha guardado correctamente.<?= $aviso !== '' ? $escape($aviso) : '' ?></p><?php endif; ?>
    <?php if ($documentosInicio !== []): ?>
        <section class="company-evaluations__documents" aria-labelledby="company-evaluations-documents-title">
            <h2 id="company-evaluations-documents-title">Documentación recibida de la escuela</h2>
            <ul>
                <?php foreach ($documentosInicio as $documento): ?>
                    <li><span><?= $escape(trim((string)$documento['nombre'] . ' ' . (string)$documento['apellidos'])) ?> · <?= $escape((string)$documento['nombre_original']) ?></span> <a href="index.php?action=descargar-documentacion-empresa&amp;id=<?= (int)$documento['id'] ?>">Descargar</a></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
    <?php if ($evaluaciones === []): ?>
        <p class="empty-state">No tienes alumnos asignados para gestionar.</p>
    <?php else: ?>
        <div class="company-evaluations__list">
            <?php foreach ($evaluaciones as $evaluacion): ?>
                <?php $student = trim((string)$evaluacion['alumno_nombre'] . ' ' . (string)$evaluacion['apellidos']); ?>
                <article class="company-evaluation-card">
                    <div class="company-evaluation-card__summary">
                        <h2><?= $escape($student) ?></h2>
                        <p><?= $escape((string)$evaluacion['nombre_especialidad']) ?> · <?= $escape((string)$evaluacion['fecha_inicio']) ?> — <?= $escape((string)$evaluacion['fecha_fin']) ?></p>
                        <p class="company-evaluation-card__status">Estado: <strong><?= $escape((string)$evaluacion['estado']) ?></strong><?php if ($evaluacion['evaluacion_dictamen']): ?> · Dictamen: <strong><?= $escape((string)$evaluacion['evaluacion_dictamen']) ?></strong><?php endif; ?></p>
                        <?php if ($evaluacion['evaluacion_nombre_original']): ?><small>Último archivo: <?= $escape((string)$evaluacion['evaluacion_nombre_original']) ?></small><?php endif; ?>
                    </div>
                    <form class="company-evaluation-form" method="POST" enctype="multipart/form-data" action="index.php?action=guardar-evaluacion-empresa">
                        <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                        <input type="hidden" name="asignacion_id" value="<?= (int)$evaluacion['id'] ?>">
                        <label>Dictamen
                            <select name="dictamen" required>
                                <option value="">Selecciona…</option>
                                <option value="Apto"<?= $evaluacion['evaluacion_dictamen'] === 'Apto' ? ' selected' : '' ?>>Apto</option>
                                <option value="No Apto"<?= $evaluacion['evaluacion_dictamen'] === 'No Apto' ? ' selected' : '' ?>>No Apto</option>
                            </select>
                        </label>
                        <label>Informe firmado (PDF, Excel o Word; máximo 10 MB)
                            <input type="file" name="informe_evaluacion" accept=".pdf,.xlsx,.docx,application/pdf,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>
                        </label>
                        <button class="button button-primary" type="submit">Guardar evaluación</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
