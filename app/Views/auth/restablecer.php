<?php if ($error !== ''): ?>
    <p class="message message-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<h2>Nueva contraseña</h2>
<?php if ($token !== ''): ?>
<form class="page-form page-form--narrow" method="POST" action="index.php?action=restablecer">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
    <label for="nueva-password">Contraseña (12-72 caracteres)</label>
    <input id="nueva-password" type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required>
    <small>Debe tener entre 12 y 72 caracteres. Los espacios cuentan.</small>
    <button type="submit">Guardar contraseña</button>
</form>
<?php endif; ?>
