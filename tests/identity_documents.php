<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Core/View.php';
require_once __DIR__ . '/../app/Middleware/CsrfMiddleware.php';
require_once __DIR__ . '/../app/Controllers/AlumnoAdminController.php';
require_once __DIR__ . '/../app/Controllers/EmpresaController.php';

function assertIdentity(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function captureIdentityOutput(callable $action): string
{
    ob_start();
    try {
        $action();
        return (string)ob_get_contents();
    } finally {
        ob_end_clean();
    }
}

// Solo lecturas vacías: un ciclo inexistente permite comprobar que el documento
// superó la validación sin crear cuentas ni enviar correos.
final class IdentityReadOnlyDatabase extends PDO
{
    public int $cycleChecks = 0;

    public function __construct() {}

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (!str_starts_with(ltrim($query), 'SELECT ')) {
            throw new RuntimeException('La prueba no permite escrituras.');
        }
        return $this->emptyStatement();
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($query !== 'SELECT 1 FROM especialidades WHERE id = :id AND activo = 1 LIMIT 1') {
            throw new RuntimeException('Consulta inesperada en la prueba de validación.');
        }
        $this->cycleChecks++;
        return $this->emptyStatement();
    }

    private function emptyStatement(): PDOStatement
    {
        return new class extends PDOStatement {
            public function execute(?array $params = null): bool { return true; }
            public function fetchColumn(int $column = 0): mixed { return false; }
            public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return []; }
        };
    }
}

$identityServer = $_SERVER;
$identityPost = $_POST;
$identityGet = $_GET;
$identityHadSession = isset($_SESSION);
$identitySession = $_SESSION ?? [];

try {
    $_SESSION = ['usuario_id' => 1, 'rol' => 'Administrador'];
    $_GET = [];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $identityDb = new IdentityReadOnlyDatabase();
    $identityController = new AlumnoAdminController($identityDb);
    $identityCases = [
        ['NIF_NIE_DNI', '12345678Z', true],
        ['NIF_NIE_DNI', 'X1234567L', true],
        ['NIF_NIE_DNI', 'Y1234567X', true],
        ['NIF_NIE_DNI', 'Z1234567R', true],
        ['NIF_NIE_DNI', '12345678A', false],
        ['NIF_NIE_DNI', 'X1234567A', false],
        ['NIF_NIE_DNI', 'AB1234', false],
        ['PASAPORTE', 'AB1234', true],
        ['PASAPORTE', 'abc123456789012', true],
        ['PASAPORTE', '123456', true],
        ['PASAPORTE', '12345678A', true], // No aplicar letra de NIF al pasaporte.
        ['PASAPORTE', 'ABCDE', false],
        ['PASAPORTE', 'ABCDEFGHIJKLMNOP', false],
        ['PASAPORTE', 'ABC-123', false],
        ['PASAPORTE', 'ABC 123', false],
        ['PASAPORTE', 'ÁBC123', false],
        ['PASAPORTE', '', false],
        ['DESCONOCIDO', '12345678Z', false],
        ['', '12345678Z', false],
        [['PASAPORTE'], 'AB1234', false],
        ['PASAPORTE', ['AB1234'], false],
    ];
    foreach ($identityCases as [$type, $number, $valid]) {
        $_POST = [
            'tipo_documento' => $type, 'dni' => $number,
            'nombre' => 'Ana', 'apellidos' => 'García', 'email' => 'ana@example.test',
            'especialidad_id' => '1', 'curso' => '1', 'observaciones_administrativas' => '',
        ];
        $before = $identityDb->cycleChecks;
        $html = captureIdentityOutput(fn() => $identityController->gestionar());
        $case = json_encode([$type, $number], JSON_UNESCAPED_UNICODE);
        assertIdentity(
            $identityDb->cycleChecks - $before === ($valid ? 1 : 0),
            "Validación incorrecta según tipo seleccionado: $case"
        );
        assertIdentity(
            str_contains($html, $valid ? 'Selecciona un ciclo formativo activo.' : 'message-error'),
            "Falta mensaje de validación para $case"
        );
    }

    // Se inspecciona el HTML real, incluyendo el estado tras un envío fallido.
    foreach (['NIF_NIE_DNI' => 'NIF / NIE / DNI', 'PASAPORTE' => 'Pasaporte'] as $type => $label) {
        $html = captureIdentityOutput(fn() => View::render('admin/alumnos', [
            'error' => '', 'creado' => false, 'values' => ['tipo_documento' => $type, 'dni' => 'AB1234'],
            'especialidades' => [], 'alumnos' => [],
        ]));
        assertIdentity(
            preg_match('/<option value="' . preg_quote($type, '/') . '"\s+selected\s*>' . preg_quote($label, '/') . '<\/option>/', $html) === 1,
            'El selector debe conservar el tipo seleccionado y mostrar NIF o Pasaporte.'
        );
        assertIdentity(
            str_contains($html, 'Opción destinada a casos especiales de alumnado no residente según la normativa de Formación Profesional')
                && str_contains($html, 'aria-describedby="student-document-help"')
                && str_contains($html, 'id="student-document-help"'),
            'La nota del pasaporte debe estar visible y asociada al selector.'
        );
        assertIdentity(preg_match('/\bCIF\b/i', $html) === 0, 'El alta de alumnado no debe mostrar CIF.');
    }

    $company = new EmpresaController($identityDb);
    $staffHtml = captureIdentityOutput(fn() => View::render('admin/usuarios', [
        'error' => '', 'eliminado' => false, 'creado' => false, 'usuarios' => [],
    ]));
    assertIdentity(
        str_contains($staffHtml, 'NIF / NIE / DNI (identificador de acceso)')
            && preg_match('/\bCIF\b/i', $staffHtml) === 0,
        'El alta de usuarios individuales debe mostrar NIF / NIE / DNI y no CIF.'
    );
    $companyHtml = captureIdentityOutput(fn() => View::render('empresas/registro', ['error' => '']));
    assertIdentity(
        str_contains($companyHtml, 'NIF (Persona Jurídica)')
            && !str_contains($companyHtml, 'name="password"')
            && !str_contains($companyHtml, 'CIF'),
        'El alta de empresa debe etiquetar su NIF de persona jurídica y no solicitar contraseña antes de activar.'
    );
    foreach (['B12345678', 'P1234567E', '12345678Z', 'AB1234', '', ['B12345674']] as $invalidNif) {
        $_POST = [
            'nif_persona_juridica' => $invalidNif, 'email' => 'empresa@example.test',
            'password' => 'PasswordDePrueba123!', 'nombre_comercial' => 'Empresa de prueba',
            'direccion' => 'Calle de prueba 1', 'telefono' => '', 'persona_contacto' => '',
        ];
        $html = captureIdentityOutput(fn() => $company->registrar());
        assertIdentity(str_contains($html, 'role="alert"'), 'El NIF de empresa inválido debe mostrar un error.');
        if (is_string($invalidNif)) {
            assertIdentity(
                str_contains($html, 'Introduce un NIF (Persona Jurídica) con un carácter de control válido.'),
                'El mensaje debe explicar el error del NIF de empresa.'
            );
        }
        assertIdentity(preg_match('/\bCIF\b/i', $html) === 0, 'El registro de empresa no debe contener CIF.');
        assertIdentity(str_contains($html, 'name="nif_persona_juridica"'), 'El formulario debe usar el campo NIF de empresa.');
    }
} finally {
    $_SERVER = $identityServer;
    $_POST = $identityPost;
    $_GET = $identityGet;
    if ($identityHadSession) {
        $_SESSION = $identitySession;
    } else {
        unset($_SESSION);
    }
}

echo "OK: documentos NIF/NIE/DNI y Pasaporte, nota accesible, NIF jurídico y activación sin contraseña inicial.\n";
