<?php
/** @var string $contenido HTML generado por el controlador */
$rolActual = $_SESSION['rol'] ?? null;
$usuarioAutenticado = $rolActual !== null;
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
$themeIsland = '<div data-vue-island="ThemeToggle"></div>';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <base href="<?= htmlspecialchars(($basePath === '' ? '' : $basePath) . '/', ENT_QUOTES, 'UTF-8') ?>">

    <title>Sistema FCT</title>
    <script src="assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= (int)filemtime(__DIR__ . '/../../../public/assets/css/style.css') ?>">
    <script src="assets/js/password-toggle.js" defer></script>
    <?= FrontendAssets::render($contenido . $themeIsland) ?>
</head>

<body>

    <header class="site-header">
        <a class="site-header__brand" href="index.php" aria-label="Sistema FCT, inicio">
            <span aria-hidden="true" class="site-header__brand-mark">FCT</span>
            <span>Sistema FCT</span>
        </a>

        <details class="site-navigation__disclosure" open>
            <summary class="site-navigation__toggle">
                <span>Menú</span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M4 6h16M4 12h16M4 18h16"></path>
                </svg>
            </summary>
            <nav class="site-navigation" aria-label="Navegación principal">
                <div class="site-navigation__links">
            <a href="index.php">Inicio</a>
            <?php if (in_array($rolActual, ['Administrador', 'Coordinador', 'Observador', 'Empresa'], true)): ?>
                <a href="index.php?action=ofertas">Ofertas</a>
            <?php endif; ?>
            <?php if ($rolActual === 'Empresa'): ?>
                <a href="index.php?action=publicar-oferta">Publicar oferta</a>
                <a href="index.php?action=sedes">Sedes</a>
                <a href="index.php?action=convenios">Convenios</a>
            <?php elseif (in_array($rolActual, ['Administrador', 'Coordinador'], true)): ?>
                <a href="index.php?action=revisar-convenios">Revisar convenios</a>
                <a href="index.php?action=revisar-ofertas">Revisar ofertas</a>
                <a href="index.php?action=emparejar">Emparejar alumnos</a>
                <a href="index.php?action=asignaciones">Asignaciones</a>
                <a href="index.php?action=consultas">Consultas</a>
                <a href="index.php?action=calificaciones">Calificaciones</a>
                <a href="index.php?action=recuperaciones">Recuperaciones</a>
                <?php if ($rolActual === 'Administrador'): ?><a href="index.php?action=usuarios">Usuarios</a><?php endif; ?>
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
                <a href="index.php?action=mis-calificaciones">Mis calificaciones</a>
            <?php endif; ?>
            <?php if (!$usuarioAutenticado): ?>
                <a href="index.php?action=registro-alumno">Registro alumno</a>
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
