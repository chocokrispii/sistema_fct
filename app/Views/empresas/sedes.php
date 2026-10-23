<?php if ($error !== ''): ?><p id="branch-error" class="message message-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<h2>Sedes de <?= htmlspecialchars($empresa['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?></h2>
<form class="page-form page-form--narrow" method="POST" action="index.php?action=sedes"<?= $error !== '' ? ' aria-describedby="branch-error"' : '' ?>>
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
    <label for="branch-name">Nombre <input id="branch-name" type="text" name="nombre" value="<?= htmlspecialchars($values['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required></label>
    <label for="branch-address">Dirección <input id="branch-address" type="text" name="direccion" value="<?= htmlspecialchars($values['direccion'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required></label>
    <label for="branch-phone">Teléfono <input id="branch-phone" type="text" name="telefono" value="<?= htmlspecialchars($values['telefono'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></label>
    <button type="submit">Añadir sede</button>
</form>
<ul><?php foreach ($sedes as $sede): ?>
    <li><?= htmlspecialchars($sede['nombre'] . ' — ' . $sede['direccion'] . ($sede['telefono'] ? ' — ' . $sede['telefono'] : ''), ENT_QUOTES, 'UTF-8') ?></li>
<?php endforeach; ?></ul>
