<?php
/** @var string $contenido HTML generado por el controlador */
$rolActual = $_SESSION['rol'] ?? null;
$usuarioAutenticado = $rolActual !== null;

$iconos = [
    'inicio'     => 'M12 3 2 12h3v8h5v-6h4v6h5v-8h3z',
    'expediente' => 'M6 2h8l6 6v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zM8 12h8v1.6H8zM8 15.5h8v1.6H8z',
    'ofertas'    => 'M9 3h6a1 1 0 0 1 1 1v2h4a2 2 0 0 1 2 2v3H2V8a2 2 0 0 1 2-2h4V4a1 1 0 0 1 1-1zm1 2v1h4V5zM2 13h8v1.5h4V13h8v5a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2z',
    'calendario' => 'M7 2h2v2h6V2h2v2h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2zM5 9v11h14V9zm2 3h3v3H7z',
    'usuarios'   => 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm8 0a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM9 13c-3.3 0-7 1.6-7 4.5V20h14v-2.5C16 14.6 12.3 13 9 13zm8.5.2c2.5.4 4.5 1.7 4.5 3.8V20h-4v-2.5c0-1.5-.6-2.8-1.6-3.8.3-.1.7-.4 1.1-.5z',
    'estrella'   => 'M12 2l3 6.3 6.9.9-5 4.8 1.2 6.9L12 17.6 5.9 20.9 7.1 14 2.1 9.2 9 8.3z',
    'documento'  => 'M6 2h8l6 6v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm7 1.5V9h5.5z',
    'buscar'     => 'M10.5 3a7.5 7.5 0 0 1 5.9 12.1l4.3 4.3-1.4 1.4-4.3-4.3A7.5 7.5 0 1 1 10.5 3zm0 2a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11z',
    'salir'      => 'M10 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h5v-2H5V5h5zm6.6 4.6L15.2 9l2 2H9v2h8.2l-2 2 1.4 1.4L21 12z',
    'mas'        => 'M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6z',
    'sede'       => 'M12 2a7 7 0 0 0-7 7c0 5 7 13 7 13s7-8 7-13a7 7 0 0 0-7-7zm0 9.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5z',
    'check'      => 'M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z',
    'refrescar'  => 'M17.7 6.3A8 8 0 1 0 20 12h-2a6 6 0 1 1-1.8-4.2L13 11h8V3z',
    'usuario'    => 'M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9zm0 2c-4.4 0-8 2.2-8 5v2h16v-2c0-2.8-3.6-5-8-5z',
    'campana'    => 'M12 22a2.5 2.5 0 0 0 2.5-2.5h-5A2.5 2.5 0 0 0 12 22zm7-6v-5a7 7 0 0 0-5-6.7V3a2 2 0 0 0-4 0v1.3A7 7 0 0 0 5 11v5l-2 2v1h18v-1z',
    'edificio'   => 'M4 21V3h11v6h5v12h-6v-4h-3v4zM7 6v2h2V6zm4 0v2h2V6zM7 11v2h2v-2zm4 0v2h2v-2zM7 15v2h2v-2zm4 0v2h2v-2z',
];
$svg = static fn(string $k): string => '<svg class="fct-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="' . $iconos[$k] . '"/></svg>';

$navItems = [['Inicio', 'index.php', 'inicio'], ['Ofertas de prácticas', 'index.php?action=ofertas', 'ofertas']];
if ($rolActual === 'Empresa') {
    $navItems = array_merge($navItems, [['Publicar oferta', 'index.php?action=publicar-oferta', 'mas'], ['Sedes', 'index.php?action=sedes', 'sede'], ['Convenios', 'index.php?action=convenios', 'documento']]);
} elseif (in_array($rolActual, ['Administrador', 'Coordinador'], true)) {
    $navItems = array_merge($navItems, [['Revisar convenios', 'index.php?action=revisar-convenios', 'documento'], ['Revisar ofertas', 'index.php?action=revisar-ofertas', 'check'], ['Emparejar alumnos', 'index.php?action=emparejar', 'usuarios'], ['Asignaciones', 'index.php?action=asignaciones', 'calendario'], ['Consultas', 'index.php?action=consultas', 'buscar'], ['Calificaciones', 'index.php?action=calificaciones', 'estrella'], ['Recuperaciones', 'index.php?action=recuperaciones', 'refrescar']]);
    if ($rolActual === 'Administrador') $navItems[] = ['Usuarios', 'index.php?action=usuarios', 'usuario'];
} elseif ($rolActual === 'Observador') {
    $navItems = array_merge($navItems, [['Asignaciones', 'index.php?action=asignaciones', 'usuarios'], ['Consultas', 'index.php?action=consultas', 'buscar'], ['Calificaciones', 'index.php?action=calificaciones', 'estrella']]);
} elseif ($rolActual === 'Alumno') {
    $navItems = [['Inicio', 'index.php', 'inicio'], ['Mi expediente', 'index.php?action=portal-alumno', 'expediente'], ['Ofertas de prácticas', 'index.php?action=ofertas', 'ofertas'], ['Disponibilidad', 'index.php?action=disponibilidad', 'calendario'], ['Asignaciones', 'index.php?action=mis-asignaciones', 'usuarios'], ['Calificaciones', 'index.php?action=mis-calificaciones', 'estrella']];
}
if (!$usuarioAutenticado) $navItems = array_merge($navItems, [['Registro alumno', 'index.php?action=registro-alumno', 'usuario'], ['Registro empresa', 'index.php?action=registro-empresa', 'edificio'], ['Iniciar sesión', 'index.php?action=login', 'salir']]);

$actionActual = $_GET['action'] ?? '';
$nombreUsuario = $_SESSION['nombre'] ?? $_SESSION['email'] ?? 'Usuario';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Sistema FCT</title>
    <link rel="stylesheet" href="assets/css/style.css?v=20261015-a">
</head>

<body data-role="<?= htmlspecialchars(strtolower($rolActual ?? 'publico'), ENT_QUOTES, 'UTF-8') ?>">
<div class="app-shell">
  <aside class="app-sidebar">
    <a class="app-brand" href="index.php">
      <svg class="app-brand__mark" viewBox="0 0 40 40" aria-hidden="true"><path d="M20 5 2 14l18 9 18-9z" fill="#8b7bff"/><path d="M9 19v8c0 3 5 6 11 6s11-3 11-6v-8l-11 5.5z" fill="#2f8cff"/><path d="M35 15v10" stroke="#8b7bff" stroke-width="2" stroke-linecap="round"/></svg>
      <span>Sistema FCT</span>
    </a>
    <nav class="app-nav" aria-label="Navegación principal">
      <?php foreach ($navItems as [$label, $url, $icon]): $itemAction = parse_url($url, PHP_URL_QUERY); parse_str($itemAction ?? '', $itemQuery); $itemKey = $itemQuery['action'] ?? ''; $active = ($url === 'index.php' && $actionActual === '') || ($itemKey !== '' && $itemKey === $actionActual); ?>
      <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" class="<?= $active ? 'is-active' : '' ?>" <?= $active ? 'aria-current="page"' : '' ?>><span class="app-nav__icon"><?= $svg($icon) ?></span><span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span></a>
      <?php endforeach; ?>
    </nav>
    <?php if ($usuarioAutenticado): ?><div class="app-sidebar__bottom"><form method="POST" action="index.php?action=logout"><input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>"><button type="submit"><span class="app-nav__icon"><?= $svg('salir') ?></span><span>Cerrar sesión</span></button></form></div><?php endif; ?>
  </aside>
  <div class="app-main">
    <header class="app-topbar">
      <?php if ($usuarioAutenticado): ?><span class="app-topbar__bell" role="img" aria-label="Notificaciones"><?= $svg('campana') ?><i class="app-topbar__dot"></i></span><?php endif; ?>
      <?php if ($usuarioAutenticado): ?>
      <?php $csrfToken = htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8'); ?>
      <details class="app-menu" data-app-menu>
        <summary class="app-user" aria-label="Menú de usuario">
          <span class="app-user__avatar" aria-hidden="true"><?= $svg('usuario') ?></span>
          <div><strong><?= htmlspecialchars((string)$nombreUsuario, ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($rolActual, ENT_QUOTES, 'UTF-8') ?></small></div>
          <svg class="fct-ico fct-ico--stroke app-user__chev" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
        </summary>
        <div class="app-menu__panel" role="menu">
          <div class="app-menu__head">
            <span class="app-user__avatar app-user__avatar--lg" aria-hidden="true"><?= $svg('usuario') ?></span>
            <div><strong><?= htmlspecialchars((string)$nombreUsuario, ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars((string)($_SESSION['email'] ?? $rolActual), ENT_QUOTES, 'UTF-8') ?></small></div>
          </div>
          <?php if ($rolActual === 'Alumno'): ?>
          <a role="menuitem" href="index.php?action=portal-alumno"><?= $svg('expediente') ?><span>Mi expediente</span></a>
          <a role="menuitem" href="index.php?action=perfil-alumno"><?= $svg('usuario') ?><span>Editar perfil</span></a>
          <?php else: ?>
          <a role="menuitem" href="index.php"><?= $svg('inicio') ?><span>Inicio</span></a>
          <?php endif; ?>
          <div class="app-menu__sep"></div>
          <form method="POST" action="index.php?action=logout"><input type="hidden" name="_csrf" value="<?= $csrfToken ?>"><button type="submit" role="menuitem"><?= $svg('refrescar') ?><span>Cambiar de cuenta</span></button></form>
          <form method="POST" action="index.php?action=logout"><input type="hidden" name="_csrf" value="<?= $csrfToken ?>"><button type="submit" role="menuitem" class="is-danger"><?= $svg('salir') ?><span>Cerrar sesión</span></button></form>
        </div>
      </details>
      <?php else: ?>
      <div class="app-user"><span class="app-user__avatar" aria-hidden="true"><?= $svg('usuario') ?></span><div><strong>Invitado</strong><small>Acceso público</small></div></div>
      <?php endif; ?>
    </header>
    <main><?= $contenido ?></main>
    <footer><p>Gestión de Formación en Centros de Trabajo</p></footer>
  </div>
</div>
<script src="assets/js/app-menu.js" defer></script>
</body>
</html>
