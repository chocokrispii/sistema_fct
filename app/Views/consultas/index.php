<h2>Consultas y seguimiento</h2>
<?php if ($error !== ''): ?><p class="message message-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form class="page-form consultations-filters" method="GET" action="index.php">
    <input type="hidden" name="action" value="consultas">
    <label>Ciclo
        <select name="especialidad_id">
            <option value="">Todos</option>
            <?php foreach ($especialidades as $especialidad): ?>
                <option value="<?= (int)$especialidad['id'] ?>" <?= (string)$filters['especialidad'] === (string)$especialidad['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($especialidad['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Estado de oferta
        <select name="estado">
            <option value="">Todos</option>
            <?php foreach (['Pendiente', 'Aprobada', 'Rechazada'] as $status): ?>
                <option value="<?= $status ?>" <?= $filters['estado'] === $status ? 'selected' : '' ?>><?= $status ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <div class="consultations-filters__date-range">
        <label>Inicio desde <input type="date" name="desde" value="<?= htmlspecialchars($filters['desde']?->format('Y-m-d') ?? '', ENT_QUOTES, 'UTF-8') ?>"></label>
        <label>Fin hasta <input type="date" name="hasta" value="<?= htmlspecialchars($filters['hasta']?->format('Y-m-d') ?? '', ENT_QUOTES, 'UTF-8') ?>"></label>
    </div>
    <button type="submit">Filtrar</button>
</form>
<section class="consultations-results" aria-label="Ofertas y seguimiento">
    <p class="consultations-results__count" role="status">
        <?= count($rows) ?> <?= count($rows) === 1 ? 'resultado encontrado' : 'resultados encontrados' ?>
    </p>
    <?php if ($rows === []): ?>
        <div class="consultations-empty">
            <h3>No hay resultados para estos filtros</h3>
            <p>Prueba a cambiar el ciclo, el estado o el periodo seleccionado.</p>
        </div>
    <?php else: ?>
        <div class="consultations-grid">
            <?php foreach ($rows as $row): ?>
                <?php
                $offerStatusClass = match ($row['estado']) {
                    'Aprobada' => 'approved',
                    'Rechazada' => 'rejected',
                    default => 'pending',
                };
                $studentName = trim(($row['alumno_nombre'] ?? '') . ' ' . ($row['apellidos'] ?? ''));
                $offerTitle = trim((string)($row['titulo'] ?? '')) ?: 'Oferta FCT';
                $offerSubtitle = trim((string)($row['modalidad'] ?? ''));
                if (!empty($row['sede_nombre']) || !empty($row['sede_direccion'])) {
                    $offerSubtitle = trim(
                        $offerSubtitle . ' · ' . trim((string)($row['sede_nombre'] ?? '') . ' — ' . (string)($row['sede_direccion'] ?? ''), ' —')
                    );
                }
                $offerSubtitle = $offerSubtitle ?: 'Modalidad y ubicación no especificadas';
                ?>
                <article class="consultation-card">
                    <header class="consultation-card__header">
                        <div class="consultation-card__heading">
                            <p class="consultation-card__company"><?= htmlspecialchars($row['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?></p>
                            <?php if (mb_strlen($offerTitle) > 160): ?>
                                <details class="consultation-card__expandable consultation-card__expandable--title">
                                    <summary>
                                        <h3><?= htmlspecialchars($offerTitle, ENT_QUOTES, 'UTF-8') ?></h3>
                                        <span class="consultation-card__expand-label" data-more>Ver título completo</span>
                                        <span class="consultation-card__expand-label" data-less>Ver menos</span>
                                    </summary>
                                    <p><?= htmlspecialchars($offerTitle, ENT_QUOTES, 'UTF-8') ?></p>
                                </details>
                            <?php else: ?>
                                <h3><?= htmlspecialchars($offerTitle, ENT_QUOTES, 'UTF-8') ?></h3>
                            <?php endif; ?>
                            <?php if (mb_strlen($offerSubtitle) > 180): ?>
                                <details class="consultation-card__expandable consultation-card__expandable--subtitle">
                                    <summary>
                                        <span><?= htmlspecialchars($offerSubtitle, ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="consultation-card__expand-label" data-more>Ver más</span>
                                        <span class="consultation-card__expand-label" data-less>Ver menos</span>
                                    </summary>
                                    <p><?= htmlspecialchars($offerSubtitle, ENT_QUOTES, 'UTF-8') ?></p>
                                </details>
                            <?php else: ?>
                                <p class="consultation-card__subtitle"><?= htmlspecialchars($offerSubtitle, ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>
                        </div>
                        <span class="status-badge status-badge--<?= $offerStatusClass ?>">
                            <?= htmlspecialchars($row['estado'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </header>
                    <dl class="consultation-card__details">
                        <div>
                            <dt>Ciclo</dt>
                            <dd><?= htmlspecialchars($row['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                        <div class="consultation-card__period">
                            <dt>Periodo</dt>
                            <dd><?= EuropeanDate::periodBlock($row['fecha_inicio'], $row['fecha_fin']) ?></dd>
                        </div>
                        <div>
                            <dt><?= ($_SESSION['rol'] ?? null) === 'Observador' ? 'Alumno' : 'Alumno asignado' ?></dt>
                            <dd><?= htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8') ?: '—' ?></dd>
                        </div>
                        <div>
                            <dt>Estado de prácticas</dt>
                            <dd>
                                <?php if (!empty($row['estado_asignacion'])): ?>
                                    <span class="status-badge status-badge--neutral"><?= htmlspecialchars($row['estado_asignacion'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php else: ?>
                                    <span class="consultation-card__unassigned">Sin asignación</span>
                                <?php endif; ?>
                            </dd>
                        </div>
                    </dl>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
