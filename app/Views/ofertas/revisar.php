<h2>Ofertas pendientes de revisión</h2>
<p>Aprueba solo ofertas cuyo periodo esté cubierto por un convenio aprobado del mismo ciclo formativo.</p>
<?php if ($error !== ''): ?><p class="message message-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<?php if ($ofertas === []): ?>
    <p>No hay ofertas pendientes.</p>
<?php else: ?>
    <?php foreach ($ofertas as $oferta): ?>
        <?php
        $jornadaLabel = match ($oferta['jornada'] ?? null) {
            'normal' => 'Jornada normal',
            'partida' => 'Jornada partida',
            default => 'Jornada no especificada',
        };
        ?>
        <?php $turnLabel = ['M' => 'Mañana', 'T' => 'Tarde', 'P' => 'Partido', 'Indiferente' => 'Indiferente'][$oferta['turno']] ?? 'Sin especificar'; ?>
        <section class="review-card offer-review-card">
            <span class="offer-create__eyebrow"><?= htmlspecialchars($oferta['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($oferta['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?></span>
            <h3><?= htmlspecialchars(($oferta['titulo'] ?? '') !== '' ? $oferta['titulo'] : $oferta['nombre_empresa'] . ' — ' . $oferta['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?></h3>
            <?php if (!empty($oferta['descripcion'])): ?><p><?= nl2br(htmlspecialchars($oferta['descripcion'], ENT_QUOTES, 'UTF-8')) ?></p><?php endif; ?>
            <p>
                <?= (int)$oferta['plazas_cuantas'] ?> plazas ·
                <?= htmlspecialchars($oferta['fecha_inicio'] . ' – ' . $oferta['fecha_fin'], ENT_QUOTES, 'UTF-8') ?> ·
                <span class="status-badge status-badge--neutral"><?= htmlspecialchars($turnLabel, ENT_QUOTES, 'UTF-8') ?></span> ·
                <span class="status-badge status-badge--neutral"><?= htmlspecialchars($jornadaLabel, ENT_QUOTES, 'UTF-8') ?></span> ·
                <span class="status-badge status-badge--neutral"><?= htmlspecialchars($oferta['modalidad'] ?? 'Presencial', ENT_QUOTES, 'UTF-8') ?></span>
            </p>
            <?php if (!empty($oferta['ubicacion'] ?? $oferta['lugar'] ?? '')): ?>
                <p><strong>Ubicación:</strong> <?= htmlspecialchars(($oferta['ubicacion'] ?? '') !== '' ? $oferta['ubicacion'] : $oferta['lugar'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <?php if (!empty($oferta['requisitos'])): ?><p><strong>Requisitos:</strong> <?= nl2br(htmlspecialchars($oferta['requisitos'], ENT_QUOTES, 'UTF-8')) ?></p><?php endif; ?>
            <?php if (!empty($oferta['tecnologias'])): ?><p><strong>Tecnologías:</strong> <?= htmlspecialchars($oferta['tecnologias'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
            <?php if (!empty($oferta['horarios'])): ?>
                <ul><?php foreach ($oferta['horarios'] as $shift): ?>
                    <?php
                    $dayLabel = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'][(int)($shift['dia_semana'] ?? 0)] ?? 'Día';
                    $startSecond = (string)($shift['hora_inicio_2'] ?? '');
                    $endSecond = (string)($shift['hora_fin_2'] ?? '');
                    ?>
                    <li><?= htmlspecialchars($dayLabel . ' · 1.ª franja: ' . substr((string)($shift['hora_inicio'] ?? ''), 0, 5) . '–' . substr((string)($shift['hora_fin'] ?? ''), 0, 5), ENT_QUOTES, 'UTF-8') ?>
                        <?php if ($startSecond !== '' && $endSecond !== ''): ?>
                            <br><?= htmlspecialchars($dayLabel . ' · 2.ª franja: ' . substr($startSecond, 0, 5) . '–' . substr($endSecond, 0, 5), ENT_QUOTES, 'UTF-8') ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?></ul>
            <?php else: ?>
                <p class="message message-error">Oferta sin horario semanal exacto; no se puede aprobar hasta volver a publicarla con franjas semanales.</p>
            <?php endif; ?>
            <?php if (($_SESSION['rol'] ?? null) === 'Administrador'): ?>
                <form class="review-decision" method="POST" action="index.php?action=revisar-oferta">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="oferta_id" value="<?= (int)$oferta['id'] ?>">
                    <div
                        data-vue-island="ReviewOfferDecision"
                        data-prop-csrf="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>"
                        data-prop-offer-id="<?= (int)$oferta['id'] ?>"
                    ></div>
                    <label>Motivo u observación <input type="text" name="motivo" maxlength="500"></label>
                    <button name="decision" value="Aprobada" type="submit">Aprobar</button>
                    <button name="decision" value="Rechazada" type="submit">Rechazar</button>
                    <a href="index.php?action=revisar-convenios">Consultar convenios</a>
                </form>
            <?php else: ?>
                <p>La resolución de ofertas está reservada a administración.</p>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
<?php if (isset($paginacion)): ?>
    <?php require __DIR__ . '/../partials/paginacion.php'; ?>
<?php endif; ?>
