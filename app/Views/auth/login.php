<?php if ($error !== ''): ?>
    <p class="message message-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if (isset($_GET['registrada']) || isset($_GET['registrado'])): ?>
    <p class="message message-success">Registro completado. Ya puedes iniciar sesión.</p>
<?php endif; ?>
<?php if (isset($_GET['restablecida'])): ?>
    <p class="message message-success">Contraseña actualizada. Ya puedes iniciar sesión.</p>
<?php endif; ?>
<h2>Iniciar sesión</h2>
<form class="page-form page-form--narrow" method="POST" action="index.php?action=login">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
    <label for="login-email">Correo electrónico</label>
    <input id="login-email" type="email" name="email" autocomplete="username" required>
    <label for="login-password">Contraseña</label>
    <input id="login-password" type="password" name="password" maxlength="72" autocomplete="current-password" aria-describedby="login-password-help" required>
    <label><input type="checkbox" data-password-toggle="login-password" aria-controls="login-password"> Mostrar contraseña</label>
    <small id="login-password-help">Máximo 72 caracteres, igual que al registrarse. Los espacios cuentan.</small>
    <button type="submit">Entrar</button>
</form>
<p><a href="index.php?action=recuperar">¿Has olvidado la contraseña?</a></p>
<script src="assets/js/password-toggle.js" defer></script>
