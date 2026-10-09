<?php if ($error !== ''): ?><p class="message message-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<h2>Gestión de cuentas</h2>
<form class="page-form page-form--narrow" method="POST" action="index.php?action=usuarios">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
    <label>Correo <input type="email" name="email" required></label>
    <label>Contraseña inicial (12-72 caracteres) <input type="password" name="password" minlength="12" maxlength="72" aria-describedby="staff-password-help" required></label>
    <small id="staff-password-help">Debe tener entre 12 y 72 caracteres. Los espacios cuentan.</small>
    <label>Nombre (para coordinador) <input type="text" name="nombre"></label>
    <label>Apellidos (para coordinador) <input type="text" name="apellidos"></label>
    <label>Rol
        <select name="rol" required>
            <option value="Coordinador">Coordinador</option>
            <option value="Observador">Observador</option>
            <option value="Administrador">Administrador</option>
        </select>
    </label>
    <button type="submit">Crear cuenta del centro</button>
</form>
<table>
    <thead><tr><th>Correo</th><th>Rol</th><th>Estado</th><th>Acción</th></tr></thead>
    <tbody><?php foreach ($usuarios as $usuario): ?>
        <tr>
            <td><?= htmlspecialchars($usuario['email'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($usuario['rol'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= (int)$usuario['activo'] === 1 ? 'Activo' : 'Desactivado' ?></td>
            <td><form class="action-form" method="POST" action="index.php?action=estado-usuario">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="usuario_id" value="<?= (int)$usuario['id'] ?>">
                <input type="hidden" name="activo" value="<?= (int)$usuario['activo'] === 1 ? '0' : '1' ?>">
                <button type="submit"><?= (int)$usuario['activo'] === 1 ? 'Desactivar' : 'Activar' ?></button>
            </form></td>
        </tr>
    <?php endforeach; ?></tbody>
</table>
