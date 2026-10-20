<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Core/PhpIni.php';
require_once __DIR__ . '/../app/Core/View.php';
require_once __DIR__ . '/../app/Core/FrontendAssets.php';
require_once __DIR__ . '/../app/Core/JsonResponse.php';
require_once __DIR__ . '/../app/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../app/Middleware/CsrfMiddleware.php';
require_once __DIR__ . '/../app/Controllers/InicioController.php';
require_once __DIR__ . '/../app/Controllers/AuthController.php';
require_once __DIR__ . '/../app/Controllers/RecuperacionController.php';
require_once __DIR__ . '/../app/Controllers/AlumnoController.php';
require_once __DIR__ . '/../app/Controllers/EmpresaController.php';
require_once __DIR__ . '/../app/Controllers/OfertaController.php';
require_once __DIR__ . '/../app/Controllers/DisponibilidadController.php';
require_once __DIR__ . '/../app/Controllers/DevelopmentPreviewController.php';
require_once __DIR__ . '/../app/Controllers/AsignacionController.php';
require_once __DIR__ . '/../app/Controllers/CalificacionController.php';
require_once __DIR__ . '/../app/Controllers/ConvenioController.php';
require_once __DIR__ . '/../app/Controllers/UsuarioAdminController.php';
require_once __DIR__ . '/../app/Controllers/ConsultaController.php';
require_once __DIR__ . '/../app/Controllers/ApiTokenAdminController.php';
require_once __DIR__ . '/../app/Controllers/IntegrationTestController.php';
require_once __DIR__ . '/../app/Controllers/WebhookInboxController.php';
require_once __DIR__ . '/../app/Controllers/WebhookCompanyActivityController.php';
require_once __DIR__ . '/../app/Controllers/IntegrationHubController.php';

$contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
if (
    strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && is_string($contentLength)
    && ctype_digit($contentLength)
    && (int)$contentLength > PhpIni::sizeToBytes((string)ini_get('post_max_size'))
) {
    http_response_code(413);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('La solicitud supera el límite configurado.');
}

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store');
$viteOrigin = FrontendAssets::developmentOrigin();
$contentSecurityPolicy = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'";
if ($viteOrigin !== null) {
    $viteWebSocketOrigin = 'ws://' . substr($viteOrigin, strlen('http://'));
    $contentSecurityPolicy = "default-src 'self'; script-src 'self' $viteOrigin; style-src 'self' $viteOrigin 'unsafe-inline'; connect-src 'self' $viteOrigin $viteWebSocketOrigin; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'";
}
header('Content-Security-Policy: ' . $contentSecurityPolicy);

$action = $_GET['action'] ?? 'inicio';
$routes = require __DIR__ . '/../routes/web.php';
$outputBufferLevel = ob_get_level();
ob_start();

try {
    if (!is_string($action) || !isset($routes[$action])) {
        http_response_code(404);
        View::render('errors/404');
    } else {
        $route = $routes[$action];
        $methods = (array)$route['method'];
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if (!in_array($method, $methods, true)) {
            header('Allow: ' . implode(', ', $methods));
            http_response_code(405);
            View::render('errors/405');
        } else {
            if ($route['roles'] !== []) {
                AuthMiddleware::requireRoles($route['roles'], $action);
            }
            if ($method === 'POST') {
                CsrfMiddleware::verify();
            }

            if ($route['controller'] === null) {
                View::render('home/index');
            } else {
                $controller = new $route['controller']();
                $controller->{$route['action']}();
            }
        }
    }
} catch (Throwable $e) {
    while (ob_get_level() > $outputBufferLevel + 1) {
        ob_end_clean();
    }
    ob_clean();

    error_log('Error no controlado en "' . (is_string($action) ? $action : 'invalid') . '": ' . $e->getMessage());
    if (http_response_code() < 400) {
        http_response_code(500);
    }
    if (JsonResponse::wantsJson()) {
        JsonResponse::failure('No se pudo completar la solicitud.', http_response_code());
    } else {
        View::render('errors/generic', ['status' => http_response_code()]);
    }
}

while (ob_get_level() > $outputBufferLevel + 1) {
    ob_end_flush();
}
$contenido = ob_get_clean();
if (JsonResponse::hasSent()) {
    echo $contenido;
    exit;
}

require __DIR__ . '/../app/Views/layouts/main.php';
