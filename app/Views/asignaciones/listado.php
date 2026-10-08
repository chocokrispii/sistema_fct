<h2><?= $soloPropias ? 'Mis prácticas' : 'Asignaciones FCT' ?></h2>
<?php if ($error !== ''): ?><p class="message message-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<?php if ($asignaciones === []): ?><p>No hay asignaciones para mostrar.</p>
<?php else: ?>
<table>
    <thead><tr><th>Alumno</th><th>Empresa</th><th>Ciclo</th><th>Periodo</th><th>Tutor de empresa</th><th>Estado</th><?php if ($puedeGestionar): ?><th>Acciones</th><?php endif; ?></tr></thead>
    <tbody><?php foreach ($asignaciones as $item): ?>
        <tr>
            <td><?= htmlspecialchars($item['alumno_nombre'] . ' ' . $item['apellidos'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($item['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($item['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($item['fecha_inicio'] . ' – ' . $item['fecha_fin'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($item['tutor_empresa'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($item['estado'], ENT_QUOTES, 'UTF-8') ?></td>
            <?php if ($puedeGestionar): ?><td>
                <?php if ($item['estado'] === 'Asignada'): ?>
                    <form method="POST" action="index.php?action=cerrar-practica">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="asignacion_id" value="<?= (int)$item['id'] ?>">
                        <button type="submit">Cerrar prácticas</button>
                    </form>
                <?php endif; ?>
            </td><?php endif; ?>
        </tr>
    <?php endforeach; ?></tbody>
</table>
<?php endif; ?>
