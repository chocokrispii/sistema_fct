<h2><?= $soloCompatibles ? 'Ofertas compatibles con tu perfil' : 'Ofertas de FCT aprobadas' ?></h2>
<?php if ($ofertas === []): ?>
    <?php if ($soloCompatibles && $tieneAsignacionActiva): ?>
        <p role="status">Ya tienes una práctica asignada. Cuando finalice, podrás consultar nuevas ofertas compatibles.</p>
    <?php elseif ($soloCompatibles && !$tieneDisponibilidad): ?>
        <p role="status">Registra una disponibilidad vigente para encontrar ofertas compatibles con tu perfil.</p>
        <a href="index.php?action=disponibilidad">Registrar disponibilidad</a>
    <?php else: ?>
        <p role="status"><?= $soloCompatibles ? 'No hay ofertas con plazas compatibles con tu ciclo, disponibilidad y turno.' : 'No hay ofertas aprobadas para mostrar.' ?></p>
    <?php endif; ?>
<?php else: ?>
    <table>
        <thead><tr><th>Empresa</th><th>Ciclo</th><th><?= $soloCompatibles ? 'Plazas disponibles' : 'Plazas' ?></th><th>Turno</th><th>Inicio</th><th>Fin</th></tr></thead>
        <tbody>
        <?php foreach ($ofertas as $oferta): ?>
            <tr>
                <td><?= htmlspecialchars($oferta['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($oferta['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= (int)($soloCompatibles ? $oferta['plazas_disponibles'] : $oferta['plazas_cuantas']) ?></td>
                <td><?= htmlspecialchars($oferta['turno'] === 'M' ? 'Mañana' : ($oferta['turno'] === 'T' ? 'Tarde' : 'Indiferente'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars(date('d/m/Y', strtotime($oferta['fecha_inicio'])), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars(date('d/m/Y', strtotime($oferta['fecha_fin'])), ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
