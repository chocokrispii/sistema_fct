<?php
// app/Models/Alumno.php

require_once __DIR__ . '/../../config/database.php';

class Alumno {
    private PDO $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? getPDOConnection();
    }

    public function obtenerPorUsuario(int $usuarioId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT a.id, a.nombre, a.apellidos, a.fecha_nacimiento, a.dni,
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
        ?array $tutor = null
    ): void {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

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
                     dni = :dni, numero_seguridad_social = :nss, codigo_postal = :codigo_postal,
                     correo = :email
                 WHERE id = :id'
            );
            $updateStudent->execute([
                'nombre' => $nombre,
                'apellidos' => $apellidos,
                'nacimiento' => $fechaNacimiento,
                'dni' => $dni,
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
            if ($ownsTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->db->inTransaction()) {
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
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

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

            if ($ownsTransaction) {
                $this->db->commit();
            }

            return $alumnoId;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
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
