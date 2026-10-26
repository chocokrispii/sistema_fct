<section class="company-approval-page" aria-labelledby="company-approval-page-title">
    <h2 id="company-approval-page-title">Aprobar empresa</h2>
    <?php if ($empresa !== []): ?>
        <dl class="company-approval-page__details">
            <dt>Razón social</dt><dd><?= htmlspecialchars($empresa['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?></dd>
            <dt>Sector</dt><dd><?= htmlspecialchars($empresa['categoria'] ?? '', ENT_QUOTES, 'UTF-8') ?></dd>
            <dt>CIF / NIF</dt><dd><?= htmlspecialchars($empresa['cif_empresa'], ENT_QUOTES, 'UTF-8') ?></dd>
            <dt>Correo</dt><dd><?= htmlspecialchars($empresa['email'], ENT_QUOTES, 'UTF-8') ?></dd>
            <dt>Representante legal</dt><dd><?= htmlspecialchars($empresa['representante_legal'] ?? '', ENT_QUOTES, 'UTF-8') ?></dd>
        </dl>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <p id="company-approval-error" class="message message-error" role="alert" tabindex="-1"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <?php if (($empresa['estado'] ?? null) === 'Pendiente_Aprobacion'): ?>
        <form class="page-form page-form--narrow" method="POST" enctype="multipart/form-data" action="index.php?action=aprobar-empresa&amp;id=<?= (int)$empresa['id'] ?>"<?= $error !== '' ? ' aria-describedby="company-approval-error"' : '' ?>>
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <label for="approval-agreement-number">Número de Convenio Marco
                <input id="approval-agreement-number" type="text" name="numero_convenio" maxlength="100" required>
            </label>
            <label for="approval-agreement-pdf">Convenio firmado (PDF, máximo 10 MB)
                <input id="approval-agreement-pdf" type="file" name="pdf_convenio" accept="application/pdf,.pdf" required>
            </label>
            <button type="submit">Aprobar empresa</button>
        </form>
    <?php else: ?>
        <p>Esta solicitud no se encuentra pendiente de aprobación.</p>
    <?php endif; ?>

    <p><a href="index.php?action=solicitudes-empresa">Volver a solicitudes</a></p>
</section>
