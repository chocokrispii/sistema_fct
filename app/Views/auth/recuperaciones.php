<?php if ($error): ?>
    <p class="message message-error" role="alert">La solicitud ya no está pendiente o no se pudo enviar el correo. Revisa la configuración de correo e inténtalo de nuevo.</p>
<?php endif; ?>
<?php if ($aprobada): ?>
    <p class="message message-success" role="status">La solicitud se aprobó y el enlace de 24 horas se envió por correo.</p>
<?php endif; ?>
<?php if ($rechazada): ?>
    <p class="message message-success" role="status">La solicitud se rechazó correctamente.</p>
<?php endif; ?>
<section class="password-reset-requests" data-password-reset-requests aria-labelledby="password-reset-requests-title">
<h2 id="password-reset-requests-title">Solicitudes de restablecimiento pendientes</h2>
<p>Al aprobar, se envía al correo de la cuenta un enlace de un solo uso válido durante 24 horas.</p>
<?php if ($recuperaciones === []): ?>
    <p class="password-reset-requests__empty">No hay solicitudes pendientes.</p>
<?php else: ?>
<div class="table-responsive" role="region" aria-label="Recuperaciones pendientes" tabindex="0">
<table>
    <thead><tr><th>Usuario</th><th>Correo</th><th>Fecha de solicitud</th><th>Acciones</th></tr></thead>
    <tbody>
        <?php foreach ($recuperaciones as $recuperacion): ?>
            <tr>
                <td><?= (int)$recuperacion['usuario_id'] ?></td>
                <td><?= htmlspecialchars($recuperacion['email'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($recuperacion['solicitado_en'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <form method="POST" action="index.php?action=aprobar-reset-password" class="inline-form" data-reset-password-action="approve">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="id" value="<?= (int)$recuperacion['id'] ?>">
                        <button type="submit">Aprobar y enviar enlace</button>
                    </form>
                    <form method="POST" action="index.php?action=rechazar-reset-password" class="inline-form" data-reset-password-action="reject">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="id" value="<?= (int)$recuperacion['id'] ?>">
                        <button type="submit">Rechazar</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<div data-vue-island="PasswordResetApprovals" data-prop-csrf-token="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"></div>
</section>
