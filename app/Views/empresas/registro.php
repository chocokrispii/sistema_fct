<?php if ($error !== ''): ?>
    <p class="message message-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<h2>Registro de empresa</h2>
<form method="POST" action="index.php?action=registro-empresa">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
    <label>Correo de acceso <input type="email" name="email" required></label>
    <label>Contraseña (12-72 bytes) <input type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required></label>
    <label>Razón social <input type="text" name="nombre_comercial" required></label>
    <label>CIF <input type="text" name="cif" required></label>
    <label>Persona de contacto <input type="text" name="persona_contacto"></label>
    <label>Teléfono <input type="text" name="telefono"></label>
    <label>Dirección principal <input type="text" name="direccion" required></label>
    <button type="submit">Registrar empresa</button>
</form>
