<?php
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<h2>Alta de alumnado por el centro</h2>
<p>Solo el personal autorizado puede crear estas cuentas. El alumnado no dispone de un formulario público de alta; al guardar, recibirá un enlace de activación por correo.</p>

<?php if ($error !== ''): ?>
    <p class="message message-error" role="alert"><?= $escape($error) ?></p>
<?php endif; ?>

<?php if ($creado): ?>
    <section class="message message-success" role="status">
        <h3>Cuenta creada y correo enviado</h3>
        <p>Se ha enviado a <strong><?= $escape($sentTo) ?></strong> un enlace seguro para elegir contraseña y activar la cuenta.</p>
        <p>El enlace caduca en 24 horas y solo se puede utilizar una vez.</p>
    </section>
<?php endif; ?>

<form class="page-form page-form--narrow" method="POST" action="index.php?action=alumnos-centro">
    <input type="hidden" name="_csrf" value="<?= $escape(CsrfMiddleware::token()) ?>">

    <label for="student-document-type">Tipo de documento</label>
    <select id="student-document-type" name="tipo_documento" aria-describedby="student-document-help" required>
        <option value="NIF_NIE_DNI" <?= ($values['tipo_documento'] ?? 'NIF_NIE_DNI') === 'NIF_NIE_DNI' ? 'selected' : '' ?>>NIF</option>
        <option value="PASAPORTE" <?= ($values['tipo_documento'] ?? '') === 'PASAPORTE' ? 'selected' : '' ?>>Pasaporte</option>
    </select>
    <label for="student-dni">Número de documento</label>
    <input id="student-dni" type="text" name="dni" maxlength="20" autocomplete="off" required value="<?= $escape($values['dni'] ?? '') ?>">
    <small id="student-document-help">Opción destinada a casos especiales de alumnado no residente según la normativa de Formación Profesional.</small>

    <label for="student-name">Nombre</label>
    <input id="student-name" type="text" name="nombre" maxlength="50" autocomplete="given-name" required value="<?= $escape($values['nombre'] ?? '') ?>">

    <label for="student-surname">Apellidos</label>
    <input id="student-surname" type="text" name="apellidos" maxlength="100" autocomplete="family-name" required value="<?= $escape($values['apellidos'] ?? '') ?>">

    <label for="student-email">Correo electrónico</label>
    <input id="student-email" type="email" name="email" maxlength="100" autocomplete="email" required value="<?= $escape($values['email'] ?? '') ?>">

    <label for="student-cycle">Ciclo formativo</label>
    <select id="student-cycle" name="especialidad_id" required>
        <option value="">Selecciona un ciclo</option>
        <?php foreach ($especialidades as $especialidad): ?>
            <option
                value="<?= (int)$especialidad['id'] ?>"
                <?= (string)($values['especialidad_id'] ?? '') === (string)$especialidad['id'] ? 'selected' : '' ?>
            ><?= $escape($especialidad['nombre_especialidad']) ?></option>
        <?php endforeach; ?>
    </select>

    <label for="student-course">Curso</label>
    <select id="student-course" name="curso" required>
        <option value="">Selecciona el curso</option>
        <option value="1" <?= ($values['curso'] ?? '') === '1' ? 'selected' : '' ?>>1.º</option>
        <option value="2" <?= ($values['curso'] ?? '') === '2' ? 'selected' : '' ?>>2.º</option>
    </select>

    <label for="student-notes">Observaciones administrativas</label>
    <textarea id="student-notes" name="observaciones_administrativas" maxlength="2000" rows="4"><?= $escape($values['observaciones_administrativas'] ?? '') ?></textarea>

    <button type="submit">Crear cuenta de alumno</button>
</form>

<h3>Alumnado registrado</h3>
<table>
    <thead>
        <tr><th>Tipo y número de documento</th><th>Nombre</th><th>Correo</th><th>Ciclo</th><th>Curso</th><th>Observaciones administrativas</th><th>Estado</th></tr>
    </thead>
    <tbody>
        <?php foreach ($alumnos as $alumno): ?>
            <tr>
                <td><?= $escape($alumno['tipo_documento'] === 'PASAPORTE' ? 'Pasaporte' : 'NIF') ?>: <?= $escape($alumno['dni']) ?></td>
                <td><?= $escape($alumno['nombre'] . ' ' . $alumno['apellidos']) ?></td>
                <td><?= $escape($alumno['correo']) ?></td>
                <td><?= $escape($alumno['nombre_especialidad'] ?? '—') ?></td>
                <td><?= $alumno['curso'] === null ? '—' : (int)$alumno['curso'] . '.º' ?></td>
                <td><?= $escape($alumno['observaciones_administrativas'] ?? '—') ?></td>
                <td><?= (int)$alumno['activo'] === 1 ? 'Activo' : 'Desactivado' ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
