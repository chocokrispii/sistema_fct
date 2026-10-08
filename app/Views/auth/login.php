<?php if ($error !== ''): ?>
    <p class="message message-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if (isset($_GET['registrada']) || isset($_GET['registrado'])): ?>
    <p class="message message-success">Registro completado. Ya puedes iniciar sesión.</p>
<?php endif; ?>
<h2>Iniciar sesión</h2>
<form method="POST" action="index.php?action=login">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
    <label for="login-email">Correo electrónico</label>
    <input id="login-email" type="email" name="email" autocomplete="username" required>
    <label for="login-password">Contraseña</label>
    <input id="login-password" type="password" name="password" autocomplete="current-password" required>
    <button type="submit">Entrar</button>
</form>
