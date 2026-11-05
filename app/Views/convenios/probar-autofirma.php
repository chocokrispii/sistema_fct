<section class="page-section">
    <p><a href="index.php?action=revisar-convenios">Volver a revisar convenios</a></p>
    <h1>Prueba de AutoFirma</h1>
    <p>Esta prueba firma un documento sin datos reales y no aprueba ni modifica convenios.</p>
    <p>Necesitas tener instalado AutoFirma en este equipo y un certificado de prueba disponible.</p>
    <div data-autofirma-island
         data-vue-island="AutoFirmaTest"
         data-prop-pdf-base64="<?= htmlspecialchars($pdfBase64, ENT_QUOTES, 'UTF-8') ?>"
         data-prop-challenge="<?= htmlspecialchars($challenge, ENT_QUOTES, 'UTF-8') ?>"
         data-prop-csrf="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"></div>
</section>
