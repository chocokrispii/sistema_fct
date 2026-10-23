<?php $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>
<h2>Activar cuenta</h2>

<?php if ($activated): ?>
    <p class="message message-success" role="status">Tu cuenta está activada. Ya puedes iniciar sesión con tu identificador fiscal y la contraseña elegida.</p>
    <p><a href="index.php?action=login">Iniciar sesión</a></p>
<?php else: ?>
    <?php if ($requestSent): ?>
        <p class="message message-success" role="status">Si los datos corresponden a una cuenta pendiente de activación, el Centro revisará la solicitud antes de enviar un nuevo enlace.</p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p class="message message-error" role="alert"><?= $escape($error) ?></p>
    <?php endif; ?>
    <?php if ($token !== ''): ?>
        <p>Elige una contraseña nueva para activar tu acceso. Usa al menos 12 caracteres.</p>
        <form class="page-form page-form--narrow" method="POST" action="index.php?action=activar-cuenta">
            <input type="hidden" name="_csrf" value="<?= $escape(CsrfMiddleware::token()) ?>">
            <input type="hidden" name="token" value="<?= $escape($token) ?>">
            <label for="activation-password">Nueva contraseña</label>
            <input id="activation-password" type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required>
            <label for="activation-password-confirmation">Repite la contraseña</label>
            <input id="activation-password-confirmation" type="password" name="password_confirmation" minlength="12" maxlength="72" autocomplete="new-password" required>
            <button type="submit">Activar cuenta</button>
        </form>
    <?php elseif ($showResendRequest): ?>
        <p>Solicitar nuevo enlace al Centro. La solicitud quedará pendiente hasta su revisión por un administrador.</p>
        <form class="page-form page-form--narrow" method="POST" action="index.php?action=solicitar-reenvio-activacion">
            <input type="hidden" name="_csrf" value="<?= $escape(CsrfMiddleware::token()) ?>">
            <label for="activation-email">Correo de la cuenta</label>
            <input id="activation-email" type="email" name="email" maxlength="100" autocomplete="email" required>
            <label for="activation-identifier">Documento de identidad</label>
            <input id="activation-identifier" type="text" name="identificador_fiscal" maxlength="20" autocomplete="off" required>
            <small>Alumnado: NIF / NIE / DNI o pasaporte. Personal: NIF / NIE / DNI. Empresa: NIF (Persona Jurídica).</small>
            <button type="submit">Solicitar nuevo enlace al Centro</button>
        </form>
    <?php endif; ?>
<?php endif; ?>
