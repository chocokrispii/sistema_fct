<section class="offer-list-section" aria-labelledby="approved-offers-title">
    <header class="offer-list-section__heading">
        <div>
            <span class="offer-create__eyebrow">Oportunidades</span>
            <h2 id="approved-offers-title">Ofertas de FCT aprobadas</h2>
        </div>
        <p>Consulta las propuestas vigentes, sus horarios y requisitos.</p>
    </header>
<?php if ($ofertas === []): ?>
    <div class="offer-list-section__empty"><h3>No hay ofertas aprobadas para mostrar</h3><p>Vuelve a consultar más adelante.</p></div>
<?php else: ?>
    <div class="assignments-table-wrap offer-list-table-wrap">
        <table class="assignments-table offer-list-table">
            <thead><tr><th scope="col">Oferta</th><th scope="col">Empresa</th><th scope="col">Ciclo</th><th scope="col">Plazas</th><th scope="col">Horario semanal</th><th scope="col">Periodo</th><th scope="col">Modalidad</th><th scope="col">Ubicación</th></tr></thead>
            <tbody><?php foreach ($ofertas as $oferta): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($oferta['titulo'] ?? 'Oferta FCT', ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars((string)($oferta['descripcion'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small><?php if (!empty($oferta['requisitos'])): ?><small>Requisitos: <?= htmlspecialchars($oferta['requisitos'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?><?php if (!empty($oferta['tecnologias'])): ?><small>Tecnologías: <?= htmlspecialchars($oferta['tecnologias'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?></td>
                    <td><?= htmlspecialchars($oferta['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($oferta['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int)$oferta['plazas_cuantas'] ?></td>
                    <td class="offer-list-table__schedule"><?php if ($oferta['horarios'] === []): ?><span class="text-muted">Sin horario</span><?php else: ?><ul class="weekly-schedule" aria-label="Horario semanal de la oferta"><?php foreach ($oferta['horarios'] as $shift): ?><li><span class="weekly-schedule__day"><?= htmlspecialchars(['', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'][(int)$shift['dia_semana']], ENT_QUOTES, 'UTF-8') ?></span><time class="weekly-schedule__time"><?= htmlspecialchars(substr($shift['hora_inicio'], 0, 5) . '–' . substr($shift['hora_fin'], 0, 5), ENT_QUOTES, 'UTF-8') ?></time></li><?php endforeach; ?></ul><?php endif; ?></td>
                    <td><?= EuropeanDate::periodBlock($oferta['fecha_inicio'], $oferta['fecha_fin']) ?></td>
                    <td><span class="status-badge status-badge--neutral"><?= htmlspecialchars($oferta['modalidad'], ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td><?= htmlspecialchars($oferta['ubicacion'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table>
    </div>
<?php endif; ?>
</section>
