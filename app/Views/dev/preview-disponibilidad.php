<section class="availability-page" aria-labelledby="availability-title">
    <div class="availability-page__intro">
        <span class="student-registration__eyebrow">Vista previa de desarrollo</span>
        <h2 id="availability-title">Mi disponibilidad para FCT</h2>
        <p>Prueba el horario general, la disponibilidad indiferente y la edición por día con datos de ejemplo. Los cambios de esta vista previa no se guardan.</p>
    </div>
    <?php
    $serializedState = json_encode(
        $state,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
    );
    ?>
    <form class="page-form page-form--narrow" method="POST" action="index.php?action=preview-disponibilidad">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($state['csrf'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="turno" value="M">
        <div
            data-vue-island="AvailabilitySchedule"
            data-prop-state="<?= htmlspecialchars($serializedState, ENT_QUOTES, 'UTF-8') ?>"
            data-prop-preview
        >
            <fieldset class="availability-schedule__indifferent">
                <legend>Preferencia horaria</legend>
                <input type="hidden" name="es_indiferente" value="0">
                <label class="availability-toggle-card">
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
                    <input class="availability-toggle-card__input" type="checkbox" name="es_indiferente" value="1">
                    <span class="availability-toggle-card__switch" aria-hidden="true"><span></span></span>
                </label>
            </fieldset>
            <fieldset class="availability-schedule">
                <legend>Disponibilidad semanal</legend>
                <p>Define franjas exactas por día. Puedes probar el horario general con JavaScript o editar cada día directamente.</p>
                <p class="availability-schedule__selection" role="status" aria-live="polite">4 días seleccionados</p>
                <div class="availability-schedule__table-wrap">
                    <table class="availability-schedule__table">
                        <thead><tr><th>Día</th><th>Disponible</th><th>Desde</th><th>Hasta</th></tr></thead>
                        <tbody><?php foreach ($state['days'] as $day): ?>
                            <tr>
                                <th scope="row"><?= htmlspecialchars($day['name'], ENT_QUOTES, 'UTF-8') ?></th>
                                <td><input type="checkbox" name="horarios[<?= $day['day'] ?>][activo]" value="1" aria-label="<?= htmlspecialchars($day['name'], ENT_QUOTES, 'UTF-8') ?>" <?= $day['active'] ? 'checked' : '' ?>></td>
                                <td><input type="time" step="300" name="horarios[<?= $day['day'] ?>][hora_inicio]" value="<?= htmlspecialchars($day['start'], ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($day['name'] . ', hora de inicio', ENT_QUOTES, 'UTF-8') ?>" <?= !$day['active'] ? 'disabled' : '' ?>></td>
                                <td><input type="time" step="300" name="horarios[<?= $day['day'] ?>][hora_fin]" value="<?= htmlspecialchars($day['end'], ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($day['name'] . ', hora de fin', ENT_QUOTES, 'UTF-8') ?>" <?= !$day['active'] ? 'disabled' : '' ?>></td>
                            </tr>
                        <?php endforeach; ?></tbody>
                    </table>
                </div>
            </fieldset>
        </div>
        <label for="preview-availability-start">Desde
            <input id="preview-availability-start" type="date" name="fecha_inicio" value="<?= htmlspecialchars($fechaInicio, ENT_QUOTES, 'UTF-8') ?>" required>
        </label>
        <label for="preview-availability-end">Hasta
            <input id="preview-availability-end" type="date" name="fecha_fin" value="<?= htmlspecialchars($fechaFin, ENT_QUOTES, 'UTF-8') ?>" required>
        </label>
        <button type="submit">Probar guardado local</button>
    </form>
</section>
