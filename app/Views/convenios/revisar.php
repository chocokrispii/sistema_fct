<h2>Convenios registrados</h2>
<p>Consulta los documentos y el estado de cada convenio. Los pendientes pueden aprobarse o rechazarse desde aquí.</p>
<?php if ($convenios === []): ?><p>No hay convenios registrados.</p>
<?php else: foreach ($convenios as $item): ?>
    <section class="review-card">
        <h3><?= htmlspecialchars($item['nombre_empresa'] . ' — ' . $item['numero_convenio'] . ' — ' . ($item['nombre_especialidad'] ?? 'Sin clasificar (anterior)'), ENT_QUOTES, 'UTF-8') ?></h3>
        <p>Estado: <?= htmlspecialchars($item['estado'], ENT_QUOTES, 'UTF-8') ?> · Firma <?= htmlspecialchars($item['fecha_firma'], ENT_QUOTES, 'UTF-8') ?> · Vigencia
            <?= htmlspecialchars($item['fecha_inicio'] . ' – ' . $item['fecha_fin'], ENT_QUOTES, 'UTF-8') ?>
            <?php if ($item['nombre_sede']): ?> · Sede <?= htmlspecialchars($item['nombre_sede'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
        </p>
        <a href="index.php?action=descargar-convenio&id=<?= (int)$item['id'] ?>">Revisar documento PDF</a>
        <?php if ($item['estado'] === 'Pendiente'): ?>
        <form class="review-decision" method="POST" action="index.php?action=resolver-convenio">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="convenio_id" value="<?= (int)$item['id'] ?>">
            <label>Motivo/observación <input name="motivo" maxlength="500"></label>
            <button name="decision" value="Aprobado" type="submit">Aprobar</button>
            <button name="decision" value="Rechazado" type="submit">Rechazar</button>
        </form>
        <?php elseif (is_string($item['motivo_revision']) && $item['motivo_revision'] !== ''): ?>
            <p>Observación: <?= htmlspecialchars($item['motivo_revision'], ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
    </section>
<?php endforeach; endif; ?>
