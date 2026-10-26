<?php if ($error !== ''): ?>
    <p class="message message-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if (isset($_GET['enviada'])): ?>
    <p class="message message-success" role="status">Si la cuenta existe y está activa, se ha registrado la solicitud para revisión del centro.</p>
<?php endif; ?>
<h2>Recuperar la cuenta</h2>
<p>El Administrador revisará la solicitud. Si la aprueba, recibirás por correo un enlace de un solo uso válido durante <?= (int)($validezHoras ?? SolicitudResetPassword::VALIDEZ_HORAS) ?> horas.</p>
<form class="page-form page-form--narrow" method="POST" action="index.php?action=recuperar">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf ?? CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
    <?php if (FrontendAssets::isEnabled()): ?>
        <div data-vue-island="OlvidePassword" data-prop-csrf-token="<?= htmlspecialchars($csrf ?? CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>"></div>
    <?php endif; ?>
    <label for="recuperar-identificador">Correo electrónico o DNI/NIE/NIF de la cuenta</label>
    <input id="recuperar-identificador" type="text" name="identifier" maxlength="100" autocomplete="username" required>
    <button type="submit">Solicitar recuperación</button>
</form>
