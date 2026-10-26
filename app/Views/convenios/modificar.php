<h2>Modificar convenios</h2>
<p>Administradores y Coordinadores pueden corregir convenios pendientes o rechazados, además de cambiar las fechas o los datos de un convenio aprobado. Al guardar, cualquier convenio modificado vuelve a revisión.</p>
<?php if (is_string($_GET['error'] ?? null)): ?>
    <p class="message message-error" role="alert"><?= htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if (isset($_GET['modificado'])): ?>
    <p class="message message-success" role="status">Convenio actualizado y enviado a revisión.</p>
<?php endif; ?>
<?php if ($convenios === []): ?>
    <p role="status">No hay convenios registrados que se puedan modificar.</p>
<?php else: ?>
    <div class="table-responsive" role="region" aria-label="Convenios modificables" tabindex="0">
        <table>
            <thead>
                <tr><th>Empresa</th><th>Número</th><th>Ciclo</th><th>Firma</th><th>Vigencia desde</th><th>Vigencia hasta</th><th>Estado</th><th>Acción</th></tr>
            </thead>
            <tbody>
            <?php foreach ($convenios as $item): ?>
                <tr>
                    <td><?= htmlspecialchars($item['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($item['numero_convenio'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($item['nombre_especialidad'] ?? 'Sin clasificar (anterior)', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($item['fecha_firma'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($item['fecha_inicio'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($item['fecha_fin'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($item['estado'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><a class="button" href="index.php?action=editar-convenio&amp;id=<?= (int)$item['id'] ?>">Modificar</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
