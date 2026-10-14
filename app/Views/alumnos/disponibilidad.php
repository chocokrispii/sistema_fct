<section class="availability-page" aria-labelledby="availability-title">
    <?php if ($error !== ''): ?><p class="message message-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <?php if (isset($_GET['guardada'])): ?><p class="message message-success" role="status">Disponibilidad guardada.</p><?php endif; ?>
    <div class="availability-page__intro">
        <span class="student-registration__eyebrow">Área de alumnado</span>
        <h2 id="availability-title">Mi disponibilidad para FCT</h2>
        <p>Indica el periodo, turno y franjas semanales en las que puedes realizar las prácticas. El centro utilizará estos datos para buscar ofertas compatibles.</p>
    </div>
    <form class="availability-form" method="POST" action="index.php?action=disponibilidad">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
        <fieldset class="availability-schedule">
            <legend>Disponibilidad semanal</legend>
            <p>Selecciona los días y el horario en el que puedes realizar las prácticas cada semana.</p>
            <div class="availability-schedule__table-wrap">
                <table class="availability-schedule__table">
                    <thead><tr><th>Día</th><th>Disponible</th><th>Desde</th><th>Hasta</th></tr></thead>
                    <tbody><?php foreach ($diasSemana as $dia => $nombreDia): ?>
                        <?php $horario = $values['horarios'][$dia] ?? ['activo' => false, 'hora_inicio' => '', 'hora_fin' => '']; ?>
                        <tr>
                            <th scope="row"><?= htmlspecialchars($nombreDia, ENT_QUOTES, 'UTF-8') ?></th>
                            <td><input type="checkbox" name="horarios[<?= (int)$dia ?>][activo]" value="1" aria-label="<?= htmlspecialchars($nombreDia, ENT_QUOTES, 'UTF-8') ?>" <?= $horario['activo'] ? 'checked' : '' ?>></td>
                            <td><input type="time" step="600" name="horarios[<?= (int)$dia ?>][hora_inicio]" value="<?= htmlspecialchars($horario['hora_inicio'], ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($nombreDia . ', hora de inicio', ENT_QUOTES, 'UTF-8') ?>"></td>
                            <td><input type="time" step="600" name="horarios[<?= (int)$dia ?>][hora_fin]" value="<?= htmlspecialchars($horario['hora_fin'], ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($nombreDia . ', hora de fin', ENT_QUOTES, 'UTF-8') ?>"></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table>
            </div>
        </fieldset>
        <div class="availability-period">
            <h3>Periodo y turno</h3>
            <p>Define entre qué fechas puedes hacer las prácticas y en qué turno.</p>
            <div class="availability-period__dates">
                <label for="availability-start">Desde
                    <input id="availability-start" type="date" name="fecha_inicio" value="<?= htmlspecialchars($values['fecha_inicio'], ENT_QUOTES, 'UTF-8') ?>" required>
                </label>
                <label for="availability-end">Hasta
                    <input id="availability-end" type="date" name="fecha_fin" value="<?= htmlspecialchars($values['fecha_fin'], ENT_QUOTES, 'UTF-8') ?>" required>
                </label>
            </div>
            <label for="availability-shift">Turno
                <select id="availability-shift" name="turno" required>
                    <option value="Indiferente" <?= $values['turno'] === 'Indiferente' ? 'selected' : '' ?>>Indiferente</option>
                    <option value="M" <?= $values['turno'] === 'M' ? 'selected' : '' ?>>Mañana</option>
                    <option value="T" <?= $values['turno'] === 'T' ? 'selected' : '' ?>>Tarde</option>
                </select>
            </label>
            <button type="submit"><?= $disponibilidades === [] ? 'Guardar disponibilidad' : 'Actualizar disponibilidad' ?></button>
        </div>
    </form>
    <section class="availability-page__current" aria-labelledby="availability-current-title">
        <h3 id="availability-current-title">Disponibilidad registrada</h3>
        <div class="availability-current__grid"><div>
        <?php if ($disponibilidades === []): ?><p>Aún no has registrado disponibilidad.</p>
        <?php else: ?><ul class="student-portal__list"><?php foreach ($disponibilidades as $item): ?>
            <li>
                <strong><?= htmlspecialchars($item['fecha_inicio'] . ' – ' . $item['fecha_fin'], ENT_QUOTES, 'UTF-8') ?></strong>
                <span><?= htmlspecialchars($item['turno'] === 'M' ? 'Mañana' : ($item['turno'] === 'T' ? 'Tarde' : 'Indiferente'), ENT_QUOTES, 'UTF-8') ?></span>
            </li>
        <?php endforeach; ?></ul><?php endif; ?>
        </div><?php if ($horarioSemanal !== []): ?><div>
            <h4>Horario semanal guardado</h4>
            <ul class="student-portal__list">
                <?php foreach ($horarioSemanal as $item): ?>
                    <li><strong><?= htmlspecialchars($diasSemana[(int)$item['dia_semana']], ENT_QUOTES, 'UTF-8') ?></strong><span><?= htmlspecialchars(substr($item['hora_inicio'], 0, 5) . '–' . substr($item['hora_fin'], 0, 5), ENT_QUOTES, 'UTF-8') ?></span></li>
                <?php endforeach; ?>
            </ul></div>
        <?php endif; ?></div>
        <a class="student-portal__text-action" href="index.php?action=portal-alumno">Volver a mi expediente</a>
    </section>
</section>
