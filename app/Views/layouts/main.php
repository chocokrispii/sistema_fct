<?php
/** @var string $contenido HTML generado por el controlador */
require_once __DIR__ . '/../../Models/BrandingSettings.php';
require_once __DIR__ . '/../../Core/IntegrationFeature.php';
$rolActual = $_SESSION['rol'] ?? null;
$usuarioAutenticado = $rolActual !== null;
$marcaPortal = (new BrandingSettings())->get();
$integrationsEnabled = IntegrationFeature::isEnabled();
$pendientesReenvioActivacion = 0;
if ($rolActual === 'Administrador') {
    $pendientesReenvioActivacion = (int)getPDOConnection()->query(
        "SELECT COUNT(*) FROM activation_resend_requests WHERE estado = 'Pendiente de Reenvío'"
    )->fetchColumn();
}
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
$themeIsland = '<div data-vue-island="ThemeToggle"></div>';
?>
<!DOCTYPE html>
<html lang="es-ES">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
    <base href="<?= htmlspecialchars(($basePath === '' ? '' : $basePath) . '/', ENT_QUOTES, 'UTF-8') ?>">

    <title>Sistema FCT</title>
    <script src="assets/js/theme-init.js"></script>
    <?php /* En desarrollo Vite inyecta el CSS vía JS (módulo diferido): se enlaza aquí para evitar el parpadeo sin estilos. */ ?>
    <?php if (!FrontendAssets::isEnabled() || FrontendAssets::developmentOrigin() !== null): ?>
        <link rel="stylesheet" href="assets/css/style.css?v=<?= (int)filemtime(__DIR__ . '/../../../public/assets/css/style.css') ?>">
    <?php endif; ?>
    <script src="assets/js/password-toggle.js" defer></script>
    <script src="assets/js/textarea-autogrow.js" defer></script>
    <?php if (str_contains($contenido, 'data-autofirma-island')): ?>
        <script src="assets/js/autofirma/autoscript.js" defer></script>
    <?php endif; ?>
    <?= FrontendAssets::render($contenido . $themeIsland) ?>
    <link id="brand-theme-styles" rel="stylesheet" href="index.php?action=branding-styles">
    <?php if (str_contains($contenido, 'data-brand-design')): ?>
        <script src="assets/js/brand-design.js" defer></script>
    <?php endif; ?>
</head>

<body>

    <header class="site-header">
        <a class="site-header__brand" href="index.php" aria-label="Sistema FCT, inicio">
            <?php if (is_string($marcaPortal['logo_path'])): ?>
                <img class="site-header__logo" src="index.php?action=branding-logo" alt="">
            <?php else: ?>
                <span aria-hidden="true" class="site-header__brand-mark">FCT</span>
                <span>Sistema FCT</span>
            <?php endif; ?>
        </a>

        <details class="site-navigation__disclosure" open>
            <summary class="site-navigation__toggle">
                <span>Menú</span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M4 6h16M4 12h16M4 18h16"></path>
                </svg>
            </summary>
            <nav class="site-navigation" aria-label="Navegación principal">
                <?php if ($usuarioAutenticado): ?>
                    <div data-vue-island="TabNavigation" data-prop-role="<?= htmlspecialchars((string)$rolActual, ENT_QUOTES, 'UTF-8') ?>" data-prop-integrations-enabled="<?= $integrationsEnabled ? 'true' : 'false' ?>"></div>
                <?php endif; ?>
                <div class="site-navigation__links site-navigation__links--fallback">
            <a href="index.php">Inicio</a>
            <?php if (in_array($rolActual, ['Administrador', 'Coordinador', 'Observador'], true)): ?>
                <a href="index.php?action=ofertas">Ofertas</a>
            <?php endif; ?>
            <?php if ($rolActual === 'Empresa'): ?>
                <a class="site-navigation__company-link" href="index.php?action=mi-empresa">Mi empresa</a>
            <?php elseif (in_array($rolActual, ['Administrador', 'Coordinador'], true)): ?>
                <a href="index.php?action=alumnos-centro">Alumnado</a>
                <?php if ($rolActual === 'Coordinador'): ?>
                    <a href="index.php?action=revisar-convenios">Revisar convenios</a>
                <?php endif; ?>
                <a href="index.php?action=revisar-ofertas">Revisar ofertas</a>
                <a href="index.php?action=emparejar">Emparejar alumnos</a>
                <a href="index.php?action=asignaciones">Asignaciones</a>
                <?php if ($rolActual === 'Coordinador'): ?>
                    <a href="index.php?action=consultas">Consultas</a>
                <?php endif; ?>
                <a href="index.php?action=calificaciones">Calificaciones</a>
                <?php if ($rolActual === 'Administrador'): ?>
                    <a href="index.php?action=gestion-empresas">Gestión de empresas</a>
                    <a href="index.php?action=gestion-cuentas">
                        Cuentas y recuperación
                        <?php if ($pendientesReenvioActivacion > 0): ?>
                            <span class="status-badge status-badge--pending"><?= $pendientesReenvioActivacion ?> pendientes</span>
                        <?php endif; ?>
                    </a>
                    <a href="index.php?action=ciclos">Ciclos formativos</a>
                    <a href="index.php?action=diseno">Diseño</a>
                    <?php if ($integrationsEnabled): ?><a href="index.php?action=integraciones">Integraciones</a><?php endif; ?>
                <?php endif; ?>
            <?php elseif ($rolActual === 'Observador'): ?>
                <a href="index.php?action=asignaciones">Consulta de asignaciones</a>
                <a href="index.php?action=auditar-convenios">Consulta de convenios</a>
                <a href="index.php?action=consultas">Consultas</a>
                <a href="index.php?action=calificaciones">Calificaciones</a>
            <?php elseif ($rolActual === 'Alumno'): ?>
                <a href="index.php?action=portal-alumno">Mi expediente</a>
                <a href="index.php?action=perfil-alumno">Editar perfil</a>
                <a href="index.php?action=disponibilidad">Mi disponibilidad</a>
                <a href="index.php?action=mis-asignaciones">Mis prácticas</a>
                <a href="index.php?action=documentacion-inicio">Documentación de inicio</a>
                <a href="index.php?action=documentacion-cierre">Documentación de cierre</a>
                <a href="index.php?action=mis-calificaciones">Mis calificaciones</a>
            <?php endif; ?>
            <?php if (!$usuarioAutenticado): ?>
                <a href="index.php?action=registro-empresa">Registro empresa</a>
                <a href="index.php?action=login">Iniciar sesión</a>
            <?php endif; ?>
                </div>
                <?php if ($usuarioAutenticado): ?>
                <div class="site-navigation__account">
                <div data-vue-island="SessionControls" data-prop-csrf-token="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>"></div>
                <form method="POST" action="index.php?action=logout" class="inline-form session-fallback">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit">Cerrar sesión (<?= htmlspecialchars($_SESSION['email'], ENT_QUOTES, 'UTF-8') ?>)</button>
                </form>
                </div>
                <?php endif; ?>
                <div class="site-navigation__theme" data-vue-island="ThemeToggle"></div>
            </nav>
        </details>
    </header>
    <main><?= $contenido ?></main>
    <footer><p>Gestión de Formación en Centros de Trabajo</p></footer>
  </div>
</div>
<script src="assets/js/app-menu.js" defer></script>
</body>
</html>
