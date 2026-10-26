<?php if ($error !== ''): ?>
    <p class="message message-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if (isset($_GET['registrada'])): ?>
    <p class="message message-success">Solicitud registrada. Un administrador debe validar el alta antes de que puedas activar y utilizar la cuenta.</p>
<?php elseif (isset($_GET['registrado'])): ?>
    <p class="message message-success">Registro completado. Ya puedes iniciar sesión.</p>
<?php endif; ?>
<?php if (isset($_GET['restablecida'])): ?>
    <p class="message message-success">Contraseña actualizada. Ya puedes iniciar sesión.</p>
<?php endif; ?>
<h2>Iniciar sesión</h2>
<?php $csrfToken = CsrfMiddleware::token(); ?>
<form class="page-form page-form--narrow" method="POST" action="index.php?action=login">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <label for="login-identifier">NIF o pasaporte</label>
    <input id="login-identifier" type="text" name="identifier" autocomplete="username" required>
    <small>Introduce tu NIF o pasaporte. El acceso con correo electrónico no está permitido.</small>
    <label for="login-password">Contraseña</label>
    <input id="login-password" type="password" name="password" maxlength="72" autocomplete="current-password" aria-describedby="login-password-help" required>
    <small id="login-password-help">Máximo 72 caracteres, igual que al registrarse. Los espacios cuentan.</small>
    <button type="submit">Entrar</button>
</form>
<p><a href="index.php?action=recuperar">¿Has olvidado la contraseña?</a></p>
