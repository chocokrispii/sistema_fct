<?php if ($error !== ''): ?><p class="message message-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<?php if (isset($_GET['guardada'])): ?><p class="message message-success">Disponibilidad guardada.</p><?php endif; ?>
<h2>Mi disponibilidad para FCT</h2>
<p>Indica un periodo continuo en el que puedas realizar las prácticas y tu turno preferido.</p>
<form method="POST" action="index.php?action=disponibilidad">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
    <label>Desde <input type="date" name="fecha_inicio" required></label>
    <label>Hasta <input type="date" name="fecha_fin" required></label>
    <label>Turno
        <select name="turno"><option>Indiferente</option><option value="M">Mañana</option><option value="T">Tarde</option></select>
    </label>
    <button type="submit">Guardar disponibilidad</button>
</form>
<h3>Disponibilidad registrada</h3>
<?php if ($disponibilidades === []): ?><p>Aún no has registrado disponibilidad.</p>
<?php else: ?><ul><?php foreach ($disponibilidades as $item): ?>
    <li><?= htmlspecialchars($item['fecha_inicio'] . ' – ' . $item['fecha_fin'] . ' (' . $item['turno'] . ')', ENT_QUOTES, 'UTF-8') ?></li>
<?php endforeach; ?></ul><?php endif; ?>
