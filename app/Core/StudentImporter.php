<?php

declare(strict_types=1);

require_once __DIR__ . '/../Models/Usuario.php';
require_once __DIR__ . '/../Models/Alumno.php';
require_once __DIR__ . '/../Models/Auditoria.php';
require_once __DIR__ . '/SpanishDataValidator.php';
require_once __DIR__ . '/AccountActivationMailer.php';
require_once __DIR__ . '/ActivationUrl.php';
require_once __DIR__ . '/../Services/WebhookPublisher.php';

final class StudentImporter
{
    private Usuario $users;
    private Alumno $students;
    private Auditoria $audit;

    public function __construct(private PDO $db, private ?Closure $sendActivation = null)
    {
        $this->users = new Usuario($db);
        $this->students = new Alumno($db);
        $this->audit = new Auditoria($db);
    }

    /**
     * @param list<array{linea:int,email:string,dni:string,nombre:string,apellidos:string,ciclo:string,curso:string}> $rows
     * @param list<array{id:int,nombre_especialidad:string,activo:int|string}> $activeCycles
     * @return array{total:int,valid_rows:list<array<string,mixed>>,activation_rows:list<array<string,mixed>>,errors:list<array{linea:int,email:string,motivo:string}>}
     */
    public function preview(array $rows, array $activeCycles): array
    {
        $cycleIds = [];
        foreach ($activeCycles as $cycle) {
            $key = self::normalizeText($cycle['nombre_especialidad']);
            if (isset($cycleIds[$key])) {
                $cycleIds[$key] = null;
            } else {
                $cycleIds[$key] = (int)$cycle['id'];
            }
        }

        $candidates = [];
        foreach ($rows as $row) {
            $email = strtolower(trim($row['email']));
            $dni = SpanishDataValidator::normalizeDniNie($row['dni']);
            if (SpanishDataValidator::isEmail($email)) {
                $candidates['emails'][$email] = true;
            }
            if (SpanishDataValidator::isDniNie($dni)) {
                $candidates['dnis'][$dni] = true;
            }
        }
        $existingEmails = $this->findExistingEmails(array_keys($candidates['emails'] ?? []));
        $existingDnis = $this->findExistingDnis(array_keys($candidates['dnis'] ?? []));
        $pendingStudents = $this->findPendingActivationStudents(array_keys($candidates['emails'] ?? []));

        $seenEmails = [];
        $seenDnis = [];
        $validRows = [];
        $activationRows = [];
        $errors = [];
        foreach ($rows as $row) {
            $email = strtolower(trim($row['email']));
            $dni = SpanishDataValidator::normalizeDniNie($row['dni']);
            $cycleName = trim($row['ciclo']);
            $cycleId = $cycleIds[self::normalizeText($cycleName)] ?? null;
            $course = trim($row['curso']);
            $rowErrors = [];
            $duplicateEmailInFile = isset($seenEmails[$email]);

            if (!SpanishDataValidator::isEmail($email)) {
                $rowErrors[] = 'Correo electrónico no válido.';
            } elseif ($duplicateEmailInFile) {
                $rowErrors[] = 'Correo duplicado dentro del archivo.';
            } elseif (isset($existingEmails[$email])) {
                $rowErrors[] = 'El correo ya está registrado.';
            }
            if ($email !== '') {
                $seenEmails[$email] = true;
            }

            if (!SpanishDataValidator::isDniNie($dni)) {
                $rowErrors[] = 'DNI/NIE no válido.';
            } elseif (isset($seenDnis[$dni])) {
                $rowErrors[] = 'DNI/NIE duplicado dentro del archivo.';
            } elseif (isset($existingDnis[$dni])) {
                $rowErrors[] = 'El DNI/NIE ya está asociado a una cuenta.';
            }
            if ($dni !== '') {
                $seenDnis[$dni] = true;
            }

            if (!SpanishDataValidator::isPersonName(trim($row['nombre']), 50)) {
                $rowErrors[] = 'Nombre vacío o no válido (máximo 50 caracteres).';
            }
            if (!SpanishDataValidator::isPersonName(trim($row['apellidos']), 100)) {
                $rowErrors[] = 'Apellidos vacíos o no válidos (máximo 100 caracteres).';
            }
            if ($cycleName === '' || !array_key_exists(self::normalizeText($cycleName), $cycleIds)) {
                $rowErrors[] = 'El ciclo formativo no existe o no está activo.';
            } elseif ($cycleId === null) {
                $rowErrors[] = 'El nombre del ciclo formativo no identifica una opción única.';
            }
            if (!in_array($course, ['1', '2'], true)) {
                $rowErrors[] = 'El curso debe ser 1 o 2.';
            }

            $pendingStudent = $pendingStudents[$email] ?? null;
            if (is_array($pendingStudent) && !$duplicateEmailInFile && SpanishDataValidator::isEmail($email)) {
                $activationRows[] = [
                    'linea' => $row['linea'],
                    'email' => $email,
                    'dni' => $dni,
                    'nombre' => trim($row['nombre']),
                    'apellidos' => trim($row['apellidos']),
                    'cycle_id' => $cycleId,
                    'curso' => (int)$course,
                ];
                continue;
            }

            if ($rowErrors !== []) {
                $errors[] = [
                    'linea' => $row['linea'],
                    'email' => $email,
                    'motivo' => implode(' ', $rowErrors),
                ];
                continue;
            }

            $validRows[] = [
                'linea' => $row['linea'],
                'email' => $email,
                'dni' => $dni,
                'nombre' => trim($row['nombre']),
                'apellidos' => trim($row['apellidos']),
                'ciclo' => $cycleName,
                'cycle_id' => $cycleId,
                'curso' => (int)$course,
            ];
        }

        return [
            'total' => count($rows),
            'valid_rows' => $validRows,
            'activation_rows' => $activationRows,
            'errors' => $errors,
        ];
    }

    /**
     * @param list<array{linea:int,email:string,dni:string,nombre:string,apellidos:string,cycle_id:int,curso:int}> $rows
     * @return array{enviados:int,resultados:list<array{linea:int,email:string,estado:string,motivo:string}>}
     */
    public function resendActivation(array $rows, int $adminId): array
    {
        $appUrl = rtrim(getenv('FCT_APP_URL') ?: '', '/');
        if (!ActivationUrl::isAllowedBaseUrl($appUrl)) {
            throw new RuntimeException('FCT_APP_URL no está configurada con una dirección segura.');
        }

        $sent = 0;
        $results = [];
        foreach ($rows as $row) {
            try {
                $this->resendActivationRow($row, $adminId, $appUrl);
                $sent++;
                $results[] = [
                    'linea' => $row['linea'],
                    'email' => $row['email'],
                    'estado' => 'Correo de activación enviado',
                    'motivo' => '',
                ];
            } catch (DomainException $exception) {
                $results[] = [
                    'linea' => $row['linea'],
                    'email' => $row['email'],
                    'estado' => 'Error',
                    'motivo' => $exception->getMessage(),
                ];
            } catch (Throwable $exception) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                error_log('Reenvío de activación de alumnado, línea ' . $row['linea'] . ': ' . $exception->getMessage());
                $results[] = [
                    'linea' => $row['linea'],
                    'email' => $row['email'],
                    'estado' => 'Error',
                    'motivo' => 'No se pudo enviar el correo de activación.',
                ];
            }
        }

        if ($rows !== []) {
            $this->audit->registrar(
                $adminId,
                'alumnos.activacion_masiva_reenviada',
                'alumnos',
                null,
                'solicitados=' . count($rows) . '; enviados=' . $sent . '; fallidos=' . (count($rows) - $sent),
                'reenvío de activación'
            );
        }

        return ['enviados' => $sent, 'resultados' => $results];
    }

    /**
     * @param array{linea:int,email:string,dni:string,nombre:string,apellidos:string,cycle_id:int,curso:int} $row
     */
    private function resendActivationRow(array $row, int $adminId, string $appUrl): void
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT u.id AS usuario_id, u.email, u.activo, a.id AS alumno_id,
                        a.dni, a.nombre, a.apellidos, a.especialidad_id, a.curso
                 FROM usuarios u
                 INNER JOIN alumnos a ON a.usuario_id = u.id
                 WHERE LOWER(u.email) = :email AND u.rol = 'Alumno'
                 LIMIT 1 FOR UPDATE"
            );
            $stmt->execute(['email' => strtolower($row['email'])]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            if (
                !is_array($student)
                || (int)$student['activo'] !== 0
            ) {
                throw new DomainException('La cuenta de alumno no está disponible o ya está activada.');
            }

            $pendingToken = $this->db->prepare(
                'SELECT id FROM student_activation_tokens
                 WHERE usuario_id = :usuario_id AND used_at IS NULL
                 LIMIT 1 FOR UPDATE'
            );
            $pendingToken->execute(['usuario_id' => (int)$student['usuario_id']]);
            if ($pendingToken->fetchColumn() === false) {
                throw new DomainException('La cuenta no tiene una activación pendiente.');
            }

            $invalidate = $this->db->prepare(
                'UPDATE student_activation_tokens
                 SET used_at = CURRENT_TIMESTAMP
                 WHERE usuario_id = :usuario_id AND used_at IS NULL'
            );
            $invalidate->execute(['usuario_id' => (int)$student['usuario_id']]);

            $token = bin2hex(random_bytes(32));
            $insertToken = $this->db->prepare(
                'INSERT INTO student_activation_tokens (usuario_id, token_hash, expires_at)
                 VALUES (:usuario_id, :token_hash, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR))'
            );
            $insertToken->execute([
                'usuario_id' => (int)$student['usuario_id'],
                'token_hash' => hash('sha256', $token),
            ]);

            $activationUrl = $appUrl . '/index.php?action=activar-cuenta&token=' . rawurlencode($token);
            if ($this->sendActivation !== null) {
                ($this->sendActivation)(
                    $student['email'],
                    $student['nombre'] . ' ' . $student['apellidos'],
                    $activationUrl
                );
            } else {
                (new AccountActivationMailer())->send(
                    $student['email'],
                    $student['nombre'] . ' ' . $student['apellidos'],
                    $activationUrl
                );
            }

            $this->audit->registrar(
                $adminId,
                'alumno.activacion_reenviada',
                'alumnos',
                (int)$student['alumno_id'],
                'usuario_id=' . (int)$student['usuario_id'],
                'reenvío de activación'
            );
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param list<array{linea:int,email:string,dni:string,nombre:string,apellidos:string,ciclo:string,cycle_id:int,curso:int}> $rows
     * @return array{insertados:int,resultados:list<array{linea:int,email:string,estado:string,motivo:string}>}
     */
    public function import(
        array $rows,
        int $adminId,
        string $fileName,
        string $fileHash,
        ?int $totalRows = null,
        int $previewErrors = 0
    ): array
    {
        $appUrl = rtrim(getenv('FCT_APP_URL') ?: '', '/');
        if (!ActivationUrl::isAllowedBaseUrl($appUrl)) {
            throw new RuntimeException('FCT_APP_URL no está configurada con una dirección segura.');
        }

        $inserted = 0;
        $results = [];
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($fileName)) ?? '';
        foreach ($rows as $row) {
            try {
                $this->importRow($row, $adminId, $appUrl);
                $inserted++;
                $results[] = [
                    'linea' => $row['linea'],
                    'email' => $row['email'],
                    'estado' => 'Importado',
                    'motivo' => '',
                ];
            } catch (DomainException $exception) {
                $results[] = [
                    'linea' => $row['linea'],
                    'email' => $row['email'],
                    'estado' => 'Error',
                    'motivo' => $exception->getMessage(),
                ];
            } catch (Throwable $exception) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                error_log('Importación masiva de alumnado, línea ' . $row['linea'] . ': ' . $exception->getMessage());
                $results[] = [
                    'linea' => $row['linea'],
                    'email' => $row['email'],
                    'estado' => 'Error',
                    'motivo' => 'No se pudo crear la cuenta o enviar el correo de activación.',
                ];
            }
        }

        $this->audit->registrar(
            $adminId,
            'alumnos.importacion_masiva',
            'alumnos',
            null,
            'archivo=' . mb_substr($safeName, 0, 80) . '; sha256=' . $fileHash
                . '; filas=' . ($totalRows ?? count($rows))
                . '; exitosas=' . $inserted
                . '; fallidas=' . ($previewErrors + count($results) - $inserted),
            'creación'
        );

        return ['insertados' => $inserted, 'resultados' => $results];
    }

    /**
     * @param array{linea:int,email:string,dni:string,nombre:string,apellidos:string,ciclo:string,cycle_id:int,curso:int} $row
     */
    private function importRow(array $row, int $adminId, string $appUrl): void
    {
        $this->db->beginTransaction();
        try {
            if ($this->users->obtenerPorEmail($row['email']) !== null) {
                throw new DomainException('El correo ya está registrado.');
            }
            if (!$this->isCycleActive($row['cycle_id'])) {
                throw new DomainException('El ciclo formativo dejó de estar activo; vuelve a generar la previsualización.');
            }
            $this->users->assertIdentificadorFiscalDisponible($row['dni']);

            $userId = $this->users->crear(
                $row['email'],
                bin2hex(random_bytes(32)),
                'Alumno',
                false
            );
            if ($userId < 1) {
                throw new RuntimeException('No se pudo crear el usuario.');
            }
            $studentId = $this->students->crearDesdePersonal(
                $userId,
                $row['nombre'],
                $row['apellidos'],
                $row['dni'],
                $row['email'],
                $row['cycle_id'],
                $row['curso'],
                ''
            );
            WebhookPublisher::publish($this->db, 'alumno.alta', ['alumno_id' => $studentId]);
            $this->audit->registrar(
                $adminId,
                'alumno.importado_masivamente',
                'alumnos',
                $studentId,
                'ciclo_id=' . $row['cycle_id'] . '; curso=' . $row['curso'],
                'creación'
            );

            $token = bin2hex(random_bytes(32));
            $tokenInsert = $this->db->prepare(
                'INSERT INTO student_activation_tokens (usuario_id, token_hash, expires_at)
                 VALUES (:usuario_id, :token_hash, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR))'
            );
            $tokenInsert->execute([
                'usuario_id' => $userId,
                'token_hash' => hash('sha256', $token),
            ]);
            $activationUrl = $appUrl . '/index.php?action=activar-cuenta&token=' . rawurlencode($token);
            if ($this->sendActivation !== null) {
                ($this->sendActivation)($row['email'], $row['nombre'] . ' ' . $row['apellidos'], $activationUrl);
            } else {
                (new AccountActivationMailer())->send(
                    $row['email'],
                    $row['nombre'] . ' ' . $row['apellidos'],
                    $activationUrl
                );
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function isCycleActive(int $cycleId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM especialidades WHERE id = :id AND activo = 1 LIMIT 1');
        $stmt->execute(['id' => $cycleId]);
        return (bool)$stmt->fetchColumn();
    }

    /** @param list<string> $emails @return array<string,true> */
    private function findExistingEmails(array $emails): array
    {
        $existing = [];
        foreach (array_chunk(array_values(array_unique($emails)), 400) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->prepare("SELECT LOWER(email) FROM usuarios WHERE LOWER(email) IN ($placeholders)");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $email) {
                $existing[(string)$email] = true;
            }
        }
        return $existing;
    }

    /** @param list<string> $dnis @return array<string,true> */
    private function findExistingDnis(array $dnis): array
    {
        $existing = [];
        foreach (array_chunk(array_values(array_unique($dnis)), 250) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $normalizedStudent = "UPPER(REPLACE(REPLACE(REPLACE(dni, ' ', ''), '-', ''), '.', ''))";
            $normalizedCompany = "UPPER(REPLACE(REPLACE(REPLACE(cif_empresa, ' ', ''), '-', ''), '.', ''))";
            $normalizedStaff = "UPPER(REPLACE(REPLACE(REPLACE(identificador_fiscal, ' ', ''), '-', ''), '.', ''))";
            $sql = "SELECT $normalizedStudent FROM alumnos WHERE $normalizedStudent IN ($placeholders)
                    UNION SELECT $normalizedCompany FROM empresas WHERE $normalizedCompany IN ($placeholders)
                    UNION SELECT $normalizedStaff FROM usuarios
                    WHERE identificador_fiscal IS NOT NULL AND $normalizedStaff IN ($placeholders)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([...$chunk, ...$chunk, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $dni) {
                $existing[(string)$dni] = true;
            }
        }
        return $existing;
    }

    /** @param list<string> $emails @return array<string,array<string,mixed>> */
    private function findPendingActivationStudents(array $emails): array
    {
        $pending = [];
        foreach (array_chunk(array_values(array_unique($emails)), 400) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->prepare(
                "SELECT LOWER(u.email) AS email, a.dni, a.nombre, a.apellidos,
                        a.especialidad_id, a.curso
                 FROM usuarios u
                 INNER JOIN alumnos a ON a.usuario_id = u.id
                 WHERE u.rol = 'Alumno' AND u.activo = 0
                   AND LOWER(u.email) IN ($placeholders)
                   AND EXISTS (
                       SELECT 1 FROM student_activation_tokens t
                       WHERE t.usuario_id = u.id AND t.used_at IS NULL
                   )"
            );
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $student) {
                $pending[(string)$student['email']] = $student;
            }
        }
        return $pending;
    }

    private static function normalizeText(string $value): string
    {
        $value = strtr(mb_strtolower(trim($value)), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n',
        ]);
        return preg_replace('/\s+/u', ' ', $value) ?? '';
    }
}
