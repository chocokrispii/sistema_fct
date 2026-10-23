<?php

require_once __DIR__ . '/../Models/Alumno.php';
require_once __DIR__ . '/../Models/Disponibilidad.php';
require_once __DIR__ . '/../Models/Asignacion.php';
require_once __DIR__ . '/../Models/Calificacion.php';
require_once __DIR__ . '/../Core/JsonResponse.php';
require_once __DIR__ . '/../Core/SpanishDataValidator.php';

class AlumnoController
{
    private PDO $db;
    private Alumno $alumnoModel;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? getPDOConnection();
        $this->alumnoModel = new Alumno($this->db);
    }

    public function portal(): void
    {
        $usuarioId = (int)$_SESSION['usuario_id'];
        $alumno = $this->alumnoModel->obtenerPorUsuario($usuarioId);
        $resultadoAsignaciones = $alumno
            ? (new Asignacion($this->db))->obtenerPagina(
                true,
                $usuarioId,
                Paginacion::desdeEntrada($_GET['pagina'] ?? null)
            )
            : ['items' => [], 'total' => 0];

        View::render('alumnos/portal', [
            'alumno' => $alumno,
            'disponibilidades' => $alumno
                ? (new Disponibilidad($this->db))->obtenerPorUsuario($usuarioId)
                : [],
            'horarioSemanal' => $alumno
                ? (new Disponibilidad($this->db))->obtenerHorarioSemanalPorUsuario($usuarioId)
                : [],
            'asignaciones' => $resultadoAsignaciones['items'],
            'totalAsignaciones' => $resultadoAsignaciones['total'],
            'paginacion' => $resultadoAsignaciones,
            'calificaciones' => $alumno
                ? (new Calificacion($this->db))->listar(true, $usuarioId)
                : [],
        ]);
    }

    public function editarPerfil(): void
    {
        $usuarioId = (int)$_SESSION['usuario_id'];
        $alumno = $this->alumnoModel->obtenerPorUsuario($usuarioId);
        if ($alumno === null) {
            throw new DomainException('No se encontró el expediente del alumno.');
        }

        $values = [
            'email' => $alumno['correo'],
            'nombre' => $alumno['nombre'],
            'apellidos' => $alumno['apellidos'],
            'fecha_nacimiento' => $alumno['fecha_nacimiento'] ?? '',
            'tipo_documento' => $alumno['tipo_documento'] ?? SpanishDataValidator::DOCUMENT_TYPE_NIF_NIE_DNI,
            'dni' => $alumno['dni'],
            'nss' => $alumno['numero_seguridad_social'],
            'telefono' => $alumno['telefono'] ?? '',
            'direccion' => $alumno['direccion'] ?? '',
            'codigo_postal' => $alumno['codigo_postal'] ?? '',
        ];
        $guardian = $this->db->prepare(
            'SELECT nombre_completo, dni, numero_seguridad_social, direccion, telefono, correo
             FROM tutores_legales
             WHERE alumno_id = :alumno_id
             ORDER BY id
             LIMIT 1'
        );
        $guardian->execute(['alumno_id' => (int)$alumno['id']]);
        $guardianData = $guardian->fetch(PDO::FETCH_ASSOC) ?: [];
        $values['tutor_nombre'] = $guardianData['nombre_completo'] ?? '';
        $values['tutor_dni'] = $guardianData['dni'] ?? '';
        $values['tutor_nss'] = $guardianData['numero_seguridad_social'] ?? '';
        $values['tutor_direccion'] = $guardianData['direccion'] ?? '';
        $values['tutor_telefono'] = $guardianData['telefono'] ?? '';
        $values['tutor_correo'] = $guardianData['correo'] ?? '';
        $error = '';
        $errorStatus = 422;
        $fieldErrors = [];
        $tutorData = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $jsonRequest = JsonResponse::wantsJson();
            $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
            $requestValues = $_POST;
            if (str_contains($contentType, 'application/json')) {
                try {
                    $decoded = json_decode((string)file_get_contents('php://input'), false, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    JsonResponse::failure('El cuerpo JSON no es válido.', 400);
                    return;
                }
                if (!$decoded instanceof stdClass) {
                    JsonResponse::failure('El cuerpo JSON debe ser un objeto de datos.', 400);
                    return;
                }
                $requestValues = get_object_vars($decoded);
            }

            foreach ($values as $field => $_value) {
                $posted = $requestValues[$field] ?? '';
                if (!is_string($posted)) {
                    $error = 'Revisa los datos del formulario; todos los campos deben ser texto válido.';
                    break;
                }
                $values[$field] = trim($posted);
            }
            $values['dni'] = $values['tipo_documento'] === SpanishDataValidator::DOCUMENT_TYPE_PASSPORT
                ? strtoupper($values['dni'])
                : SpanishDataValidator::normalizeDniNie($values['dni']);
            $values['telefono'] = SpanishDataValidator::normalizePhone($values['telefono']);

            $birthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $values['fecha_nacimiento']);
            $dateErrors = DateTimeImmutable::getLastErrors();
            $birthDateValid = $birthDate !== false
                && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0));
            $email = SpanishDataValidator::isEmail($values['email']);
            $name = SpanishDataValidator::isPersonName($values['nombre'], 50);
            $surname = SpanishDataValidator::isPersonName($values['apellidos'], 100);
            $documentTypeValid = SpanishDataValidator::isStudentDocumentType($values['tipo_documento']);
            $documentValid = $values['tipo_documento'] === SpanishDataValidator::DOCUMENT_TYPE_PASSPORT
                ? SpanishDataValidator::isPassport($values['dni'])
                : SpanishDataValidator::isDniNie($values['dni']);
            $nss = preg_match('/\A[A-Za-z0-9][A-Za-z0-9 .-]{4,19}\z/u', $values['nss']) === 1;
            $phone = SpanishDataValidator::isSpanishPhone($values['telefono']);
            $address = $values['direccion'] !== ''
                && mb_strlen($values['direccion']) <= 255
                && preg_match('/[\p{Cc}]/u', $values['direccion']) !== 1;
            $postcode = SpanishDataValidator::isSpanishPostcode($values['codigo_postal']);
            if (!$email) {
                $fieldErrors['email'] = 'Introduce un correo electrónico válido (máximo 100 caracteres).';
            }
            if (!$name) {
                $fieldErrors['nombre'] = 'Introduce un nombre de hasta 50 letras, espacios, apóstrofos, puntos o guiones.';
            }
            if (!$surname) {
                $fieldErrors['apellidos'] = 'Introduce apellidos válidos de hasta 100 caracteres.';
            }
            if (!$documentTypeValid || !$documentValid) {
                $fieldErrors['dni'] = $values['tipo_documento'] === SpanishDataValidator::DOCUMENT_TYPE_PASSPORT
                    ? 'Introduce un pasaporte de 6 a 15 caracteres alfanuméricos.'
                    : 'Introduce un NIF con una letra de control válida.';
            }
            if (!$nss) {
                $fieldErrors['nss'] = 'Introduce entre 5 y 20 caracteres alfanuméricos, espacios, puntos o guiones.';
            }
            if (!$phone) {
                $fieldErrors['telefono'] = 'Introduce un teléfono español válido de nueve cifras, con o sin prefijo +34.';
            }
            if (!$address) {
                $fieldErrors['direccion'] = 'La dirección es obligatoria, no puede superar 255 caracteres ni contener controles.';
            }
            if (!$postcode) {
                $fieldErrors['codigo_postal'] = 'Introduce un código postal español válido de cinco cifras (provincias 01–52).';
            }
            if (!$birthDateValid) {
                $fieldErrors['fecha_nacimiento'] = 'Introduce una fecha de nacimiento válida.';
            }
            if ($error === '' && $birthDateValid && $birthDate > new DateTimeImmutable('today')) {
                $fieldErrors['fecha_nacimiento'] = 'La fecha no puede ser futura.';
                $error = 'La fecha de nacimiento no puede ser futura.';
            } elseif ($error === '' && $birthDateValid && $birthDate->diff(new DateTimeImmutable('today'))->y < 16) {
                $fieldErrors['fecha_nacimiento'] = 'El alumno debe tener al menos 16 años.';
                $error = 'El alumno debe tener al menos 16 años.';
            }
            if ($error === '' && $fieldErrors !== []) {
                $error = 'Revisa los campos indicados y vuelve a intentarlo.';
            }

            if ($error === '' && $birthDate->diff(new DateTimeImmutable('today'))->y < 18) {
                $tutorData = [
                    'nombre' => $values['tutor_nombre'],
                    'dni' => SpanishDataValidator::normalizeDniNie($values['tutor_dni']),
                    'nss' => $values['tutor_nss'],
                    'direccion' => $values['tutor_direccion'],
                    'telefono' => SpanishDataValidator::normalizePhone($values['tutor_telefono']),
                    'correo' => $values['tutor_correo'],
                ];
                if (
                    !SpanishDataValidator::isPersonName($tutorData['nombre'], 100)
                    || !SpanishDataValidator::isDniNie($tutorData['dni'])
                    || preg_match('/\A[A-Za-z0-9][A-Za-z0-9 .-]{4,19}\z/u', $tutorData['nss']) !== 1
                    || $tutorData['direccion'] === ''
                    || mb_strlen($tutorData['direccion']) > 255
                    || preg_match('/[\p{Cc}]/u', $tutorData['direccion']) === 1
                    || !SpanishDataValidator::isSpanishPhone($tutorData['telefono'])
                    || !SpanishDataValidator::isEmail($tutorData['correo'])
                ) {
                    $error = 'Completa los datos del tutor legal con información válida para guardar el expediente de un menor.';
                }
            }

            if ($error === '') {
                try {
                    $this->alumnoModel->actualizarPerfil(
                        $usuarioId,
                        $values['email'],
                        $values['nombre'],
                        $values['apellidos'],
                        $birthDate->format('Y-m-d'),
                        $values['dni'],
                        $values['nss'],
                        $values['telefono'],
                        $values['direccion'],
                        $values['codigo_postal'],
                        $tutorData,
                        $values['tipo_documento']
                    );
                    $_SESSION['email'] = $values['email'];
                    if ($jsonRequest) {
                        JsonResponse::success(['message' => 'Tus datos se han actualizado.']);
                        return;
                    }
                    header('Location: index.php?action=perfil-alumno&guardado=1');
                    exit;
                } catch (PDOException $exception) {
                    if ((string)$exception->getCode() === '23000') {
                        $error = 'El correo o documento de identidad indicado ya está asociado a otra cuenta.';
                        $errorStatus = 409;
                    } else {
                        error_log('Error al actualizar perfil de alumno: ' . $exception->getMessage());
                        $error = 'No se pudo guardar el expediente. Inténtalo de nuevo o contacta con el centro.';
                        $errorStatus = 500;
                    }
                }
            }
            if ($jsonRequest) {
                JsonResponse::failure(
                    $error !== '' ? $error : 'Revisa los campos indicados y vuelve a intentarlo.',
                    $errorStatus
                );
                return;
            }
        }

        View::render('alumnos/perfil', [
            'values' => $values,
            'fieldErrors' => $fieldErrors,
            'error' => $error,
            'guardado' => isset($_GET['guardado']),
        ]);
    }

}
