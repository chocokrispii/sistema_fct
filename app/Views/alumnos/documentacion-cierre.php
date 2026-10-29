<?php
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$assignmentsJson = json_encode($asignaciones, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
?>
<section class="student-documents-page" aria-labelledby="student-documents-title">
    <header class="student-documents-page__header">
        <p class="student-documents-page__eyebrow">Cierre de FCT</p>
        <h1 id="student-documents-title">Documentación de prácticas finalizadas</h1>
        <p>Selecciona una práctica cerrada, captura o elige tu documento y recórtalo antes de entregarlo.</p>
    </header>
    <?php if ($error !== ''): ?><p class="message message-error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
    <?php if ($subido): ?><p class="message message-success" role="status">Documento entregado correctamente.</p><?php endif; ?>
    <div data-vue-island="DocumentScannerIsland"
         data-prop-csrf-token="<?= $escape($csrfToken) ?>"
         data-prop-assignments-json="<?= $escape($assignmentsJson) ?>"></div>
    <noscript>
        <form class="page-form page-form--narrow" method="POST" enctype="multipart/form-data" action="index.php?action=subir-documentacion-cierre">
            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
            <label>Práctica finalizada
                <select name="asignacion_id" required>
                    <?php foreach ($asignaciones as $asignacion): ?><option value="<?= (int)$asignacion['id'] ?>"><?= $escape($asignacion['nombre_empresa'] . ' · ' . $asignacion['nombre_especialidad']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Tipo de documentación
                <select name="tipo_documento" required><option value="MEMORIA">Memoria de prácticas</option><option value="HOJA_FIRMAS">Hoja de firmas</option></select>
            </label>
            <label>Documento (PDF, JPG o PNG; máximo 10 MB)<input type="file" name="documento" accept="application/pdf,image/jpeg,image/png" required></label>
            <button type="submit">Entregar documento</button>
        </form>
    </noscript>
</section>
