<?php
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<h2>Alumnado</h2>
<p>Consulta, filtra y edita los expedientes existentes. Para crear una cuenta, utiliza <a href="index.php?action=alta-alumno&tab=altas">Altas &gt; Alumnado</a>.</p>

<?php if ($error !== ''): ?>
    <p class="message message-error" role="alert"><?= $escape($error) ?></p>
<?php endif; ?>
<?php if ($actualizado): ?>
    <p class="message message-success" role="status">El expediente se ha actualizado correctamente.</p>
<?php endif; ?>

<form class="page-form" method="GET" action="index.php">
    <input type="hidden" name="action" value="alumnos-centro">
    <input type="hidden" name="tab" value="principal">
    <label for="student-search">Buscar por documento, nombre, correo o ciclo</label>
    <input id="student-search" type="search" name="q" value="<?= $escape($search) ?>" maxlength="100">
    <label for="student-cycle-filter">Ciclo formativo</label>
    <select id="student-cycle-filter" name="ciclo">
        <option value="">Todos los ciclos</option>
        <?php foreach ($especialidades as $especialidad): ?>
            <option value="<?= (int)$especialidad['id'] ?>" <?= (string)$cycleId === (string)$especialidad['id'] ? 'selected' : '' ?>>
                <?= $escape($especialidad['nombre_especialidad']) ?><?= (int)$especialidad['activo'] === 1 ? '' : ' (inactivo)' ?>
            </option>
        <?php endforeach; ?>
    </select>
    <label for="student-status-filter">Estado de la cuenta</label>
    <select id="student-status-filter" name="estado">
        <option value="">Todos los estados</option>
        <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Activo</option>
        <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Desactivado</option>
    </select>
    <button type="submit">Aplicar filtros</button>
    <a href="index.php?action=alumnos-centro&tab=principal">Limpiar filtros</a>
</form>

<table>
    <thead>
        <tr><th>Documento</th><th>Nombre</th><th>Correo</th><th>Ciclo</th><th>Curso</th><th>Estado</th><th>Expediente</th></tr>
    </thead>
    <tbody>
        <?php foreach ($alumnos as $alumno): ?>
            <tr>
                <td><?= $escape($alumno['tipo_documento'] === 'PASAPORTE' ? 'Pasaporte' : 'NIF / NIE / DNI') ?>: <?= $escape($alumno['dni']) ?></td>
                <td><?= $escape($alumno['nombre'] . ' ' . $alumno['apellidos']) ?></td>
                <td><?= $escape($alumno['correo']) ?></td>
                <td><?= $escape($alumno['nombre_especialidad'] ?? '—') ?></td>
                <td><?= $alumno['curso'] === null ? '—' : (int)$alumno['curso'] . '.º' ?></td>
                <td><?= (int)$alumno['activo'] === 1 ? 'Activo' : 'Desactivado' ?></td>
                <td>
                    <button
                        class="student-edit-trigger"
                        type="button"
                        data-edit-student="<?= (int)$alumno['id'] ?>"
                        aria-haspopup="dialog"
                        aria-controls="student-edit-dialog-<?= (int)$alumno['id'] ?>"
                    >Editar</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($alumnos === []): ?>
            <tr><td colspan="7">No hay expedientes que coincidan con los filtros.</td></tr>
        <?php endif; ?>
    </tbody>
</table>

<?php foreach ($alumnos as $alumno): ?>
    <?php $studentId = (int)$alumno['id']; ?>
    <dialog
        class="student-edit-dialog"
        id="student-edit-dialog-<?= $studentId ?>"
        aria-labelledby="student-edit-title-<?= $studentId ?>"
        data-student-edit-dialog
    >
        <div class="student-edit-dialog__header">
            <div>
                <h3 id="student-edit-title-<?= $studentId ?>">Editar expediente</h3>
                <p><?= $escape($alumno['nombre'] . ' ' . $alumno['apellidos']) ?></p>
            </div>
            <button class="student-edit-dialog__close" type="button" data-close-student-dialog aria-label="Cerrar">×</button>
        </div>
        <form class="student-edit-form" method="POST" action="index.php?action=actualizar-alumno-centro">
            <input type="hidden" name="_csrf" value="<?= $escape(CsrfMiddleware::token()) ?>">
            <input type="hidden" name="alumno_id" value="<?= $studentId ?>">
            <label for="student-document-type-<?= $studentId ?>">Tipo de documento
                <select id="student-document-type-<?= $studentId ?>" name="tipo_documento" required>
                    <option value="NIF_NIE_DNI" <?= $alumno['tipo_documento'] === 'NIF_NIE_DNI' ? 'selected' : '' ?>>NIF / NIE / DNI</option>
                    <option value="PASAPORTE" <?= $alumno['tipo_documento'] === 'PASAPORTE' ? 'selected' : '' ?>>Pasaporte</option>
                </select>
            </label>
            <label for="student-document-<?= $studentId ?>">Número de documento
                <input id="student-document-<?= $studentId ?>" type="text" name="dni" maxlength="20" value="<?= $escape($alumno['dni']) ?>" required>
            </label>
            <label for="student-name-<?= $studentId ?>">Nombre
                <input id="student-name-<?= $studentId ?>" type="text" name="nombre" maxlength="50" value="<?= $escape($alumno['nombre']) ?>" required>
            </label>
            <label for="student-surname-<?= $studentId ?>">Apellidos
                <input id="student-surname-<?= $studentId ?>" type="text" name="apellidos" maxlength="100" value="<?= $escape($alumno['apellidos']) ?>" required>
            </label>
            <label for="student-email-<?= $studentId ?>">Correo electrónico
                <input id="student-email-<?= $studentId ?>" type="email" name="email" maxlength="100" value="<?= $escape($alumno['correo']) ?>" required>
            </label>
            <label for="student-cycle-<?= $studentId ?>">Ciclo formativo
                <select id="student-cycle-<?= $studentId ?>" name="especialidad_id" required>
                    <?php foreach ($especialidades as $especialidad): ?>
                        <option value="<?= (int)$especialidad['id'] ?>" <?= (string)$alumno['especialidad_id'] === (string)$especialidad['id'] ? 'selected' : '' ?>>
                            <?= $escape($especialidad['nombre_especialidad']) ?><?= (int)$especialidad['activo'] === 1 ? '' : ' (inactivo)' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label for="student-course-<?= $studentId ?>">Curso
                <select id="student-course-<?= $studentId ?>" name="curso" required>
                    <option value="1" <?= (string)$alumno['curso'] === '1' ? 'selected' : '' ?>>1.º</option>
                    <option value="2" <?= (string)$alumno['curso'] === '2' ? 'selected' : '' ?>>2.º</option>
                </select>
            </label>
            <label class="student-edit-form__notes" for="student-notes-<?= $studentId ?>">Observaciones administrativas
                <textarea id="student-notes-<?= $studentId ?>" name="observaciones_administrativas" maxlength="2000" rows="4"><?= $escape($alumno['observaciones_administrativas'] ?? '') ?></textarea>
            </label>
            <div class="student-edit-form__actions">
                <button class="student-edit-form__cancel" type="button" data-close-student-dialog>Cancelar</button>
                <button type="submit">Guardar cambios</button>
            </div>
        </form>
    </dialog>
<?php endforeach; ?>
<script src="assets/js/admin-students.js" defer></script>
