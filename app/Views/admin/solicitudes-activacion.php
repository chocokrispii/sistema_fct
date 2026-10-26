<?php $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>
<h2>Solicitudes de reenvío de activación</h2>

<?php if ($error !== ''): ?>
    <p class="message message-error" role="alert"><?= $escape($error) ?></p>
<?php endif; ?>
<?php if ($reenviada): ?>
    <p class="message message-success" role="status">El nuevo enlace se ha enviado y el anterior ha quedado invalidado.</p>
<?php endif; ?>

<?php if ($solicitudes === []): ?>
    <p>No hay solicitudes pendientes de reenvío.</p>
<?php else: ?>
    <table>
        <thead>
            <tr><th>Fecha</th><th>Nombre</th><th>Correo</th><th>NIF/DNI/NIE</th><th>Tipo de cuenta</th><th>Acción</th></tr>
        </thead>
        <tbody>
            <?php foreach ($solicitudes as $solicitud): ?>
                <tr>
                    <td><?= $escape((string)$solicitud['creada']) ?></td>
                    <td><?= $escape((string)$solicitud['nombre_completo']) ?></td>
                    <td><?= $escape((string)$solicitud['email']) ?></td>
                    <td><?= $escape((string)($solicitud['identificador_fiscal'] ?? '—')) ?></td>
                    <td><?= $escape((string)$solicitud['rol']) ?></td>
                    <td>
                        <form method="POST" action="index.php?action=aprobar-reenvio-activacion">
                            <input type="hidden" name="_csrf" value="<?= $escape(CsrfMiddleware::token()) ?>">
                            <input type="hidden" name="solicitud_id" value="<?= (int)$solicitud['solicitud_id'] ?>">
                            <button type="submit">Aprobar y reenviar enlace</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
