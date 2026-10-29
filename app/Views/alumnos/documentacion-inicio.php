<section class="student-documents-page" aria-labelledby="student-start-documents-title">
    <header class="student-documents-page__header">
        <p class="student-documents-page__eyebrow">Área privada</p>
        <h1 id="student-start-documents-title">Documentación de inicio</h1>
        <p>Estos documentos se generan automáticamente al confirmar tu asignación de prácticas.</p>
    </header>
    <?php if ($documentos === []): ?>
        <p class="document-scanner__empty">Todavía no hay documentación de inicio disponible.</p>
    <?php else: ?>
        <div class="student-documents-page__list">
            <?php foreach ($documentos as $documento): ?>
                <article class="student-documents-page__document">
                    <div><strong><?= $documento['tipo_documento'] === 'PLAN_FORMATIVO' ? 'Plan formativo' : 'Guía de inicio' ?></strong><small><?= htmlspecialchars($documento['nombre_empresa'] . ' · ' . $documento['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?></small></div>
                    <a class="student-documents-page__download" href="index.php?action=descargar-documentacion-inicio&id=<?= (int)$documento['id'] ?>">Descargar PDF</a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
