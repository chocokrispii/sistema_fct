<?php
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$initialData = json_encode([
    'nombreEmpresa' => (string)($empresa['nombre_empresa'] ?? ''),
    'cifEmpresa' => (string)($empresa['cif_empresa'] ?? ''),
    'personaContacto' => (string)($empresa['persona_contacto'] ?? ''),
    'telefono' => (string)($contacto['telefono'] ?? ''),
    'direccion' => (string)($contacto['direccion_completa'] ?? ''),
    'sedes' => array_map(static fn(array $sede): array => [
        'nombre' => (string)($sede['nombre'] ?? ''),
        'direccion' => (string)($sede['direccion'] ?? ''),
        'telefono' => (string)($sede['telefono'] ?? ''),
    ], $sedes),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
?>
<section class="company-onboarding" aria-labelledby="company-onboarding-title">
    <div class="company-onboarding__intro">
        <span class="company-onboarding__eyebrow">Primer acceso</span>
        <h2 id="company-onboarding-title">Completa el perfil de tu empresa</h2>
        <p>Reúne tus datos fiscales, los datos del representante y tus sedes en un único paso. Después podrás publicar ofertas.</p>
    </div>

    <?php if ($error !== ''): ?>
        <p class="message message-error" role="alert"><?= $escape($error) ?></p>
    <?php endif; ?>
    <?php if (isset($_GET['onboarding'])): ?>
        <p class="message message-success" role="status">Datos guardados. Ya puedes gestionar tus ofertas y convenios.</p>
    <?php endif; ?>

    <?php if (FrontendAssets::isEnabled()): ?>
        <div
            data-vue-island="CompanyOnboardingWizard"
            data-prop-csrf-token="<?= $escape(CsrfMiddleware::token()) ?>"
            data-prop-initial-data="<?= $escape((string)$initialData) ?>"
        ></div>
    <?php else: ?>
        <form class="page-form company-onboarding__fallback" method="POST" action="index.php?action=onboarding-empresa">
            <input type="hidden" name="_csrf" value="<?= $escape(CsrfMiddleware::token()) ?>">
            <label>Razón social <input type="text" name="nombre_empresa" maxlength="150" value="<?= $escape((string)$empresa['nombre_empresa']) ?>" required></label>
            <label>NIF (Persona Jurídica) <input type="text" name="cif_empresa" maxlength="20" value="<?= $escape((string)$empresa['cif_empresa']) ?>" required></label>
            <label>Representante legal <input type="text" name="persona_contacto" maxlength="150" value="<?= $escape((string)$empresa['persona_contacto']) ?>" required></label>
            <label>Teléfono <input type="tel" name="telefono" maxlength="20" value="<?= $escape((string)$contacto['telefono']) ?>" required></label>
            <label>Dirección fiscal <input type="text" name="direccion" maxlength="255" value="<?= $escape((string)$contacto['direccion_completa']) ?>" required></label>
            <label>Nombre de la sede <input type="text" name="sedes[0][nombre]" maxlength="150" required></label>
            <label>Dirección de la sede <input type="text" name="sedes[0][direccion]" maxlength="255" required></label>
            <label>Teléfono de la sede <input type="tel" name="sedes[0][telefono]" maxlength="20"></label>
            <button type="submit">Guardar y continuar</button>
        </form>
    <?php endif; ?>
</section>
