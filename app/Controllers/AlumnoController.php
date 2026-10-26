<?php

require_once __DIR__ . '/../Models/Usuario.php';
require_once __DIR__ . '/../Models/Alumno.php';
require_once __DIR__ . '/../Models/Especialidad.php';
require_once __DIR__ . '/../Models/Disponibilidad.php';
require_once __DIR__ . '/../Models/Asignacion.php';
require_once __DIR__ . '/../Models/Calificacion.php';
require_once __DIR__ . '/../Core/JsonResponse.php';
require_once __DIR__ . '/../Core/SpanishDataValidator.php';

class AlumnoController
{
    private PDO $db;
    private Usuario $usuarioModel;
    private Alumno $alumnoModel;
    private Especialidad $especialidadModel;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? getPDOConnection();
        $this->usuarioModel = new Usuario($this->db);
        $this->alumnoModel = new Alumno($this->db);
        $this->especialidadModel = new Especialidad($this->db);
    }

    public function registrar(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->mostrarFormulario();
            return;
        }

        $fields = [
            'email', 'password', 'nombre', 'apellidos', 'dni', 'nss', 'telefono', 'direccion', 'codigo_postal',
            'fecha_nacimiento', 'tutor_nombre', 'tutor_dni', 'tutor_nss', 'tutor_correo',
            'tutor_direccion', 'tutor_telefono',
        ];
        $input = [];
        foreach ($fields as $field) {
            $value = $_POST[$field] ?? '';
            if (!is_string($value)) {
                $this->mostrarFormulario(
                    'Los datos del registro deben ser valores de texto válidos.',
                    [$field => 'Este campo debe contener un único valor de texto.']
                );
                return;
            }
            // La contraseña no se recorta: los espacios cuentan.
            $input[$field] = $field === 'password' ? $value : trim($value);
        }
        $email = $input['email'];
        $password = $input['password'];
        $nombre = $input['nombre'];
        $apellidos = $input['apellidos'];
        $dni = $input['dni'];
        $nss = $input['nss'];
        $telefono = $input['telefono'];
        $direccion = $input['direccion'];
        $codigoPostal = $input['codigo_postal'];
        $fechaNacimiento = $input['fecha_nacimiento'];
        $especialidadId = filter_var(
            $_POST['especialidad_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $fieldErrors = [];
        $fechaNacimientoObj = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaNacimiento);
        $fechaErrores = DateTimeImmutable::getLastErrors();
        if (
            !$fechaNacimientoObj
            || ($fechaErrores !== false && ($fechaErrores['warning_count'] > 0 || $fechaErrores['error_count'] > 0))
        ) {
            $fieldErrors['fecha_nacimiento'] = 'Introduce una fecha de nacimiento válida.';
        } else {
            if ($fechaNacimientoObj > new DateTimeImmutable('today')) {
                $fieldErrors['fecha_nacimiento'] = 'La fecha de nacimiento no puede ser futura.';
            }
        }

        $hoy = new DateTimeImmutable('today');
        $registrationErrors = [
            'email' => !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100
                ? 'Introduce un correo electrónico válido de hasta 100 caracteres.'
                : null,
            'password' => strlen($password) < 12 || strlen($password) > 72 || trim($password) === ''
                ? 'La contraseña debe tener entre 12 y 72 caracteres y no estar vacía.'
                : null,
            'nombre' => !SpanishDataValidator::isPersonName($nombre, 50)
                ? 'Introduce un nombre válido de hasta 50 caracteres.'
                : null,
            'apellidos' => !SpanishDataValidator::isPersonName($apellidos, 100)
                ? 'Introduce apellidos válidos de hasta 100 caracteres.'
                : null,
            'dni' => mb_strlen($dni) > 20 || !SpanishDataValidator::isDniNie($dni)
                ? 'Introduce un DNI/NIE con una letra de control válida.'
                : null,
            'nss' => preg_match('/\A[A-Za-z0-9][A-Za-z0-9 .-]{4,19}\z/u', $nss) !== 1
                ? 'Introduce un número de Seguridad Social válido (5–20 caracteres).'
                : null,
            'telefono' => mb_strlen($telefono) > 20 || !SpanishDataValidator::isSpanishPhone($telefono)
                ? 'Introduce un teléfono español válido de hasta 20 caracteres.'
                : null,
            'direccion' => $direccion === ''
                || mb_strlen($direccion) > 255
                || preg_match('/[\p{Cc}]/u', $direccion) === 1
                ? 'Introduce una dirección de hasta 255 caracteres, sin caracteres de control.'
                : null,
            'codigo_postal' => !SpanishDataValidator::isSpanishPostcode($codigoPostal)
                ? 'Introduce un código postal español válido de cinco cifras.'
                : null,
            'especialidad_id' => $especialidadId === false
                ? 'Selecciona un ciclo formativo válido.'
                : null,
        ];
        foreach ($registrationErrors as $field => $message) {
            if ($message !== null) {
                $fieldErrors[$field] = $message;
            }
        }
        if ($fieldErrors !== []) {
            $this->mostrarFormulario('Completa correctamente los campos indicados.', $fieldErrors);
            return;
        }

        if (!$this->especialidadModel->existe($especialidadId)) {
            $this->mostrarFormulario(
                'La especialidad seleccionada no existe.',
                ['especialidad_id' => 'Selecciona un ciclo formativo disponible.']
            );
            return;
        }

        $edad = $fechaNacimientoObj->diff($hoy)->y;
        if ($edad < 16) {
            $this->mostrarFormulario(
                'El alumno debe tener al menos 16 años.',
                ['fecha_nacimiento' => 'El alumno debe tener al menos 16 años.']
            );
            return;
        }

        $tutor = [
            'nombre' => $input['tutor_nombre'],
            'dni' => $input['tutor_dni'],
            'nss' => $input['tutor_nss'],
            'correo' => $input['tutor_correo'],
            'direccion' => $input['tutor_direccion'],
            'telefono' => $input['tutor_telefono'],
        ];
        $tutorFieldErrors = [];
        foreach ([
            'nombre' => ['tutor_nombre', 100],
            'dni' => ['tutor_dni', 20],
            'nss' => ['tutor_nss', 20],
            'correo' => ['tutor_correo', 100],
            'direccion' => ['tutor_direccion', 255],
            'telefono' => ['tutor_telefono', 20],
        ] as $field => [$inputField, $limit]) {
            $length = $field === 'correo' ? strlen($tutor[$field]) : mb_strlen($tutor[$field]);
            if ($length > $limit) {
                $tutorFieldErrors[$inputField] = "No puede superar $limit caracteres.";
            }
        }
        if (
            !isset($tutorFieldErrors['tutor_dni'])
            && $tutor['dni'] !== ''
            && !SpanishDataValidator::isDniNie($tutor['dni'])
        ) {
            $tutorFieldErrors['tutor_dni'] = 'Introduce un DNI/NIE con una letra de control válida.';
        }
        if (
            !isset($tutorFieldErrors['tutor_telefono'])
            && $tutor['telefono'] !== ''
            && !SpanishDataValidator::isSpanishPhone($tutor['telefono'])
        ) {
            $tutorFieldErrors['tutor_telefono'] = 'Introduce un teléfono español válido de nueve cifras.';
        }
        if (
            !isset($tutorFieldErrors['tutor_correo'])
            && $tutor['correo'] !== ''
            && !SpanishDataValidator::isEmail($tutor['correo'])
        ) {
            $tutorFieldErrors['tutor_correo'] = 'Introduce un correo electrónico válido.';
        }
        if (
            !isset($tutorFieldErrors['tutor_nombre'])
            && $tutor['nombre'] !== ''
            && !SpanishDataValidator::isPersonName($tutor['nombre'], 100)
        ) {
            $tutorFieldErrors['tutor_nombre'] = 'Introduce un nombre válido de hasta 100 caracteres.';
        }
        if (
            !isset($tutorFieldErrors['tutor_nss'])
            && $tutor['nss'] !== ''
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9 .-]{4,19}\z/u', $tutor['nss']) !== 1
        ) {
            $tutorFieldErrors['tutor_nss'] = 'Introduce un número de Seguridad Social válido (5–20 caracteres).';
        }
        if (
            !isset($tutorFieldErrors['tutor_direccion'])
            && $tutor['direccion'] !== ''
            && preg_match('/[\p{Cc}]/u', $tutor['direccion']) === 1
        ) {
            $tutorFieldErrors['tutor_direccion'] = 'La dirección no puede contener caracteres de control.';
        }
        if ($tutorFieldErrors !== []) {
            $this->mostrarFormulario('Comprueba los datos del tutor indicados.', $tutorFieldErrors);
            return;
        }
        $dni = SpanishDataValidator::normalizeDniNie($dni);
        $telefono = SpanishDataValidator::normalizePhone($telefono);
        if ($tutor['dni'] !== '') {
            $tutor['dni'] = SpanishDataValidator::normalizeDniNie($tutor['dni']);
        }
        if ($tutor['telefono'] !== '') {
            $tutor['telefono'] = SpanishDataValidator::normalizePhone($tutor['telefono']);
        }

        if (
            $edad < 18
            && (
                $tutor['nombre'] === ''
                || $tutor['dni'] === ''
                || $tutor['nss'] === ''
                || !SpanishDataValidator::isEmail($tutor['correo'])
                || $tutor['direccion'] === ''
                || $tutor['telefono'] === ''
            )
        ) {
            $tutorFieldErrors = [];
            foreach ([
                'nombre' => 'tutor_nombre',
                'dni' => 'tutor_dni',
                'nss' => 'tutor_nss',
                'correo' => 'tutor_correo',
                'direccion' => 'tutor_direccion',
                'telefono' => 'tutor_telefono',
            ] as $field => $inputField) {
                if ($tutor[$field] === '') {
                    $tutorFieldErrors[$inputField] = 'Este campo es obligatorio para alumnos menores de 18 años.';
                }
            }
            $this->mostrarFormulario(
                'Para menores de 18 años, completa todos los datos del tutor legal.',
                $tutorFieldErrors
            );
            return;
        }

        try {
            $this->db->beginTransaction();

            $usuarioId = $this->usuarioModel->crear($email, $password, 'Alumno');
            if ($usuarioId < 1) {
                if ($this->usuarioModel->obtenerPorEmail($email) !== null) {
                    $this->db->rollBack();
                    $this->mostrarFormulario(
                        'El correo indicado ya está asociado a otra cuenta.',
                        ['email' => 'Este correo electrónico ya está registrado.']
                    );
                    return;
                }
                throw new RuntimeException('No se pudo crear el usuario del alumno.');
            }

            $alumnoId = $this->alumnoModel->crear(
                $usuarioId,
                $nombre,
                $apellidos,
                $fechaNacimientoObj->format('Y-m-d'),
                $dni,
                $nss,
                $email,
                $telefono,
                $direccion,
                $especialidadId,
                $codigoPostal
            );
            if ($alumnoId < 1) {
                throw new RuntimeException('No se pudo crear el perfil del alumno.');
            }

            if ($edad < 18 && !$this->alumnoModel->registrarTutor(
                $alumnoId,
                $tutor['nombre'],
                $tutor['dni'],
                $tutor['nss'],
                $tutor['direccion'],
                $tutor['telefono'],
                $tutor['correo']
            )) {
                throw new RuntimeException('No se pudieron guardar los datos del tutor legal.');
            }

            $this->db->commit();
            header('Location: index.php?action=login&registrado=1');
            exit;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
                $duplicateErrors = $this->obtenerErroresDuplicadosRegistro($email, $dni, $nss);
                if ($duplicateErrors !== []) {
                    $this->mostrarFormulario(
                        'El correo, DNI/NIE o número de Seguridad Social indicado ya está asociado a otra cuenta.',
                        $duplicateErrors
                    );
                    return;
                }
                error_log('Error al registrar alumno: se detectó un conflicto de unicidad sin campo coincidente.');
                $this->mostrarFormulario('No se pudo completar el registro del alumno.');
                return;
            }
            error_log('Error al registrar alumno: ' . $e->getMessage());
            $this->mostrarFormulario('No se pudo completar el registro del alumno.');
        }
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
            $studentPhoneWithinLimit = mb_strlen($values['telefono']) <= 20;
            $studentDniWithinLimit = mb_strlen($values['dni']) <= 20;
            $values['dni'] = SpanishDataValidator::normalizeDniNie($values['dni']);
            $values['telefono'] = SpanishDataValidator::normalizePhone($values['telefono']);

            $birthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $values['fecha_nacimiento']);
            $dateErrors = DateTimeImmutable::getLastErrors();
            $birthDateValid = $birthDate !== false
                && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0));
            $email = SpanishDataValidator::isEmail($values['email']);
            $name = SpanishDataValidator::isPersonName($values['nombre'], 50);
            $surname = SpanishDataValidator::isPersonName($values['apellidos'], 100);
            $dni = $studentDniWithinLimit && SpanishDataValidator::isDniNie($values['dni']);
            $nss = preg_match('/\A[A-Za-z0-9][A-Za-z0-9 .-]{4,19}\z/u', $values['nss']) === 1;
            $phone = $studentPhoneWithinLimit && SpanishDataValidator::isSpanishPhone($values['telefono']);
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
            if (!$dni) {
                $fieldErrors['dni'] = 'Introduce un DNI/NIE válido de hasta 20 caracteres, con letra de control.';
            }
            if (!$nss) {
                $fieldErrors['nss'] = 'Introduce entre 5 y 20 caracteres alfanuméricos, espacios, puntos o guiones.';
            }
            if (!$phone) {
                $fieldErrors['telefono'] = 'Introduce un teléfono español válido de hasta 20 caracteres.';
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
                $guardianPhoneWithinLimit = mb_strlen($values['tutor_telefono']) <= 20;
                $guardianDniWithinLimit = mb_strlen($values['tutor_dni']) <= 20;
                $tutorData = [
                    'nombre' => $values['tutor_nombre'],
                    'dni' => SpanishDataValidator::normalizeDniNie($values['tutor_dni']),
                    'nss' => $values['tutor_nss'],
                    'direccion' => $values['tutor_direccion'],
                    'telefono' => SpanishDataValidator::normalizePhone($values['tutor_telefono']),
                    'correo' => $values['tutor_correo'],
                ];
                $tutorFieldErrors = [
                    'tutor_nombre' => !SpanishDataValidator::isPersonName($tutorData['nombre'], 100)
                        ? 'Introduce un nombre válido de hasta 100 caracteres.'
                        : null,
                    'tutor_dni' => !$guardianDniWithinLimit || !SpanishDataValidator::isDniNie($tutorData['dni'])
                        ? 'Introduce un DNI/NIE válido de hasta 20 caracteres, con letra de control.'
                        : null,
                    'tutor_nss' => preg_match('/\A[A-Za-z0-9][A-Za-z0-9 .-]{4,19}\z/u', $tutorData['nss']) !== 1
                        ? 'Introduce un número de Seguridad Social válido (5–20 caracteres).'
                        : null,
                    'tutor_direccion' => $tutorData['direccion'] === ''
                        || mb_strlen($tutorData['direccion']) > 255
                        || preg_match('/[\p{Cc}]/u', $tutorData['direccion']) === 1
                        ? 'Introduce una dirección de hasta 255 caracteres, sin caracteres de control.'
                        : null,
                    'tutor_telefono' => !$guardianPhoneWithinLimit
                        || !SpanishDataValidator::isSpanishPhone($tutorData['telefono'])
                        ? 'Introduce un teléfono español válido de hasta 20 caracteres.'
                        : null,
                    'tutor_correo' => !SpanishDataValidator::isEmail($tutorData['correo'])
                        ? 'Introduce un correo electrónico válido.'
                        : null,
                ];
                foreach ($tutorFieldErrors as $field => $message) {
                    if ($message !== null) {
                        $fieldErrors[$field] = $message;
                    }
                }
                if ($fieldErrors !== []) {
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
                        $tutorData
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
                        $error = 'El correo, DNI/NIE o número de Seguridad Social indicado ya está asociado a otra cuenta.';
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

    private function obtenerErroresDuplicadosRegistro(string $email, string $dni, string $nss): array
    {
        $duplicates = $this->db->prepare(
            'SELECT
                EXISTS(SELECT 1 FROM usuarios WHERE email = :email) AS email,
                EXISTS(SELECT 1 FROM alumnos WHERE dni = :dni) AS dni,
                EXISTS(SELECT 1 FROM alumnos WHERE numero_seguridad_social = :nss) AS nss'
        );
        $duplicates->execute([
            'email' => $email,
            'dni' => $dni,
            'nss' => $nss,
        ]);
        $existing = $duplicates->fetch(PDO::FETCH_ASSOC);
        if (!is_array($existing)) {
            throw new RuntimeException('No se pudieron comprobar los datos duplicados del registro.');
        }

        $fieldErrors = [];
        if ((int)$existing['email'] === 1) {
            $fieldErrors['email'] = 'Este correo electrónico ya está registrado.';
        }
        if ((int)$existing['dni'] === 1) {
            $fieldErrors['dni'] = 'Este DNI/NIE ya está asociado a otro expediente.';
        }
        if ((int)$existing['nss'] === 1) {
            $fieldErrors['nss'] = 'Este número de Seguridad Social ya está asociado a otro expediente.';
        }

        return $fieldErrors;
    }

    private function mostrarFormulario(string $error = '', array $fieldErrors = []): void
    {
        $especialidades = $this->especialidadModel->obtenerTodas();
        $values = [];
        foreach ([
            'email', 'nombre', 'apellidos', 'telefono', 'direccion', 'codigo_postal',
            'fecha_nacimiento', 'especialidad_id', 'tutor_nombre', 'tutor_correo',
            'tutor_direccion', 'tutor_telefono',
        ] as $field) {
            if (isset($_POST[$field]) && is_string($_POST[$field])) {
                $values[$field] = $_POST[$field];
            }
        }
        View::render('alumnos/registro', [
            'error' => $error,
            'fieldErrors' => $fieldErrors,
            'especialidades' => $especialidades,
            'values' => $values,
        ]);
    }
}
