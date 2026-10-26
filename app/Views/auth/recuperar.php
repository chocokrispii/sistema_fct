<?php if ($error !== ''): ?>
    <p class="message message-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if (isset($_GET['enviada'])): ?>
    <p class="message message-success" role="status">Su solicitud de restablecimiento ha sido enviada al Administrador. Recibirá un correo electrónico con las instrucciones una vez que sea aprobada.</p>
<?php endif; ?>
<h2>Recuperar la cuenta</h2>
<p>El Administrador revisará la solicitud. Si la aprueba, recibirás por correo un enlace de un solo uso válido durante 24 horas.</p>
<form class="page-form page-form--narrow" method="POST" action="index.php?action=recuperar">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf ?? CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
    <?php if (FrontendAssets::isEnabled()): ?>
        <div data-vue-island="OlvidePassword" data-prop-csrf-token="<?= htmlspecialchars($csrf ?? CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>"></div>
    <?php endif; ?>
    <label for="recuperar-email">Correo de la cuenta</label>
    <input id="recuperar-email" type="email" name="email" maxlength="100" required>
    <button type="submit">Solicitar recuperación</button>
</form>
