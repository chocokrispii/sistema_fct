<?php

declare(strict_types=1);

$testDatabase = getenv('FCT_TEST_DB_NAME');
if (!is_string($testDatabase)
    || preg_match('/\Afct_test_[a-zA-Z0-9_]+\z/', $testDatabase) !== 1
    || getenv('FCT_DB_NAME') !== $testDatabase) {
    fwrite(STDERR, "Define FCT_DB_NAME y FCT_TEST_DB_NAME con la misma base fct_test_*.\n");
    exit(1);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Core/View.php';
require_once __DIR__ . '/../app/Core/Paginacion.php';
require_once __DIR__ . '/../app/Core/JsonResponse.php';
require_once __DIR__ . '/../app/Middleware/CsrfMiddleware.php';
require_once __DIR__ . '/../app/Controllers/OfertaController.php';

function assertOfferSite(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = getPDOConnection();
$requiredSite = $db->query(
    "SELECT IS_NULLABLE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'empresa_especialidades_plazas' AND COLUMN_NAME = 'sede_id'"
)->fetchColumn();
assertOfferSite($requiredSite === 'NO', 'sede_id debe ser obligatorio en el esquema de ofertas.');
$siteForeignKey = (int)$db->query(
    "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'empresa_especialidades_plazas'
       AND CONSTRAINT_NAME = 'fk_oferta_sede_empresa'
       AND COLUMN_NAME = 'sede_id'
       AND REFERENCED_TABLE_NAME = 'empresa_sedes'"
)->fetchColumn();
assertOfferSite($siteForeignKey === 1, 'La sede de la oferta debe quedar protegida por una clave foránea.');

$fixture = bin2hex(random_bytes(5));
$companyIds = [];
$userIds = [];
$offerIds = [];
$siteIds = [];

try {
    $specialtyId = (int)$db->query('SELECT id FROM especialidades WHERE activo = 1 ORDER BY id LIMIT 1')->fetchColumn();
    assertOfferSite($specialtyId > 0, 'La base de pruebas necesita al menos un ciclo activo.');

    $insertUser = $db->prepare(
        'INSERT INTO usuarios (email, password_hash, rol) VALUES (:email, :password, \'Empresa\')'
    );
    $insertCompany = $db->prepare(
        'INSERT INTO empresas (usuario_id, nombre_empresa, cif_empresa, persona_contacto)
         VALUES (:usuario, :nombre, :cif, :contacto)'
    );
    foreach (['Principal', 'Ajena'] as $label) {
        $insertUser->execute([
            'email' => strtolower($label) . "-sede-$fixture@example.test",
            'password' => password_hash('TestPassword123!', PASSWORD_DEFAULT),
        ]);
        $userIds[] = (int)$db->lastInsertId();
        $insertCompany->execute([
            'usuario' => end($userIds),
            'nombre' => "Empresa $label $fixture",
            'cif' => strtoupper(substr($fixture, 0, 8)) . (count($companyIds) + 1),
            'contacto' => 'Contacto de pruebas',
        ]);
        $companyIds[] = (int)$db->lastInsertId();
    }

    $companyModel = new Empresa($db);
    $offerModel = new Oferta($db);
    $existingSiteId = $companyModel->crearSede(
        $companyIds[0],
        "Sede existente $fixture",
        'Calle de la Empresa 10, Madrid',
        '910000001'
    );
    $siteIds[] = $existingSiteId;
    $foreignSiteId = $companyModel->crearSede(
        $companyIds[1],
        "Sede ajena $fixture",
        'Calle ajena 20, Madrid',
        ''
    );
    $siteIds[] = $foreignSiteId;

    $_SESSION = ['usuario_id' => $userIds[0], 'rol' => 'Empresa'];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = [];
    $_POST = [
        'titulo' => 'Oferta sin sede',
        'descripcion' => 'Prueba de validación.',
        'especialidad_id' => (string)$specialtyId,
        'plazas' => '1',
        'fecha_inicio' => '2027-03-01',
        'fecha_fin' => '2027-06-30',
        'turno' => 'M',
        'modalidad' => 'Presencial',
        'horarios' => [1 => ['activo' => '1', 'hora_inicio' => '09:00', 'hora_fin' => '17:00']],
    ];
    ob_start();
    (new OfertaController($db))->publicar();
    $invalidForm = (string)ob_get_clean();
    assertOfferSite(
        str_contains($invalidForm, 'id="offer-site-error"')
            && str_contains($invalidForm, 'aria-invalid="true"')
            && str_contains($invalidForm, 'Selecciona una sede activa'),
        'El formulario debe marcar el selector y explicar que falta una sede válida.'
    );

    $beforeInvalid = (int)$db->query(
        'SELECT COUNT(*) FROM empresa_especialidades_plazas WHERE empresa_id = ' . $companyIds[0]
    )->fetchColumn();
    assertOfferSite($beforeInvalid === 0, 'El envío sin sede no debe guardar ninguna oferta.');

    $createOffer = static function (int $siteId, string $title) use ($offerModel, $companyIds, $specialtyId): int {
        return $offerModel->crearOferta(
            $companyIds[0],
            $siteId,
            $specialtyId,
            1,
            '2027-03-01',
            '2027-06-30',
            $title,
            'Oferta de integración.',
            'M',
            'Presencial',
            null,
            'Prueba',
            [['dia_semana' => 1, 'hora_inicio' => '09:00:00', 'hora_fin' => '17:00:00']]
        );
    };

    $existingOfferId = $createOffer($existingSiteId, "Oferta sede existente $fixture");
    $offerIds[] = $existingOfferId;
    $listedOffer = array_values(array_filter(
        $offerModel->obtenerPorEmpresa($companyIds[0]),
        static fn(array $offer): bool => (int)$offer['id'] === $existingOfferId
    ))[0] ?? null;
    assertOfferSite(
        is_array($listedOffer)
            && (int)$listedOffer['sede_id'] === $existingSiteId
            && $listedOffer['sede_nombre'] === "Sede existente $fixture"
            && $listedOffer['sede_direccion'] === 'Calle de la Empresa 10, Madrid'
            && $listedOffer['sede_telefono'] === '910000001',
        'El detalle de la oferta debe incluir la sede y su dirección completa.'
    );

    $db->beginTransaction();
    try {
        $inlineSiteId = $companyModel->crearSede(
            $companyIds[0],
            "Sede nueva $fixture",
            'Avenida de la Innovación 25, Planta 3, Madrid',
            '910000002'
        );
        $siteIds[] = $inlineSiteId;
        $inlineOfferId = $createOffer($inlineSiteId, "Oferta sede nueva $fixture");
        $offerIds[] = $inlineOfferId;
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }

    $inlineOffer = array_values(array_filter(
        $offerModel->obtenerPorEmpresa($companyIds[0]),
        static fn(array $offer): bool => (int)$offer['id'] === $inlineOfferId
    ))[0] ?? null;
    assertOfferSite(
        is_array($inlineOffer)
            && (int)$inlineOffer['sede_id'] === $inlineSiteId
            && $inlineOffer['sede_nombre'] === "Sede nueva $fixture"
            && $inlineOffer['sede_direccion'] === 'Avenida de la Innovación 25, Planta 3, Madrid',
        'La sede creada dentro del formulario debe persistir y quedar asociada a la oferta.'
    );

    $foreignSiteRejected = false;
    try {
        $createOffer($foreignSiteId, "Oferta sede ajena $fixture");
    } catch (DomainException $exception) {
        $foreignSiteRejected = str_contains($exception->getMessage(), 'sede');
    }
    assertOfferSite($foreignSiteRejected, 'No se debe aceptar una sede de otra empresa.');

    $foreignKeyRejected = false;
    try {
        $invalidOffer = $db->prepare(
            "INSERT INTO empresa_especialidades_plazas
                (empresa_id, sede_id, especialidad_id, plazas_cuantas, fecha_inicio, fecha_fin,
                 titulo, descripcion, turno, modalidad, ubicacion)
             VALUES (:empresa, :sede, :especialidad, 1, '2027-03-01', '2027-06-30',
                 'Sede ajena', 'Prueba', 'M', 'Presencial', 'Calle ajena 20, Madrid')"
        );
        $invalidOffer->execute([
            'empresa' => $companyIds[0],
            'sede' => $foreignSiteId,
            'especialidad' => $specialtyId,
        ]);
    } catch (PDOException) {
        $foreignKeyRejected = true;
    }
    assertOfferSite($foreignKeyRejected, 'La base de datos debe impedir asociar a la oferta la sede de otra empresa.');
} finally {
    if ($offerIds !== []) {
        $deleteOffers = $db->prepare(
            'DELETE FROM empresa_especialidades_plazas WHERE id IN ('
            . implode(',', array_map('intval', $offerIds)) . ')'
        );
        $deleteOffers->execute();
    }
    foreach ($siteIds as $siteId) {
        $deleteSite = $db->prepare('DELETE FROM empresa_sedes WHERE id = :id');
        $deleteSite->execute(['id' => $siteId]);
    }
    foreach ($companyIds as $companyId) {
        $deleteCompany = $db->prepare('DELETE FROM empresas WHERE id = :id');
        $deleteCompany->execute(['id' => $companyId]);
    }
    foreach ($userIds as $userId) {
        $deleteUser = $db->prepare('DELETE FROM usuarios WHERE id = :id');
        $deleteUser->execute(['id' => $userId]);
    }
}

echo "Offer site tests passed.\n";
