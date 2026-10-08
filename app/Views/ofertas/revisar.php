<h2>Ofertas pendientes de revisión</h2>
<?php if ($ofertas === []): ?>
    <p>No hay ofertas pendientes.</p>
<?php else: ?>
    <?php foreach ($ofertas as $oferta): ?>
        <section class="review-card">
            <h3><?= htmlspecialchars($oferta['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($oferta['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?></h3>
            <p><?= (int)$oferta['plazas_cuantas'] ?> plazas · Turno <?= htmlspecialchars($oferta['turno'], ENT_QUOTES, 'UTF-8') ?> ·
                <?= htmlspecialchars($oferta['fecha_inicio'] . ' – ' . $oferta['fecha_fin'], ENT_QUOTES, 'UTF-8') ?></p>
            <form method="POST" action="index.php?action=revisar-oferta">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="oferta_id" value="<?= (int)$oferta['id'] ?>">
                <label>Motivo u observación <input type="text" name="motivo" maxlength="500"></label>
                <button name="decision" value="Aprobada" type="submit">Aprobar</button>
                <button name="decision" value="Rechazada" type="submit">Rechazar</button>
            </form>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
