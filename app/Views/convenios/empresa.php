<?php if ($error !== ''): ?><p class="message message-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<?php if (isset($_GET['enviado'])): ?><p class="message message-success">Convenio enviado a revisión.</p><?php endif; ?>
<h2>Convenios de <?= htmlspecialchars($empresa['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?></h2>
<form class="page-form" method="POST" enctype="multipart/form-data" action="index.php?action=convenios">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
    <label>Número de convenio <input type="text" name="numero_convenio" maxlength="100" required></label>
    <label for="agreement-specialty">Ciclo formativo</label>
    <?php $especialidadSelectId = 'agreement-specialty'; ?>
    <?php require __DIR__ . '/../partials/select_especialidades.php'; ?>
    <?php unset($especialidadSelectId); ?>
    <label>Sede (opcional)
        <select name="sede_id"><option value="">Empresa completa</option>
        <?php foreach ($sedes as $sede): ?><option value="<?= (int)$sede['id'] ?>"><?= htmlspecialchars($sede['nombre'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
        </select>
    </label>
    <label>Fecha de firma <input type="date" name="fecha_firma" required></label>
    <label>Válido desde <input type="date" name="fecha_inicio" required></label>
    <label>Válido hasta <input type="date" name="fecha_fin" required></label>
    <label>Convenio firmado (PDF, máximo 10 MB) <input type="file" name="archivo" accept="application/pdf,.pdf" required></label>
    <button type="submit">Enviar a revisión</button>
</form>
<h3>Historial de convenios</h3>
<?php if ($convenios === []): ?><p>No hay convenios registrados.</p>
<?php else: ?><table><thead><tr><th>Número</th><th>Ciclo</th><th>Fechas</th><th>Estado</th><th>Motivo</th><th>Documento</th></tr></thead><tbody>
<?php foreach ($convenios as $item): ?><tr>
    <td><?= htmlspecialchars($item['numero_convenio'], ENT_QUOTES, 'UTF-8') ?></td>
    <td><?= htmlspecialchars($item['nombre_especialidad'] ?? 'Sin clasificar (anterior)', ENT_QUOTES, 'UTF-8') ?></td>
    <td><?= EuropeanDate::periodBlock($item['fecha_inicio'], $item['fecha_fin']) ?></td>
    <td><?= htmlspecialchars($item['estado'], ENT_QUOTES, 'UTF-8') ?></td>
    <td><?= htmlspecialchars($item['motivo_revision'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
    <td><?php if (($item['estado'] ?? '') === 'Aprobado' && !empty($item['documento_firmado_path'])): ?>
        <a href="index.php?action=descargar-convenio-firmado&amp;id=<?= (int)$item['id'] ?>">Descargar convenio aprobado</a>
        <?php else: ?><span>Descarga reservada a administración</span><?php endif; ?>
    </td>
</tr><?php endforeach; ?></tbody></table><?php endif; ?>
