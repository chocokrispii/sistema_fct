<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este seed solo puede ejecutarse desde PHP CLI.\n");
    exit(2);
}
if (getenv('FCT_ENABLE_DEMO_SEED') !== '1') {
    fwrite(STDERR, "Activa FCT_ENABLE_DEMO_SEED=1 para confirmar que se trata de una base local de desarrollo.\n");
    exit(2);
}

$dbHost = getenv('FCT_DB_HOST') ?: 'localhost';
$dbName = getenv('FCT_DB_NAME') ?: 'sistema_fct';
$dockerAllowed = $dbHost === 'db' && getenv('FCT_DEMO_SEED_ALLOW_DOCKER') === '1';
if (
    $dbName !== 'sistema_fct'
    || (!in_array($dbHost, ['localhost', '127.0.0.1'], true) && !$dockerAllowed)
) {
    fwrite(STDERR, "Por seguridad, este seed solo admite sistema_fct local o Docker con confirmación explícita.\n");
    exit(2);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/storage.php';

function demoEnsureUser(PDO $db, string $email, string $role): int
{
    $find = $db->prepare('SELECT id, rol FROM usuarios WHERE email = :email LIMIT 1');
    $find->execute(['email' => $email]);
    $user = $find->fetch(PDO::FETCH_ASSOC);
    if ($user !== false) {
        if ($user['rol'] !== $role) {
            throw new RuntimeException("La cuenta sintética $email ya existe con otro rol.");
        }
        return (int)$user['id'];
    }

    $insert = $db->prepare(
        'INSERT INTO usuarios (email, password_hash, rol)
         VALUES (:email, :password_hash, :rol)'
    );
    $insert->execute([
        'email' => $email,
        'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
        'rol' => $role,
    ]);
    return (int)$db->lastInsertId();
}

function demoCreatePdf(string $company, string $agreementNumber, string $uploadDirectory): array
{
    $lines = [
        'DOCUMENTO SINTETICO DE DEMOSTRACION - SIN VALIDEZ LEGAL',
        'Convenio: ' . $agreementNumber,
        'Empresa: ' . $company,
        'Documento generado unicamente para probar la interfaz local del Sistema FCT.',
        'No representa un convenio firmado ni debe utilizarse en un tramite real.',
    ];
    $escape = static fn(string $text): string => str_replace(
        ['\\', '(', ')'],
        ['\\\\', '\\(', '\\)'],
        $text
    );
    $stream = "BT\n/F1 14 Tf\n50 740 Td\n(" . $escape($lines[0]) . ") Tj\n";
    $stream .= "/F1 11 Tf\n0 -35 Td\n(" . $escape($lines[1]) . ") Tj\n";
    foreach (array_slice($lines, 2) as $line) {
        $stream .= "0 -25 Td\n(" . $escape($line) . ") Tj\n";
    }
    $stream .= "ET\n";

    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        5 => '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream',
    ];
    $pdf = "%PDF-1.4\n% FCT synthetic document\n";
    $offsets = [0];
    foreach ($objects as $id => $object) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    for ($id = 1; $id <= 5; $id++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
    }
    $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF\n";

    do {
        $filename = bin2hex(random_bytes(24)) . '.pdf';
        $path = $uploadDirectory . DIRECTORY_SEPARATOR . $filename;
    } while (file_exists($path));

    if (file_put_contents($path, $pdf, LOCK_EX) !== strlen($pdf)) {
        if (is_file($path) && !unlink($path)) {
            error_log('No se pudo retirar un PDF sintético escrito parcialmente.');
        }
        throw new RuntimeException('No se pudo guardar el PDF sintético del convenio.');
    }
    return [$filename, $path];
}

$storageRoot = fctStoragePath();
$uploadDirectory = $storageRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'convenios';
if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0700, true) && !is_dir($uploadDirectory)) {
    throw new RuntimeException('No se pudo preparar la carpeta privada para documentos de convenios.');
}
$resolvedStorageRoot = realpath($storageRoot);
$resolvedUploadDirectory = realpath($uploadDirectory);
if (
    $resolvedStorageRoot === false
    || $resolvedUploadDirectory === false
    || is_link($uploadDirectory)
    || !str_starts_with(
        $resolvedUploadDirectory . DIRECTORY_SEPARATOR,
        rtrim($resolvedStorageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
    )
) {
    throw new RuntimeException('La carpeta de convenios debe estar dentro del almacenamiento privado configurado.');
}

$companies = [
    ['key' => '01', 'email' => 'empresa.demo01@example.invalid', 'name' => 'Empresa Demo FCT 01', 'cif' => 'DEMO-FCT-EMP-001', 'contact' => 'Contacto Sintetico 01', 'specialty' => 'Desarrollo de Aplicaciones Web (DAW)', 'shift' => 'M'],
    ['key' => '02', 'email' => 'empresa.demo02@example.invalid', 'name' => 'Empresa Demo FCT 02', 'cif' => 'DEMO-FCT-EMP-002', 'contact' => 'Contacto Sintetico 02', 'specialty' => 'Desarrollo de Aplicaciones Multiplataforma (DAM)', 'shift' => 'T'],
    ['key' => '03', 'email' => 'empresa.demo03@example.invalid', 'name' => 'Empresa Demo FCT 03', 'cif' => 'DEMO-FCT-EMP-003', 'contact' => 'Contacto Sintetico 03', 'specialty' => 'Sistemas Microinformáticos y Redes (SMR)', 'shift' => 'Indiferente'],
];
$students = [
    ['key' => '01', 'email' => 'alumno.demo01@example.invalid', 'name' => 'Alumno Demo', 'surname' => 'Muestra 01', 'dni' => 'DEMO-ALU-001', 'nss' => 'DEMO-SS-001', 'specialty' => 'Desarrollo de Aplicaciones Web (DAW)'],
    ['key' => '02', 'email' => 'alumno.demo02@example.invalid', 'name' => 'Alumno Demo', 'surname' => 'Muestra 02', 'dni' => 'DEMO-ALU-002', 'nss' => 'DEMO-SS-002', 'specialty' => 'Desarrollo de Aplicaciones Web (DAW)'],
    ['key' => '03', 'email' => 'alumno.demo03@example.invalid', 'name' => 'Alumno Demo', 'surname' => 'Muestra 03', 'dni' => 'DEMO-ALU-003', 'nss' => 'DEMO-SS-003', 'specialty' => 'Desarrollo de Aplicaciones Multiplataforma (DAM)'],
    ['key' => '04', 'email' => 'alumno.demo04@example.invalid', 'name' => 'Alumno Demo', 'surname' => 'Muestra 04', 'dni' => 'DEMO-ALU-004', 'nss' => 'DEMO-SS-004', 'specialty' => 'Desarrollo de Aplicaciones Multiplataforma (DAM)'],
    ['key' => '05', 'email' => 'alumno.demo05@example.invalid', 'name' => 'Alumno Demo', 'surname' => 'Muestra 05', 'dni' => 'DEMO-ALU-005', 'nss' => 'DEMO-SS-005', 'specialty' => 'Sistemas Microinformáticos y Redes (SMR)'],
    ['key' => '06', 'email' => 'alumno.demo06@example.invalid', 'name' => 'Alumno Demo', 'surname' => 'Muestra 06', 'dni' => 'DEMO-ALU-006', 'nss' => 'DEMO-SS-006', 'specialty' => 'Sistemas Microinformáticos y Redes (SMR)'],
];

$db = getPDOConnection();
$requiredTables = [
    'usuarios',
    'empresas',
    'alumnos',
    'especialidades',
    'empresa_sedes',
    'empresa_especialidades_plazas',
    'convenios',
    'alumno_disponibilidad',
    'alumno_disponibilidad_semanal',
];
foreach ($requiredTables as $table) {
    $check = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
    );
    $check->execute(['table' => $table]);
    if ((int)$check->fetchColumn() !== 1) {
        throw new RuntimeException("Falta la tabla $table; aplica primero las migraciones del esquema.");
    }
}

$specialties = [];
foreach ($db->query('SELECT id, nombre_especialidad FROM especialidades') as $specialty) {
    $specialties[$specialty['nombre_especialidad']] = (int)$specialty['id'];
}
foreach (array_merge(
    array_column($companies, 'specialty'),
    array_column($students, 'specialty')
) as $specialtyName) {
    if (!isset($specialties[$specialtyName])) {
        throw new RuntimeException("No existe la especialidad requerida: $specialtyName.");
    }
}

$generatedFiles = [];
$createdCounts = ['empresas' => 0, 'alumnos' => 0, 'ofertas' => 0, 'convenios' => 0];
$db->beginTransaction();
try {
    $reviewerId = $db->query(
        "SELECT id FROM usuarios WHERE rol = 'Administrador' ORDER BY id LIMIT 1"
    )->fetchColumn();
    if ($reviewerId === false) {
        throw new RuntimeException('Se necesita una cuenta Administrador para asociar las revisiones sintéticas.');
    }
    $reviewerId = (int)$reviewerId;

    $companyIds = [];
    foreach ($companies as $company) {
        $userId = demoEnsureUser($db, $company['email'], 'Empresa');
        $findCompany = $db->prepare('SELECT id FROM empresas WHERE usuario_id = :usuario LIMIT 1');
        $findCompany->execute(['usuario' => $userId]);
        $companyId = $findCompany->fetchColumn();
        if ($companyId === false) {
            $insertCompany = $db->prepare(
                'INSERT INTO empresas (usuario_id, nombre_empresa, cif_empresa, persona_contacto)
                 VALUES (:usuario, :nombre, :cif, :contacto)'
            );
            $insertCompany->execute([
                'usuario' => $userId,
                'nombre' => $company['name'],
                'cif' => $company['cif'],
                'contacto' => $company['contact'],
            ]);
            $companyId = $db->lastInsertId();
            $createdCounts['empresas']++;
        }
        $companyIds[$company['key']] = (int)$companyId;
    }

    foreach ($students as $student) {
        $userId = demoEnsureUser($db, $student['email'], 'Alumno');
        $findStudent = $db->prepare('SELECT id FROM alumnos WHERE usuario_id = :usuario LIMIT 1');
        $findStudent->execute(['usuario' => $userId]);
        $studentId = $findStudent->fetchColumn();
        if ($studentId === false) {
            $insertStudent = $db->prepare(
                'INSERT INTO alumnos
                    (usuario_id, nombre, apellidos, fecha_nacimiento, dni,
                     numero_seguridad_social, codigo_postal, correo, especialidad_id)
                 VALUES (:usuario, :nombre, :apellidos, :nacimiento, :dni, :nss, :postal, :correo, :especialidad)'
            );
            $insertStudent->execute([
                'usuario' => $userId,
                'nombre' => $student['name'],
                'apellidos' => $student['surname'],
                'nacimiento' => '2000-01-01',
                'dni' => $student['dni'],
                'nss' => $student['nss'],
                'postal' => '28013',
                'correo' => $student['email'],
                'especialidad' => $specialties[$student['specialty']],
            ]);
            $studentId = $db->lastInsertId();
            $createdCounts['alumnos']++;
        }
        $findAvailability = $db->prepare(
            'SELECT id FROM alumno_disponibilidad
             WHERE alumno_id = :alumno AND fecha_inicio = :inicio AND fecha_fin = :fin
             LIMIT 1'
        );
        $findAvailability->execute([
            'alumno' => $studentId,
            'inicio' => '2027-02-01',
            'fin' => '2027-06-30',
        ]);
        $availabilityId = $findAvailability->fetchColumn();
        if ($availabilityId === false) {
            $insertAvailability = $db->prepare(
                'INSERT INTO alumno_disponibilidad (alumno_id, fecha_inicio, fecha_fin, turno)
                 VALUES (:alumno, :inicio, :fin, :turno)'
            );
            $insertAvailability->execute([
                'alumno' => $studentId,
                'inicio' => '2027-02-01',
                'fin' => '2027-06-30',
                'turno' => 'Indiferente',
            ]);
            $availabilityId = $db->lastInsertId();
        }
        $findWeekly = $db->prepare(
            'SELECT id FROM alumno_disponibilidad_semanal
             WHERE disponibilidad_id = :disponibilidad AND dia_semana = 1
             LIMIT 1'
        );
        $findWeekly->execute(['disponibilidad' => $availabilityId]);
        if ($findWeekly->fetchColumn() === false) {
            $insertWeekly = $db->prepare(
                'INSERT INTO alumno_disponibilidad_semanal
                    (disponibilidad_id, dia_semana, hora_inicio, hora_fin)
                 VALUES (:disponibilidad, 1, :inicio, :fin)'
            );
            $insertWeekly->execute([
                'disponibilidad' => $availabilityId,
                'inicio' => '08:00:00',
                'fin' => '18:00:00',
            ]);
        }
    }

    $offers = [];
    foreach ($companies as $index => $company) {
        $offers[] = [
            'company' => $company,
            'specialty' => $company['specialty'],
            'start' => '2027-02-01',
            'end' => '2027-06-30',
            'state' => 'Aprobada',
            'shift' => $company['shift'],
            'number' => sprintf('DEMO-FCT-2027-%02d', $index + 1),
        ];
    }
    $offers[] = [
        'company' => $companies[0],
        'specialty' => 'Desarrollo de Aplicaciones Multiplataforma (DAM)',
        'start' => '2027-03-01',
        'end' => '2027-06-30',
        'state' => 'Pendiente',
        'shift' => 'Indiferente',
        'number' => 'DEMO-FCT-2027-04',
    ];

    foreach ($offers as $offer) {
        $companyId = $companyIds[$offer['company']['key']];
        $specialtyId = $specialties[$offer['specialty']];
        $findSite = $db->prepare(
            'SELECT id FROM empresa_sedes
             WHERE empresa_id = :empresa AND nombre = :nombre AND activa = 1 LIMIT 1'
        );
        $findSite->execute([
            'empresa' => $companyId,
            'nombre' => 'Sede de Demostracion ' . $offer['company']['key'],
        ]);
        $siteId = $findSite->fetchColumn();
        if ($siteId === false) {
            $insertSite = $db->prepare(
                'INSERT INTO empresa_sedes (empresa_id, nombre, direccion)
                 VALUES (:empresa, :nombre, :direccion)'
            );
            $insertSite->execute([
                'empresa' => $companyId,
                'nombre' => 'Sede de Demostracion ' . $offer['company']['key'],
                'direccion' => 'Direccion sintetica de pruebas, Madrid',
            ]);
            $siteId = $db->lastInsertId();
        }

        $findOffer = $db->prepare(
            'SELECT id FROM empresa_especialidades_plazas
             WHERE empresa_id = :empresa AND especialidad_id = :especialidad
               AND fecha_inicio = :inicio AND fecha_fin = :fin
             LIMIT 1'
        );
        $findOffer->execute([
            'empresa' => $companyId,
            'especialidad' => $specialtyId,
            'inicio' => $offer['start'],
            'fin' => $offer['end'],
        ]);
        if ($findOffer->fetchColumn() === false) {
            $reviewedAt = $offer['state'] === 'Aprobada' ? date('Y-m-d H:i:s') : null;
            $insertOffer = $db->prepare(
                'INSERT INTO empresa_especialidades_plazas
                    (empresa_id, sede_id, especialidad_id, plazas_cuantas, fecha_inicio, fecha_fin, estado, turno,
                     revisado_por, revisado_en)
                 VALUES (:empresa, :sede, :especialidad, 2, :inicio, :fin, :estado, :turno, :revisor, :revisado_en)'
            );
            $insertOffer->execute([
                'empresa' => $companyId,
                'sede' => (int)$siteId,
                'especialidad' => $specialtyId,
                'inicio' => $offer['start'],
                'fin' => $offer['end'],
                'estado' => $offer['state'],
                'turno' => $offer['shift'],
                'revisor' => $offer['state'] === 'Aprobada' ? $reviewerId : null,
                'revisado_en' => $reviewedAt,
            ]);
            $createdCounts['ofertas']++;
        }

        $agreementExists = $db->prepare(
            'SELECT id FROM convenios
             WHERE numero_convenio = :numero
                OR (empresa_id = :empresa AND especialidad_id = :especialidad)
             LIMIT 1'
        );
        $agreementExists->execute([
            'numero' => $offer['number'],
            'empresa' => $companyId,
            'especialidad' => $specialtyId,
        ]);
        if ($agreementExists->fetchColumn() === false) {
            [$filename, $pdfPath] = demoCreatePdf(
                $offer['company']['name'],
                $offer['number'],
                $resolvedUploadDirectory
            );
            $generatedFiles[] = $pdfPath;
            $agreementState = $offer['state'] === 'Aprobada' ? 'Aprobado' : 'Pendiente';
            $reviewedAt = $agreementState === 'Aprobado' ? date('Y-m-d H:i:s') : null;
            $insertAgreement = $db->prepare(
                'INSERT INTO convenios
                    (empresa_id, especialidad_id, sede_id, numero_convenio, fecha_firma,
                     fecha_inicio, fecha_fin, archivo_path, estado, revisado_por, revisado_en)
                 VALUES (:empresa, :especialidad, :sede, :numero, :firma, :inicio, :fin,
                         :archivo, :estado, :revisor, :revisado_en)'
            );
            $insertAgreement->execute([
                'empresa' => $companyId,
                'especialidad' => $specialtyId,
                'sede' => (int)$siteId,
                'numero' => $offer['number'],
                'firma' => '2026-10-15',
                'inicio' => $offer['start'],
                'fin' => $offer['end'],
                'archivo' => $filename,
                'estado' => $agreementState,
                'revisor' => $agreementState === 'Aprobado' ? $reviewerId : null,
                'revisado_en' => $reviewedAt,
            ]);
            $createdCounts['convenios']++;
        }
    }

    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    foreach ($generatedFiles as $file) {
        if (is_file($file) && !unlink($file)) {
            error_log('No se pudo retirar un PDF sintético tras fallar el seed.');
        }
    }
    throw $exception;
}

echo "Datos sintéticos listos (solo desarrollo; identificados como DEMO):\n";
foreach ($createdCounts as $entity => $count) {
    echo "  $entity nuevos: $count\n";
}
echo "  Empresas de muestra: empresas-centro (Administración/Coordinación)\n";
echo "  Ofertas aprobadas: ofertas (público) y emparejar (candidatos compatibles)\n";
echo "  Convenios aprobados y pendientes: revisar-convenios (Administración/Coordinación)\n";
