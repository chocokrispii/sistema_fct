<?php

declare(strict_types=1);

final class HttpRegressionFailure extends RuntimeException
{
}

function expectRegression(bool $condition, string $message): void
{
    if (!$condition) {
        throw new HttpRegressionFailure($message);
    }
}

function responseEnvelope(array $response): array
{
    $decoded = json_decode($response['body'], true);
    expectRegression(
        is_array($decoded) && array_key_exists('success', $decoded),
        'Expected a JSON response envelope.'
    );

    return $decoded;
}

function responseData(array $response, int $status = 200): array
{
    $decoded = json_decode($response['body'], true);
    $detail = is_array($decoded) && is_string($decoded['error'] ?? null)
        ? ' (' . $decoded['error'] . ')'
        : '';
    expectRegression(
        $response['status'] === $status,
        'Expected HTTP status ' . $status . ', received ' . $response['status'] . $detail . '.'
    );
    $envelope = responseEnvelope($response);
    expectRegression(
        $envelope['success'] === true && isset($envelope['data']) && is_array($envelope['data']),
        'Expected a successful JSON response.'
    );

    return $envelope['data'];
}

function responseFailure(array $response, int $status): array
{
    $decoded = json_decode($response['body'], true);
    $detail = is_array($decoded) && is_string($decoded['error'] ?? null)
        ? ' (' . $decoded['error'] . ')'
        : '';
    expectRegression(
        $response['status'] === $status,
        'Expected HTTP error status ' . $status . ', received ' . $response['status'] . $detail . '.'
    );
    $envelope = responseEnvelope($response);
    expectRegression(
        $envelope['success'] === false && isset($envelope['error']) && is_string($envelope['error']),
        'Expected a JSON error envelope.'
    );

    return $envelope;
}

function pathIsWithin(string $candidate, string $parent): bool
{
    $candidate = rtrim($candidate, DIRECTORY_SEPARATOR);
    $parent = rtrim($parent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    return DIRECTORY_SEPARATOR === '\\'
        ? strncasecmp($candidate . DIRECTORY_SEPARATOR, $parent, strlen($parent)) === 0
        : str_starts_with($candidate . DIRECTORY_SEPARATOR, $parent);
}

function removeTestDirectory(string $directory): bool
{
    if (!file_exists($directory) && !is_link($directory)) {
        return true;
    }
    if (is_link($directory) || !is_dir($directory)) {
        return @unlink($directory);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        if ($entry->isLink() || !$entry->isDir()) {
            if (!@unlink($path)) {
                return false;
            }
        } elseif (!@rmdir($path)) {
            return false;
        }
    }

    return @rmdir($directory);
}

function stopTestServer(&$process): bool
{
    if (!is_resource($process)) {
        return true;
    }

    $status = proc_get_status($process);
    if ($status['running']) {
        @proc_terminate($process);
        $deadline = microtime(true) + 5.0;
        do {
            usleep(50000);
            $status = proc_get_status($process);
        } while ($status['running'] && microtime(true) < $deadline);
    }

    $closed = @proc_close($process);
    $process = null;

    return !$status['running'] && ($closed === 0 || $closed === -1);
}

final class RegressionHttpClient
{
    private array $cookies = [];

    public function __construct(private string $baseUrl)
    {
    }

    public function cookieHeader(): string
    {
        $parts = [];
        foreach ($this->cookies as $name => $value) {
            $parts[] = $name . '=' . $value;
        }

        return implode('; ', $parts);
    }

    public function request(
        string $method,
        string $action,
        array $headers = [],
        string $body = '',
        ?string $cookieOverride = null,
        string $query = ''
    ): array {
        $headerLines = ['Connection: close'];
        $acceptHeader = null;
        foreach ($headers as $name => $value) {
            $headerLine = is_int($name) ? (string)$value : $name . ': ' . $value;
            if (preg_match('/\AAccept\s*:/i', $headerLine) === 1) {
                $acceptHeader = $headerLine;
            } else {
                $headerLines[] = $headerLine;
            }
        }
        array_unshift($headerLines, $acceptHeader ?? 'Accept: application/json');

        $cookieHeader = $cookieOverride ?? $this->cookieHeader();
        if ($cookieHeader !== '') {
            $headerLines[] = 'Cookie: ' . $cookieHeader;
        }

        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 10,
                'protocol_version' => 1.1,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
        ]);
        $url = str_starts_with($action, '/')
            ? $this->baseUrl . $action . $query
            : $this->baseUrl . '/index.php?action=' . rawurlencode($action) . $query;
        $responseBody = @file_get_contents($url, false, $context);
        expectRegression(is_string($responseBody), 'The HTTP server did not return a response.');

        $rawHeaders = $http_response_header ?? [];
        $status = 0;
        $responseHeaders = [];
        $setCookies = [];
        foreach ($rawHeaders as $line) {
            if (preg_match('/^HTTP\/\S+\s+([0-9]{3})/', $line, $matches) === 1) {
                $status = (int)$matches[1];
                continue;
            }
            $separator = strpos($line, ':');
            if ($separator === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $separator)));
            $value = trim(substr($line, $separator + 1));
            if ($name === 'set-cookie') {
                $setCookies[] = $value;
            } else {
                $responseHeaders[$name] = $value;
            }
        }

        foreach ($setCookies as $setCookie) {
            if (preg_match('/^([^=;\s]+)=([^;]*)/', $setCookie, $matches) !== 1) {
                continue;
            }
            if ($matches[2] === '') {
                unset($this->cookies[$matches[1]]);
            } else {
                $this->cookies[$matches[1]] = $matches[2];
            }
        }

        expectRegression($status > 0, 'The HTTP response status was not available.');

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => $responseBody,
        ];
    }
}

function fetchCsrfToken(RegressionHttpClient $client): string
{
    $data = responseData($client->request('GET', 'session'));
    expectRegression(
        array_key_exists('user', $data) && $data['user'] === null,
        'A new HTTP session should not contain an authenticated user.'
    );
    expectRegression(
        isset($data['csrfToken']) && is_string($data['csrfToken']) && $data['csrfToken'] !== '',
        'The session endpoint should issue a CSRF token.'
    );

    return $data['csrfToken'];
}

function loginFixture(
    RegressionHttpClient $client,
    string $identifier,
    string $password,
    string $expectedRole
): string {
    $initialToken = fetchCsrfToken($client);
    $response = $client->request(
        'POST',
        'login',
        [
            'Content-Type' => 'application/json',
            'X-CSRF-Token' => $initialToken,
        ],
        json_encode(['identifier' => $identifier, 'password' => $password], JSON_THROW_ON_ERROR)
    );
    $data = responseData($response);
    expectRegression(
        isset($data['user']['rol']) && $data['user']['rol'] === $expectedRole,
        'Fixture login returned an unexpected role.'
    );
    expectRegression(
        isset($data['csrfToken']) && is_string($data['csrfToken']) && $data['csrfToken'] !== '',
        'Successful login should return a refreshed CSRF token.'
    );

    return $data['csrfToken'];
}

function multipartFieldsBody(array $fields, string $boundary): string
{
    $body = '';
    foreach ($fields as $name => $value) {
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
        $body .= (string)$value . "\r\n";
    }

    return $body;
}

function multipartBody(
    array $fields,
    string $filename,
    string $fileBytes,
    string $boundary,
    string $fileField = 'archivo'
): string {
    $body = multipartFieldsBody($fields, $boundary);
    $body .= '--' . $boundary . "\r\n";
    $body .= 'Content-Disposition: form-data; name="' . $fileField . '"; filename="' . $filename . '"' . "\r\n";
    $body .= 'Content-Type: application/pdf' . "\r\n\r\n";
    $body .= $fileBytes . "\r\n";
    $body .= '--' . $boundary . "--\r\n";

    return $body;
}

function validFixtureCif(string $nonce): string
{
    $digits = substr(str_pad((string)hexdec(substr($nonce, 0, 7)), 7, '0', STR_PAD_LEFT), -7);
    $sum = 0;
    for ($index = 0; $index < 7; $index++) {
        $digit = (int)$digits[$index];
        if ($index % 2 === 0) {
            $doubled = $digit * 2;
            $sum += intdiv($doubled, 10) + ($doubled % 10);
        } else {
            $sum += $digit;
        }
    }

    return 'B' . $digits . (string)((10 - ($sum % 10)) % 10);
}

function validFixtureDni(string $nonce): string
{
    $digits = substr(str_pad((string)hexdec(substr($nonce, 0, 7)), 8, '0', STR_PAD_LEFT), -8);
    return $digits . 'TRWAGMYFPDXBNJZSQVHLCKE'[(int)$digits % 23];
}

function insertFixtureUser(
    PDO $db,
    string $nonce,
    string $label,
    string $role,
    array &$fixtureEmails,
    array &$fixtureUserIds
): array {
    $email = 'http-regression-' . $nonce . '-' . $label . '@example.invalid';
    $password = bin2hex(random_bytes(20));
    $identityNonce = substr(hash('sha256', $nonce . ':' . $label), 0, 16);
    $identifier = match ($role) {
        'Alumno' => validFixtureDni($nonce),
        'Empresa' => validFixtureCif($identityNonce),
        default => validFixtureDni($identityNonce),
    };
    $statement = $db->prepare(
        'INSERT INTO usuarios (email, identificador_fiscal, password_hash, rol, activo)
         VALUES (:email, :identifier, :password_hash, :rol, 1)'
    );
    $statement->execute([
        'email' => $email,
        'identifier' => in_array($role, ['Administrador', 'Coordinador', 'Observador'], true) ? $identifier : null,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'rol' => $role,
    ]);
    $fixtureEmails[] = $email;

    $userId = (int)$db->lastInsertId();
    $fixtureUserIds[] = $userId;

    return [
        'id' => $userId,
        'email' => $email,
        'identifier' => $identifier,
        'password' => $password,
        'role' => $role,
    ];
}

function assertFeatureSchema(PDO $db, string $databaseName): void
{
    $statement = $db->prepare(
        'SELECT TABLE_NAME, COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :schema
           AND TABLE_NAME IN (
               \'usuarios\', \'empresas\', \'especialidades\', \'convenios\',
               \'auditoria\', \'audit_logs\', \'intentos_login\', \'intentos_reset_password\',
               \'solicitudes_reset_password\'
           )'
    );
    $statement->execute(['schema' => $databaseName]);
    $columns = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $columns[$row['TABLE_NAME']][$row['COLUMN_NAME']] = true;
    }

    $required = [
        'usuarios' => ['id', 'email', 'password_hash', 'rol', 'activo', 'session_version'],
        'empresas' => [
            'id', 'usuario_id', 'nombre_empresa', 'nombre_comercial', 'cif_empresa',
            'representante_legal', 'dni_representante', 'estado', 'numero_convenio',
            'categoria', 'pdf_convenio_path', 'fecha_aprobacion', 'fecha_firma_convenio',
        ],
        'especialidades' => ['id', 'nombre_especialidad'],
        'convenios' => ['id', 'empresa_id', 'especialidad_id', 'numero_convenio', 'fecha_firma', 'fecha_inicio', 'fecha_fin', 'archivo_path'],
        'auditoria' => ['id', 'usuario_id', 'evento', 'entidad', 'entidad_id', 'detalle'],
        'audit_logs' => [
            'id', 'id_usuario_admin', 'nombre_completo', 'accion', 'entidad_afectada',
            'id_registro_modificado', 'ip_address', 'timestamp',
        ],
        'intentos_login' => ['id', 'email', 'ip', 'fecha'],
        'intentos_reset_password' => [
            'id', 'identificador_hash', 'ip_hash', 'solicitado_en',
        ],
        'solicitudes_reset_password' => [
            'id', 'usuario_id', 'email', 'estado', 'token', 'expira_en',
            'solicitado_en', 'aprobado_en', 'aprobado_por',
        ],
    ];
    foreach ($required as $table => $tableColumns) {
        foreach ($tableColumns as $column) {
            expectRegression(
                isset($columns[$table][$column]),
                'The fct_test database is missing a required migrated schema column.'
            );
        }
    }
}

function assertJsonForbidden(array $response): void
{
    $envelope = responseFailure($response, 403);
    expectRegression($envelope['error'] !== '', 'The forbidden response should explain the denial.');
}

$configuredDatabase = getenv('FCT_DB_NAME');
if (
    !is_string($configuredDatabase)
    || preg_match('/\Afct_test_[A-Za-z0-9_]+\z/', $configuredDatabase) !== 1
) {
    fwrite(STDERR, "Refusing to run: FCT_DB_NAME must match fct_test_*.\n");
    exit(2);
}
if (
    PHP_SAPI !== 'cli'
    || PHP_VERSION_ID < 80200
    || !extension_loaded('pdo_mysql')
    || !extension_loaded('fileinfo')
    || !extension_loaded('mbstring')
    || !function_exists('proc_open')
    || !function_exists('stream_socket_server')
) {
    fwrite(STDERR, "PHP CLI 8.2+, pdo_mysql, fileinfo, mbstring, proc_open, and TCP streams are required.\n");
    exit(2);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/Controllers/DevelopmentPreviewController.php';
require_once __DIR__ . '/../../app/Models/SolicitudResetPassword.php';

$database = null;
$serverProcess = null;
$serverStdin = null;
$fixtureUserIds = [];
$fixtureCompanyIds = [];
$fixtureEmails = [];
$agreementId = null;
$agreementNumber = null;
$fixtureSpecialtyId = null;
$storageRoot = null;
$storageCleanupPath = null;
$exitCode = 0;
$failureMessage = '';
$passedTests = [];
$student = null;
$companyOwner = null;
$otherCompany = null;

try {
    $database = getPDOConnection();
    expectRegression(
        $database->query('SELECT DATABASE()')->fetchColumn() === $configuredDatabase,
        'The selected database did not match the fct_test database name.'
    );
    assertFeatureSchema($database, $configuredDatabase);

    $projectRoot = dirname(__DIR__, 2);
    $publicRoot = realpath($projectRoot . DIRECTORY_SEPARATOR . 'public');
    expectRegression($publicRoot !== false, 'The public document root could not be resolved.');

    $nonce = bin2hex(random_bytes(12));
    $specialtyInsert = $database->prepare(
        'INSERT INTO especialidades (nombre_especialidad) VALUES (:nombre)'
    );
    $specialtyInsert->execute(['nombre' => 'HTTP Regression ' . $nonce]);
    $fixtureSpecialtyId = (int)$database->lastInsertId();

    $storageRoot = __DIR__ . DIRECTORY_SEPARATOR . '.fct-http-storage-' . $nonce;
    $storageCleanupPath = $storageRoot;
    $sessionRoot = $storageRoot . DIRECTORY_SEPARATOR . 'sessions';
    $uploadTempRoot = $storageRoot . DIRECTORY_SEPARATOR . 'upload-tmp';
    $agreementDirectory = $storageRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'convenios';
    expectRegression(
        @mkdir($sessionRoot, 0700, true)
            && @mkdir($uploadTempRoot, 0700, true),
        'The isolated HTTP test storage could not be created.'
    );
    $storageRoot = realpath($storageRoot);
    $sessionRoot = realpath($sessionRoot);
    $uploadTempRoot = realpath($uploadTempRoot);
    expectRegression(
        is_string($storageRoot)
            && is_string($sessionRoot)
            && is_string($uploadTempRoot)
            && !pathIsWithin($storageRoot, $publicRoot)
            && !pathIsWithin($publicRoot, $storageRoot),
        'The isolated storage must remain separate from the public document root.'
    );
    $agreementDirectory = $storageRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'convenios';

    $pdfBytes = "%PDF-1.4\n"
        . "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        . "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
        . "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 10 10] /Resources << >> /Contents 4 0 R >>\nendobj\n"
        . "4 0 obj\n<< /Length 0 >>\nstream\n\nendstream\nendobj\n"
        . "trailer\n<< /Root 1 0 R >>\n%%EOF\n";

    $student = insertFixtureUser($database, $nonce, 'alumno', 'Alumno', $fixtureEmails, $fixtureUserIds);
    $companyOwner = insertFixtureUser($database, $nonce, 'empresa-a', 'Empresa', $fixtureEmails, $fixtureUserIds);
    $otherCompany = insertFixtureUser($database, $nonce, 'empresa-b', 'Empresa', $fixtureEmails, $fixtureUserIds);
    $admin = insertFixtureUser($database, $nonce, 'admin', 'Administrador', $fixtureEmails, $fixtureUserIds);
    $studentIdentity = $student['identifier'];
    $studentProfile = $database->prepare(
        'INSERT INTO alumnos
            (usuario_id, nombre, apellidos, dni, numero_seguridad_social, correo, especialidad_id)
         VALUES (:usuario_id, \'HTTP\', \'Regression\', :dni, :nss, :correo, :especialidad)'
    );
    $studentProfile->execute([
        'usuario_id' => $student['id'],
        'dni' => $studentIdentity,
        'nss' => 'HTTP' . substr($nonce, 0, 10),
        'correo' => $student['email'],
        'especialidad' => $fixtureSpecialtyId,
    ]);
    $database->prepare(
        'INSERT INTO coordinadores (usuario_id, nombre, apellidos)
         VALUES (:usuario_id, \'HTTP\', \'Administrator\')'
    )->execute(['usuario_id' => $admin['id']]);

    $companyInsert = $database->prepare(
        "INSERT INTO empresas (usuario_id, nombre_empresa, cif_empresa, estado)
         VALUES (:usuario, :nombre, :cif, 'Aprobada')"
    );
    foreach ([
        ['user' => $companyOwner, 'suffix' => 'A'],
        ['user' => $otherCompany, 'suffix' => 'B'],
    ] as $companyFixture) {
        $companyInsert->execute([
            'usuario' => $companyFixture['user']['id'],
            'nombre' => 'HTTP Regression ' . $nonce . ' ' . $companyFixture['suffix'],
            'cif' => $companyFixture['user']['identifier'],
        ]);
        $fixtureCompanyIds[] = (int)$database->lastInsertId();
    }

    $agreementNumber = 'HTTP-' . $nonce;

    $routeTable = require __DIR__ . '/../../routes/web.php';
    $routesForVerb = [];
    foreach ($routeTable as $routeName => $routeDefinition) {
        if (array_intersect(['PUT', 'DELETE'], (array)$routeDefinition['method']) !== []) {
            $routesForVerb[] = $routeName;
        }
    }
    expectRegression(
        $routesForVerb === [],
        'PUT/DELETE CSRF coverage needs updating when the MVC router adds those verbs.'
    );

    $portProbe = @stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketErrorText);
    expectRegression(is_resource($portProbe), 'An isolated local HTTP port could not be reserved.');
    $socketAddress = stream_socket_get_name($portProbe, false);
    fclose($portProbe);
    expectRegression(is_string($socketAddress) && strrpos($socketAddress, ':') !== false, 'The local HTTP port was unavailable.');
    $port = (int)substr($socketAddress, strrpos($socketAddress, ':') + 1);
    $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $nullDevice, 'a'],
        2 => ['file', $nullDevice, 'a'],
    ];
    $serverEnvironment = getenv();
    if (!is_array($serverEnvironment)) {
        $serverEnvironment = [];
    }
    $serverEnvironment['FCT_DB_NAME'] = $configuredDatabase;
    $serverEnvironment['FCT_STORAGE_PATH'] = $storageRoot;
    $serverEnvironment['APP_ENV'] = 'test';
    $serverEnvironment['FCT_PUBLIC_BASE_URL'] = 'http://127.0.0.1:' . $port;
    $serverEnvironment['FCT_TEST_MAILBOX'] = $storageRoot . DIRECTORY_SEPARATOR . 'mailbox.jsonl';
    $serverEnvironment['FCT_CLAMAV_ENABLED'] = 'false';
    $serverEnvironment['FCT_WEBHOOK_OUTBOUND_URL'] = '';
    $serverProcess = @proc_open(
        [
            PHP_BINARY,
            '-d',
            'display_errors=0',
            '-d',
            'error_log=' . $nullDevice,
            '-d',
            'sys_temp_dir=' . $storageRoot,
            '-d',
            'upload_tmp_dir=' . $uploadTempRoot,
            '-d',
            'upload_max_filesize=10M',
            '-d',
            'post_max_size=12M',
            '-d',
            'max_file_uploads=10',
            '-d',
            'session.save_path=' . $sessionRoot,
            '-S',
            '127.0.0.1:' . $port,
            '-t',
            $publicRoot,
            $projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'dev-router.php',
        ],
        $descriptors,
        $serverPipes,
        $projectRoot,
        $serverEnvironment
    );
    expectRegression(is_resource($serverProcess), 'The isolated PHP HTTP server could not be started.');
    $serverStdin = $serverPipes[0];
    fclose($serverStdin);
    $serverStdin = null;

    $ready = false;
    for ($attempt = 0; $attempt < 80; $attempt++) {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $connectError, $connectErrorText, 0.2);
        if (is_resource($socket)) {
            fclose($socket);
            $ready = true;
            break;
        }
        $processStatus = proc_get_status($serverProcess);
        if (!$processStatus['running']) {
            break;
        }
        usleep(100000);
    }
    expectRegression($ready, 'The isolated PHP HTTP server did not become ready.');

    $baseUrl = 'http://127.0.0.1:' . $port;
    $studentClient = new RegressionHttpClient($baseUrl);
    $initialToken = fetchCsrfToken($studentClient);
    $passedTests[] = 'Anonymous session GET returns the JSON envelope and CSRF token';

    $missingCsrf = $studentClient->request(
        'POST',
        'login',
        ['Content-Type' => 'application/json'],
        '{}'
    );
    responseFailure($missingCsrf, 403);
    $invalidCsrf = $studentClient->request(
        'POST',
        'login',
        [
            'Content-Type' => 'application/json',
            'X-CSRF-Token' => 'invalid-test-token',
        ],
        '{}'
    );
    responseFailure($invalidCsrf, 403);
    $passedTests[] = 'Missing and invalid POST CSRF tokens are rejected by the real middleware';

    foreach (['PUT', 'DELETE'] as $verb) {
        foreach ([null, 'invalid-test-token'] as $csrfValue) {
            $verbHeaders = [];
            if ($csrfValue !== null) {
                $verbHeaders['X-CSRF-Token'] = $csrfValue;
            }
            $response = $studentClient->request($verb, 'login', $verbHeaders);
            expectRegression($response['status'] === 405, 'Unsupported HTTP verbs should be rejected before dispatch.');
            expectRegression(
                isset($response['headers']['allow'])
                    && str_contains($response['headers']['allow'], 'GET')
                    && str_contains($response['headers']['allow'], 'POST'),
                'Unsupported HTTP verbs should expose the login route method contract.'
            );
        }
    }
    $passedTests[] = 'PUT/DELETE are documented as unavailable routes and safely return 405 before the POST-only CSRF gate';

    $wrongPassword = bin2hex(random_bytes(20));
    $failedLogin = $studentClient->request(
        'POST',
        'login',
        [
            'Content-Type' => 'application/json',
            'X-CSRF-Token' => $initialToken,
        ],
        json_encode(['identifier' => $student['identifier'], 'password' => $wrongPassword], JSON_THROW_ON_ERROR)
    );
    $failure = responseFailure($failedLogin, 401);
    expectRegression($failure['error'] === 'Credenciales incorrectas.', 'Invalid credentials should fail with the login error envelope.');

    $longUtf8Password = str_repeat('é', 37);
    expectRegression(strlen($longUtf8Password) > 72, 'The oversized UTF-8 fixture must exceed 72 bytes.');
    $oversizedPassword = $studentClient->request(
        'POST',
        'login',
        [
            'Content-Type' => 'application/json',
            'X-CSRF-Token' => $initialToken,
        ],
        json_encode(['identifier' => $student['identifier'], 'password' => $longUtf8Password], JSON_THROW_ON_ERROR)
    );
    $oversizedFailure = responseFailure($oversizedPassword, 422);
    expectRegression(
        $oversizedFailure['error'] === 'La contraseña no puede superar 72 bytes.',
        'Passwords longer than 72 UTF-8 bytes must be rejected before password verification.'
    );
    $studentCsrf = loginFixture($studentClient, $student['identifier'], $student['password'], 'Alumno');
    $passedTests[] = 'JSON login handles failure, rejects a 74-byte UTF-8 password before verification, and succeeds';

    $authenticatedSession = responseData($studentClient->request('GET', 'session'));
    expectRegression(
        isset($authenticatedSession['user']['id'])
            && $authenticatedSession['user']['id'] === $student['id']
            && $authenticatedSession['user']['rol'] === 'Alumno',
        'Authenticated session GET should return the fixture user.'
    );
    $passedTests[] = 'Authenticated session GET returns the current user';

    $studentAdminDenied = $studentClient->request('GET', 'usuarios');
    assertJsonForbidden($studentAdminDenied);
    $companyAdminClient = new RegressionHttpClient($baseUrl);
    loginFixture(
        $companyAdminClient,
        $companyOwner['identifier'],
        $companyOwner['password'],
        'Empresa'
    );
    $companyAdminDenied = $companyAdminClient->request('GET', 'usuarios');
    assertJsonForbidden($companyAdminDenied);
    $passedTests[] = 'Alumno and Empresa are denied access to the administrator-only route';

    $resetClient = new RegressionHttpClient($baseUrl);
    $resetCsrf = fetchCsrfToken($resetClient);
    $resetForm = $resetClient->request(
        'GET',
        '/index.php?action=recuperar',
        ['Accept' => 'text/html']
    );
    expectRegression(
        $resetForm['status'] === 200
            && str_contains($resetForm['body'], 'name="_csrf"')
            && str_contains($resetForm['body'], 'name="identifier"'),
        'The password recovery HTML fallback must retain an identifier field and a CSRF token.'
    );
    $missingResetCsrf = $resetClient->request(
        'POST',
        '/api/auth/solicitar-reset-password',
        ['Content-Type' => 'application/json'],
        json_encode(['identifier' => $student['email']], JSON_THROW_ON_ERROR)
    );
    responseFailure($missingResetCsrf, 403);
    $resetRequestBody = json_encode(['email' => $student['email']], JSON_THROW_ON_ERROR);
    $resetRequest = $resetClient->request(
        'POST',
        '/api/auth/solicitar-reset-password',
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $resetCsrf],
        $resetRequestBody
    );
    $resetRequestData = responseData($resetRequest, 202);
    $unknownResetRequest = $resetClient->request(
        'POST',
        '/api/auth/solicitar-reset-password',
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $resetCsrf],
        json_encode(['email' => 'unknown-' . $nonce . '@example.invalid'], JSON_THROW_ON_ERROR)
    );
    $unknownResetData = responseData($unknownResetRequest, 202);
    $dniResetRequest = $resetClient->request(
        'POST',
        '/api/auth/solicitar-reset-password',
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $resetCsrf],
        json_encode(['identifier' => $studentIdentity], JSON_THROW_ON_ERROR)
    );
    $dniResetData = responseData($dniResetRequest, 202);
    $nieResetRequest = $resetClient->request(
        'POST',
        '/api/auth/solicitar-reset-password',
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $resetCsrf],
        json_encode(['identifier' => 'X1234567L'], JSON_THROW_ON_ERROR)
    );
    $nieResetData = responseData($nieResetRequest, 202);
    $nifResetRequest = $resetClient->request(
        'POST',
        '/api/auth/solicitar-reset-password',
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $resetCsrf],
        json_encode(['identifier' => validFixtureCif('abcdef0123456789')], JSON_THROW_ON_ERROR)
    );
    $nifResetData = responseData($nifResetRequest, 202);
    expectRegression(
        $resetRequestData['message'] === $unknownResetData['message']
            && $resetRequestData['message'] === $dniResetData['message']
            && $resetRequestData['message'] === $nieResetData['message']
            && $resetRequestData['message'] === $nifResetData['message']
            && $resetRequestData['message'] === 'Si la cuenta existe y está activa, se ha registrado la solicitud para revisión del centro.',
        'Reset requests must accept email/DNI identifiers and confirm identically for known and unknown accounts.'
    );
    $resetRequestCount = $database->prepare(
        "SELECT COUNT(*) FROM solicitudes_reset_password
         WHERE usuario_id = :usuario AND estado = 'Pendiente_Aprobacion'"
    );
    $resetRequestCount->execute(['usuario' => $student['id']]);
    expectRegression(
        (int)$resetRequestCount->fetchColumn() === 1,
        'Repeated reset requests should keep a single active pending request.'
    );
    $resetAdminClient = new RegressionHttpClient($baseUrl);
    $resetAdminCsrf = loginFixture($resetAdminClient, $admin['identifier'], $admin['password'], 'Administrador');
    $resetAdminPage = $resetAdminClient->request(
        'GET',
        '/admin/solicitudes-password',
        ['Accept' => 'text/html']
    );
    expectRegression(
        $resetAdminPage['status'] === 200
            && str_contains($resetAdminPage['body'], $student['email'])
            && str_contains($resetAdminPage['body'], 'Aprobar y enviar enlace')
            && str_contains($resetAdminPage['body'], 'Rechazar')
            && str_contains($resetAdminPage['body'], 'name="_csrf"'),
        'The Administrator reset panel should list pending requests with approve and reject actions.'
    );
    $pendingResetId = (int)$database->query(
        "SELECT id FROM solicitudes_reset_password
         WHERE usuario_id = " . (int)$student['id'] . " AND estado = 'Pendiente_Aprobacion'
         ORDER BY id DESC LIMIT 1"
    )->fetchColumn();
    expectRegression($pendingResetId > 0, 'The pending reset request should have a request identifier.');
    $approveResetPage = $resetAdminClient->request(
        'POST',
        '/api/admin/solicitudes-password/' . $pendingResetId . '/aprobar',
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $resetAdminCsrf],
        '{}'
    );
    $approveResetData = responseData($approveResetPage);
    expectRegression(
        $approveResetData['estado'] === 'Aprobada'
            && !str_contains($approveResetPage['body'], 'token'),
        'Approval should send the link without exposing the token in the API response.'
    );
    $mailMessages = file($storageRoot . DIRECTORY_SEPARATOR . 'mailbox.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $firstMail = is_array($mailMessages) && $mailMessages !== []
        ? json_decode((string)end($mailMessages), true)
        : null;
    expectRegression(
        is_array($firstMail)
            && ($firstMail['to'] ?? null) === $student['email']
            && preg_match('/[?&]token=([a-f0-9]{64})/', (string)($firstMail['body'] ?? ''), $resetTokenMatch) === 1,
        'Approving a reset request should dispatch an email containing a 64-character reset token.'
    );
    $resetToken = $resetTokenMatch[1];
    $approvalAuditQuery = $database->prepare(
        "SELECT id_usuario_admin, nombre_completo, accion, entidad_afectada,
                id_registro_modificado, ip_address
         FROM audit_logs
         WHERE accion = 'APROBAR_RECUPERACION_PASSWORD'
           AND id_registro_modificado = :usuario
         ORDER BY id DESC LIMIT 1"
    );
    $approvalAuditQuery->execute(['usuario' => $student['id']]);
    $resetApprovalAudit = $approvalAuditQuery->fetch(PDO::FETCH_ASSOC);
    expectRegression(
        is_array($resetApprovalAudit)
            && (int)$resetApprovalAudit['id_usuario_admin'] === (int)$admin['id']
            && $resetApprovalAudit['nombre_completo'] === 'HTTP Administrator'
            && $resetApprovalAudit['accion'] === 'APROBAR_RECUPERACION_PASSWORD'
            && $resetApprovalAudit['entidad_afectada'] === 'usuario'
            && (int)$resetApprovalAudit['id_registro_modificado'] === (int)$student['id']
            && filter_var($resetApprovalAudit['ip_address'], FILTER_VALIDATE_IP) !== false,
        'Password reset approval must atomically persist the required administrator audit record.'
    );
    $priorReset = $database->prepare(
        "INSERT INTO solicitudes_reset_password (usuario_id, email, estado)
         VALUES (:usuario, :email, 'Pendiente_Aprobacion')"
    );
    $priorReset->execute(['usuario' => $student['id'], 'email' => $student['email']]);
    $priorApprovalId = (int)$database->lastInsertId();
    $secondApproval = $resetAdminClient->request(
        'POST',
        '/api/admin/solicitudes-password/' . $priorApprovalId . '/aprobar',
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $resetAdminCsrf],
        '{}'
    );
    responseData($secondApproval);
    $expiredPriorToken = $database->prepare(
        'SELECT estado, token FROM solicitudes_reset_password WHERE id = :id'
    );
    $expiredPriorToken->execute(['id' => $pendingResetId]);
    $expiredPriorTokenRow = $expiredPriorToken->fetch(PDO::FETCH_ASSOC);
    expectRegression(
        is_array($expiredPriorTokenRow)
            && $expiredPriorTokenRow['estado'] === 'Expirada'
            && $expiredPriorTokenRow['token'] === null,
        'Approving a new reset token must invalidate every other active token for the same user.'
    );
    $mailMessages = file($storageRoot . DIRECTORY_SEPARATOR . 'mailbox.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $secondMail = is_array($mailMessages) && $mailMessages !== []
        ? json_decode((string)end($mailMessages), true)
        : null;
    expectRegression(
        is_array($secondMail)
            && preg_match('/[?&]token=([a-f0-9]{64})/', (string)($secondMail['body'] ?? ''), $secondTokenMatch) === 1,
        'The replacement approved token should be delivered without exposing it in the response.'
    );
    $invalidatedTokenResponse = $resetClient->request(
        'POST',
        '/api/auth/confirmar-reset-password',
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $resetCsrf],
        json_encode([
            'token' => $resetToken,
            'password' => 'NewResetPassword123!',
            'confirm_password' => 'NewResetPassword123!',
        ], JSON_THROW_ON_ERROR)
    );
    responseFailure($invalidatedTokenResponse, 410);
    $resetToken = $secondTokenMatch[1];
    $storedReset = $database->prepare(
        'SELECT estado, token, expira_en, aprobado_en, aprobado_por,
                TIMESTAMPDIFF(SECOND, aprobado_en, expira_en) AS validez_segundos
         FROM solicitudes_reset_password WHERE usuario_id = :usuario ORDER BY id DESC LIMIT 1'
    );
    $storedReset->execute(['usuario' => $student['id']]);
    $storedResetRow = $storedReset->fetch(PDO::FETCH_ASSOC);
    expectRegression(
        is_array($storedResetRow)
            && $storedResetRow['estado'] === 'Aprobada'
            && $storedResetRow['token'] === hash('sha256', $resetToken)
            && (int)$storedResetRow['aprobado_por'] === $admin['id']
            && (int)$storedResetRow['validez_segundos'] === 86400,
        'Approval must store only the token hash, the Administrator id, and an exact 24-hour expiry.'
    );
    $resetPage = $resetClient->request(
        'GET',
        '/restablecer-password',
        ['Accept' => 'text/html'],
        '',
        null,
        '?token=' . rawurlencode($resetToken)
    );
    expectRegression(
        $resetPage['status'] === 200 && str_contains($resetPage['body'], 'name="confirm_password"'),
        'A valid emailed token should open the password-reset form.'
    );
    $newPassword = 'NewResetPassword123!';
    $confirmation = $resetClient->request(
        'POST',
        '/api/auth/confirmar-reset-password',
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $resetCsrf],
        json_encode([
            'token' => $resetToken,
            'password' => $newPassword,
            'confirm_password' => $newPassword,
        ], JSON_THROW_ON_ERROR)
    );
    responseData($confirmation);
    $studentPasswordHash = $database->prepare('SELECT password_hash FROM usuarios WHERE id = :id');
    $studentPasswordHash->execute(['id' => $student['id']]);
    $studentUsedRequest = $database->prepare(
        "SELECT estado, token FROM solicitudes_reset_password
         WHERE usuario_id = :usuario ORDER BY id DESC LIMIT 1"
    );
    $studentUsedRequest->execute(['usuario' => $student['id']]);
    $usedResetRow = $studentUsedRequest->fetch(PDO::FETCH_ASSOC);
    expectRegression(
        password_verify($newPassword, (string)$studentPasswordHash->fetchColumn())
            && is_array($usedResetRow)
            && $usedResetRow['estado'] === 'Usada'
            && $usedResetRow['token'] === null,
        'Confirmation should update the password and immediately consume the token.'
    );
    $revokedSession = $studentClient->request('GET', 'session');
    expectRegression(
        responseData($revokedSession)['user'] === null,
        'A password reset must invalidate an already authenticated session for the account.'
    );
    $resetCsrf = fetchCsrfToken($resetClient);
    $reusedToken = $resetClient->request(
        'POST',
        '/api/auth/confirmar-reset-password',
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $resetCsrf],
        json_encode([
            'token' => $resetToken,
            'password' => $newPassword,
            'confirm_password' => $newPassword,
        ], JSON_THROW_ON_ERROR)
    );
    responseFailure($reusedToken, 410);
    $passedTests[] = 'Password reset approval emails a 24-hour hashed token and confirmation consumes it once';

    $resetClient->request(
        'POST',
        '/api/auth/solicitar-reset-password',
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $resetCsrf],
        $resetRequestBody
    );
    $rejectedRequestId = (int)$database->query(
        "SELECT id FROM solicitudes_reset_password
         WHERE usuario_id = " . (int)$student['id'] . " AND estado = 'Pendiente_Aprobacion'
         ORDER BY id DESC LIMIT 1"
    )->fetchColumn();
    $rejectedRequest = $resetAdminClient->request(
        'POST',
        '/api/admin/solicitudes-password/' . $rejectedRequestId . '/rechazar',
        ['Content-Type' => 'application/json', 'X-CSRF-Token' => $resetAdminCsrf],
        '{}'
    );
    expectRegression(
        responseData($rejectedRequest)['estado'] === 'Rechazada',
        'An Administrator should be able to reject a pending reset request.'
    );
    $rejectAudit = $database->prepare(
        "SELECT id_usuario_admin, nombre_completo, accion, entidad_afectada, id_registro_modificado, ip_address
         FROM audit_logs
         WHERE accion = 'RECHAZAR_RECUPERACION_PASSWORD'
           AND id_registro_modificado = :usuario
         ORDER BY id DESC LIMIT 1"
    );
    $rejectAudit->execute(['usuario' => $student['id']]);
    $rejectAuditRow = $rejectAudit->fetch(PDO::FETCH_ASSOC);
    expectRegression(
        is_array($rejectAuditRow)
            && (int)$rejectAuditRow['id_usuario_admin'] === (int)$admin['id']
            && $rejectAuditRow['nombre_completo'] === 'HTTP Administrator'
            && $rejectAuditRow['accion'] === 'RECHAZAR_RECUPERACION_PASSWORD'
            && $rejectAuditRow['entidad_afectada'] === 'usuario'
            && (int)$rejectAuditRow['id_registro_modificado'] === (int)$student['id']
            && filter_var($rejectAuditRow['ip_address'], FILTER_VALIDATE_IP) !== false,
        'Password reset rejection must persist the required administrator audit record.'
    );
    $originalRemoteAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $_SERVER['REMOTE_ADDR'] = '198.51.100.77';
    $resetModel = new SolicitudResetPassword($database);
    for ($rateAttempt = 0; $rateAttempt < 31; $rateAttempt++) {
        $resetModel->solicitar(
            'rate-limit-' . $rateAttempt . '@example.invalid',
            new DateTimeImmutable()
        );
    }
    $resetModel->solicitar($companyOwner['email'], new DateTimeImmutable());
    $companyResetCount = $database->prepare(
        "SELECT COUNT(*) FROM solicitudes_reset_password
         WHERE usuario_id = :usuario AND estado = 'Pendiente_Aprobacion'"
    );
    $companyResetCount->execute(['usuario' => $companyOwner['id']]);
    expectRegression(
        (int)$companyResetCount->fetchColumn() === 0,
        'Reset requests must enforce a per-IP throttle without exposing rate-limit state to callers.'
    );
    if ($originalRemoteAddress === null) {
        unset($_SERVER['REMOTE_ADDR']);
    } else {
        $_SERVER['REMOTE_ADDR'] = $originalRemoteAddress;
    }
    for ($identifierAttempt = 0; $identifierAttempt < 5; $identifierAttempt++) {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.' . ($identifierAttempt + 1);
        $resetModel->solicitar($companyOwner['email'], new DateTimeImmutable());
    }
    $database->prepare(
        "DELETE FROM solicitudes_reset_password
         WHERE usuario_id = :usuario AND estado = 'Pendiente_Aprobacion'"
    )->execute(['usuario' => $companyOwner['id']]);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.6';
    $resetModel->solicitar($companyOwner['email'], new DateTimeImmutable());
    $companyResetCount->execute(['usuario' => $companyOwner['id']]);
    expectRegression(
        (int)$companyResetCount->fetchColumn() === 0,
        'Reset requests must enforce an identifier-specific throttle across source IPs.'
    );
    if ($originalRemoteAddress === null) {
        unset($_SERVER['REMOTE_ADDR']);
    } else {
        $_SERVER['REMOTE_ADDR'] = $originalRemoteAddress;
    }
    $passedTests[] = 'Administrator can reject pending reset requests';

    $registrationClient = new RegressionHttpClient($baseUrl);
    $registrationCsrf = fetchCsrfToken($registrationClient);
    $registrationPage = $registrationClient->request('GET', '/registro-empresa', ['Accept' => 'text/html']);
    expectRegression(
        $registrationPage['status'] === 200
            && str_contains($registrationPage['body'], 'name="sector"')
            && str_contains($registrationPage['body'], 'name="dni_representante"')
            && str_contains($registrationPage['body'], 'NIF (Persona Jurídica)')
            && !str_contains($registrationPage['body'], 'CIF'),
        'The MVC company registration page should expose the required fields and use the approved legal-person tax-ID terminology.'
    );
    $registrationEmail = 'http-regression-' . $nonce . '-public-company@example.invalid';
    $registrationPassword = 'Regression-Pass-' . substr($nonce, 0, 12);
    $registrationTaxId = validFixtureCif($nonce);
    $missingSectorEmail = 'http-regression-' . $nonce . '-missing-sector@example.invalid';
    $missingSectorBoundary = 'fct-missing-sector-' . $nonce;
    $missingSectorBody = multipartFieldsBody([
        '_csrf' => $registrationCsrf,
        'email' => $missingSectorEmail,
        'password' => $registrationPassword,
        'razon_social' => 'Missing Sector ' . $nonce . ' S.L.',
        'nombre_comercial' => 'Missing Sector ' . $nonce,
        'cif' => $registrationTaxId,
        'representante_legal' => 'Representante de prueba',
        'dni_representante' => '12345678Z',
        'telefono' => '600123456',
        'direccion' => 'Calle de prueba 1, Madrid',
    ], $missingSectorBoundary) . '--' . $missingSectorBoundary . "--\r\n";
    $missingSectorResponse = $registrationClient->request(
        'POST',
        '/api/public/empresas/solicitud',
        [
            'Content-Type' => 'multipart/form-data; boundary=' . $missingSectorBoundary,
            'Content-Length' => (string)strlen($missingSectorBody),
            'X-CSRF-Token' => $registrationCsrf,
        ],
        $missingSectorBody
    );
    responseFailure($missingSectorResponse, 422);
    $missingSectorUserCount = $database->prepare('SELECT COUNT(*) FROM usuarios WHERE email = :email');
    $missingSectorUserCount->execute(['email' => $missingSectorEmail]);
    expectRegression(
        (int)$missingSectorUserCount->fetchColumn() === 0,
        'A company registration without a sector must not create a user.'
    );
    $passedTests[] = 'Company registration page and API require sector before persisting the account';

    $registrationBoundary = 'fct-registration-' . $nonce;
    $registrationBody = multipartFieldsBody([
        '_csrf' => $registrationCsrf,
        'email' => $registrationEmail,
        'password' => $registrationPassword,
        'razon_social' => 'HTTP Registration ' . $nonce . ' S.L.',
        'nombre_comercial' => 'HTTP Registration ' . $nonce,
        'sector' => 'Tecnología',
        'cif' => $registrationTaxId,
        'representante_legal' => 'Representante de prueba',
        'dni_representante' => '12345678Z',
        'telefono' => '600123456',
        'direccion' => 'Calle de prueba 1, Madrid',
    ], $registrationBoundary) . '--' . $registrationBoundary . "--\r\n";
    $registrationResponse = $registrationClient->request(
        'POST',
        '/api/public/empresas/solicitud',
        [
            'Content-Type' => 'multipart/form-data; boundary=' . $registrationBoundary,
            'Content-Length' => (string)strlen($registrationBody),
            'X-CSRF-Token' => $registrationCsrf,
        ],
        $registrationBody
    );
    $registrationData = responseData($registrationResponse, 201);
    expectRegression(
        isset($registrationData['id'])
            && $registrationData['estado'] === 'Pendiente_Aprobacion'
            && str_contains($registrationData['message'], 'Pendiente de Aprobación'),
        'Public registration should return the pending approval state and confirmation message.'
    );
    $findRegistration = $database->prepare(
        'SELECT e.id, e.usuario_id, e.nombre_empresa, e.nombre_comercial, e.categoria, e.cif_empresa,
                e.representante_legal, e.dni_representante, e.estado
         FROM empresas e
         INNER JOIN usuarios u ON u.id = e.usuario_id
         WHERE u.email = :email'
    );
    $findRegistration->execute(['email' => $registrationEmail]);
    $registeredCompany = $findRegistration->fetch(PDO::FETCH_ASSOC);
    expectRegression(is_array($registeredCompany), 'The public registration should persist its company profile.');
    $fixtureEmails[] = $registrationEmail;
    $fixtureUserIds[] = (int)$registeredCompany['usuario_id'];
    $fixtureCompanyIds[] = (int)$registeredCompany['id'];
    expectRegression(
        $registeredCompany['estado'] === 'Pendiente_Aprobacion'
            && $registeredCompany['nombre_comercial'] === 'HTTP Registration ' . $nonce
            && $registeredCompany['categoria'] === 'Tecnología'
            && $registeredCompany['cif_empresa'] === $registrationTaxId
            && $registeredCompany['representante_legal'] === 'Representante de prueba'
            && $registeredCompany['dni_representante'] === '12345678Z',
        'Public registration should persist legal data in the pending state.'
    );

    $pendingLogin = $registrationClient->request(
        'POST',
        'login',
        [
            'Content-Type' => 'application/json',
            'X-CSRF-Token' => $registrationCsrf,
        ],
        json_encode([
            'identifier' => $registrationTaxId,
            'password' => $registrationPassword,
        ], JSON_THROW_ON_ERROR)
    );
    $pendingLoginFailure = responseFailure($pendingLogin, 403);
    expectRegression(
        str_contains($pendingLoginFailure['error'], 'pendiente de aprobación'),
        'A pending company should receive an explanatory HTTP 403 at login.'
    );
    $pendingStatusPage = $registrationClient->request(
        'GET',
        'solicitud-pendiente',
        ['Accept' => 'text/html']
    );
    expectRegression(
        $pendingStatusPage['status'] === 200
            && str_contains($pendingStatusPage['body'], 'Su solicitud de convenio se encuentra en proceso de validación administrativa.'),
        'Pending companies should receive the MVC application-status page after JSON login is rejected.'
    );
    $passedTests[] = 'Public company registration persists a pending request and blocks login until approval';

    $adminClient = new RegressionHttpClient($baseUrl);
    $adminCsrf = loginFixture($adminClient, $admin['identifier'], $admin['password'], 'Administrador');
    $pendingList = $adminClient->request('GET', '/admin/convenios');
    expectRegression(
        $pendingList['status'] === 200
            && str_contains($pendingList['body'], 'HTTP Registration ' . $nonce . ' S.L.')
            && str_contains($pendingList['body'], 'Pendiente_Aprobacion'),
        'The administrator request list should show pending companies by default.'
    );
    $approvalNumber = 'MARCO-' . $nonce;
    $approvalBoundary = 'fct-approval-' . $nonce;
    $approvalBody = multipartBody([
        '_csrf' => $adminCsrf,
        'numero_convenio' => $approvalNumber,
    ], 'convenio-firmado.pdf', $pdfBytes, $approvalBoundary, 'pdf_convenio');
    $approvalResponse = $adminClient->request(
        'POST',
        '/api/admin/empresas/' . (int)$registeredCompany['id'] . '/aprobar-convenio',
        [
            'Content-Type' => 'multipart/form-data; boundary=' . $approvalBoundary,
            'Content-Length' => (string)strlen($approvalBody),
            'X-CSRF-Token' => $adminCsrf,
        ],
        $approvalBody
    );
    $approvalData = responseData($approvalResponse);
    expectRegression(
        $approvalData['estado'] === 'Aprobada'
            && (int)$approvalData['id'] === (int)$registeredCompany['id'],
        'The admin approval endpoint should return the approved company.'
    );
    $approvedCompanyQuery = $database->prepare(
        'SELECT estado, numero_convenio, pdf_convenio_path, fecha_aprobacion, fecha_firma_convenio
         FROM empresas WHERE id = :id'
    );
    $approvedCompanyQuery->execute(['id' => $registeredCompany['id']]);
    $approvedCompany = $approvedCompanyQuery->fetch(PDO::FETCH_ASSOC);
    expectRegression(
        is_array($approvedCompany)
            && $approvedCompany['estado'] === 'Aprobada'
            && $approvedCompany['numero_convenio'] === $approvalNumber
            && is_string($approvedCompany['fecha_aprobacion'])
            && is_string($approvedCompany['fecha_firma_convenio'])
            && $approvedCompany['fecha_firma_convenio'] === $approvedCompany['fecha_aprobacion']
            && preg_match('#\Auploads/convenios-marco/[a-f0-9]{48}\.pdf\z#', (string)$approvedCompany['pdf_convenio_path']) === 1,
        'Approval should persist its state, agreement number, approval date, and private PDF path.'
    );
    $approvalAuditQuery = $database->prepare(
        "SELECT id_usuario_admin, nombre_completo, accion, entidad_afectada,
                id_registro_modificado, ip_address, `timestamp`
         FROM audit_logs
         WHERE accion = 'APROBAR_EMPRESA' AND id_registro_modificado = :id
         ORDER BY id DESC LIMIT 1"
    );
    $approvalAuditQuery->execute(['id' => $registeredCompany['id']]);
    $approvalAudit = $approvalAuditQuery->fetch(PDO::FETCH_ASSOC);
    expectRegression(
        is_array($approvalAudit)
            && (int)$approvalAudit['id_usuario_admin'] === (int)$admin['id']
            && trim($approvalAudit['nombre_completo']) !== ''
            && $approvalAudit['accion'] === 'APROBAR_EMPRESA'
            && $approvalAudit['entidad_afectada'] === 'empresa'
            && (int)$approvalAudit['id_registro_modificado'] === (int)$registeredCompany['id']
            && filter_var($approvalAudit['ip_address'], FILTER_VALIDATE_IP) !== false
            && is_string($approvalAudit['timestamp'])
            && $approvalAudit['timestamp'] !== '',
        'Approval should persist the mandatory audit_logs record with the administrator, action, entity, record, IP, and timestamp.'
    );
    $legacyApprovalAudit = $database->prepare(
        "SELECT COUNT(*) FROM auditoria
         WHERE usuario_id = :admin_id
           AND evento = 'empresa.aprobada'
           AND entidad = 'empresas'
           AND entidad_id = :empresa_id"
    );
    $legacyApprovalAudit->execute([
        'admin_id' => $admin['id'],
        'empresa_id' => $registeredCompany['id'],
    ]);
    expectRegression(
        (int)$legacyApprovalAudit->fetchColumn() === 1,
        'Approval should continue writing its existing auditoria event alongside audit_logs.'
    );
    $passedTests[] = 'Company approval writes the required audit_logs record';
    $approvalFile = $storageRoot . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $approvedCompany['pdf_convenio_path']);
    $resolvedApprovalFile = realpath($approvalFile);
    expectRegression(
        is_string($resolvedApprovalFile)
            && pathIsWithin($resolvedApprovalFile, $storageRoot)
            && !pathIsWithin($resolvedApprovalFile, $publicRoot)
            && file_get_contents($resolvedApprovalFile) === $pdfBytes,
        'The signed agreement PDF should be scanned and stored outside the public document root.'
    );

    $approvedCompanyClient = new RegressionHttpClient($baseUrl);
    loginFixture(
        $approvedCompanyClient,
        $registrationTaxId,
        $registrationPassword,
        'Empresa'
    );
    $approvedCompanyHtmlClient = new RegressionHttpClient($baseUrl);
    loginFixture(
        $approvedCompanyHtmlClient,
        $registrationTaxId,
        $registrationPassword,
        'Empresa'
    );
    $rejectedCompanyClient = new RegressionHttpClient($baseUrl);
    loginFixture(
        $rejectedCompanyClient,
        $registrationTaxId,
        $registrationPassword,
        'Empresa'
    );
    $setPending = $database->prepare("UPDATE empresas SET estado = 'Pendiente_Aprobacion' WHERE id = :id");
    $setPending->execute(['id' => $registeredCompany['id']]);
    try {
        $blockedCompanyPage = $approvedCompanyClient->request('GET', 'sedes');
        $blockedCompanyFailure = responseFailure($blockedCompanyPage, 403);
        expectRegression(
            str_contains($blockedCompanyFailure['error'], 'pendiente de aprobación'),
            'An existing company session should lose private access when its approval state is pending.'
        );
        $blockedHtmlPage = $approvedCompanyHtmlClient->request(
            'GET',
            'sedes',
            ['Accept' => 'text/html']
        );
        expectRegression(
            $blockedHtmlPage['status'] === 302
                && str_contains($blockedHtmlPage['headers']['location'] ?? '', 'action=solicitud-pendiente'),
            'HTML requests from a pending company should redirect to the MVC status page.'
        );
        $htmlStatusPage = $approvedCompanyHtmlClient->request(
            'GET',
            'solicitud-pendiente',
            ['Accept' => 'text/html']
        );
        expectRegression(
            $htmlStatusPage['status'] === 200
                && str_contains($htmlStatusPage['body'], 'Su solicitud de convenio se encuentra en proceso de validación administrativa.'),
            'The protected-route redirect should show the company approval status message.'
        );
        $setRejected = $database->prepare("UPDATE empresas SET estado = 'Rechazada' WHERE id = :id");
        $setRejected->execute(['id' => $registeredCompany['id']]);
        $rejectedMiddleware = responseFailure($rejectedCompanyClient->request('GET', 'sedes'), 403);
        expectRegression(
            str_contains($rejectedMiddleware['error'], 'rechazada'),
            'An existing company session should lose private access when its approval state is rejected.'
        );
        $rejectedLoginClient = new RegressionHttpClient($baseUrl);
        $rejectedLoginCsrf = fetchCsrfToken($rejectedLoginClient);
        $rejectedLogin = $rejectedLoginClient->request(
            'POST',
            'login',
            [
                'Content-Type' => 'application/json',
                'X-CSRF-Token' => $rejectedLoginCsrf,
            ],
            json_encode([
                'identifier' => $registrationTaxId,
                'password' => $registrationPassword,
            ], JSON_THROW_ON_ERROR)
        );
        $rejectedLoginFailure = responseFailure($rejectedLogin, 403);
        expectRegression(
            str_contains($rejectedLoginFailure['error'], 'rechazada'),
            'A rejected company should be denied login with an explanatory HTTP 403.'
        );
    } finally {
        $restoreApproval = $database->prepare("UPDATE empresas SET estado = 'Aprobada' WHERE id = :id");
        $restoreApproval->execute(['id' => $registeredCompany['id']]);
    }
    $passedTests[] = 'Admin approval scans and stores the private PDF and gates existing company sessions by state';

    $studentCsrf = loginFixture($studentClient, $student['identifier'], $newPassword, 'Alumno');
    $staleStudentCookie = $studentClient->cookieHeader();
    expectRegression($staleStudentCookie !== '', 'An authenticated HTTP session cookie should be present.');
    $logoutResponse = $studentClient->request(
        'POST',
        'logout',
        [
            'Content-Type' => 'application/json',
            'X-CSRF-Token' => $studentCsrf,
        ],
        '{}'
    );
    $logoutData = responseData($logoutResponse);
    expectRegression(
        array_key_exists('user', $logoutData) && $logoutData['user'] === null,
        'JSON logout should clear the authenticated user.'
    );
    $staleSession = $studentClient->request('GET', 'session', [], '', $staleStudentCookie);
    $staleData = responseData($staleSession);
    expectRegression(
        array_key_exists('user', $staleData) && $staleData['user'] === null,
        'A destroyed session cookie must not restore an authenticated session.'
    );

    $expiringClient = new RegressionHttpClient($baseUrl);
    loginFixture($expiringClient, $student['identifier'], $newPassword, 'Alumno');
    $disableAccount = $database->prepare('UPDATE usuarios SET activo = 0 WHERE id = :id');
    $disableAccount->execute(['id' => $student['id']]);
    try {
        $expiredSession = responseData($expiringClient->request('GET', 'session'));
        expectRegression(
            array_key_exists('user', $expiredSession) && $expiredSession['user'] === null,
            'The session endpoint must expire sessions for inactive accounts.'
        );
    } finally {
        $enableAccount = $database->prepare('UPDATE usuarios SET activo = 1 WHERE id = :id');
        $enableAccount->execute(['id' => $student['id']]);
    }
    $passedTests[] = 'Logout destroys the session and session GET expires inactive accounts';

    $companyOwnerClient = new RegressionHttpClient($baseUrl);
    $companyOwnerCsrf = loginFixture(
        $companyOwnerClient,
        $companyOwner['identifier'],
        $companyOwner['password'],
        'Empresa'
    );
    expectRegression($companyOwnerCsrf !== '', 'Company login should return a CSRF token.');

    $invalidMimeNumber = $agreementNumber . '-invalid-mime';
    $invalidMimeBoundary = 'fct-invalid-mime-' . $nonce;
    $invalidMimeBody = multipartBody([
        'numero_convenio' => $invalidMimeNumber,
        'especialidad_id' => (string)$fixtureSpecialtyId,
        'sede_id' => '',
        'fecha_firma' => '2026-01-01',
        'fecha_inicio' => '2026-02-01',
        'fecha_fin' => '2027-01-31',
    ], 'not-a-pdf.txt', 'plain text is not a PDF', $invalidMimeBoundary);
    $invalidMimeUpload = $companyOwnerClient->request(
        'POST',
        'convenios',
        [
            'Content-Type' => 'multipart/form-data; boundary=' . $invalidMimeBoundary,
            'Content-Length' => (string)strlen($invalidMimeBody),
            'X-CSRF-Token' => $companyOwnerCsrf,
        ],
        $invalidMimeBody
    );
    expectRegression(
        $invalidMimeUpload['status'] === 200
            && str_contains($invalidMimeUpload['body'], 'El documento debe ser un archivo PDF.'),
        'The HTTP upload should reject a mismatched extension and detected MIME type.'
    );
    $invalidMimeCount = $database->prepare(
        'SELECT COUNT(*) FROM convenios WHERE empresa_id = :empresa AND numero_convenio = :numero'
    );
    $invalidMimeCount->execute([
        'empresa' => $fixtureCompanyIds[0],
        'numero' => $invalidMimeNumber,
    ]);
    expectRegression(
        (int)$invalidMimeCount->fetchColumn() === 0 && !is_dir($agreementDirectory),
        'A rejected MIME type should not create a convention row or private upload directory.'
    );

    $oversizedNumber = $agreementNumber . '-oversized';
    $oversizedBoundary = 'fct-oversized-' . $nonce;
    $oversizedBytes = str_repeat('x', 10 * 1024 * 1024 + 1);
    $oversizedBody = multipartBody([
        'numero_convenio' => $oversizedNumber,
        'especialidad_id' => (string)$fixtureSpecialtyId,
        'sede_id' => '',
        'fecha_firma' => '2026-01-01',
        'fecha_inicio' => '2026-02-01',
        'fecha_fin' => '2027-01-31',
    ], 'oversized.pdf', $oversizedBytes, $oversizedBoundary);
    $oversizedUpload = $companyOwnerClient->request(
        'POST',
        'convenios',
        [
            'Content-Type' => 'multipart/form-data; boundary=' . $oversizedBoundary,
            'Content-Length' => (string)strlen($oversizedBody),
            'X-CSRF-Token' => $companyOwnerCsrf,
        ],
        $oversizedBody
    );
    expectRegression(
        $oversizedUpload['status'] === 200
            && str_contains($oversizedUpload['body'], 'Adjunta un PDF válido de hasta 10 MB.'),
        'The HTTP upload should reject a PDF larger than the configured 10 MiB maximum.'
    );
    $oversizedCount = $database->prepare(
        'SELECT COUNT(*) FROM convenios WHERE empresa_id = :empresa AND numero_convenio = :numero'
    );
    $oversizedCount->execute([
        'empresa' => $fixtureCompanyIds[0],
        'numero' => $oversizedNumber,
    ]);
    expectRegression(
        (int)$oversizedCount->fetchColumn() === 0 && !is_dir($agreementDirectory),
        'An oversized upload should not create a convention row or private upload directory.'
    );
    $passedTests[] = 'HTTP upload rejects invalid PDF MIME/extension and files larger than 10 MiB without persisting them';

    $uploadBoundary = 'fct-regression-' . $nonce;
    $uploadBody = multipartBody([
        'numero_convenio' => $agreementNumber,
        'especialidad_id' => (string)$fixtureSpecialtyId,
        'sede_id' => '',
        'fecha_firma' => '2026-01-01',
        'fecha_inicio' => '2026-02-01',
        'fecha_fin' => '2027-01-31',
    ], 'fixture.pdf', $pdfBytes, $uploadBoundary);
    $upload = $companyOwnerClient->request(
        'POST',
        'convenios',
        [
            'Content-Type' => 'multipart/form-data; boundary=' . $uploadBoundary,
            'Content-Length' => (string)strlen($uploadBody),
            'X-CSRF-Token' => $companyOwnerCsrf,
        ],
        $uploadBody
    );
    expectRegression(
        $upload['status'] === 302
            && isset($upload['headers']['location'])
            && str_contains($upload['headers']['location'], 'action=convenios'),
        'A valid convention upload should complete and redirect to the company agreements page.'
    );
    $findUploadedAgreement = $database->prepare(
        'SELECT id, archivo_path FROM convenios WHERE empresa_id = :empresa AND numero_convenio = :numero'
    );
    $findUploadedAgreement->execute([
        'empresa' => $fixtureCompanyIds[0],
        'numero' => $agreementNumber,
    ]);
    $uploadedAgreement = $findUploadedAgreement->fetch(PDO::FETCH_ASSOC);
    expectRegression(
        is_array($uploadedAgreement)
            && isset($uploadedAgreement['id'], $uploadedAgreement['archivo_path'])
            && is_string($uploadedAgreement['archivo_path'])
            && preg_match('/\A[a-f0-9]{48}\.pdf\z/', $uploadedAgreement['archivo_path']) === 1,
        'The HTTP upload should create a private convention record with a generated filename.'
    );
    $agreementId = (int)$uploadedAgreement['id'];
    $storedPdfName = $uploadedAgreement['archivo_path'];
    $resolvedAgreementDirectory = realpath($agreementDirectory);
    expectRegression(
        is_string($resolvedAgreementDirectory)
            && !pathIsWithin($resolvedAgreementDirectory, $publicRoot)
            && !pathIsWithin($publicRoot, $resolvedAgreementDirectory)
            && is_file($resolvedAgreementDirectory . DIRECTORY_SEPARATOR . $storedPdfName),
        'The HTTP upload should store the PDF in the isolated private storage root.'
    );
    $passedTests[] = 'Convention upload accepts the safe PDF fixture and stores it privately';

    $download = $companyOwnerClient->request(
        'GET',
        'descargar-convenio',
        [],
        '',
        null,
        '&id=' . $agreementId
    );
    expectRegression($download['status'] === 200, 'The owning company should be able to download its agreement.');
    expectRegression(
        ($download['headers']['content-type'] ?? '') === 'application/pdf',
        'Agreement download should return application/pdf.'
    );
    expectRegression(
        isset($download['headers']['content-length'])
            && (int)$download['headers']['content-length'] === strlen($pdfBytes),
        'Agreement download should return the exact content length.'
    );
    expectRegression(
        ($download['headers']['content-disposition'] ?? '')
            === 'attachment; filename="convenio-' . $agreementId . '.pdf"',
        'Agreement download should use an attachment filename.'
    );
    expectRegression($download['body'] === $pdfBytes, 'Agreement download should return the safe fixture bytes.');
    $passedTests[] = 'Convention download returns the expected PDF headers and body to its owning company';

    $otherCompanyClient = new RegressionHttpClient($baseUrl);
    loginFixture(
        $otherCompanyClient,
        $otherCompany['identifier'],
        $otherCompany['password'],
        'Empresa'
    );
    $otherCompanyDownload = $otherCompanyClient->request(
        'GET',
        'descargar-convenio',
        [],
        '',
        null,
        '&id=' . $agreementId
    );
    assertJsonForbidden($otherCompanyDownload);
    $passedTests[] = 'A different Empresa account cannot download another company agreement';
} catch (HttpRegressionFailure $error) {
    $failureMessage = $error->getMessage();
    $exitCode = 1;
} catch (Throwable) {
    $failureMessage = 'An unexpected failure occurred; sensitive details are suppressed.';
    $exitCode = 1;
} finally {
    if (is_resource($serverStdin)) {
        fclose($serverStdin);
    }
    if (!stopTestServer($serverProcess)) {
        $failureMessage = 'The isolated HTTP server did not stop cleanly.';
        $exitCode = 1;
    }

    if ($database instanceof PDO) {
        try {
            if ($fixtureEmails !== []) {
                $deleteAttempts = $database->prepare('DELETE FROM intentos_login WHERE email = :email');
                foreach ($fixtureEmails as $email) {
                    $deleteAttempts->execute(['email' => $email]);
                }
            }
            if ($agreementId === null && $agreementNumber !== null && $fixtureCompanyIds !== []) {
                $findAgreement = $database->prepare(
                    'SELECT id FROM convenios WHERE empresa_id = :empresa AND numero_convenio = :numero'
                );
                $findAgreement->execute([
                    'empresa' => $fixtureCompanyIds[0],
                    'numero' => $agreementNumber,
                ]);
                $foundAgreementId = $findAgreement->fetchColumn();
                if ($foundAgreementId !== false) {
                    $agreementId = (int)$foundAgreementId;
                }
            }
            if ($agreementId !== null) {
                $deleteAgreementAudit = $database->prepare(
                    'DELETE FROM auditoria WHERE entidad = \'convenios\' AND entidad_id = :id'
                );
                $deleteAgreementAudit->execute(['id' => $agreementId]);
                $deleteAgreement = $database->prepare('DELETE FROM convenios WHERE id = :id');
                $deleteAgreement->execute(['id' => $agreementId]);
            }
            if ($fixtureSpecialtyId !== null) {
                $deleteSpecialty = $database->prepare('DELETE FROM especialidades WHERE id = :id');
                $deleteSpecialty->execute(['id' => $fixtureSpecialtyId]);
            }
            if ($fixtureUserIds !== []) {
                $placeholders = implode(',', array_fill(0, count($fixtureUserIds), '?'));
                $deleteAudit = $database->prepare(
                    'DELETE FROM auditoria WHERE usuario_id IN (' . $placeholders . ')'
                );
                $deleteAudit->execute($fixtureUserIds);
            }
            if ($fixtureCompanyIds !== []) {
                $placeholders = implode(',', array_fill(0, count($fixtureCompanyIds), '?'));
                $deleteApprovalEvent = $database->prepare(
                    "DELETE FROM auditoria
                     WHERE evento = 'empresa.aprobada'
                       AND entidad = 'empresas'
                       AND entidad_id IN ($placeholders)"
                );
                $deleteApprovalEvent->execute($fixtureCompanyIds);
                $deleteApprovalAudit = $database->prepare(
                    "DELETE FROM audit_logs
                     WHERE accion = 'APROBAR_EMPRESA' AND id_registro_modificado IN ($placeholders)"
                );
                $deleteApprovalAudit->execute($fixtureCompanyIds);
                $deleteCompanies = $database->prepare(
                    'DELETE FROM empresas WHERE id IN (' . $placeholders . ')'
                );
                $deleteCompanies->execute($fixtureCompanyIds);
            }
            if ($fixtureUserIds !== []) {
                $placeholders = implode(',', array_fill(0, count($fixtureUserIds), '?'));
                $deleteUsers = $database->prepare(
                    'DELETE FROM usuarios WHERE id IN (' . $placeholders . ')'
                );
                $deleteUsers->execute($fixtureUserIds);
            }
        } catch (Throwable) {
            $failureMessage = 'Fixture database cleanup failed; inspect the fct_test database before rerunning.';
            $exitCode = 1;
        }
    }

    try {
        if (
            is_string($storageCleanupPath)
            && $storageCleanupPath !== ''
            && !removeTestDirectory($storageCleanupPath)
        ) {
            $failureMessage = 'Isolated test storage cleanup failed.';
            $exitCode = 1;
        }
    } catch (Throwable) {
        $failureMessage = 'Isolated test storage cleanup failed.';
        $exitCode = 1;
    }
}

if ($exitCode !== 0) {
    fwrite(STDERR, 'FAIL: ' . $failureMessage . PHP_EOL);
    exit($exitCode);
}

foreach ($passedTests as $passedTest) {
    fwrite(STDOUT, 'PASS: ' . $passedTest . PHP_EOL);
}
fwrite(STDOUT, 'HTTP regression suite passed.' . PHP_EOL);
