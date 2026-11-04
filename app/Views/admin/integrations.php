<h2>Integraciones</h2>
<?php
$infoBannerTitle = 'Conecta el Sistema FCT con tus herramientas';
$infoBannerText = 'La API REST permite sincronizar recursos con sistemas externos y LMS de terceros. Los webhooks pueden notificar esos sistemas tras eventos del FCT. Cada solicitud se autentica con un token API y respeta los permisos de la cuenta asociada.';
$infoBannerLinks = [
    ['href' => 'api-docs.html', 'label' => 'Abrir documentación interactiva (Swagger UI)'],
    ['href' => 'openapi.json', 'label' => 'Consultar especificación OpenAPI (JSON)'],
    ['href' => 'index.php?action=api-tokens', 'label' => 'Gestionar tokens API'],
];
require __DIR__ . '/../components/info_banner.php';
?>
<?php require __DIR__ . '/_api_console.php'; ?>
<?php require __DIR__ . '/_webhooks.php'; ?>
