<section class="assignments-page" aria-labelledby="grades-title">
    <div class="assignments-page__intro">
        <span class="student-registration__eyebrow"><?= $soloPropias ? 'Área de alumnado' : 'Seguimiento académico' ?></span>
        <h2 id="grades-title"><?= $soloPropias ? 'Mis calificaciones de FCT' : 'Calificaciones de prácticas' ?></h2>
        <p><?= $soloPropias
            ? 'Consulta las notas y observaciones registradas por el centro para tus prácticas finalizadas.'
            : 'Las calificaciones finales se registran únicamente después de cerrar una práctica.' ?></p>
        <?php if ($soloPropias): ?>
            <a class="student-portal__action" href="index.php?action=portal-alumno">Volver a mi expediente</a>
        <?php else: ?>
            <a class="student-portal__action" href="index.php?action=asignaciones">Volver a asignaciones</a>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['guardada'])): ?><p class="message message-success" role="status">Calificación guardada.</p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="message message-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <?php if ($calificaciones === []): ?>
        <div class="matching-page__empty">
            <h3>No hay prácticas para mostrar</h3>
            <p><?= $soloPropias ? 'Cuando el centro cierre y califique una práctica, aparecerá aquí.' : 'Las prácticas y sus calificaciones aparecerán aquí.' ?></p>
        </div>
    <?php else: ?>
        <div class="assignments-table-wrap">
            <table class="assignments-table">
                <thead><tr>
                    <?php if (!$soloPropias): ?><th>Alumno</th><?php endif; ?>
                    <th>Empresa</th><th>Ciclo</th><th>Periodo</th><th>Estado</th><th>Nota final</th><th>Observaciones</th>
                    <?php if ($puedeGestionar): ?><th>Registrar / actualizar</th><?php endif; ?>
                </tr></thead>
                <tbody><?php foreach ($calificaciones as $item): ?>
                    <tr>
                        <?php if (!$soloPropias): ?><td><?= htmlspecialchars($item['alumno_nombre'] . ' ' . $item['apellidos'], ENT_QUOTES, 'UTF-8') ?><small><?= htmlspecialchars($item['alumno_correo'], ENT_QUOTES, 'UTF-8') ?></small></td><?php endif; ?>
                        <td><?= htmlspecialchars($item['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($item['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($item['fecha_inicio'] . ' – ' . $item['fecha_fin'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($item['estado_asignacion'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $item['nota'] !== null ? htmlspecialchars(number_format((float)$item['nota'], 2, ',', '') . ' / 10', ENT_QUOTES, 'UTF-8') : 'Pendiente' ?></td>
                        <td><?= $item['observaciones'] !== null && $item['observaciones'] !== '' ? nl2br(htmlspecialchars($item['observaciones'], ENT_QUOTES, 'UTF-8')) : '—' ?></td>
                        <?php if ($puedeGestionar): ?><td>
                            <?php if ($item['estado_asignacion'] === 'Cerrada'): ?>
                                <form class="grade-form" method="POST" action="index.php?action=guardar-calificacion">
                                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="asignacion_id" value="<?= (int)$item['asignacion_id'] ?>">
                                    <label>Nota (0–10)
                                        <input type="text" name="nota" inputmode="decimal" maxlength="5" pattern="(?:10(?:[.,]0{1,2})?|[0-9](?:[.,][0-9]{1,2})?)" value="<?= $item['nota'] !== null ? htmlspecialchars((string)$item['nota'], ENT_QUOTES, 'UTF-8') : '' ?>" required>
                                    </label>
                                    <div data-vue-island="GradeForm" data-prop-csrf="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>"></div>
                                    <label>Observaciones
                                        <textarea name="observaciones" maxlength="2000" rows="3"><?= htmlspecialchars($item['observaciones'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </label>
                                    <button type="submit"><?= $item['nota'] !== null ? 'Actualizar nota' : 'Guardar nota' ?></button>
                                </form>
                            <?php else: ?><span class="text-muted">Disponible al cerrar las prácticas</span><?php endif; ?>
                        </td><?php endif; ?>
                    </tr>
                <?php endforeach; ?></tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
