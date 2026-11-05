<?php
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<h2>Alta de personal del centro</h2>
<p>Se enviará un enlace de un solo uso y válido durante 24 horas para que la persona establezca su contraseña y active la cuenta.</p>
<p><a href="index.php?action=usuarios">Volver a gestión de cuentas</a></p>

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

<form class="page-form page-form--narrow" method="POST" action="index.php?action=alta-personal&tab=altas">
    <input type="hidden" name="_csrf" value="<?= $escape(CsrfMiddleware::token()) ?>">
    <label for="staff-email">Correo</label>
    <input id="staff-email" type="email" name="email" maxlength="100" required>
    <label for="staff-document">NIF / NIE / DNI (identificador de acceso)</label>
    <input id="staff-document" type="text" name="identificador_fiscal" maxlength="20" autocomplete="off" required>
    <small>Debe tener una letra de control válida y no puede estar asignado a otra cuenta.</small>
    <label for="staff-name">Nombre</label>
    <input id="staff-name" type="text" name="nombre" maxlength="50" autocomplete="given-name" required>
    <label for="staff-surname">Apellidos</label>
    <input id="staff-surname" type="text" name="apellidos" maxlength="100" autocomplete="family-name" required>
    <label for="staff-role">Rol
        <select id="staff-role" name="rol" required>
            <option value="Coordinador">Coordinador</option>
            <option value="Observador">Observador</option>
            <option value="Administrador">Administrador</option>
        </select>
    </label>
    <button type="submit">Crear cuenta del centro</button>
</form>
