<?php
// app/Models/Alumno.php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Core/SpanishDataValidator.php';

class Alumno {
    private PDO $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? getPDOConnection();
    }

    public function obtenerPorUsuario(int $usuarioId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT a.id, a.nombre, a.apellidos, a.fecha_nacimiento, a.dni, a.tipo_documento,
                    a.numero_seguridad_social, a.codigo_postal, u.email AS correo,
                    es.nombre_especialidad,
                    (SELECT dt.telefono
                     FROM direcciones_telefonos dt
                     WHERE dt.alumno_id = a.id
                     ORDER BY dt.es_principal DESC, dt.id
                     LIMIT 1) AS telefono,
                    (SELECT dt.direccion_completa
                     FROM direcciones_telefonos dt
                     WHERE dt.alumno_id = a.id
                     ORDER BY dt.es_principal DESC, dt.id
                     LIMIT 1) AS direccion
             FROM alumnos a
             INNER JOIN usuarios u ON u.id = a.usuario_id
             LEFT JOIN especialidades es ON es.id = a.especialidad_id
             WHERE a.usuario_id = :usuario_id
             LIMIT 1'
        );
        $stmt->execute(['usuario_id' => $usuarioId]);
        $alumno = $stmt->fetch(PDO::FETCH_ASSOC);

        return $alumno ?: null;
    }

    public function actualizarPerfil(
        int $usuarioId,
        string $email,
        string $nombre,
        string $apellidos,
        string $fechaNacimiento,
        string $dni,
        string $nss,
        string $telefono,
        string $direccion,
        string $codigoPostal,
        ?array $tutor = null,
        string $tipoDocumento = SpanishDataValidator::DOCUMENT_TYPE_NIF_NIE_DNI
    ): void {
        $this->db->beginTransaction();
        try {
            $profile = $this->db->prepare(
                'SELECT a.id
                 FROM alumnos a
                 INNER JOIN usuarios u ON u.id = a.usuario_id
                 WHERE u.id = :usuario_id
                 FOR UPDATE'
            );
            $profile->execute(['usuario_id' => $usuarioId]);
            $alumnoId = $profile->fetchColumn();
            if ($alumnoId === false) {
                throw new DomainException('No se encontró el expediente del alumno.');
            }

            $updateUser = $this->db->prepare('UPDATE usuarios SET email = :email WHERE id = :id');
            $updateUser->execute(['email' => $email, 'id' => $usuarioId]);
            $updateStudent = $this->db->prepare(
                'UPDATE alumnos
                 SET nombre = :nombre, apellidos = :apellidos, fecha_nacimiento = :nacimiento,
                     dni = :dni, tipo_documento = :tipo_documento,
                     numero_seguridad_social = :nss, codigo_postal = :codigo_postal,
                     correo = :email
                 WHERE id = :id'
            );
            $updateStudent->execute([
                'nombre' => $nombre,
                'apellidos' => $apellidos,
                'nacimiento' => $fechaNacimiento,
                'dni' => $dni,
                'tipo_documento' => $tipoDocumento,
                'nss' => $nss,
                'codigo_postal' => $codigoPostal,
                'email' => $email,
                'id' => $alumnoId,
            ]);

            $contact = $this->db->prepare(
                'SELECT id FROM direcciones_telefonos
                 WHERE alumno_id = :alumno_id
                 ORDER BY es_principal DESC, id
                 LIMIT 1
                 FOR UPDATE'
            );
            $contact->execute(['alumno_id' => $alumnoId]);
            $contactId = $contact->fetchColumn();
            if ($contactId === false) {
                $saveContact = $this->db->prepare(
                    'INSERT INTO direcciones_telefonos (alumno_id, telefono, direccion_completa)
                     VALUES (:alumno_id, :telefono, :direccion)'
                );
                $saveContact->execute([
                    'alumno_id' => $alumnoId,
                    'telefono' => $telefono,
                    'direccion' => $direccion,
                ]);
            } else {
                $saveContact = $this->db->prepare(
                    'UPDATE direcciones_telefonos
                     SET telefono = :telefono, direccion_completa = :direccion
                     WHERE id = :id'
                );
                $saveContact->execute([
                    'telefono' => $telefono,
                    'direccion' => $direccion,
                    'id' => $contactId,
                ]);
            }

            if ($tutor !== null) {
                $guardian = $this->db->prepare(
                    'SELECT id FROM tutores_legales
                     WHERE alumno_id = :alumno_id
                     ORDER BY id
                     LIMIT 1
                     FOR UPDATE'
                );
                $guardian->execute(['alumno_id' => $alumnoId]);
                $guardianId = $guardian->fetchColumn();
                if ($guardianId === false) {
                    $saveGuardian = $this->db->prepare(
                        'INSERT INTO tutores_legales
                            (alumno_id, nombre_completo, dni, numero_seguridad_social, direccion, telefono, correo)
                         VALUES
                            (:alumno_id, :nombre, :dni, :nss, :direccion, :telefono, :correo)'
                    );
                    $saveGuardian->execute([
                        'alumno_id' => $alumnoId,
                        'nombre' => $tutor['nombre'],
                        'dni' => $tutor['dni'],
                        'nss' => $tutor['nss'],
                        'direccion' => $tutor['direccion'],
                        'telefono' => $tutor['telefono'],
                        'correo' => $tutor['correo'],
                    ]);
                } else {
                    $saveGuardian = $this->db->prepare(
                        'UPDATE tutores_legales
                         SET nombre_completo = :nombre, dni = :dni,
                             numero_seguridad_social = :nss, direccion = :direccion,
                             telefono = :telefono, correo = :correo
                         WHERE id = :id'
                    );
                    $saveGuardian->execute([
                        'nombre' => $tutor['nombre'],
                        'dni' => $tutor['dni'],
                        'nss' => $tutor['nss'],
                        'direccion' => $tutor['direccion'],
                        'telefono' => $tutor['telefono'],
                        'correo' => $tutor['correo'],
                        'id' => $guardianId,
                    ]);
                }
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function crear(
        int $usuarioId,
        string $nombre,
        string $apellidos,
        string $fechaNacimiento,
        string $dni,
        string $nss,
        string $email,
        string $telefono,
        string $direccion,
        int $especialidadId,
        string $codigoPostal = ''
    ): int {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO alumnos
                    (usuario_id, nombre, apellidos, fecha_nacimiento, dni, numero_seguridad_social,
                     codigo_postal, correo, especialidad_id)
                 VALUES
                    (:usuario_id, :nombre, :apellidos, :fecha_nacimiento, :dni, :nss,
                     :codigo_postal, :correo, :especialidad_id)'
            );
            $stmt->execute([
                'usuario_id' => $usuarioId,
                'nombre' => $nombre,
                'apellidos' => $apellidos,
                'fecha_nacimiento' => $fechaNacimiento,
                'dni' => $dni,
                'nss' => $nss,
                'codigo_postal' => $codigoPostal !== '' ? $codigoPostal : null,
                'correo' => $email,
                'especialidad_id' => $especialidadId,
            ]);

            $alumnoId = (int)$this->db->lastInsertId();
            $contacto = $this->db->prepare(
                'INSERT INTO direcciones_telefonos (alumno_id, telefono, direccion_completa)
                 VALUES (:alumno_id, :telefono, :direccion)'
            );
            $contacto->execute([
                'alumno_id' => $alumnoId,
                'telefono' => $telefono,
                'direccion' => $direccion,
            ]);

            return $alumnoId;
        } catch (PDOException $e) {
            error_log("Error al crear alumno: " . $e->getMessage());
            return 0;
        }
    }

    public function crearDesdePersonal(
        int $usuarioId,
        string $nombre,
        string $apellidos,
        string $dni,
        string $email,
        int $especialidadId,
        int $curso,
        string $observacionesAdministrativas,
        string $tipoDocumento = SpanishDataValidator::DOCUMENT_TYPE_NIF_NIE_DNI
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO alumnos
                (usuario_id, nombre, apellidos, dni, tipo_documento, correo, especialidad_id, curso, observaciones_administrativas)
             VALUES
                (:usuario_id, :nombre, :apellidos, :dni, :tipo_documento, :correo, :especialidad_id, :curso, :observaciones)'
        );
        $stmt->execute([
            'usuario_id' => $usuarioId,
            'nombre' => $nombre,
            'apellidos' => $apellidos,
            'dni' => $dni,
            'tipo_documento' => $tipoDocumento,
            'correo' => $email,
            'especialidad_id' => $especialidadId,
            'curso' => $curso,
            'observaciones' => $observacionesAdministrativas !== '' ? $observacionesAdministrativas : null,
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function obtenerUsuarioIdParaCentro(int $alumnoId, bool $bloquear = false): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT a.usuario_id
             FROM alumnos a
             INNER JOIN usuarios u ON u.id = a.usuario_id AND u.rol = 'Alumno'
             WHERE a.id = :id
             LIMIT 1" . ($bloquear ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['id' => $alumnoId]);
        $usuarioId = $stmt->fetchColumn();

        return $usuarioId === false ? null : (int)$usuarioId;
    }

    public function actualizarDesdePersonal(
        int $alumnoId,
        string $nombre,
        string $apellidos,
        string $dni,
        string $tipoDocumento,
        string $email,
        int $especialidadId,
        int $curso,
        string $observacionesAdministrativas
    ): void {
        $usuarioId = $this->obtenerUsuarioIdParaCentro($alumnoId, true);
        if ($usuarioId === null) {
            throw new DomainException('No se encontró el expediente del alumno.');
        }

        $updateUser = $this->db->prepare(
            "UPDATE usuarios SET email = :email WHERE id = :id AND rol = 'Alumno'"
        );
        $updateUser->execute(['email' => $email, 'id' => $usuarioId]);

        $updateStudent = $this->db->prepare(
            'UPDATE alumnos
             SET nombre = :nombre, apellidos = :apellidos, dni = :dni,
                 tipo_documento = :tipo_documento, correo = :email,
                 especialidad_id = :especialidad_id, curso = :curso,
                 observaciones_administrativas = :observaciones
             WHERE id = :id AND usuario_id = :usuario_id'
        );
        $updateStudent->execute([
            'nombre' => $nombre,
            'apellidos' => $apellidos,
            'dni' => $dni,
            'tipo_documento' => $tipoDocumento,
            'email' => $email,
            'especialidad_id' => $especialidadId,
            'curso' => $curso,
            'observaciones' => $observacionesAdministrativas !== '' ? $observacionesAdministrativas : null,
            'id' => $alumnoId,
            'usuario_id' => $usuarioId,
        ]);
    }

    public function obtenerTodosParaCentro(
        string $search = '',
        ?int $especialidadId = null,
        ?bool $activo = null
    ): array {
        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = '(a.dni LIKE :document_search OR a.nombre LIKE :name_search
                         OR a.apellidos LIKE :surname_search OR u.email LIKE :email_search
                         OR e.nombre_especialidad LIKE :cycle_search)';
            $pattern = '%' . $search . '%';
            $params['document_search'] = $pattern;
            $params['name_search'] = $pattern;
            $params['surname_search'] = $pattern;
            $params['email_search'] = $pattern;
            $params['cycle_search'] = $pattern;
        }
        if ($especialidadId !== null) {
            $where[] = 'a.especialidad_id = :especialidad_id';
            $params['especialidad_id'] = $especialidadId;
        }
        if ($activo !== null) {
            $where[] = 'u.activo = :activo';
            $params['activo'] = $activo ? 1 : 0;
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $stmt = $this->db->prepare(
            'SELECT a.id, a.usuario_id, a.dni, a.tipo_documento, a.nombre, a.apellidos,
                    a.correo, a.curso, a.especialidad_id, a.observaciones_administrativas,
                    e.nombre_especialidad, u.activo
             FROM alumnos a
             INNER JOIN usuarios u ON u.id = a.usuario_id AND u.rol = \'Alumno\'
             LEFT JOIN especialidades e ON e.id = a.especialidad_id'
             . $whereSql .
             ' ORDER BY a.apellidos, a.nombre, a.id'
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function registrarTutor(
        int $alumnoId,
        string $nombreTutor,
        string $dniTutor,
        string $nssTutor,
        string $direccionTutor,
        string $telefonoTutor,
        string $correoTutor
    ): bool {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO tutores_legales (alumno_id, nombre_completo, dni, numero_seguridad_social, direccion, telefono, correo)
                 VALUES (:alumno_id, :nombre, :dni, :nss, :direccion, :telefono, :correo)'
            );
            return $stmt->execute([
                'alumno_id' => $alumnoId,
                'nombre'    => $nombreTutor,
                'dni'       => $dniTutor,
                'nss'       => $nssTutor,
                'direccion' => $direccionTutor,
                'telefono'  => $telefonoTutor,
                'correo'    => $correoTutor,
            ]);
        } catch (PDOException $e) {
            error_log("Error al registrar tutor legal: " . $e->getMessage());
            return false;
        }
    }
}
