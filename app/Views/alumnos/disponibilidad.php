<section class="availability-page" aria-labelledby="availability-title">
    <?php if ($error !== ''): ?><p class="message message-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <?php if (isset($_GET['guardada'])): ?><p class="message message-success" role="status">Disponibilidad guardada.</p><?php endif; ?>
    <div class="availability-page__intro">
        <span class="student-registration__eyebrow">Área de alumnado</span>
        <h2 id="availability-title">Mi disponibilidad para FCT</h2>
        <p>Indica el periodo y las franjas semanales exactas en las que puedes realizar las prácticas. El centro utilizará estos datos para buscar ofertas compatibles.</p>
    </div>
    <?php
    $scheduleState = [
        'days' => [],
        'csrf' => CsrfMiddleware::token(),
        'esIndiferente' => (bool)$values['es_indiferente'],
    ];
    foreach ($diasSemana as $dia => $nombreDia) {
        $horario = $values['horarios'][$dia] ?? ['activo' => false, 'hora_inicio' => '', 'hora_fin' => ''];
        $scheduleState['days'][] = [
            'day' => (int)$dia,
            'name' => $nombreDia,
            'active' => (bool)$horario['activo'],
            'start' => $horario['hora_inicio'],
            'end' => $horario['hora_fin'],
        ];
    }
    $selectedScheduleDays = count(array_filter(
        $scheduleState['days'],
        static fn(array $day): bool => $day['active']
    ));
    $savedSchedule = [
        'fecha_inicio' => $values['fecha_inicio'],
        'fecha_fin' => $values['fecha_fin'],
        'es_indiferente' => (bool)$values['es_indiferente'],
        'dias' => array_map(
            static fn(array $day): array => [
                'dia_semana' => (int)$day['dia_semana'],
                'dia' => $diasSemana[(int)$day['dia_semana']],
                'hora_inicio' => $day['hora_inicio'],
                'hora_fin' => $day['hora_fin'],
            ],
            $horarioSemanal
        ),
    ];
    ?>
    <form class="page-form page-form--narrow" method="POST" action="index.php?action=disponibilidad">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($scheduleState['csrf'], ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="turno" value="M">
        <div
            data-vue-island="AvailabilitySchedule"
            data-prop-state="<?= htmlspecialchars(json_encode($scheduleState, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8') ?>"
        >
        <fieldset class="availability-schedule__indifferent">
            <legend>Preferencia horaria</legend>
            <input type="hidden" name="es_indiferente" value="0">
            <label class="availability-toggle-card <?= $scheduleState['esIndiferente'] ? 'is-enabled' : '' ?>">
                <span class="availability-toggle-card__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <circle cx="12" cy="12" r="9"></circle>
                        <path d="M12 7v5l3 2"></path>
                    </svg>
                </span>
                <span class="availability-toggle-card__copy">
                    <strong>Disponibilidad indiferente</strong>
                    <small>Se aceptan turnos de mañana, tarde o partido.</small>
                </span>
                <input class="availability-toggle-card__input" type="checkbox" name="es_indiferente" value="1" <?= $scheduleState['esIndiferente'] ? 'checked' : '' ?>>
                <span class="availability-toggle-card__switch" aria-hidden="true"><span></span></span>
            </label>
        </fieldset>
        <fieldset class="availability-schedule">
            <legend>Disponibilidad semanal</legend>
            <p>Selecciona los días y el horario en el que puedes realizar las prácticas cada semana.</p>
            <p>Define franjas exactas por día. Para aplicar un horario a varios días, usa el selector de horario general cuando JavaScript esté disponible.</p>
            <p class="availability-schedule__selection" role="status" aria-live="polite">
                <?php if ($scheduleState['esIndiferente']): ?>Aplicable a cualquier horario.
                <?php else: ?><?= $selectedScheduleDays === 1 ? '1 día seleccionado' : $selectedScheduleDays . ' días seleccionados' ?><?php endif; ?>
            </p>
            <div class="availability-schedule__table-wrap" role="region" aria-label="Horario semanal de disponibilidad" tabindex="0">
                <table class="availability-schedule__table">
                    <thead><tr><th>Día</th><th>Disponible</th><th>Desde</th><th>Hasta</th></tr></thead>
                    <tbody><?php foreach ($scheduleState['days'] as $day): ?>
                        <tr>
                            <th scope="row"><?= htmlspecialchars($day['name'], ENT_QUOTES, 'UTF-8') ?></th>
                            <td><input type="checkbox" name="horarios[<?= $day['day'] ?>][activo]" value="1" aria-label="<?= htmlspecialchars($day['name'], ENT_QUOTES, 'UTF-8') ?>" <?= $day['active'] ? 'checked' : '' ?> <?= $scheduleState['esIndiferente'] ? 'disabled' : '' ?>></td>
                            <td><input type="time" step="300" name="horarios[<?= $day['day'] ?>][hora_inicio]" value="<?= htmlspecialchars($day['start'], ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($day['name'] . ', hora de inicio', ENT_QUOTES, 'UTF-8') ?>" <?= $scheduleState['esIndiferente'] || !$day['active'] ? 'disabled' : '' ?>></td>
                            <td><input type="time" step="300" name="horarios[<?= $day['day'] ?>][hora_fin]" value="<?= htmlspecialchars($day['end'], ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($day['name'] . ', hora de fin', ENT_QUOTES, 'UTF-8') ?>" <?= $scheduleState['esIndiferente'] || !$day['active'] ? 'disabled' : '' ?>></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table>
            </div>
        </fieldset>
        </div>
        <label for="availability-start">Desde
            <input id="availability-start" type="date" name="fecha_inicio" value="<?= htmlspecialchars($values['fecha_inicio'], ENT_QUOTES, 'UTF-8') ?>" required>
        </label>
        <label for="availability-end">Hasta
            <input id="availability-end" type="date" name="fecha_fin" value="<?= htmlspecialchars($values['fecha_fin'], ENT_QUOTES, 'UTF-8') ?>" required>
        </label>
        <button type="submit"><?= $disponibilidades === [] ? 'Guardar disponibilidad' : 'Actualizar disponibilidad' ?></button>
    </form>
    <?php
    $savedScheduleJson = json_encode(
        $savedSchedule,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
    );
    ?>
    <div
        data-vue-island="SavedAvailability"
        data-prop-state="<?= htmlspecialchars($savedScheduleJson, ENT_QUOTES, 'UTF-8') ?>"
    >
        <section class="availability-page__current" aria-labelledby="availability-current-title">
            <h3 id="availability-current-title">Horario semanal guardado</h3>
            <?php if ($values['fecha_inicio'] !== '' && $values['fecha_fin'] !== ''): ?>
                <p>Registrado de <?= htmlspecialchars($values['fecha_inicio'], ENT_QUOTES, 'UTF-8') ?> a <?= htmlspecialchars($values['fecha_fin'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php else: ?>
                <p>Aún no has registrado disponibilidad.</p>
            <?php endif; ?>
            <?php if ($values['es_indiferente']): ?>
                <p>Disponible para cualquier horario.</p>
            <?php elseif ($horarioSemanal !== []): ?>
                <ul class="student-portal__list">
                    <?php foreach ($savedSchedule['dias'] as $day): ?>
                        <li><strong><?= htmlspecialchars($day['dia'], ENT_QUOTES, 'UTF-8') ?></strong><span><?= htmlspecialchars(substr($day['hora_inicio'], 0, 5) . '–' . substr($day['hora_fin'], 0, 5), ENT_QUOTES, 'UTF-8') ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php elseif ($values['fecha_inicio'] !== ''): ?>
                <p>No hay franjas semanales guardadas.</p>
            <?php endif; ?>
        </section>
    </div>
    <a class="student-portal__text-action" href="index.php?action=portal-alumno">Volver a mi expediente</a>
</section>
