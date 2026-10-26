<?php if (isset($_GET['error'])): ?>
    <p class="message message-error" role="alert">Esa solicitud ya no está pendiente.</p>
<?php endif; ?>
<?php if (isset($_GET['enviado'])): ?>
    <p class="message message-success" role="status">Solicitud aprobada. Se ha enviado por correo el enlace de regeneración, válido durante 24 horas.</p>
<?php endif; ?>
<?php if ($token !== ''): ?>
    <p class="message message-success" role="status">Identidad verificada. Envía a la persona el enlace de un solo uso, válido <?= RecuperacionCuenta::VALIDEZ_MINUTOS ?> minutos: <a href="index.php?action=restablecer&amp;token=<?= rawurlencode($token) ?>">Abrir enlace de recuperación</a></p>
<?php endif; ?>
<h2>Recuperaciones pendientes de verificar</h2>
<p>Aprueba la solicitud para autorizar el envío automático de un enlace de regeneración válido durante 24 horas.</p>
<?php if ($recuperaciones === []): ?>
    <p>No hay solicitudes.</p>
<?php else: ?>
<table>
    <thead><tr><th>Correo</th><th>Solicitada</th><th></th></tr></thead>
    <tbody>
        <?php foreach ($recuperaciones as $recuperacion): ?>
            <tr>
                <td><?= htmlspecialchars($recuperacion['email'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($recuperacion['creada'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <form method="POST" action="index.php?action=verificar-recuperacion" class="inline-form">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="id" value="<?= (int)$recuperacion['id'] ?>">
                        <button type="submit">Aprobar y enviar enlace</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
