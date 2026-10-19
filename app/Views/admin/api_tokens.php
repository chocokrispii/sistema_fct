<?php if ($error !== ''): ?><p class="message message-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<h2>Tokens de API</h2>
<p>Los tokens heredan el rol de la cuenta seleccionada. El secreto completo se muestra una sola vez al crearlo.</p>
<?php if (is_string($plainTextToken)): ?>
    <p class="message message-success" role="status">Copia y guarda este token ahora. No volverá a mostrarse.</p>
    <pre><?= htmlspecialchars($plainTextToken, ENT_QUOTES, 'UTF-8') ?></pre>
<?php endif; ?>
<form class="page-form page-form--narrow" method="POST" action="index.php?action=api-tokens">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="token_action" value="create">
    <label>Nombre para identificar el uso <input type="text" name="name" maxlength="100" required></label>
    <label>Cuenta y permisos
        <select name="user_id" required>
            <?php foreach ($users as $user): ?>
                <option value="<?= (int)$user['id'] ?>"><?= htmlspecialchars($user['email'] . ' — ' . $user['rol'], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button type="submit" <?= $users === [] ? 'disabled' : '' ?>>Crear token</button>
</form>
<div class="table-responsive" role="region" aria-label="Tokens de API" tabindex="0">
<table>
    <thead><tr><th>Nombre</th><th>Cuenta</th><th>Rol</th><th>Creado</th><th>Último uso</th><th>Estado</th><th>Acción</th></tr></thead>
    <tbody><?php foreach ($tokens as $token): ?>
        <tr>
            <td><?= htmlspecialchars($token['name'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($token['email'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($token['rol'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($token['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($token['last_used_at'] ?? 'Nunca', ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= $token['revoked_at'] === null ? 'Activo' : 'Revocado' ?></td>
            <td><?php if ($token['revoked_at'] === null): ?>
                <form method="POST" action="index.php?action=api-tokens">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="token_action" value="revoke">
                    <input type="hidden" name="token_id" value="<?= (int)$token['id'] ?>">
                    <button type="submit">Revocar</button>
                </form>
            <?php else: ?>—<?php endif; ?></td>
        </tr>
    <?php endforeach; ?></tbody>
</table>
</div>
