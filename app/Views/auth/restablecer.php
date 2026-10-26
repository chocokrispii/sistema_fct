<?php if ($error !== ''): ?>
    <p id="reset-password-error" class="message message-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<h2>Nueva contraseña</h2>
<?php if (($canReset ?? false) === true): ?>
<form class="page-form page-form--narrow" method="POST" action="index.php?action=restablecer"<?= $error !== '' ? ' aria-describedby="reset-password-error"' : '' ?>>
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="token" value="<?= htmlspecialchars($token ?? '', ENT_QUOTES, 'UTF-8') ?>">
    <?php if (FrontendAssets::isEnabled()): ?>
        <div
            data-vue-island="RestablecerPassword"
            data-prop-csrf-token="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>"
            data-prop-token="<?= htmlspecialchars($token ?? '', ENT_QUOTES, 'UTF-8') ?>"
        ></div>
    <?php endif; ?>
    <label for="nueva-password">Nueva contraseña</label>
    <input id="nueva-password" type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="reset-password-help" required>
    <small id="reset-password-help">12-72 bytes; incluye mayúscula, minúscula y número. El enlace vence a las <?= (int)($validezHoras ?? SolicitudResetPassword::VALIDEZ_HORAS) ?> horas.</small>
    <label for="confirmar-password">Confirmar nueva contraseña</label>
    <input id="confirmar-password" type="password" name="confirm_password" minlength="12" maxlength="72" autocomplete="new-password" required>
    <button type="submit">Guardar contraseña</button>
</form>
<?php else: ?>
    <p>Solicita un nuevo enlace si el Administrador ya aprobó tu solicitud y el anterior ha caducado.</p>
<?php endif; ?>
