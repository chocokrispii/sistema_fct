<?php
/** @var string $contenido HTML generado por el controlador */
$rolActual = $_SESSION['rol'] ?? null;
$usuarioAutenticado = $rolActual !== null;
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Sistema FCT</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>

    <header>
        <h1>Sistema FCT</h1>

        <nav>
            <a href="index.php">Inicio</a>
            <a href="index.php?action=ofertas">Ofertas</a>
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
                <?php if ($rolActual === 'Administrador'): ?><a href="index.php?action=usuarios">Usuarios</a><?php endif; ?>
            <?php elseif ($rolActual === 'Observador'): ?>
                <a href="index.php?action=asignaciones">Consulta de asignaciones</a>
                <a href="index.php?action=consultas">Consultas</a>
            <?php elseif ($rolActual === 'Alumno'): ?>
                <a href="index.php?action=disponibilidad">Mi disponibilidad</a>
                <a href="index.php?action=mis-asignaciones">Mis prácticas</a>
            <?php endif; ?>
            <?php if (!$usuarioAutenticado): ?>
                <a href="index.php?action=registro-alumno">Registro alumno</a>
                <a href="index.php?action=registro-empresa">Registro empresa</a>
                <a href="index.php?action=login">Iniciar sesión</a>
            <?php else: ?>
                <form method="POST" action="index.php?action=logout" class="inline-form">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit">Cerrar sesión (<?= htmlspecialchars($_SESSION['email'], ENT_QUOTES, 'UTF-8') ?>)</button>
                </form>
            <?php endif; ?>
        </nav>
    </header>

    <main>
        <?= $contenido ?>
    </main>

    <footer>
        <p>Gestión de Formación en Centros de Trabajo</p>
    </footer>

</body>

</html>
