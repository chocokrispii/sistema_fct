<?php
/** @var array $especialidades Filas con `id` y `nombre_especialidad` */
?>
<select id="<?= isset($especialidadSelectId) ? htmlspecialchars($especialidadSelectId, ENT_QUOTES, 'UTF-8') : 'especialidad-id' ?>" name="especialidad_id" required>
    <option value="">-- Selecciona una especialidad --</option>
    <?php foreach ($especialidades as $especialidad): ?>
        <option value="<?= (int)$especialidad['id'] ?>">
            <?= htmlspecialchars($especialidad['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?>
        </option>
    <?php endforeach; ?>
</select>
