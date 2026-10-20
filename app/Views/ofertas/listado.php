<?php
$soloCompatibles = ($soloCompatibles ?? false) === true;
$ofertas = $ofertas ?? [];
?>
<section class="offer-list-section" aria-labelledby="approved-offers-title">
    <header class="offer-list-section__heading">
        <div>
            <span class="offer-create__eyebrow">Oportunidades</span>
            <h2 id="approved-offers-title"><?= $soloCompatibles ? 'Ofertas compatibles con tu perfil' : 'Ofertas de prácticas' ?></h2>
        </div>
        <p>Consulta las propuestas vigentes, sus horarios y requisitos.</p>
    </header>
    <?php if ($ofertas === []): ?>
        <div class="offer-list-section__empty">
            <?php if ($soloCompatibles && !empty($tieneAsignacionActiva)): ?>
                <h3>Ya tienes una práctica asignada</h3>
                <p role="status">Cuando finalice, podrás consultar nuevas ofertas compatibles.</p>
            <?php elseif ($soloCompatibles && empty($tieneDisponibilidad)): ?>
                <h3>Registra tu disponibilidad</h3>
                <p role="status">Necesitas una disponibilidad vigente para encontrar ofertas compatibles con tu perfil.</p>
                <a href="index.php?action=disponibilidad">Registrar disponibilidad</a>
            <?php else: ?>
                <h3><?= $soloCompatibles ? 'No hay ofertas compatibles' : 'No hay ofertas aprobadas para mostrar' ?></h3>
                <p role="status"><?= $soloCompatibles ? 'No hay plazas que coincidan con tu ciclo, disponibilidad y turno.' : 'Vuelve a consultar más adelante.' ?></p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="assignments-table-wrap" role="region" aria-label="Listado de ofertas" tabindex="0">
            <table class="assignments-table">
                <caption><?= $soloCompatibles ? 'Ofertas compatibles con tu perfil' : 'Ofertas de prácticas aprobadas' ?></caption>
                <thead>
                    <tr>
                        <th scope="col">Oferta</th>
                        <th scope="col">Empresa</th>
                        <th scope="col">Ciclo</th>
                        <th scope="col"><?= $soloCompatibles ? 'Vacantes disponibles' : 'Plazas' ?></th>
                        <th scope="col">Horario semanal</th>
                        <th scope="col">Periodo</th>
                        <th scope="col">Turno</th>
                        <th scope="col">Modalidad</th>
                        <th scope="col">Ubicación</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ofertas as $oferta): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars(($oferta['titulo'] ?? '') !== '' ? $oferta['titulo'] : 'Oferta FCT', ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if (!empty($oferta['descripcion'])): ?><small><?= htmlspecialchars($oferta['descripcion'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
                                <?php if (!empty($oferta['requisitos'])): ?><small>Requisitos: <?= htmlspecialchars($oferta['requisitos'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
                                <?php if (!empty($oferta['tecnologias'])): ?><small>Tecnologías: <?= htmlspecialchars($oferta['tecnologias'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($oferta['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($oferta['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= (int)($soloCompatibles ? ($oferta['plazas_disponibles'] ?? 0) : $oferta['plazas_cuantas']) ?></td>
                            <td>
                                <?php if (!empty($oferta['horarios'])): ?>
                                    <?php foreach ($oferta['horarios'] as $shift): ?>
                                        <?= htmlspecialchars(['', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'][(int)$shift['dia_semana']] . ' ' . substr($shift['hora_inicio'], 0, 5) . '–' . substr($shift['hora_fin'], 0, 5), ENT_QUOTES, 'UTF-8') ?><br>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span>Horario no especificado</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($oferta['fecha_inicio'] . ' – ' . $oferta['fecha_fin'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(['M' => 'Mañana', 'T' => 'Tarde', 'P' => 'Partido', 'Indiferente' => 'Indiferente'][$oferta['turno']] ?? 'Sin especificar', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><span class="status-badge status-badge--neutral"><?= htmlspecialchars($oferta['modalidad'] ?? 'Presencial', ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td><?= htmlspecialchars(($oferta['ubicacion'] ?? '') !== '' ? $oferta['ubicacion'] : (($oferta['lugar'] ?? '') !== '' ? $oferta['lugar'] : '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php if (isset($paginacion)): ?>
        <?php require __DIR__ . '/../partials/paginacion.php'; ?>
    <?php endif; ?>
</section>
