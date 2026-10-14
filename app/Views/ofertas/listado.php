<h2>Ofertas de FCT aprobadas</h2>
<?php if ($ofertas === []): ?>
    <p>No hay ofertas aprobadas para mostrar.</p>
<?php else: ?>
    <table>
        <thead><tr><th>Empresa</th><th>Ciclo</th><th>Plazas</th><th>Turno</th><th>Inicio</th><th>Fin</th></tr></thead>
        <tbody>
        <?php foreach ($ofertas as $oferta): ?>
            <tr>
                <td><?= htmlspecialchars($oferta['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($oferta['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= (int)$oferta['plazas_cuantas'] ?></td>
                <td><?= htmlspecialchars($oferta['turno'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($oferta['fecha_inicio'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($oferta['fecha_fin'], ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
