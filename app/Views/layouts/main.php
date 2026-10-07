<?php
/** @var string $contenido HTML generado por el controlador */
$rolActual = $_SESSION['rol'] ?? null;
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
            <?php endif; ?>
            <?php if ($rolActual === null): ?>
                <a href="index.php?action=registro-alumno">Registro alumno</a>
                <a href="index.php?action=registro-empresa">Registro empresa</a>
                <a href="index.php?action=login">Iniciar sesión</a>
            <?php else: ?>
                <a href="index.php?action=logout">Cerrar sesión (<?= htmlspecialchars($_SESSION['email'], ENT_QUOTES, 'UTF-8') ?>)</a>
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
