<?php
/** @var array $especialidades Filas con `id` y `nombre_especialidad` */
$selectId = isset($especialidadSelectId) ? $especialidadSelectId : 'especialidad-id';
$selectedCycle = (string)($especialidadSeleccionada ?? '');
$cycleChoices = array_map(
    static fn(array $especialidad): array => [
        'id' => (int)$especialidad['id'],
        'nombre_especialidad' => (string)$especialidad['nombre_especialidad'],
    ],
    $especialidades
);
?>
<div
    data-vue-island="CurriculumSelect"
    data-prop-choices="<?= htmlspecialchars(json_encode($cycleChoices, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8') ?>"
    data-prop-selected="<?= htmlspecialchars($selectedCycle, ENT_QUOTES, 'UTF-8') ?>"
    data-prop-select-id="<?= htmlspecialchars($selectId, ENT_QUOTES, 'UTF-8') ?>"
>
    <select id="<?= htmlspecialchars($selectId, ENT_QUOTES, 'UTF-8') ?>" name="especialidad_id" required>
        <option value="">-- Selecciona un ciclo formativo --</option>
        <?php foreach ($cycleChoices as $especialidad): ?>
            <option value="<?= $especialidad['id'] ?>" <?= $selectedCycle === (string)$especialidad['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($especialidad['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>
