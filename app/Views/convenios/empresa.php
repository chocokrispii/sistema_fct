<?php if ($error !== ''): ?><p id="agreement-error" class="message message-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<?php if (isset($_GET['enviado'])): ?><p class="message message-success">Convenio enviado a revisión.</p><?php endif; ?>
<?php if (isset($_GET['modificado'])): ?><p class="message message-success">Convenio actualizado y enviado a revisión.</p><?php endif; ?>
<?php if (is_string($_GET['error'] ?? null) && $error === ''): ?><p class="message message-error"><?= htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<h2>Convenios de <?= htmlspecialchars($empresa['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?></h2>
<form class="page-form" method="POST" enctype="multipart/form-data" action="index.php?action=convenios"<?= $error !== '' ? ' aria-describedby="agreement-error"' : '' ?>>
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
    <label for="agreement-number">Número de convenio <input id="agreement-number" type="text" name="numero_convenio" maxlength="100" value="<?= htmlspecialchars($values['numero_convenio'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required></label>
    <label for="agreement-specialty">Ciclo formativo</label>
    <?php $especialidadSelectId = 'agreement-specialty'; ?>
    <?php $especialidadSeleccionada = $values['especialidad_id'] ?? ''; ?>
    <?php require __DIR__ . '/../partials/select_especialidades.php'; ?>
    <?php unset($especialidadSelectId, $especialidadSeleccionada); ?>
    <label for="agreement-branch">Sede (opcional)
        <select id="agreement-branch" name="sede_id">
            <option value="" <?= ($values['sede_id'] ?? '') === '' ? 'selected' : '' ?>>Empresa completa</option>
            <?php foreach ($sedes as $sede): ?>
                <?php $branchId = (string)$sede['id']; ?>
                <option value="<?= (int)$sede['id'] ?>" <?= ($values['sede_id'] ?? '') === $branchId ? 'selected' : '' ?>><?= htmlspecialchars($sede['nombre'], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label for="agreement-signing-date">Fecha de firma <input id="agreement-signing-date" type="date" name="fecha_firma" value="<?= htmlspecialchars($values['fecha_firma'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required></label>
    <label for="agreement-start-date">Válido desde <input id="agreement-start-date" type="date" name="fecha_inicio" value="<?= htmlspecialchars($values['fecha_inicio'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required></label>
    <label for="agreement-end-date">Válido hasta <input id="agreement-end-date" type="date" name="fecha_fin" value="<?= htmlspecialchars($values['fecha_fin'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required></label>
    <label for="agreement-file">Convenio firmado (PDF, máximo 10 MB) <input id="agreement-file" type="file" name="archivo" accept="application/pdf,.pdf" required></label>
    <button type="submit">Enviar a revisión</button>
</form>
<h3>Historial de convenios</h3>
<?php if ($convenios === []): ?><p>No hay convenios registrados.</p>
<?php else: ?><div class="table-responsive" role="region" aria-label="Historial de convenios" tabindex="0"><table><thead><tr><th>Número</th><th>Ciclo</th><th>Fechas</th><th>Estado</th><th>Motivo</th><th>Documento</th><th>Acciones</th></tr></thead><tbody>
<?php foreach ($convenios as $item): ?><tr>
    <td><?= htmlspecialchars($item['numero_convenio'], ENT_QUOTES, 'UTF-8') ?></td>
    <td><?= htmlspecialchars($item['nombre_especialidad'] ?? 'Sin clasificar (anterior)', ENT_QUOTES, 'UTF-8') ?></td>
    <td><?= htmlspecialchars($item['fecha_inicio'] . ' – ' . $item['fecha_fin'], ENT_QUOTES, 'UTF-8') ?></td>
    <td><?= htmlspecialchars($item['estado'], ENT_QUOTES, 'UTF-8') ?></td>
    <td><?= htmlspecialchars($item['motivo_revision'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
    <td><a href="index.php?action=descargar-convenio&id=<?= (int)$item['id'] ?>">Descargar PDF</a></td>
    <td><?php if (in_array($item['estado'], ['Pendiente', 'Rechazado'], true)): ?>
        <a href="index.php?action=editar-convenio&id=<?= (int)$item['id'] ?>">Modificar</a>
    <?php else: ?>No modificable<?php endif; ?></td>
</tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
