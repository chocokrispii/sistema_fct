<h2>Emparejamiento y asignación</h2>
<?php if ($error !== ''): ?><p class="message message-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<?php if ($ofertas === []): ?><p>No hay ofertas aprobadas vigentes.</p>
<?php else: foreach ($ofertas as $oferta): ?>
    <section class="review-card">
        <h3><?= htmlspecialchars($oferta['nombre_empresa'] . ' — ' . $oferta['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?></h3>
        <p><?= (int)$oferta['plazas_cuantas'] ?> plazas (<?= (int)$oferta['asignadas'] ?> asignadas) · <?= htmlspecialchars($oferta['turno'], ENT_QUOTES, 'UTF-8') ?> ·
            <?= htmlspecialchars($oferta['fecha_inicio'] . ' – ' . $oferta['fecha_fin'], ENT_QUOTES, 'UTF-8') ?></p>
        <?php if ($oferta['candidatos'] === []): ?><p>No hay alumnos disponibles que cumplan el ciclo, las fechas y el turno.</p>
        <?php else: ?>
            <?php foreach ($oferta['candidatos'] as $candidato): ?>
                <form method="POST" action="index.php?action=asignar-alumno">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="oferta_id" value="<?= (int)$oferta['id'] ?>">
                    <input type="hidden" name="alumno_id" value="<?= (int)$candidato['alumno_id'] ?>">
                    <span><?= htmlspecialchars($candidato['nombre'] . ' ' . $candidato['apellidos'] . ' — ' . $candidato['correo'], ENT_QUOTES, 'UTF-8') ?></span>
                    <label>Tutor de empresa <input type="text" name="tutor_empresa" maxlength="150" required></label>
                    <button type="submit">Asignar</button>
                </form>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
<?php endforeach; endif; ?>
