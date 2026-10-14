<?php if ($error !== ''): ?><p class="message message-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<h2>Sedes de <?= htmlspecialchars($empresa['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?></h2>
<form class="page-form page-form--narrow" method="POST" action="index.php?action=sedes">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
    <label>Nombre <input type="text" name="nombre" required></label>
    <label>Dirección <input type="text" name="direccion" required></label>
    <label>Teléfono <input type="text" name="telefono"></label>
    <button type="submit">Añadir sede</button>
</form>
<ul><?php foreach ($sedes as $sede): ?>
    <li><?= htmlspecialchars($sede['nombre'] . ' — ' . $sede['direccion'] . ($sede['telefono'] ? ' — ' . $sede['telefono'] : ''), ENT_QUOTES, 'UTF-8') ?></li>
<?php endforeach; ?></ul>
