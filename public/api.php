<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Core/ApiToken.php';
require_once __DIR__ . '/../app/Core/JsonResponse.php';
require_once __DIR__ . '/../app/Core/IntegrationFeature.php';
require_once __DIR__ . '/../app/Core/ApiResponseAdapter.php';
require_once __DIR__ . '/../app/Core/ApiRolePolicy.php';
require_once __DIR__ . '/../app/Core/PhpIni.php';
require_once __DIR__ . '/../app/Services/WebhookPublisher.php';
require_once __DIR__ . '/../app/Models/Auditoria.php';
require_once __DIR__ . '/../app/Models/Oferta.php';
require_once __DIR__ . '/../app/Models/Asignacion.php';
require_once __DIR__ . '/../app/Models/Convenio.php';
require_once __DIR__ . '/../app/Core/SpanishDataValidator.php';
require_once __DIR__ . '/../app/Core/FileScanner.php';
require_once __DIR__ . '/../app/Core/PhpIni.php';
require_once __DIR__ . '/../config/storage.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$respond = static function (int $status, array $body): never {
    JsonResponse::success(ApiResponseAdapter::successData($body), $status);
    exit;
};
$fail = static function (int $status, string $code, string $message): never {
    header('X-API-Error-Code: ' . $code);
    JsonResponse::failure($message, $status);
    exit;
};
if (!IntegrationFeature::isEnabled()) {
    $fail(404, 'not_found', 'El recurso solicitado no existe.');
}
$authorizationHeader = static function (): ?string {
    foreach ([
        $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
    ] as $authorization) {
        if (is_string($authorization) && $authorization !== '') {
            return $authorization;
        }
    }

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (is_string($name) && strcasecmp($name, 'Authorization') === 0 && is_string($value)) {
                return $value;
            }
        }
    }

    return null;
};

try {
    $db = getPDOConnection();
    $identity = ApiToken::authenticate($db, $authorizationHeader());
    if ($identity === null) {
        header('WWW-Authenticate: Bearer');
        $fail(401, 'unauthorized', 'Se requiere un token Bearer válido y activo.');
    }

    $rawPath = $_GET['path'] ?? '';
    $path = is_string($rawPath) ? trim($rawPath, '/') : '';
    $segments = $path === '' ? [] : explode('/', $path);
    $resource = $segments[0] ?? '';
    $id = isset($segments[1]) && ctype_digit($segments[1]) ? (int)$segments[1] : null;
    if (!in_array($resource, ['alumnos', 'empresas', 'ofertas', 'convenios', 'asignaciones'], true)
        || count($segments) > 2 || (isset($segments[1]) && $id === null)) {
        $fail(404, 'not_found', 'El recurso solicitado no existe.');
    }

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $role = $identity['role'];
    $userId = $identity['userId'];
    $companyStmt = $db->prepare('SELECT id FROM empresas WHERE usuario_id = :usuario_id LIMIT 1');
    $companyStmt->execute(['usuario_id' => $userId]);
    $companyId = $companyStmt->fetchColumn();
    $companyId = $companyId === false ? null : (int)$companyId;
    $studentStmt = $db->prepare('SELECT id FROM alumnos WHERE usuario_id = :usuario_id LIMIT 1');
    $studentStmt->execute(['usuario_id' => $userId]);
    $studentId = $studentStmt->fetchColumn();
    $studentId = $studentId === false ? null : (int)$studentId;

    if ($method === 'GET') {
        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        $perPage = filter_var($_GET['per_page'] ?? 50, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($page === false || $perPage === false) {
            $fail(400, 'invalid_pagination', 'page debe ser positivo y per_page debe estar entre 1 y 100.');
        }
        $query = apiReadQuery($resource, $role, $companyId, $studentId, $id);
        if ($query === null) {
            $fail(403, 'forbidden', 'Tu cuenta no tiene permiso para consultar este recurso.');
        }
        [$sql, $params] = $query;
        $countSql = preg_replace('/^SELECT .+? FROM /s', 'SELECT COUNT(*) FROM ', $sql);
        $countSql = preg_replace('/ ORDER BY .+$/s', '', (string)$countSql);
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $itemsStmt = $db->prepare($sql . ' LIMIT :limit OFFSET :offset');
        foreach ($params as $name => $value) {
            $itemsStmt->bindValue(':' . $name, $value, PDO::PARAM_INT);
        }
        $itemsStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $itemsStmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $itemsStmt->execute();
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        if ($id !== null) {
            if ($items === []) {
                $fail(404, 'not_found', 'El registro solicitado no existe.');
            }
            $respond(200, ['data' => $items[0]]);
        }
        $respond(200, [
            'data' => $items,
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ]);
    }

    if ($method === 'POST' && $id === null && $resource === 'ofertas') {
        apiRequireRole($role, ['Empresa'], $fail);
        apiCreateOffer($db, $userId, $companyId, $respond, $fail);
    }
    if ($method === 'POST' && $id === null && $resource === 'alumnos') {
        apiRequireRole($role, ['Administrador', 'Coordinador'], $fail);
        apiCreateStudent($db, $userId, $respond, $fail);
    }
    if ($method === 'POST' && $id === null && $resource === 'empresas') {
        apiRequireRole($role, ['Administrador', 'Coordinador'], $fail);
        apiCreateCompany($db, $userId, $respond, $fail);
    }
    if ($method === 'POST' && $id === null && $resource === 'asignaciones') {
        apiRequireRole($role, ['Administrador', 'Coordinador'], $fail);
        $input = apiJsonInput($fail);
        $offerId = filter_var($input['oferta_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $student = filter_var($input['alumno_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $tutor = $input['tutor_empresa'] ?? null;
        if ($offerId === false || $student === false || !is_string($tutor) || trim($tutor) === '' || mb_strlen(trim($tutor)) > 150) {
            $fail(422, 'invalid_assignment', 'Se requieren oferta_id, alumno_id y tutor_empresa válidos.');
        }
        try {
            $assignmentId = (new Asignacion($db))->crear((int)$offerId, (int)$student, $userId, trim($tutor));
        } catch (RuntimeException $exception) {
            $businessErrors = [
                'La oferta no existe o no está aprobada.',
                'La oferta ya no tiene plazas disponibles.',
                'No se encontró el expediente del alumno.',
                'El alumno no cumple el ciclo, disponibilidad o turno de la oferta.',
            ];
            if (in_array($exception->getMessage(), $businessErrors, true)) {
                $fail(422, 'invalid_assignment', $exception->getMessage());
            }
            throw $exception;
        }
        $respond(201, ['data' => ['id' => $assignmentId]]);
    }
    if ($method === 'POST' && $id === null && $resource === 'convenios') {
        apiRequireRole($role, ['Empresa'], $fail);
        apiCreateAgreement($db, $userId, $companyId, $respond, $fail);
    }
    if ($method === 'PATCH' && $id !== null && $resource === 'ofertas') {
        apiRequireRole($role, ApiRolePolicy::reviewRoles($resource), $fail);
        apiReviewOffer($db, $id, $userId, $respond, $fail);
    }
    if ($method === 'PATCH' && $id !== null && $resource === 'convenios') {
        apiRequireRole($role, ApiRolePolicy::reviewRoles($resource), $fail);
        apiReviewAgreement($db, $id, $userId, $respond, $fail);
    }
    if ($method === 'PATCH' && $id !== null && $resource === 'asignaciones') {
        apiRequireRole($role, ['Administrador', 'Coordinador'], $fail);
        $input = apiJsonInput($fail);
        if (($input['estado'] ?? null) !== 'Cerrada') {
            $fail(422, 'invalid_assignment_state', 'El estado permitido para esta operación es Cerrada.');
        }
        if (!(new Asignacion($db))->cerrar($id, $userId)) {
            $fail(404, 'not_found', 'La asignación no existe o ya está cerrada.');
        }
        $respond(200, ['data' => ['id' => $id, 'estado' => 'Cerrada']]);
    }
    if ($method === 'PATCH' && $id !== null && $resource === 'empresas') {
        apiRequireRole($role, ['Empresa'], $fail);
        if ($companyId === null || $companyId !== $id) {
            $fail(404, 'not_found', 'La empresa solicitada no existe.');
        }
        apiUpdateCompany($db, $userId, $id, $respond, $fail);
    }
    if ($method === 'PATCH' && $id !== null && $resource === 'alumnos') {
        apiRequireRole($role, ['Alumno'], $fail);
        if ($studentId === null || $studentId !== $id) {
            $fail(404, 'not_found', 'El expediente solicitado no existe.');
        }
        apiUpdateStudent($db, $userId, $id, $respond, $fail);
    }

    header('Allow: GET, POST, PATCH');
    $fail(405, 'method_not_allowed', 'Este método no está disponible para el recurso solicitado.');
} catch (PDOException $exception) {
    error_log('Error de base de datos en API: ' . $exception->getMessage());
    $fail(500, 'database_error', 'No se pudo completar la operación.');
} catch (FileScannerUnavailableException $exception) {
    error_log('Análisis antivirus no disponible para API: ' . $exception->getMessage());
    $fail(503, 'virus_scanner_unavailable', 'No se puede registrar el convenio porque el análisis antivirus no está disponible.');
} catch (DomainException | InvalidArgumentException $exception) {
    $fail(422, 'invalid_request', $exception->getMessage());
} catch (Throwable $exception) {
    error_log('Error no controlado en API: ' . $exception->getMessage());
    $fail(500, 'internal_error', 'No se pudo completar la operación.');
}

function apiReadQuery(string $resource, string $role, ?int $companyId, ?int $studentId, ?int $id): ?array
{
    $where = [];
    $params = [];
    switch ($resource) {
        case 'alumnos':
            if (!in_array($role, ['Administrador', 'Coordinador', 'Observador', 'Alumno'], true)) {
                return null;
            }
            if ($role === 'Alumno') {
                if ($studentId === null) {
                    return null;
                }
                $where[] = 'a.id = :student_id';
                $params['student_id'] = $studentId;
            }
            if ($id !== null) {
                $where[] = 'a.id = :id';
                $params['id'] = $id;
            }
            return [
                'SELECT a.id, a.nombre, a.apellidos, a.correo, a.especialidad_id,
                        es.nombre_especialidad
                 FROM alumnos a LEFT JOIN especialidades es ON es.id = a.especialidad_id'
                 . apiWhere($where) . ' ORDER BY a.apellidos, a.nombre',
                $params,
            ];
        case 'empresas':
            if (!in_array($role, ['Administrador', 'Coordinador', 'Observador', 'Empresa'], true)) {
                return null;
            }
            if ($role === 'Empresa') {
                if ($companyId === null) {
                    return null;
                }
                $where[] = 'e.id = :company_id';
                $params['company_id'] = $companyId;
            }
            if ($id !== null) {
                $where[] = 'e.id = :id';
                $params['id'] = $id;
            }
            return [
                'SELECT e.id, e.nombre_empresa, e.pagina_web, e.categoria
                 FROM empresas e' . apiWhere($where) . ' ORDER BY e.nombre_empresa',
                $params,
            ];
        case 'ofertas':
            if (!in_array($role, ['Administrador', 'Coordinador', 'Observador', 'Empresa', 'Alumno'], true)) {
                return null;
            }
            if ($role === 'Empresa') {
                if ($companyId === null) {
                    return null;
                }
                $where[] = 'p.empresa_id = :company_id';
                $params['company_id'] = $companyId;
            } elseif ($role === 'Alumno') {
                $where[] = "p.estado = 'Aprobada' AND p.fecha_fin >= CURDATE()";
            }
            if ($id !== null) {
                $where[] = 'p.id = :id';
                $params['id'] = $id;
            }
            return [
                'SELECT p.id, p.empresa_id, e.nombre_empresa, p.especialidad_id,
                        es.nombre_especialidad, p.plazas_cuantas, p.fecha_inicio,
                        p.fecha_fin, p.turno, p.estado
                 FROM empresa_especialidades_plazas p
                 INNER JOIN empresas e ON e.id = p.empresa_id
                 INNER JOIN especialidades es ON es.id = p.especialidad_id'
                 . apiWhere($where) . ' ORDER BY p.fecha_inicio, p.id',
                $params,
            ];
        case 'convenios':
            if (!in_array($role, ['Administrador', 'Coordinador', 'Observador', 'Empresa'], true)) {
                return null;
            }
            if ($role === 'Empresa') {
                if ($companyId === null) {
                    return null;
                }
                $where[] = 'c.empresa_id = :company_id';
                $params['company_id'] = $companyId;
            }
            if ($id !== null) {
                $where[] = 'c.id = :id';
                $params['id'] = $id;
            }
            return [
                'SELECT c.id, c.empresa_id, e.nombre_empresa, c.especialidad_id,
                        es.nombre_especialidad, c.sede_id, c.numero_convenio,
                        c.fecha_firma, c.fecha_inicio, c.fecha_fin, c.estado
                 FROM convenios c
                 INNER JOIN empresas e ON e.id = c.empresa_id
                 LEFT JOIN especialidades es ON es.id = c.especialidad_id'
                 . apiWhere($where) . ' ORDER BY c.creado_en DESC',
                $params,
            ];
        case 'asignaciones':
            if (!in_array($role, ['Administrador', 'Coordinador', 'Observador', 'Empresa', 'Alumno'], true)) {
                return null;
            }
            if ($role === 'Alumno') {
                if ($studentId === null) {
                    return null;
                }
                $where[] = 'a.id = :student_id';
                $params['student_id'] = $studentId;
            } elseif ($role === 'Empresa') {
                if ($companyId === null) {
                    return null;
                }
                $where[] = 'p.empresa_id = :company_id';
                $params['company_id'] = $companyId;
            }
            if ($id !== null) {
                $where[] = 'af.id = :id';
                $params['id'] = $id;
            }
            return [
                'SELECT af.id, af.oferta_id, af.alumno_id, a.nombre AS alumno_nombre,
                        a.apellidos AS alumno_apellidos, p.empresa_id, e.nombre_empresa,
                        af.tutor_empresa, af.estado, af.fecha_asignacion, af.fecha_cierre
                 FROM asignaciones_fct af
                 INNER JOIN alumnos a ON a.id = af.alumno_id
                 INNER JOIN empresa_especialidades_plazas p ON p.id = af.oferta_id
                 INNER JOIN empresas e ON e.id = p.empresa_id'
                 . apiWhere($where) . ' ORDER BY af.fecha_asignacion DESC, af.id DESC',
                $params,
            ];
    }
    return null;
}

function apiWhere(array $conditions): string
{
    return $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
}

function apiRequireRole(string $role, array $allowed, Closure $fail): void
{
    if (!in_array($role, $allowed, true)) {
        $fail(403, 'forbidden', 'Tu cuenta no tiene permiso para realizar esta operación.');
    }
}

function apiJsonInput(Closure $fail): array
{
    if (!isset($_SERVER['CONTENT_TYPE']) || !str_starts_with(strtolower($_SERVER['CONTENT_TYPE']), 'application/json')) {
        $fail(415, 'unsupported_media_type', 'Envía el cuerpo con Content-Type: application/json.');
    }
    $body = file_get_contents('php://input', false, null, 0, 65537);
    if (!is_string($body) || strlen($body) > 65536) {
        $fail(413, 'payload_too_large', 'El cuerpo supera el límite de 64 KiB.');
    }
    try {
        $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $fail(400, 'invalid_json', 'El cuerpo JSON no es válido.');
    }
    if (!is_array($data) || array_is_list($data)) {
        $fail(400, 'invalid_json_shape', 'El cuerpo debe ser un objeto JSON.');
    }
    return $data;
}

function apiCreateOffer(PDO $db, int $userId, ?int $companyId, Closure $respond, Closure $fail): never
{
    if ($companyId === null) {
        $fail(403, 'company_profile_required', 'La cuenta no tiene un perfil de empresa asociado.');
    }
    $input = apiJsonInput($fail);
    $specialtyId = filter_var($input['especialidad_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $seats = filter_var($input['plazas'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
    $start = apiDate($input['fecha_inicio'] ?? null);
    $end = apiDate($input['fecha_fin'] ?? null);
    $shift = $input['turno'] ?? 'Indiferente';
    $title = $input['titulo'] ?? 'Oferta FCT';
    $description = $input['descripcion'] ?? '';
    $modality = $input['modalidad'] ?? 'Presencial';
    $location = $input['ubicacion'] ?? '';
    $requirements = $input['requisitos_tecnologicos'] ?? $input['requisitos'] ?? '';
    $technologies = $input['tecnologias'] ?? '';
    $weeklyHoursRaw = $input['horarios'] ?? [];
    if ($specialtyId === false || $seats === false || $start === null || $end === null || $end <= $start
        || $start < new DateTimeImmutable('today') || !is_string($shift)
        || !in_array($shift, ['M', 'T', 'P', 'Indiferente'], true)
        || !is_string($title) || preg_match('/\A.{1,150}\z/us', trim($title)) !== 1
        || !is_string($description) || preg_match('/\A.{0,2000}\z/us', $description) !== 1
        || !is_string($modality) || !in_array($modality, ['Presencial', 'Remoto', 'Híbrido'], true)
        || !is_string($location) || preg_match('/\A.{0,255}\z/us', trim($location)) !== 1
        || !is_string($requirements) || preg_match('/\A.{0,2000}\z/us', $requirements) !== 1
        || !is_string($technologies) || preg_match('/\A.{0,500}\z/us', $technologies) !== 1
        || !is_array($weeklyHoursRaw)) {
        $fail(422, 'invalid_offer', 'Ciclo, plazas, fechas o turno no válidos.');
    }
    if (
        array_key_exists('modalidad', $input)
        && $modality !== 'Remoto'
        && trim($location) === ''
    ) {
        $fail(422, 'invalid_offer', 'Indica la ubicación para las modalidades presencial e híbrida.');
    }

    $title = trim($title);
    $description = trim($description);
    $location = trim($location);
    $requirements = trim($requirements);
    $technologyTags = [];
    if ($technologies !== '') {
        foreach (explode(',', $technologies) as $tag) {
            $tag = trim($tag);
            if ($tag === '') {
                continue;
            }
            if (mb_strlen($tag) > 50 || count($technologyTags) >= 10) {
                $fail(422, 'invalid_offer', 'Las tecnologías admiten hasta 10 etiquetas de 50 caracteres.');
            }
            $technologyTags[$tag] = $tag;
        }
    }
    $technologies = implode(', ', array_values($technologyTags));

    $weeklyHours = [];
    $usedDays = [];
    foreach ($weeklyHoursRaw as $weeklyHour) {
        if (!is_array($weeklyHour)) {
            $fail(422, 'invalid_offer', 'Los horarios deben ser una lista de días y franjas horarias.');
        }
        $day = filter_var($weeklyHour['dia_semana'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 7]]);
        $timeStart = $weeklyHour['hora_inicio'] ?? null;
        $timeEnd = $weeklyHour['hora_fin'] ?? null;
        if (
            $day === false
            || isset($usedDays[$day])
            || !is_string($timeStart)
            || !is_string($timeEnd)
            || preg_match('/\A(?:[01]\d|2[0-3]):(?:[0-5][05])\z/', $timeStart) !== 1
            || preg_match('/\A(?:[01]\d|2[0-3]):(?:[0-5][05])\z/', $timeEnd) !== 1
            || $timeEnd <= $timeStart
        ) {
            $fail(422, 'invalid_offer', 'Cada día debe tener una franja válida en intervalos de cinco minutos.');
        }
        $usedDays[$day] = true;
        $weeklyHours[] = [
            'dia_semana' => (int)$day,
            'hora_inicio' => $timeStart . ':00',
            'hora_fin' => $timeEnd . ':00',
        ];
    }
    if (array_key_exists('horarios', $input) && $weeklyHours === []) {
        $fail(422, 'invalid_offer', 'Selecciona al menos un horario semanal.');
    }

    $specialty = $db->prepare('SELECT nombre_especialidad FROM especialidades WHERE id = :id');
    $specialty->execute(['id' => $specialtyId]);
    $cycleName = $specialty->fetchColumn();
    if (!is_string($cycleName)) {
        $fail(422, 'invalid_offer', 'El ciclo formativo no existe.');
    }
    $agreement = $db->prepare(
        "SELECT 1 FROM convenios WHERE empresa_id = :company_id AND especialidad_id = :specialty_id
         AND estado = 'Aprobado' AND fecha_inicio <= :start_date AND fecha_fin >= :end_date LIMIT 1"
    );
    $agreement->execute([
        'company_id' => $companyId,
        'specialty_id' => $specialtyId,
        'start_date' => $start->format('Y-m-d'),
        'end_date' => $end->format('Y-m-d'),
    ]);
    if (!$agreement->fetchColumn()) {
        $fail(422, 'approved_agreement_required', 'Se necesita un convenio aprobado que cubra el ciclo y todo el periodo.');
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'INSERT INTO empresa_especialidades_plazas
                (empresa_id, especialidad_id, plazas_cuantas, fecha_inicio, fecha_fin,
                 titulo, descripcion, turno, modalidad, ubicacion, requisitos, tecnologias)
             VALUES (:company_id, :specialty_id, :seats, :start_date, :end_date,
                 :title, :description, :shift, :modality, :location, :requirements, :technologies)'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'specialty_id' => $specialtyId,
            'seats' => $seats,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'title' => $title,
            'description' => $description,
            'shift' => $shift,
            'modality' => $modality,
            'location' => $location !== '' ? $location : null,
            'requirements' => $requirements !== '' ? $requirements : null,
            'technologies' => $technologies !== '' ? $technologies : null,
        ]);
        $offerId = (int)$db->lastInsertId();
        if ($weeklyHours !== []) {
            $insertSchedule = $db->prepare(
                'INSERT INTO empresa_oferta_horarios_semanales
                    (oferta_id, dia_semana, hora_inicio, hora_fin)
                 VALUES (:offer_id, :day, :start, :end)'
            );
            foreach ($weeklyHours as $weeklyHour) {
                $insertSchedule->execute([
                    'offer_id' => $offerId,
                    'day' => $weeklyHour['dia_semana'],
                    'start' => $weeklyHour['hora_inicio'],
                    'end' => $weeklyHour['hora_fin'],
                ]);
            }
        }
        (new Auditoria($db))->registrar($userId, 'oferta.creada', 'ofertas', $offerId);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
    $respond(201, ['data' => ['id' => $offerId, 'estado' => 'Pendiente']]);
}

function apiCreateStudent(PDO $db, int $actorId, Closure $respond, Closure $fail): never
{
    $input = apiJsonInput($fail);
    $fields = ['email', 'password', 'nombre', 'apellidos', 'fecha_nacimiento', 'dni', 'nss', 'telefono', 'direccion', 'codigo_postal'];
    foreach ($fields as $field) {
        if (!is_string($input[$field] ?? null)) {
            $fail(422, 'invalid_student', "El campo $field es obligatorio y debe ser texto.");
        }
    }
    $email = trim($input['email']);
    $password = $input['password'];
    $name = trim($input['nombre']);
    $surname = trim($input['apellidos']);
    $birthDate = apiDate($input['fecha_nacimiento']);
    $documentType = $input['tipo_documento'] ?? SpanishDataValidator::DOCUMENT_TYPE_NIF_NIE_DNI;
    if (!is_string($documentType) || !SpanishDataValidator::isStudentDocumentType($documentType)) {
        $fail(422, 'invalid_student', 'Selecciona un tipo de documento válido.');
    }
    $dni = $documentType === SpanishDataValidator::DOCUMENT_TYPE_PASSPORT
        ? strtoupper(trim($input['dni']))
        : SpanishDataValidator::normalizeDniNie(trim($input['dni']));
    $nss = trim($input['nss']);
    $phone = SpanishDataValidator::normalizePhone(trim($input['telefono']));
    $address = trim($input['direccion']);
    $postcode = trim($input['codigo_postal']);
    $specialtyId = filter_var($input['especialidad_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!SpanishDataValidator::isEmail($email) || strlen($password) < 12 || strlen($password) > 72
        || trim($password) === '' || !SpanishDataValidator::isPersonName($name, 50)
        || !SpanishDataValidator::isPersonName($surname, 100) || $birthDate === null
        || $birthDate > new DateTimeImmutable('today') || $birthDate->diff(new DateTimeImmutable('today'))->y < 16
        || ($documentType === SpanishDataValidator::DOCUMENT_TYPE_PASSPORT
            ? !SpanishDataValidator::isPassport($dni)
            : !SpanishDataValidator::isDniNie($dni))
        || preg_match('/\A[A-Za-z0-9][A-Za-z0-9 .-]{4,19}\z/u', $nss) !== 1
        || !SpanishDataValidator::isSpanishPhone($phone)
        || $address === '' || mb_strlen($address) > 255 || preg_match('/[\p{Cc}]/u', $address) === 1
        || !SpanishDataValidator::isSpanishPostcode($postcode) || $specialtyId === false) {
        $fail(422, 'invalid_student', 'Revisa email, contraseña, perfil, fecha, datos de contacto, código postal y ciclo.');
    }
    $specialty = $db->prepare('SELECT 1 FROM especialidades WHERE id = :id');
    $specialty->execute(['id' => $specialtyId]);
    if (!$specialty->fetchColumn()) {
        $fail(422, 'invalid_student', 'El ciclo formativo no existe.');
    }
    $guardian = $input['tutor'] ?? null;
    if ($birthDate->diff(new DateTimeImmutable('today'))->y < 18) {
        $guardianFields = ['nombre', 'dni', 'nss', 'direccion', 'telefono', 'correo'];
        if (!is_array($guardian)) {
            $fail(422, 'guardian_required', 'Los alumnos menores de 18 años deben incluir el objeto tutor completo.');
        }
        foreach ($guardianFields as $field) {
            if (!is_string($guardian[$field] ?? null)) {
                $fail(422, 'guardian_required', "El campo tutor.$field es obligatorio.");
            }
        }
        $guardian = [
            'nombre' => trim($guardian['nombre']),
            'dni' => SpanishDataValidator::normalizeDniNie(trim($guardian['dni'])),
            'nss' => trim($guardian['nss']),
            'direccion' => trim($guardian['direccion']),
            'telefono' => SpanishDataValidator::normalizePhone(trim($guardian['telefono'])),
            'correo' => trim($guardian['correo']),
        ];
        if (!SpanishDataValidator::isPersonName($guardian['nombre'], 100)
            || !SpanishDataValidator::isDniNie($guardian['dni'])
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9 .-]{4,19}\z/u', $guardian['nss']) !== 1
            || $guardian['direccion'] === '' || mb_strlen($guardian['direccion']) > 255
            || preg_match('/[\p{Cc}]/u', $guardian['direccion']) === 1
            || !SpanishDataValidator::isSpanishPhone($guardian['telefono'])
            || !SpanishDataValidator::isEmail($guardian['correo'])) {
            $fail(422, 'invalid_guardian', 'Los datos del tutor legal no son válidos.');
        }
    } else {
        $guardian = null;
    }

    $db->beginTransaction();
    try {
        $account = $db->prepare(
            'INSERT INTO usuarios (email, password_hash, rol)
             VALUES (:email, :password_hash, \'Alumno\')'
        );
        $account->execute(['email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        $newUserId = (int)$db->lastInsertId();
        $profile = $db->prepare(
            'INSERT INTO alumnos
                (usuario_id, nombre, apellidos, fecha_nacimiento, dni, tipo_documento, numero_seguridad_social,
                 codigo_postal, correo, especialidad_id)
             VALUES (:user_id, :nombre, :apellidos, :birth_date, :dni, :document_type, :nss, :postcode, :email, :specialty_id)'
        );
        $profile->execute([
            'user_id' => $newUserId,
            'nombre' => $name,
            'apellidos' => $surname,
            'birth_date' => $birthDate->format('Y-m-d'),
            'dni' => $dni,
            'document_type' => $documentType,
            'nss' => $nss,
            'postcode' => $postcode,
            'email' => $email,
            'specialty_id' => $specialtyId,
        ]);
        $studentId = (int)$db->lastInsertId();
        $contact = $db->prepare(
            'INSERT INTO direcciones_telefonos (alumno_id, telefono, direccion_completa)
             VALUES (:student_id, :phone, :address)'
        );
        $contact->execute(['student_id' => $studentId, 'phone' => $phone, 'address' => $address]);
        if (is_array($guardian)) {
            $guardianInsert = $db->prepare(
                'INSERT INTO tutores_legales
                    (alumno_id, nombre_completo, dni, numero_seguridad_social, direccion, telefono, correo)
                 VALUES (:student_id, :name, :dni, :nss, :address, :phone, :email)'
            );
            $guardianInsert->execute([
                'student_id' => $studentId,
                'name' => $guardian['nombre'],
                'dni' => $guardian['dni'],
                'nss' => $guardian['nss'],
                'address' => $guardian['direccion'],
                'phone' => $guardian['telefono'],
                'email' => $guardian['correo'],
            ]);
        }
        (new Auditoria($db))->registrar($actorId, 'alumno.creado_api', 'alumnos', $studentId);
        WebhookPublisher::publish($db, 'alumno.alta', ['alumno_id' => $studentId]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($exception instanceof PDOException && (int)($exception->errorInfo[1] ?? 0) === 1062) {
            $fail(409, 'duplicate_student_data', 'El correo, documento u otro dato único ya está registrado.');
        }
        throw $exception;
    }
    $respond(201, ['data' => ['id' => $studentId]]);
}

function apiCreateCompany(PDO $db, int $actorId, Closure $respond, Closure $fail): never
{
    $input = apiJsonInput($fail);
    $identityField = array_key_exists('nif_persona_juridica', $input) ? 'nif_persona_juridica' : 'cif';
    $fields = ['email', 'password', $identityField, 'nombre_comercial', 'telefono', 'direccion', 'persona_contacto'];
    foreach ($fields as $field) {
        if (!is_string($input[$field] ?? null)) {
            $fail(422, 'invalid_company', "El campo $field es obligatorio y debe ser texto.");
        }
    }
    $email = trim($input['email']);
    $password = $input['password'];
    $nif = SpanishDataValidator::normalizeFiscalIdentifier($input[$identityField]);
    $name = trim($input['nombre_comercial']);
    $phone = trim($input['telefono']);
    $address = trim($input['direccion']);
    $contact = trim($input['persona_contacto']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100
        || strlen($password) < 12 || strlen($password) > 72 || trim($password) === ''
        || mb_strlen($nif) > 20 || !SpanishDataValidator::isNifPersonaJuridica($nif)
        || $name === '' || mb_strlen($name) > 150
        || mb_strlen($contact) > 150 || mb_strlen($phone) > 20
        || $address === '' || mb_strlen($address) > 255) {
        $fail(422, 'invalid_company', 'Revisa email, contraseña y los campos obligatorios de la empresa.');
    }
    $db->beginTransaction();
    try {
        $account = $db->prepare(
            'INSERT INTO usuarios (email, password_hash, rol)
             VALUES (:email, :password_hash, \'Empresa\')'
        );
        $account->execute(['email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        $newUserId = (int)$db->lastInsertId();
        $profile = $db->prepare(
            'INSERT INTO empresas (usuario_id, nombre_empresa, cif_empresa, persona_contacto)
             VALUES (:user_id, :name, :cif, :contact)'
        );
        $profile->execute([
            'user_id' => $newUserId,
            'name' => $name,
            'cif' => $nif,
            'contact' => $contact,
        ]);
        $companyId = (int)$db->lastInsertId();
        $contactRow = $db->prepare(
            'INSERT INTO direcciones_telefonos (empresa_id, telefono, direccion_completa)
             VALUES (:company_id, :phone, :address)'
        );
        $contactRow->execute(['company_id' => $companyId, 'phone' => $phone, 'address' => $address]);
        (new Auditoria($db))->registrar($actorId, 'empresa.creada_api', 'empresas', $companyId);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($exception instanceof PDOException && (int)($exception->errorInfo[1] ?? 0) === 1062) {
            $fail(409, 'duplicate_company_data', 'El correo o el NIF de persona jurídica ya están registrados.');
        }
        throw $exception;
    }
    $respond(201, ['data' => ['id' => $companyId]]);
}

function apiUpdateCompany(PDO $db, int $userId, int $companyId, Closure $respond, Closure $fail): never
{
    $input = apiJsonInput($fail);
    $allowedFields = ['nombre_empresa', 'pagina_web', 'categoria', 'pertenece_grupo_empresa'];
    if ($input === [] || array_diff(array_keys($input), $allowedFields) !== []) {
        $fail(422, 'invalid_company', 'Solo puedes modificar nombre_empresa, pagina_web, categoria y pertenece_grupo_empresa.');
    }
    $assignments = [];
    $params = ['id' => $companyId];
    if (array_key_exists('nombre_empresa', $input)) {
        if (!is_string($input['nombre_empresa']) || trim($input['nombre_empresa']) === ''
            || mb_strlen(trim($input['nombre_empresa'])) > 150) {
            $fail(422, 'invalid_company', 'nombre_empresa es obligatorio y admite hasta 150 caracteres.');
        }
        $assignments[] = 'nombre_empresa = :nombre';
        $params['nombre'] = trim($input['nombre_empresa']);
    }
    if (array_key_exists('pagina_web', $input)) {
        $website = $input['pagina_web'];
        if ($website !== null && (!is_string($website) || strlen($website) > 255
            || filter_var($website, FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string)parse_url($website, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            $fail(422, 'invalid_company', 'pagina_web debe ser una URL HTTP/HTTPS válida o null.');
        }
        $assignments[] = 'pagina_web = :website';
        $params['website'] = $website;
    }
    if (array_key_exists('categoria', $input)) {
        if ($input['categoria'] !== null && (!is_string($input['categoria']) || mb_strlen($input['categoria']) > 100)) {
            $fail(422, 'invalid_company', 'categoria debe tener hasta 100 caracteres o ser null.');
        }
        $assignments[] = 'categoria = :category';
        $params['category'] = $input['categoria'];
    }
    if (array_key_exists('pertenece_grupo_empresa', $input)) {
        if (!in_array($input['pertenece_grupo_empresa'], [true, false, 0, 1], true)) {
            $fail(422, 'invalid_company', 'pertenece_grupo_empresa debe ser booleano.');
        }
        $assignments[] = 'pertenece_grupo_empresa = :group_member';
        $params['group_member'] = (int)$input['pertenece_grupo_empresa'];
    }
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('UPDATE empresas SET ' . implode(', ', $assignments) . ' WHERE id = :id');
        $stmt->execute($params);
        (new Auditoria($db))->registrar($userId, 'empresa.actualizada_api', 'empresas', $companyId);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
    $respond(200, ['data' => ['id' => $companyId, 'updated' => array_keys($input)]]);
}

function apiUpdateStudent(PDO $db, int $userId, int $studentId, Closure $respond, Closure $fail): never
{
    require_once __DIR__ . '/../Core/SpanishDataValidator.php';
    $input = apiJsonInput($fail);
    $allowedFields = ['nombre', 'apellidos', 'correo', 'codigo_postal'];
    if ($input === [] || array_diff(array_keys($input), $allowedFields) !== []) {
        $fail(422, 'invalid_student', 'Solo puedes modificar nombre, apellidos, correo y codigo_postal.');
    }
    $assignments = [];
    $params = ['id' => $studentId, 'user_id' => $userId];
    if (array_key_exists('nombre', $input)) {
        if (!is_string($input['nombre']) || !SpanishDataValidator::isPersonName(trim($input['nombre']), 50)) {
            $fail(422, 'invalid_student', 'El nombre no es válido.');
        }
        $assignments[] = 'nombre = :nombre';
        $params['nombre'] = trim($input['nombre']);
    }
    if (array_key_exists('apellidos', $input)) {
        if (!is_string($input['apellidos']) || !SpanishDataValidator::isPersonName(trim($input['apellidos']), 100)) {
            $fail(422, 'invalid_student', 'Los apellidos no son válidos.');
        }
        $assignments[] = 'apellidos = :apellidos';
        $params['apellidos'] = trim($input['apellidos']);
    }
    if (array_key_exists('correo', $input)) {
        if (!is_string($input['correo']) || !SpanishDataValidator::isEmail(trim($input['correo']))) {
            $fail(422, 'invalid_student', 'El correo no es válido.');
        }
        $params['correo'] = trim($input['correo']);
    }
    if (array_key_exists('codigo_postal', $input)) {
        if (!is_string($input['codigo_postal']) || !SpanishDataValidator::isSpanishPostcode(trim($input['codigo_postal']))) {
            $fail(422, 'invalid_student', 'El código postal no es válido.');
        }
        $assignments[] = 'codigo_postal = :postcode';
        $params['postcode'] = trim($input['codigo_postal']);
    }
    $db->beginTransaction();
    try {
        if (array_key_exists('correo', $input)) {
            $account = $db->prepare('UPDATE usuarios SET email = :email WHERE id = :user_id');
            $account->execute(['email' => $params['correo'], 'user_id' => $userId]);
            $assignments[] = 'correo = :correo';
        }
        if ($assignments !== []) {
            $stmt = $db->prepare(
                'UPDATE alumnos SET ' . implode(', ', $assignments)
                . ' WHERE id = :id AND usuario_id = :user_id'
            );
            $stmt->execute($params);
        }
        (new Auditoria($db))->registrar($userId, 'alumno.perfil_actualizado_api', 'alumnos', $studentId);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($exception instanceof PDOException && (int)($exception->errorInfo[1] ?? 0) === 1062) {
            $fail(409, 'duplicate_email', 'El correo ya está en uso.');
        }
        throw $exception;
    }
    $respond(200, ['data' => ['id' => $studentId, 'updated' => array_keys($input)]]);
}

function apiReviewOffer(PDO $db, int $id, int $userId, Closure $respond, Closure $fail): never
{
    $input = apiJsonInput($fail);
    $decision = $input['estado'] ?? null;
    $reason = $input['motivo'] ?? '';
    if (!in_array($decision, ['Aprobada', 'Rechazada'], true) || !is_string($reason) || mb_strlen($reason) > 500
        || ($decision === 'Rechazada' && trim($reason) === '')) {
        $fail(422, 'invalid_review', 'Indica estado Aprobada o Rechazada y un motivo si rechazas.');
    }
    $db->beginTransaction();
    try {
        if ($decision === 'Aprobada') {
            $stmt = $db->prepare(
                "SELECT empresa_id, especialidad_id, fecha_inicio, fecha_fin
                 FROM empresa_especialidades_plazas WHERE id = :id AND estado = 'Pendiente' FOR UPDATE"
            );
            $stmt->execute(['id' => $id]);
            $offer = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$offer) {
                $db->rollBack();
                $fail(404, 'not_found', 'La oferta no existe o ya fue revisada.');
            }
            $agreement = $db->prepare(
                "SELECT 1 FROM convenios WHERE empresa_id = :company_id AND especialidad_id = :specialty_id
                 AND estado = 'Aprobado' AND fecha_inicio <= :start_date AND fecha_fin >= :end_date
                 LIMIT 1 FOR UPDATE"
            );
            $agreement->execute([
                'company_id' => $offer['empresa_id'],
                'specialty_id' => $offer['especialidad_id'],
                'start_date' => $offer['fecha_inicio'],
                'end_date' => $offer['fecha_fin'],
            ]);
            if (!$agreement->fetchColumn()) {
                $db->rollBack();
                $fail(422, 'approved_agreement_required', 'No hay un convenio aprobado que cubra la oferta.');
            }
        }
        if (!(new Oferta($db))->resolver($id, $decision, trim($reason), $userId)) {
            $db->rollBack();
            $fail(404, 'not_found', 'La oferta no existe o ya fue revisada.');
        }
        (new Auditoria($db))->registrar($userId, 'oferta.revisada', 'ofertas', $id, 'estado=' . $decision);
        if ($decision === 'Aprobada' && isset($offer) && is_array($offer)) {
            WebhookPublisher::publish($db, 'oferta.nueva_publicada', [
                'oferta_id' => $id,
                'empresa_id' => (int)$offer['empresa_id'],
                'especialidad_id' => (int)$offer['especialidad_id'],
            ]);
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
    $respond(200, ['data' => ['id' => $id, 'estado' => $decision]]);
}

function apiReviewAgreement(PDO $db, int $id, int $userId, Closure $respond, Closure $fail): never
{
    $input = apiJsonInput($fail);
    $decision = $input['estado'] ?? null;
    $reason = $input['motivo'] ?? '';
    if (!in_array($decision, ['Aprobado', 'Rechazado'], true) || !is_string($reason) || mb_strlen($reason) > 500
        || ($decision === 'Rechazado' && trim($reason) === '')) {
        $fail(422, 'invalid_review', 'Indica estado Aprobado o Rechazado y un motivo si rechazas.');
    }
    if ($decision === 'Aprobado') {
        $fail(
            409,
            'electronic_signature_required',
            'La aprobación de convenios requiere el flujo de firma electrónica del panel de administración.'
        );
    }
    $db->beginTransaction();
    try {
        if (!(new Convenio($db))->resolver($id, $decision, trim($reason), $userId)) {
            $db->rollBack();
            $fail(404, 'not_found', 'El convenio no existe o ya fue revisado.');
        }
        (new Auditoria($db))->registrar($userId, 'convenio.revisado', 'convenios', $id, 'estado=' . $decision);
        if ($decision === 'Aprobado') {
            WebhookPublisher::publish($db, 'convenio.aprobado_firmado', ['convenio_id' => $id]);
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
    $respond(200, ['data' => ['id' => $id, 'estado' => $decision]]);
}

function apiCreateAgreement(PDO $db, int $userId, ?int $companyId, Closure $respond, Closure $fail): never
{
    if ($companyId === null) {
        $fail(403, 'company_profile_required', 'La cuenta no tiene un perfil de empresa asociado.');
    }
    $contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
    if (is_string($contentLength) && ctype_digit($contentLength)
        && (int)$contentLength > PhpIni::sizeToBytes((string)ini_get('post_max_size'))) {
        $fail(413, 'payload_too_large', 'La solicitud supera el límite de carga configurado.');
    }

    $number = $_POST['numero_convenio'] ?? null;
    $specialtyId = filter_var($_POST['especialidad_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $siteRaw = $_POST['sede_id'] ?? '';
    $signature = apiDate($_POST['fecha_firma'] ?? null);
    $start = apiDate($_POST['fecha_inicio'] ?? null);
    $end = apiDate($_POST['fecha_fin'] ?? null);
    $upload = $_FILES['archivo'] ?? null;
    if (!is_string($number) || !is_string($siteRaw) || $specialtyId === false
        || $signature === null || $start === null || $end === null
        || trim($number) === '' || mb_strlen(trim($number)) > 100
        || $start > $end || $signature > $start) {
        $fail(422, 'invalid_agreement', 'Completa número, ciclo y fechas válidas.');
    }
    $siteId = $siteRaw === '' ? null : filter_var($siteRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($siteId === false) {
        $fail(422, 'invalid_agreement', 'La sede seleccionada no es válida.');
    }
    if ($siteId !== null) {
        $site = $db->prepare('SELECT 1 FROM empresa_sedes WHERE id = :id AND empresa_id = :company_id AND activa = 1');
        $site->execute(['id' => $siteId, 'company_id' => $companyId]);
        if (!$site->fetchColumn()) {
            $fail(422, 'invalid_agreement', 'La sede no pertenece a la empresa o está inactiva.');
        }
    }
    $specialty = $db->prepare('SELECT 1 FROM especialidades WHERE id = :id');
    $specialty->execute(['id' => $specialtyId]);
    if (!$specialty->fetchColumn()) {
        $fail(422, 'invalid_agreement', 'El ciclo formativo no existe.');
    }
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !is_int($upload['size'] ?? null) || $upload['size'] < 1 || $upload['size'] > 10 * 1024 * 1024
        || !is_string($upload['tmp_name'] ?? null) || !is_string($upload['name'] ?? null)
        || !is_uploaded_file($upload['tmp_name'])) {
        $fail(422, 'invalid_pdf', 'Adjunta un PDF válido de hasta 10 MiB en el campo archivo.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    if ($mime !== 'application/pdf' || strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) !== 'pdf') {
        $fail(422, 'invalid_pdf', 'El documento debe ser un archivo PDF.');
    }

    FileScanner::scan($upload['tmp_name']);
    $storageRoot = fctStoragePath();
    $directory = $storageRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'convenios';
    foreach ([$storageRoot, dirname($directory), $directory] as $privateDirectory) {
        if (!is_dir($privateDirectory) && !mkdir($privateDirectory, 0700) && !is_dir($privateDirectory)) {
            throw new RuntimeException('No se pudo preparar el almacenamiento privado de convenios.');
        }
        if (is_link($privateDirectory) || !chmod($privateDirectory, 0700)) {
            throw new RuntimeException('No se pudieron establecer los permisos privados de almacenamiento.');
        }
    }
    $storedName = bin2hex(random_bytes(24)) . '.pdf';
    $destination = $directory . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($upload['tmp_name'], $destination)) {
        throw new RuntimeException('No se pudo guardar el archivo privado del convenio.');
    }
    if (!chmod($destination, 0600)) {
        if (!unlink($destination)) {
            error_log('No se pudo eliminar un PDF de convenio con permisos inseguros.');
        }
        throw new RuntimeException('No se pudieron establecer permisos privados para el convenio.');
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'INSERT INTO convenios
                (empresa_id, especialidad_id, sede_id, numero_convenio, fecha_firma,
                 fecha_inicio, fecha_fin, archivo_path)
             VALUES (:company_id, :specialty_id, :site_id, :number, :signature, :start_date, :end_date, :file)'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'specialty_id' => $specialtyId,
            'site_id' => $siteId,
            'number' => trim($number),
            'signature' => $signature->format('Y-m-d'),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'file' => $storedName,
        ]);
        $agreementId = (int)$db->lastInsertId();
        (new Auditoria($db))->registrar($userId, 'convenio.creado', 'convenios', $agreementId, 'estado=Pendiente');
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if (is_file($destination) && !unlink($destination)) {
            error_log('No se pudo retirar el PDF privado tras fallar el registro del convenio.');
        }
        if ($exception instanceof PDOException && (int)($exception->errorInfo[1] ?? 0) === 1062) {
            $fail(409, 'duplicate_agreement', 'Ya existe un convenio para la empresa y el ciclo seleccionados.');
        }
        throw $exception;
    }
    $respond(201, ['data' => ['id' => $agreementId, 'estado' => 'Pendiente']]);
}

function apiDate(mixed $value): ?DateTimeImmutable
{
    if (!is_string($value)) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        ? $date
        : null;
}
