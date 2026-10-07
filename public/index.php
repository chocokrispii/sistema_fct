<?php
// public/index.php — Front Controller

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Controllers/AuthController.php';
require_once __DIR__ . '/../app/Controllers/AlumnoController.php';
require_once __DIR__ . '/../app/Controllers/EmpresaController.php';
require_once __DIR__ . '/../app/Controllers/OfertaController.php';

session_start();

$action = $_GET['action'] ?? 'inicio';

// Los controladores pueden redirigir con header(), así que nada se envía al navegador hasta el final.
ob_start();

try {
    switch ($action) {
        case 'inicio':
            echo '<h2>Bienvenido</h2><p>Gestión de Formación en Centros de Trabajo.</p>';
            break;
        case 'login':
            (new AuthController())->login();
            break;
        case 'logout':
            (new AuthController())->logout();
            break;
        case 'registro-alumno':
            (new AlumnoController())->registrar();
            break;
        case 'registro-empresa':
            (new EmpresaController())->registrar();
            break;
        case 'ofertas':
            (new OfertaController())->listar();
            break;
        case 'publicar-oferta':
            (new OfertaController())->publicar();
            break;
        default:
            http_response_code(404);
            echo '<h2>Página no encontrada</h2>';
    }
} catch (Throwable $e) {
    error_log('Error no controlado en "' . $action . '": ' . $e->getMessage());
    http_response_code(500);
    echo '<p style="color:red;">Se ha producido un error interno. Inténtalo más tarde.</p>';
}

$contenido = ob_get_clean();
require __DIR__ . '/../app/Views/layouts/main.php';
