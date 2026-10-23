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
    expectRegression($response['status'] === $status, 'Unexpected HTTP status.');
    $envelope = responseEnvelope($response);
    expectRegression(
        $envelope['success'] === true && isset($envelope['data']) && is_array($envelope['data']),
        'Expected a successful JSON response.'
    );

    return $envelope['data'];
}

function responseFailure(array $response, int $status): array
{
    expectRegression($response['status'] === $status, 'Unexpected HTTP error status.');
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
        $headerLines = ['Accept: application/json', 'Connection: close'];
        foreach ($headers as $name => $value) {
            $headerLines[] = is_int($name) ? (string)$value : $name . ': ' . $value;
        }

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
    string $email,
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
        json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR)
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
    $statement = $db->prepare(
        'INSERT INTO usuarios (email, password_hash, rol, activo)
         VALUES (:email, :password_hash, :rol, 1)'
    );
    $statement->execute([
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'rol' => $role,
    ]);
    $fixtureEmails[] = $email;

    $userId = (int)$db->lastInsertId();
    $fixtureUserIds[] = $userId;

    return [
        'id' => $userId,
        'email' => $email,
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
           AND TABLE_NAME IN (\'usuarios\', \'empresas\', \'especialidades\', \'convenios\', \'auditoria\', \'intentos_login\')'
    );
    $statement->execute(['schema' => $databaseName]);
    $columns = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $columns[$row['TABLE_NAME']][$row['COLUMN_NAME']] = true;
    }

    $required = [
        'usuarios' => ['id', 'email', 'password_hash', 'rol', 'activo'],
        'empresas' => [
            'id', 'usuario_id', 'nombre_empresa', 'nombre_comercial', 'cif_empresa',
            'representante_legal', 'dni_representante', 'estado', 'numero_convenio',
            'pdf_convenio_path', 'fecha_aprobacion',
        ],
        'especialidades' => ['id', 'nombre_especialidad'],
        'convenios' => ['id', 'empresa_id', 'especialidad_id', 'numero_convenio', 'fecha_firma', 'fecha_inicio', 'fecha_fin', 'archivo_path'],
        'auditoria' => ['id', 'usuario_id', 'evento', 'entidad', 'entidad_id', 'detalle'],
        'intentos_login' => ['id', 'email', 'ip', 'fecha'],
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
            'cif' => 'T' . substr($nonce, 0, 12) . $companyFixture['suffix'],
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
        json_encode(['email' => $student['email'], 'password' => $wrongPassword], JSON_THROW_ON_ERROR)
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
        json_encode(['email' => $student['email'], 'password' => $longUtf8Password], JSON_THROW_ON_ERROR)
    );
    $oversizedFailure = responseFailure($oversizedPassword, 422);
    expectRegression(
        $oversizedFailure['error'] === 'La contraseña no puede superar 72 bytes.',
        'Passwords longer than 72 UTF-8 bytes must be rejected before password verification.'
    );
    $studentCsrf = loginFixture($studentClient, $student['email'], $student['password'], 'Alumno');
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
        $companyOwner['email'],
        $companyOwner['password'],
        'Empresa'
    );
    $companyAdminDenied = $companyAdminClient->request('GET', 'usuarios');
    assertJsonForbidden($companyAdminDenied);
    $passedTests[] = 'Alumno and Empresa are denied access to the administrator-only route';

    $registrationClient = new RegressionHttpClient($baseUrl);
    $registrationCsrf = fetchCsrfToken($registrationClient);
    $registrationEmail = 'http-regression-' . $nonce . '-public-company@example.invalid';
    $registrationPassword = 'Regression-Pass-' . substr($nonce, 0, 12);
    $registrationTaxId = validFixtureCif($nonce);
    $registrationBoundary = 'fct-registration-' . $nonce;
    $registrationBody = multipartFieldsBody([
        '_csrf' => $registrationCsrf,
        'email' => $registrationEmail,
        'password' => $registrationPassword,
        'razon_social' => 'HTTP Registration ' . $nonce . ' S.L.',
        'nombre_comercial' => 'HTTP Registration ' . $nonce,
        'cif' => $registrationTaxId,
        'representante_legal' => 'Representante de prueba',
        'dni_representante' => '12345678Z',
        'telefono' => '600123456',
        'direccion' => 'Calle de prueba 1, Madrid',
    ], $registrationBoundary) . '--' . $registrationBoundary . "--\r\n";
    $registrationResponse = $registrationClient->request(
        'POST',
        '/api/public/empresas/registro',
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
        'SELECT e.id, e.usuario_id, e.nombre_empresa, e.nombre_comercial, e.cif_empresa,
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
            'email' => $registrationEmail,
            'password' => $registrationPassword,
        ], JSON_THROW_ON_ERROR)
    );
    $pendingLoginFailure = responseFailure($pendingLogin, 403);
    expectRegression(
        str_contains($pendingLoginFailure['error'], 'pendiente de aprobación'),
        'A pending company should receive an explanatory HTTP 403 at login.'
    );
    $passedTests[] = 'Public company registration persists a pending request and blocks login until approval';

    $adminClient = new RegressionHttpClient($baseUrl);
    $adminCsrf = loginFixture($adminClient, $admin['email'], $admin['password'], 'Administrador');
    $pendingList = $adminClient->request('GET', 'solicitudes-empresa');
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
        '/api/admin/empresas/' . (int)$registeredCompany['id'] . '/aprobar',
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
        'SELECT estado, numero_convenio, pdf_convenio_path, fecha_aprobacion
         FROM empresas WHERE id = :id'
    );
    $approvedCompanyQuery->execute(['id' => $registeredCompany['id']]);
    $approvedCompany = $approvedCompanyQuery->fetch(PDO::FETCH_ASSOC);
    expectRegression(
        is_array($approvedCompany)
            && $approvedCompany['estado'] === 'Aprobada'
            && $approvedCompany['numero_convenio'] === $approvalNumber
            && is_string($approvedCompany['fecha_aprobacion'])
            && preg_match('#\Auploads/convenios-marco/[a-f0-9]{48}\.pdf\z#', (string)$approvedCompany['pdf_convenio_path']) === 1,
        'Approval should persist its state, agreement number, approval date, and private PDF path.'
    );
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
        $registrationEmail,
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
    } finally {
        $restoreApproval = $database->prepare("UPDATE empresas SET estado = 'Aprobada' WHERE id = :id");
        $restoreApproval->execute(['id' => $registeredCompany['id']]);
    }
    $passedTests[] = 'Admin approval scans and stores the private PDF and gates existing company sessions by state';

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
    loginFixture($expiringClient, $student['email'], $student['password'], 'Alumno');
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
        $companyOwner['email'],
        $companyOwner['password'],
        'Empresa'
    );
    expectRegression($companyOwnerCsrf !== '', 'Company login should return a CSRF token.');
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
        $otherCompany['email'],
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
