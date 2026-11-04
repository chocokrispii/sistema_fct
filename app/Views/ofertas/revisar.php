<h2>Ofertas pendientes de revisión</h2>
<p>Aprueba solo las ofertas cuyo periodo esté cubierto por un convenio aprobado del mismo ciclo formativo.</p>
<?php if ($error !== ''): ?><p class="message message-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<?php if ($ofertas === []): ?>
    <p>No hay ofertas pendientes.</p>
<?php else: ?>
    <?php foreach ($ofertas as $oferta): ?>
        <section class="review-card offer-review-card">
            <?php $turnLabel = ['M' => 'Mañana', 'T' => 'Tarde', 'P' => 'Partido', 'Indiferente' => 'Indiferente'][$oferta['turno']] ?? 'Sin especificar'; ?>
            <span class="offer-create__eyebrow"><?= htmlspecialchars($oferta['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($oferta['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?></span>
            <h3><?= htmlspecialchars($oferta['titulo'] ?? 'Oferta FCT', ENT_QUOTES, 'UTF-8') ?></h3>
            <?php if (!empty($oferta['descripcion'])): ?><p><?= nl2br(htmlspecialchars($oferta['descripcion'], ENT_QUOTES, 'UTF-8')) ?></p><?php endif; ?>
            <p><?= (int)$oferta['plazas_cuantas'] ?> plazas ·
                <?= EuropeanDate::periodBlock($oferta['fecha_inicio'], $oferta['fecha_fin']) ?> ·
                <span class="status-badge status-badge--neutral"><?= htmlspecialchars($turnLabel, ENT_QUOTES, 'UTF-8') ?></span> ·
                <span class="status-badge status-badge--neutral"><?= htmlspecialchars($oferta['modalidad'], ENT_QUOTES, 'UTF-8') ?></span></p>
            <p><strong>Sede de trabajo:</strong> <?= htmlspecialchars($oferta['sede_nombre'], ENT_QUOTES, 'UTF-8') ?><br><strong>Dirección:</strong> <?= htmlspecialchars($oferta['sede_direccion'], ENT_QUOTES, 'UTF-8') ?><?php if (!empty($oferta['sede_telefono'])): ?><br><strong>Teléfono:</strong> <?= htmlspecialchars($oferta['sede_telefono'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></p>
            <?php if (!empty($oferta['requisitos'])): ?><p><strong>Requisitos:</strong> <?= nl2br(htmlspecialchars($oferta['requisitos'], ENT_QUOTES, 'UTF-8')) ?></p><?php endif; ?>
            <?php if (!empty($oferta['tecnologias'])): ?><p><strong>Tecnologías:</strong> <?= htmlspecialchars($oferta['tecnologias'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
            <ul><?php foreach ($oferta['horarios'] as $shift): ?>
                <li><?= htmlspecialchars(['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'][(int)$shift['dia_semana']] . ': ' . substr($shift['hora_inicio'], 0, 5) . '–' . substr($shift['hora_fin'], 0, 5), ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?></ul>
            <?php if ($oferta['horarios'] === []): ?>
                <p class="message message-error">Oferta histórica sin horario exacto. Completa las franjas semanales para habilitar la aprobación.</p>
                <?php if (($_SESSION['rol'] ?? null) === 'Administrador'): ?>
                    <form class="review-decision offer-schedule-repair" method="POST" action="index.php?action=actualizar-horario-oferta">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="oferta_id" value="<?= (int)$oferta['id'] ?>">
                        <fieldset>
                            <legend>Horario semanal</legend>
                            <p>Selecciona los días y la franja. Verifica que refleje el horario acordado con la empresa.</p>
                            <?php foreach (['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'] as $dayIndex => $dayName): ?>
                                <?php $dayNumber = $dayIndex + 1; ?>
                                <div class="offer-schedule-repair__day">
                                    <label class="offer-schedule-repair__active">
                                        <input type="checkbox" name="horarios[<?= $dayNumber ?>][activo]" value="1" <?= $dayNumber <= 5 ? 'checked' : '' ?>>
                                        <?= htmlspecialchars($dayName, ENT_QUOTES, 'UTF-8') ?>
                                    </label>
                                    <label>Desde
                                        <input type="time" name="horarios[<?= $dayNumber ?>][hora_inicio]" value="08:00" step="300">
                                    </label>
                                    <label>Hasta
                                        <input type="time" name="horarios[<?= $dayNumber ?>][hora_fin]" value="15:00" step="300">
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </fieldset>
                        <button type="submit">Guardar horario semanal</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (($_SESSION['rol'] ?? null) === 'Administrador'): ?><form class="review-decision" method="POST" action="index.php?action=revisar-oferta">
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
            </form>
            <?php else: ?><p>La resolución de ofertas está reservada a administración.</p><?php endif; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
<?php require __DIR__ . '/../partials/paginacion.php'; ?>
