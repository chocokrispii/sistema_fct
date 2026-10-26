<?php $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>
<h2>Solicitudes de alta de empresa</h2>
<p>Revisa los datos, asigna el Número de Convenio Marco y adjunta el PDF firmado para aprobar el acceso.</p>
<?php if ($error !== ''): ?><p class="message message-error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
<?php if ($aprobada): ?><p class="message message-success" role="status">Solicitud aprobada. Se ha enviado el enlace de activación.</p><?php endif; ?>
<?php if ($solicitudes === []): ?><p>No hay solicitudes pendientes.</p><?php else: ?>
<?php foreach ($solicitudes as $solicitud): ?>
<section class="review-card">
    <h3><?= $escape((string)$solicitud['nombre_empresa']) ?></h3>
    <p><strong>NIF:</strong> <?= $escape((string)$solicitud['cif_empresa']) ?> · <strong>Correo:</strong> <?= $escape((string)$solicitud['email']) ?></p>
    <p><strong>Contacto:</strong> <?= $escape((string)$solicitud['persona_contacto']) ?> · <strong>Teléfono:</strong> <?= $escape((string)$solicitud['telefono']) ?></p>
    <p><strong>Dirección:</strong> <?= $escape((string)$solicitud['direccion_completa']) ?></p>
    <form class="page-form" method="POST" enctype="multipart/form-data" action="index.php?action=aprobar-empresa">
        <input type="hidden" name="_csrf" value="<?= $escape(CsrfMiddleware::token()) ?>">
        <input type="hidden" name="empresa_id" value="<?= (int)$solicitud['id'] ?>">
        <label>Número de Convenio Marco <input type="text" name="numero_convenio_marco" maxlength="100" required></label>
        <label>Convenio marco firmado (PDF, máximo 10 MB) <input type="file" name="archivo_convenio_marco" accept="application/pdf,.pdf" required></label>
        <button type="submit">Aprobar solicitud</button>
    </form>
</section>
<?php endforeach; endif; ?>
