<?php

declare(strict_types=1);

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($requestPath)) {
    http_response_code(400);
    exit;
}

$decodedPath = rawurldecode($requestPath);
if ($decodedPath === '/api/public/empresas/registro') {
    $_GET['action'] = 'api-registro-empresa';
    require __DIR__ . '/index.php';
    return true;
}
if (preg_match('/\A\/api\/admin\/empresas\/([1-9][0-9]*)\/aprobar\/?\z/', $decodedPath, $matches) === 1) {
    $_GET['action'] = 'api-aprobar-empresa';
    $_GET['id'] = $matches[1];
    require __DIR__ . '/index.php';
    return true;
}

foreach (explode('/', str_replace('\\', '/', $decodedPath)) as $segment) {
    if (str_starts_with($segment, '.')) {
        http_response_code(404);
        return true;
    }
}

if ($decodedPath === '/api/v1' || str_starts_with($decodedPath, '/api/v1/')) {
    $_GET['path'] = trim(substr($decodedPath, strlen('/api/v1')), '/');
    require __DIR__ . '/api.php';
    return true;
}

if ($decodedPath === '/') {
    require __DIR__ . '/index.php';
    return true;
}

$publicRoot = realpath(__DIR__);
$requestedFile = realpath(__DIR__ . DIRECTORY_SEPARATOR . ltrim($decodedPath, '/\\'));
if (
    $publicRoot !== false
    && $requestedFile !== false
    && str_starts_with($requestedFile, $publicRoot . DIRECTORY_SEPARATOR)
    && is_file($requestedFile)
) {
    return false;
}

return false;
