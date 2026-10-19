<section class="assignments-page" aria-labelledby="assignments-title">
    <div class="assignments-page__intro">
        <span class="student-registration__eyebrow"><?= $soloPropias ? 'Área de alumnado' : 'Gestión del centro' ?></span>
        <h2 id="assignments-title"><?= $soloPropias ? 'Mis prácticas' : 'Asignaciones FCT' ?></h2>
        <p><?= $soloPropias ? 'Consulta las prácticas que el centro te ha asignado y su estado actual.' : 'Consulta las prácticas asignadas y su estado. Los periodos proceden de la oferta aprobada.' ?></p>
        <?php if ($puedeGestionar): ?>
            <a class="student-portal__action" href="index.php?action=emparejar">Buscar candidatos y asignar plazas</a>
            <a class="student-portal__action" href="index.php?action=calificaciones">Calificar prácticas cerradas</a>
        <?php elseif (!$soloPropias): ?>
            <a class="student-portal__action" href="index.php?action=calificaciones">Consultar calificaciones</a>
        <?php elseif ($soloPropias): ?>
            <a class="student-portal__action" href="index.php?action=portal-alumno">Volver a mi expediente</a>
            <a class="student-portal__action" href="index.php?action=mis-calificaciones">Consultar mis calificaciones</a>
        <?php endif; ?>
    </div>

    <?php if ($error !== ''): ?><p class="message message-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <?php if ($asignaciones === []): ?>
        <div class="matching-page__empty">
            <h3>No hay asignaciones para mostrar</h3>
            <p><?= $soloPropias ? 'Cuando el centro te asigne a una oferta, los detalles aparecerán aquí.' : 'Las asignaciones creadas mediante el emparejamiento aparecerán aquí.' ?></p>
            <?php if ($soloPropias): ?><a href="index.php?action=ofertas">Consultar ofertas públicas</a><?php endif; ?>
        </div>
    <?php else: ?>
        <div class="assignments-table-wrap" role="region" aria-label="Listado de asignaciones" tabindex="0">
            <table class="assignments-table">
                <thead><tr><?php if (!$soloPropias): ?><th>Alumno</th><?php endif; ?><th>Empresa</th><th>Ciclo</th><th>Periodo</th><th>Tutor de empresa</th><th>Estado</th><?php if ($puedeGestionar): ?><th>Acciones</th><?php endif; ?></tr></thead>
                <tbody><?php foreach ($asignaciones as $item): ?>
                    <?php $estadoModifier = $item['estado'] === 'Asignada' ? 'active' : ($item['estado'] === 'Cerrada' ? 'closed' : 'cancelled'); ?>
                    <tr>
                        <?php if (!$soloPropias): ?><td><?= htmlspecialchars($item['alumno_nombre'] . ' ' . $item['apellidos'], ENT_QUOTES, 'UTF-8') ?><small><?= htmlspecialchars($item['alumno_correo'], ENT_QUOTES, 'UTF-8') ?></small></td><?php endif; ?>
                        <td><?= htmlspecialchars($item['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($item['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($item['fecha_inicio'] . ' – ' . $item['fecha_fin'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($item['tutor_empresa'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="status-badge status-badge--<?= $estadoModifier ?>"><?= htmlspecialchars($item['estado'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <?php if ($puedeGestionar): ?><td>
                            <?php if ($item['estado'] === 'Asignada'): ?>
                                <form class="action-form" method="POST" action="index.php?action=cerrar-practica">
                                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="asignacion_id" value="<?= (int)$item['id'] ?>">
                                    <button type="submit">Cerrar prácticas</button>
                                </form>
                            <?php else: ?><span class="text-muted">Sin acciones</span><?php endif; ?>
                        </td><?php endif; ?>
                    </tr>
                <?php endforeach; ?></tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php require __DIR__ . '/../partials/paginacion.php'; ?>
</section>
