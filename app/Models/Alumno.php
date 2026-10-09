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
            'SELECT a.id, a.nombre, a.apellidos, a.fecha_nacimiento, a.dni, a.correo,
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
             LEFT JOIN especialidades es ON es.id = a.especialidad_id
             WHERE a.usuario_id = :usuario_id
             LIMIT 1'
        );
        $stmt->execute(['usuario_id' => $usuarioId]);
        $alumno = $stmt->fetch(PDO::FETCH_ASSOC);

        return $alumno ?: null;
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
        int $especialidadId
    ): int {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO alumnos (usuario_id, nombre, apellidos, fecha_nacimiento, dni, numero_seguridad_social, correo, especialidad_id)
                 VALUES (:usuario_id, :nombre, :apellidos, :fecha_nacimiento, :dni, :nss, :correo, :especialidad_id)'
            );
            $stmt->execute([
                'usuario_id' => $usuarioId,
                'nombre' => $nombre,
                'apellidos' => $apellidos,
                'fecha_nacimiento' => $fechaNacimiento,
                'dni' => $dni,
                'nss' => $nss,
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
