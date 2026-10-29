<?php $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>
<h2>Importar empresas desde Excel o PDF</h2>
<p>Sube un archivo <strong>.xlsx</strong> o <strong>.pdf</strong> (máximo 5 MB y 100 empresas) con las columnas <em>Razón social, NIF, Email, Representante</em>. Cada empresa se crea inactiva y recibe un enlace de activación válido 24 horas.</p>
<?php if ($error !== ''): ?><p class="message message-error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
<?php if (is_array($resultado)): ?>
<p class="message message-success" role="status">Empresas creadas: <?= (int)$resultado['insertados'] ?> de <?= (int)$resultado['total_procesados'] ?>. Filas con errores: <?= (int)$resultado['fallidos'] ?>.</p>
<?php if ($resultado['errores'] !== []): ?>
<table>
    <thead><tr><th>Fila</th><th>Motivo</th></tr></thead>
    <tbody>
    <?php foreach ($resultado['errores'] as $fila): ?>
        <tr><td><?= (int)$fila['linea'] ?></td><td><?= $escape((string)$fila['motivo']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; endif; ?>
<form class="page-form" method="POST" enctype="multipart/form-data" action="index.php?action=importar-empresas">
    <input type="hidden" name="_csrf" value="<?= $escape(CsrfMiddleware::token()) ?>">
    <label>Archivo de empresas (.xlsx o .pdf) <input type="file" name="archivo_empresas" accept=".xlsx,.pdf,application/pdf,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></label>
    <button type="submit">Importar empresas</button>
</form>
