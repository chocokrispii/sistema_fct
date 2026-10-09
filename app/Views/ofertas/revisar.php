<h2>Revisar Ofertas Pendientes</h2>

<?php if (isset($_GET['revisada'])): ?>
    <p class="message message-success">El estado de la oferta ha sido actualizado correctamente.</p>
<?php endif; ?>

<?php if ($ofertas === []): ?>
    <p>No hay ofertas pendientes de revisión.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Empresa</th>
                <th>Ciclo</th>
                <th>Plazas</th>
                <th>Turno</th>
                <th>Periodo</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($ofertas as $oferta): ?>
            <tr>
                <td><?= htmlspecialchars($oferta['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($oferta['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= (int)$oferta['plazas_cuantas'] ?></td>
                <td><?= htmlspecialchars($oferta['turno'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($oferta['fecha_inicio'] . ' – ' . $oferta['fecha_fin'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <form method="POST" action="../../../public/index.php?action=revisar-oferta" style="display:inline;">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="oferta_id" value="<?= (int)$oferta['id_oferta'] ?>">
                        <input type="hidden" name="estado" value="Aprobada">
                        <button type="submit" class="btn-approve">Aprobar</button>
                    </form>
                    
                    <form method="POST" action="../../../public/index.php?action=revisar-oferta" style="display:inline;">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="oferta_id" value="<?= (int)$oferta['id_oferta'] ?>">
                        <input type="hidden" name="estado" value="Rechazada">
                        <input type="text" name="motivo_revision" placeholder="Motivo si se rechaza">
                        <button type="submit" class="btn-reject">Rechazar</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>