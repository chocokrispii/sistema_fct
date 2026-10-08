<?php if ($error !== ''): ?>
    <p class="message message-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if (isset($_GET['enviada'])): ?>
    <p class="message message-success">Si el correo está registrado, el centro verificará la solicitud y te dará un enlace para elegir una contraseña nueva.</p>
<?php endif; ?>
<h2>Recuperar la cuenta</h2>
<p>El centro comprueba tu identidad y, si la solicitud es correcta, te da un enlace de un solo uso.</p>
<form method="POST" action="index.php?action=recuperar">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
    <label for="recuperar-email">Correo de la cuenta</label>
    <input id="recuperar-email" type="email" name="email" maxlength="100" required>
    <button type="submit">Solicitar recuperación</button>
</form>
