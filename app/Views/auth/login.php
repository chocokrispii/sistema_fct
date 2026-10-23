<?php $csrfToken = CsrfMiddleware::token(); ?>
<section class="login-page login-container" aria-labelledby="login-title">
    <form class="page-form page-form--narrow login-card" method="POST" action="index.php?action=login"<?= $error !== '' ? ' aria-describedby="login-error"' : '' ?>>
        <h2 id="login-title">Iniciar sesión</h2>
        <?php if ($error !== ''): ?>
            <p id="login-error" class="message message-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if (isset($_GET['registrada']) || isset($_GET['registrado'])): ?>
            <p class="message message-success" role="status">Registro completado. Ya puedes iniciar sesión.</p>
        <?php endif; ?>
        <?php if (isset($_GET['restablecida'])): ?>
            <p class="message message-success" role="status">Contraseña actualizada. Ya puedes iniciar sesión.</p>
        <?php endif; ?>
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <?php if (FrontendAssets::isEnabled()): ?>
            <div data-vue-island="AuthLoginEnhancement" data-prop-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"></div>
        <?php endif; ?>
        <label for="login-email">Correo electrónico</label>
        <input id="login-email" type="email" name="email" autocomplete="username" value="<?= htmlspecialchars($email ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
        <label for="login-password">Contraseña</label>
        <input id="login-password" type="password" name="password" maxlength="72" autocomplete="current-password" aria-describedby="login-password-help" required>
        <small id="login-password-help">Máximo 72 bytes; los caracteres acentuados pueden ocupar más de un byte.</small>
        <button type="submit">Entrar</button>
        <p class="login-page__recovery login-card__recovery"><a href="index.php?action=recuperar">¿Has olvidado la contraseña?</a></p>
    </form>
</section>
<script src="assets/js/password-toggle.js" defer></script>
