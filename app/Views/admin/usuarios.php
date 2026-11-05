<?php
require_once __DIR__ . '/../../Core/FrontendAssets.php';
$vueEnabled = FrontendAssets::isEnabled();
?>
<?php if ($error !== ''): ?><p class="message message-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<?php if ($eliminado): ?><p class="message message-success" role="status">Usuario eliminado permanentemente.</p><?php endif; ?>
<h2>Gestión de cuentas</h2>
<p>Consulta y gestiona las cuentas existentes. El alta de personal del centro está disponible en <a href="index.php?action=alta-personal&tab=altas">Altas &gt; Personal del Centro</a>. Desde esta tabla puedes corregir el identificador del personal o de una empresa existente.</p>
<table>
    <thead><tr><th>Correo</th><th>Identificador fiscal de acceso</th><th>Nombre</th><th>Rol</th><th>Estado</th><th>Acciones</th></tr></thead>
    <tbody><?php foreach ($usuarios as $usuario): ?>
        <tr>
            <td><?= htmlspecialchars($usuario['email'], ENT_QUOTES, 'UTF-8') ?></td>
            <td>
                <?php if (in_array($usuario['rol'], ['Administrador', 'Coordinador', 'Observador', 'Empresa'], true)): ?>
                    <?php $companyAccount = $usuario['rol'] === 'Empresa'; ?>
                    <form class="action-form" method="POST" action="index.php?action=actualizar-identificador-fiscal-usuario">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="usuario_id" value="<?= (int)$usuario['id'] ?>">
                        <label class="visually-hidden" for="user-fiscal-id-<?= (int)$usuario['id'] ?>"><?= $companyAccount ? 'NIF (Persona Jurídica)' : 'NIF / NIE / DNI' ?> de <?= htmlspecialchars($usuario['email'], ENT_QUOTES, 'UTF-8') ?></label>
                        <input id="user-fiscal-id-<?= (int)$usuario['id'] ?>" type="text" name="identificador_fiscal" value="<?= htmlspecialchars((string)($usuario['identificador_fiscal'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" maxlength="20" autocomplete="off" required>
                        <button type="submit">Guardar <?= $companyAccount ? 'NIF (Persona Jurídica)' : 'NIF / NIE / DNI' ?></button>
                    </form>
                <?php elseif ($usuario['rol'] === 'Alumno'): ?>
                    <?= htmlspecialchars((string)($usuario['identificador_fiscal'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                <?php else: ?>
                    —
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($usuario['nombre_completo'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($usuario['rol'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= (int)$usuario['activo'] === 1 ? 'Activo' : 'Desactivado' ?></td>
            <td><div class="user-actions">
                <form class="action-form" method="POST" action="index.php?action=estado-usuario">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="usuario_id" value="<?= (int)$usuario['id'] ?>">
                    <input type="hidden" name="activo" value="<?= (int)$usuario['activo'] === 1 ? '0' : '1' ?>">
                    <button type="submit"><?= (int)$usuario['activo'] === 1 ? 'Desactivar' : 'Activar' ?></button>
                </form>
                <?php if ((int)$usuario['id'] !== (int)($_SESSION['usuario_id'] ?? 0)): ?>
                    <button class="user-delete-trigger" type="button" data-delete-user="<?= (int)$usuario['id'] ?>" data-delete-email="<?= htmlspecialchars($usuario['email'], ENT_QUOTES, 'UTF-8') ?>">Eliminar usuario</button>
                <?php endif; ?>
            </div></td>
        </tr>
    <?php endforeach; ?></tbody>
</table>
<?php if ($vueEnabled): ?>
    <div
        data-vue-island="UserDeleteDialog"
        data-prop-csrf="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>"
    ></div>
<?php else: ?>
<dialog class="user-delete-dialog" data-user-delete-dialog aria-labelledby="user-delete-title" aria-describedby="user-delete-description">
    <form method="POST" action="index.php?action=eliminar-usuario">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="usuario_id" value="" data-delete-user-id>
        <h3 id="user-delete-title">Estás seguro?</h3>
        <p id="user-delete-description">Se eliminará permanentemente <strong data-delete-user-email></strong>. Esta acción no se puede deshacer.</p>
        <div class="user-delete-dialog__actions">
            <button type="button" class="user-delete-dialog__cancel" data-delete-cancel>No, volver.</button>
            <button type="submit" class="user-delete-dialog__confirm">Sí, eliminar</button>
        </div>
    </form>
</dialog>
<script src="assets/js/admin-users.js" defer></script>
<?php endif; ?>
