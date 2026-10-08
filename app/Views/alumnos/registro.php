<?php if ($error !== ''): ?>
    <p class="message message-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<h2>Registro de alumno</h2>
<form method="POST" action="index.php?action=registro-alumno">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
    <label>Nombre <input type="text" name="nombre" required></label>
    <label>Apellidos <input type="text" name="apellidos" required></label>
    <label>DNI/NIE <input type="text" name="dni" required></label>
    <label>Correo <input type="email" name="email" required></label>
    <label>Contraseña (12-72 bytes) <input type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required></label>
    <label>Número de Seguridad Social <input type="text" name="nss" required></label>
    <label>Teléfono <input type="text" name="telefono" required></label>
    <label>Dirección <input type="text" name="direccion" required></label>
    <label>Fecha de nacimiento <input type="date" name="fecha_nacimiento" required></label>
    <label>Ciclo formativo</label>
    <?php require __DIR__ . '/../partials/select_especialidades.php'; ?>
    <fieldset>
        <legend>Datos del tutor legal (obligatorios si el alumno es menor de 18 años)</legend>
        <label>Nombre completo <input type="text" name="tutor_nombre"></label>
        <label>DNI/NIE <input type="text" name="tutor_dni"></label>
        <label>Número de Seguridad Social <input type="text" name="tutor_nss"></label>
        <label>Correo <input type="email" name="tutor_correo"></label>
        <label>Dirección <input type="text" name="tutor_direccion"></label>
        <label>Teléfono <input type="text" name="tutor_telefono"></label>
    </fieldset>
    <button type="submit">Registrar alumno</button>
</form>
