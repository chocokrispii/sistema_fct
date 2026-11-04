<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/storage.php';
require_once __DIR__ . '/../app/Core/ApiToken.php';
require_once __DIR__ . '/../app/Core/JsonResponse.php';
require_once __DIR__ . '/../app/Core/ApiResponseAdapter.php';
require_once __DIR__ . '/../app/Core/ApiRolePolicy.php';
require_once __DIR__ . '/../app/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../app/Controllers/CalificacionController.php';
require_once __DIR__ . '/../app/Core/FileScanner.php';
require_once __DIR__ . '/../app/Core/PhpIni.php';
require_once __DIR__ . '/../app/Core/SpanishDataValidator.php';
require_once __DIR__ . '/../app/Controllers/DevelopmentPreviewController.php';

function assertFileSecurity(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

putenv('FCT_INTEGRATIONS_ENABLED=false');
$routes = require __DIR__ . '/../routes/web.php';
assertFileSecurity(
    !isset($routes['integraciones']) && !isset($routes['api-tokens']),
    'Las rutas de integraciones deben estar ocultas cuando la función está desactivada.'
);
putenv('FCT_INTEGRATIONS_ENABLED=true');
$enabledRoutes = require __DIR__ . '/../routes/web.php';
putenv('FCT_INTEGRATIONS_ENABLED=false');
assertFileSecurity(
    isset($enabledRoutes['integraciones'], $enabledRoutes['api-tokens']),
    'La bandera técnica debe volver a habilitar las rutas de integración.'
);
$routes = require __DIR__ . '/../routes/web.php';
assertFileSecurity(
    $routes['descargar-convenio']['roles'] === ['Administrador'],
    'La matriz de acceso a convenios concede acceso a un rol no autorizado.'
);
assertFileSecurity(
    $routes['portal-alumno']['roles'] === ['Alumno'],
    'El portal del expediente debe estar limitado al rol Alumno.'
);
assertFileSecurity(
    $routes['perfil-alumno']['method'] === ['GET', 'POST']
        && $routes['perfil-alumno']['roles'] === ['Alumno'],
    'La edición del perfil debe quedar limitada al propio flujo del alumno autenticado.'
);
assertFileSecurity(
    $routes['eliminar-usuario']['method'] === 'POST'
        && $routes['eliminar-usuario']['roles'] === ['Administrador'],
    'La eliminación permanente debe ser POST y estar limitada a administradores.'
);
assertFileSecurity(
    $routes['actualizar-identificador-fiscal-usuario']['method'] === 'POST'
        && $routes['actualizar-identificador-fiscal-usuario']['roles'] === ['Administrador'],
    'La actualización de identificadores fiscales debe ser POST y estar limitada a administradores.'
);
assertFileSecurity(
    $routes['solicitar-reenvio-activacion']['method'] === 'POST'
        && $routes['solicitar-reenvio-activacion']['roles'] === []
        && $routes['solicitudes-activacion']['method'] === 'GET'
        && $routes['solicitudes-activacion']['roles'] === ['Administrador']
        && $routes['aprobar-reenvio-activacion']['method'] === 'POST'
        && $routes['aprobar-reenvio-activacion']['roles'] === ['Administrador'],
    'Los reenvíos de activación deben solicitarse con respuesta genérica y aprobarse solo por administrador.'
);
$activationController = file_get_contents(__DIR__ . '/../app/Controllers/ActivacionController.php');
assertFileSecurity(
    is_string($activationController)
        && str_contains($activationController, 'expires_at > CURRENT_TIMESTAMP')
        && str_contains($activationController, 'AND used_at IS NULL'),
    'Los enlaces de activación deben rechazarse al expirar según el reloj de la base de datos y ser de un solo uso.'
);
assertFileSecurity(
    $routes['mis-calificaciones']['roles'] === ['Alumno']
        && $routes['calificaciones']['roles'] === ['Administrador', 'Coordinador', 'Observador']
        && $routes['guardar-calificacion']['roles'] === ['Administrador', 'Coordinador'],
    'La matriz de acceso a calificaciones no coincide con los permisos previstos.'
);
assertFileSecurity(
    $routes['ofertas']['roles'] === ['Administrador', 'Coordinador', 'Observador', 'Empresa']
        && !in_array('Alumno', $routes['ofertas']['roles'], true)
        && $routes['empresa-asignada']['roles'] === ['Alumno'],
    'La visibilidad de ofertas y datos de empresa asignada debe respetar el acceso del alumnado.'
);
assertFileSecurity(
    $routes['revisar-oferta']['roles'] === ['Administrador']
        && $routes['actualizar-horario-oferta']['method'] === 'POST'
        && $routes['actualizar-horario-oferta']['roles'] === ['Administrador']
        && $routes['resolver-convenio']['roles'] === ['Administrador']
        && $routes['auditar-convenios']['roles'] === ['Observador'],
    'La actualización de horarios, resolución de ofertas/convenios y consulta de auditoría deben tener roles separados.'
);
assertFileSecurity(
    $enabledRoutes['integraciones']['method'] === ['GET', 'POST']
        && $enabledRoutes['integraciones']['roles'] === ['Administrador'],
    'La configuración de webhooks debe permitir cambios solo mediante POST autenticado para administradores.'
);
assertFileSecurity(
    $routes['logout']['method'] === 'POST'
        && in_array('Observador', $routes['logout']['roles'], true),
    'Observador debe conservar el permiso de cerrar su sesión.'
);
assertFileSecurity(
    ApiRolePolicy::reviewRoles('ofertas') === $routes['revisar-oferta']['roles']
        && ApiRolePolicy::reviewRoles('convenios') === $routes['resolver-convenio']['roles'],
    'La API debe mantener los mismos roles de revisión que las rutas MVC.'
);
foreach ($routes as $name => $route) {
    $methods = is_array($route['method']) ? $route['method'] : [$route['method']];
    if (in_array('POST', $methods, true) && $name !== 'logout') {
        assertFileSecurity(
            !in_array('Observador', $route['roles'], true),
            "El rol Observador no debe ejecutar mutaciones: $name."
        );
    }
}
$hadSession = array_key_exists('_SESSION', $GLOBALS);
$originalSession = $_SESSION ?? null;
$hadAcceptHeader = array_key_exists('HTTP_ACCEPT', $_SERVER);
$originalAcceptHeader = $_SERVER['HTTP_ACCEPT'] ?? null;
$originalHttpStatus = http_response_code();
if (!is_int($originalHttpStatus)) {
    $originalHttpStatus = 200;
}
$_SESSION = [];
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$jsonAuthRejected = false;
try {
    AuthMiddleware::requireRoles(['Alumno'], 'portal-alumno');
} catch (RuntimeException) {
    $jsonAuthRejected = http_response_code() === 401;
}
assertFileSecurity(
    $jsonAuthRejected,
    'Una solicitud JSON sin sesión debe responder 401 en lugar de redirigir al formulario HTML.'
);
http_response_code($originalHttpStatus);
if ($hadSession) {
    $_SESSION = $originalSession;
} else {
    unset($_SESSION);
}
if ($hadAcceptHeader) {
    $_SERVER['HTTP_ACCEPT'] = $originalAcceptHeader;
} else {
    unset($_SERVER['HTTP_ACCEPT']);
}

$detailData = ApiResponseAdapter::successData(['data' => ['id' => 17, 'estado' => 'Aprobada']]);
$pageData = ApiResponseAdapter::successData([
    'data' => [['id' => 17]],
    'meta' => ['page' => 2, 'per_page' => 20, 'total' => 21],
]);
assertFileSecurity(
    $detailData === ['id' => 17, 'estado' => 'Aprobada']
        && $pageData === ['items' => [['id' => 17]], 'meta' => ['page' => 2, 'per_page' => 20, 'total' => 21]],
    'La API debe conservar los datos y la paginación dentro del campo data del contrato estricto.'
);
$originalHttpStatus = http_response_code();
if (!is_int($originalHttpStatus)) {
    $originalHttpStatus = 200;
}
ob_start();
JsonResponse::success($detailData, 200);
$successEnvelope = json_decode((string)ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
ob_start();
JsonResponse::failure('Acceso denegado.', 403);
$failureEnvelope = json_decode((string)ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
assertFileSecurity(
    $successEnvelope === ['success' => true, 'data' => $detailData]
        && $failureEnvelope === ['success' => false, 'error' => 'Acceso denegado.'],
    'Las respuestas de la API deben usar el contrato JSON discriminado JsonResponse.'
);
http_response_code($originalHttpStatus);

$gradeDatabaseStub = new class extends PDO {
    public function __construct()
    {
    }
};
$gradeController = new CalificacionController($gradeDatabaseStub);
$hadSession = array_key_exists('_SESSION', $GLOBALS);
$originalSession = $_SESSION ?? null;
$hadPost = array_key_exists('_POST', $GLOBALS);
$originalPost = $_POST ?? null;
$hadAcceptHeader = array_key_exists('HTTP_ACCEPT', $_SERVER);
$originalAcceptHeader = $_SERVER['HTTP_ACCEPT'] ?? null;
$hadContentType = array_key_exists('CONTENT_TYPE', $_SERVER);
$originalContentType = $_SERVER['CONTENT_TYPE'] ?? null;
$originalHttpStatus = http_response_code();
if (!is_int($originalHttpStatus)) {
    $originalHttpStatus = 200;
}
$_SESSION = ['usuario_id' => 1, 'rol' => 'Coordinador'];
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
try {
    foreach ([
        'partial decimal' => '8.',
        'partial leading decimal' => '.5',
        'nonnumeric text' => 'not-a-number',
        'numeric suffix' => '8.5abc',
        'exponent notation' => '1e1',
        'above range' => '10.01',
        'array input' => ['8.5'],
    ] as $case => $input) {
        $_POST = [
            'asignacion_id' => '1',
            'nota' => $input,
            'observaciones' => '',
        ];
        ob_start();
        $gradeController->guardar();
        $gradeResponse = json_decode((string)ob_get_clean(), true);
        assertFileSecurity(
            is_array($gradeResponse)
                && ($gradeResponse['success'] ?? null) === false
                && is_string($gradeResponse['error'] ?? null),
            "La entrada de nota '$case' debe rechazarse con un error JSON estricto."
        );
        http_response_code($originalHttpStatus);
    }
} finally {
    if ($hadSession) {
        $_SESSION = $originalSession;
    } else {
        unset($_SESSION);
    }
    if ($hadPost) {
        $_POST = $originalPost;
    } else {
        unset($_POST);
    }
    if ($hadAcceptHeader) {
        $_SERVER['HTTP_ACCEPT'] = $originalAcceptHeader;
    } else {
        unset($_SERVER['HTTP_ACCEPT']);
    }
    if ($hadContentType) {
        $_SERVER['CONTENT_TYPE'] = $originalContentType;
    } else {
        unset($_SERVER['CONTENT_TYPE']);
    }
    http_response_code($originalHttpStatus);
}

assertFileSecurity(PhpIni::sizeToBytes('12M') === 12 * 1024 * 1024, 'No se interpretó el límite PHP de 12 MiB.');
assertFileSecurity(PhpIni::sizeToBytes('512K') === 512 * 1024, 'No se interpretó un límite PHP en KiB.');
assertFileSecurity(PhpIni::sizeToBytes('0') === PHP_INT_MAX, 'El límite PHP 0 debe representar tamaño ilimitado.');
assertFileSecurity(PhpIni::sizeToBytes('invalid') === 0, 'Un límite PHP inválido no se rechazó de forma segura.');
assertFileSecurity(SpanishDataValidator::isDniNie('12345678Z'), 'Se rechazó un DNI válido.');
assertFileSecurity(SpanishDataValidator::isDniNie('00000009D'), 'Se rechazó un DNI válido cuya letra de control es D.');
assertFileSecurity(SpanishDataValidator::isDniNie('X-1234567-L'), 'Se rechazó un NIE válido con separadores.');
assertFileSecurity(!SpanishDataValidator::isDniNie('12345678A'), 'Se aceptó un DNI con letra de control incorrecta.');
assertFileSecurity(!SpanishDataValidator::isDniNie('X1234567A'), 'Se aceptó un NIE con letra de control incorrecta.');
assertFileSecurity(SpanishDataValidator::isDniNieFormat('12345678A'), 'El login debe reconocer el formato de DNI de cuentas históricas.');
assertFileSecurity(
    SpanishDataValidator::isNifPersonaJuridica('B12345674'),
    'Se rechazó un NIF de persona jurídica con carácter de control válido.'
);
assertFileSecurity(
    !SpanishDataValidator::isNifPersonaJuridica('B12345678'),
    'Se aceptó un NIF de persona jurídica con carácter de control incorrecto.'
);
assertFileSecurity(
    SpanishDataValidator::isNifPersonaJuridica('P1234567D'),
    'Se rechazó un NIF de persona jurídica con control alfabético válido.'
);
assertFileSecurity(SpanishDataValidator::isPassport('AB123456'), 'Se rechazó un pasaporte alfanumérico válido.');
assertFileSecurity(SpanishDataValidator::isPassport('123456'), 'Se rechazó un pasaporte numérico de seis caracteres.');
assertFileSecurity(SpanishDataValidator::isPassport('abc123456789012'), 'Se rechazó un pasaporte alfanumérico de quince caracteres.');
assertFileSecurity(!SpanishDataValidator::isPassport('ABC 123'), 'Se aceptó un pasaporte con espacios interiores.');
assertFileSecurity(!SpanishDataValidator::isNifPersonaJuridica('P1234567E'), 'Se aceptó un NIF de persona jurídica con letra de control incorrecta.');
assertFileSecurity(!SpanishDataValidator::isPassport('ABC-123'), 'Se aceptó un pasaporte con separadores.');
assertFileSecurity(!SpanishDataValidator::isPassport('ABC12'), 'Se aceptó un pasaporte demasiado corto.');
assertFileSecurity(!SpanishDataValidator::isPassport('ABCDEFGHIJKLMNO1'), 'Se aceptó un pasaporte demasiado largo.');
assertFileSecurity(SpanishDataValidator::isSpanishPhone('+34 612 345 678'), 'Se rechazó un teléfono válido con prefijo +34.');
assertFileSecurity(!SpanishDataValidator::isSpanishPhone('512345678'), 'Se aceptó un teléfono con prefijo nacional no permitido.');
assertFileSecurity(SpanishDataValidator::isSpanishPostcode('28013'), 'Se rechazó un código postal válido.');
assertFileSecurity(!SpanishDataValidator::isSpanishPostcode('53000'), 'Se aceptó un código postal fuera del rango provincial español.');
assertFileSecurity(SpanishDataValidator::isPersonName('María O’Connor', 100), 'Se rechazó un nombre válido con acentos y apóstrofo.');
assertFileSecurity(!SpanishDataValidator::isPersonName('María<script>', 100), 'Se aceptó contenido no permitido en el nombre.');
$apiToken = ApiToken::issue();
assertFileSecurity(
    strlen($apiToken['plainText']) === 68
        && hash_equals($apiToken['hash'], hash('sha256', $apiToken['plainText'])),
    'El token de API no se generó o resumió con el formato esperado.'
);

$publicPath = realpath(__DIR__ . '/../public');
$originalStorage = getenv('FCT_STORAGE_PATH');
$hadDocumentRoot = array_key_exists('DOCUMENT_ROOT', $_SERVER);
$originalDocumentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
$testStoragePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR
    . 'fct-file-security-' . bin2hex(random_bytes(8));
assertFileSecurity($publicPath !== false, 'No se pudo resolver el document root de prueba.');
assertFileSecurity(mkdir($testStoragePath), 'No se pudo crear el almacenamiento temporal de prueba.');
$_SERVER['DOCUMENT_ROOT'] = $publicPath;

try {
    putenv('FCT_STORAGE_PATH=' . $testStoragePath);
    $storagePath = fctStoragePath();
    assertFileSecurity(
        $storagePath === realpath($testStoragePath),
        'No se aceptó un almacenamiento correctamente configurado fuera del document root.'
    );

    putenv('FCT_STORAGE_PATH');
    try {
        fctStoragePath();
        throw new RuntimeException('Se aceptó el almacenamiento sin configurar.');
    } catch (RuntimeException $exception) {
        assertFileSecurity(
            str_contains($exception->getMessage(), 'FCT_STORAGE_PATH debe configurarse'),
            'La ausencia de FCT_STORAGE_PATH se rechazó por un motivo inesperado.'
        );
    }

    putenv('FCT_STORAGE_PATH=' . realpath(__DIR__ . '/../public/assets'));
    try {
        fctStoragePath();
        throw new RuntimeException('Se aceptó una ruta de almacenamiento dentro del document root.');
    } catch (RuntimeException $exception) {
        assertFileSecurity(
            str_contains($exception->getMessage(), 'fuera y separado del document root'),
            'La ruta dentro del document root se rechazó por un motivo inesperado.'
        );
    }

    putenv('FCT_STORAGE_PATH=' . realpath(__DIR__ . '/..'));
    try {
        fctStoragePath();
        throw new RuntimeException('Se aceptó un almacenamiento que contiene el document root.');
    } catch (RuntimeException $exception) {
        assertFileSecurity(
            str_contains($exception->getMessage(), 'fuera y separado del document root'),
            'Una ruta que contiene el document root se rechazó por un motivo inesperado.'
        );
    }
} finally {
    if ($originalStorage === false) {
        putenv('FCT_STORAGE_PATH');
    } else {
        putenv('FCT_STORAGE_PATH=' . $originalStorage);
    }
    if ($hadDocumentRoot) {
        $_SERVER['DOCUMENT_ROOT'] = $originalDocumentRoot;
    } else {
        unset($_SERVER['DOCUMENT_ROOT']);
    }
    rmdir($testStoragePath);
}

$originalScanner = getenv('FCT_CLAMSCAN');
$originalClamdHost = getenv('FCT_CLAMD_HOST');
$originalClamdPort = getenv('FCT_CLAMD_PORT');
$originalClamavEnabled = getenv('FCT_CLAMAV_ENABLED');
$originalAppEnvironment = getenv('APP_ENV');
putenv('FCT_CLAMSCAN');
putenv('FCT_CLAMD_HOST');
putenv('FCT_CLAMD_PORT');
putenv('FCT_CLAMAV_ENABLED');
putenv('APP_ENV');
try {
    $scannerRejected = false;
    putenv('FCT_CLAMAV_ENABLED=true');
    try {
        FileScanner::scan(__FILE__);
    } catch (FileScannerUnavailableException) {
        $scannerRejected = true;
    }
    assertFileSecurity($scannerRejected, 'Se aceptó un archivo sin análisis antivirus configurado.');

    putenv('APP_ENV=development');
    putenv('FCT_CLAMAV_ENABLED=false');
    FileScanner::scan(__FILE__);

    putenv('APP_ENV=test');
    FileScanner::scan(__FILE__);
    putenv('APP_ENV=testing');
    FileScanner::scan(__FILE__);

    putenv('APP_ENV=production');
    $productionDisableRejected = false;
    try {
        FileScanner::scan(__FILE__);
    } catch (FileScannerUnavailableException) {
        $productionDisableRejected = true;
    }
    assertFileSecurity($productionDisableRejected, 'Se permitió desactivar ClamAV fuera del desarrollo local.');

    putenv('APP_ENV');
    $missingEnvironmentRejected = false;
    try {
        FileScanner::scan(__FILE__);
    } catch (FileScannerUnavailableException) {
        $missingEnvironmentRejected = true;
    }
    assertFileSecurity($missingEnvironmentRejected, 'Se permitió desactivar ClamAV sin un entorno local declarado.');

    putenv('APP_ENV=staging');
    $stagingDisableRejected = false;
    try {
        FileScanner::scan(__FILE__);
    } catch (FileScannerUnavailableException) {
        $stagingDisableRejected = true;
    }
    assertFileSecurity($stagingDisableRejected, 'Se permitió desactivar ClamAV fuera de desarrollo o pruebas.');

    putenv('APP_ENV=development');
    putenv('FCT_CLAMAV_ENABLED=off');
    $invalidConfigurationRejected = false;
    try {
        FileScanner::scan(__FILE__);
    } catch (FileScannerUnavailableException) {
        $invalidConfigurationRejected = true;
    }
    assertFileSecurity($invalidConfigurationRejected, 'Se aceptó un valor inválido para FCT_CLAMAV_ENABLED.');
} finally {
    if ($originalScanner === false) {
        putenv('FCT_CLAMSCAN');
    } else {
        putenv('FCT_CLAMSCAN=' . $originalScanner);
    }
    if ($originalClamdHost === false) {
        putenv('FCT_CLAMD_HOST');
    } else {
        putenv('FCT_CLAMD_HOST=' . $originalClamdHost);
    }
    if ($originalClamdPort === false) {
        putenv('FCT_CLAMD_PORT');
    } else {
        putenv('FCT_CLAMD_PORT=' . $originalClamdPort);
    }
    if ($originalClamavEnabled === false) {
        putenv('FCT_CLAMAV_ENABLED');
    } else {
        putenv('FCT_CLAMAV_ENABLED=' . $originalClamavEnabled);
    }
    if ($originalAppEnvironment === false) {
        putenv('APP_ENV');
    } else {
        putenv('APP_ENV=' . $originalAppEnvironment);
    }
}

require_once __DIR__ . '/identity_documents.php';

echo "OK: validadores españoles, matriz de rutas, almacenamiento privado y política antivirus fail-closed.\n";
