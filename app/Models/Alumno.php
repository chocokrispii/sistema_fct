<?php
// models/Alumno.php

require_once __DIR__ . '/../config/database.php';

class Alumno {
    private PDO $db;

    public function __construct() {
        $this->db = getPDOConnection();
    }

    public function crear(int $usuarioId, string $nss, string $telefono, string $direccion, string $fechaNac, int $especialidadId): int {
        $stmt = $this->db->prepare(
            "INSERT INTO alumnos (usuario_id, num_seguridad_social, telefono, direccion, fecha_nacimiento, especialidad_id) 
             VALUES (:usuario_id, :nss, :telefono, :direccion, :fecha_nac, :especialidad_id)"
        );
        $stmt->execute([
            'usuario_id'     => $usuarioId,
            'nss'            => $nss,
            'telefono'       => $telefono,
            'direccion'      => $direccion,
            'fecha_nac'      => $fechaNac,
            'especialidad_id'=> $especialidadId
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function registrarTutor(int $alumnoId, string $nombreTutor, string $telefonoTutor, string $dniTutor): bool {
        $stmt = $this->db->prepare(
            "INSERT INTO tutores_alumnos (alumno_id, nombre_completo, telefono, dni_nie) 
             VALUES (:alumno_id, :nombre, :telefono, :dni)"
        );
        return $stmt->execute([
            'alumno_id' => $alumnoId,
            'nombre'    => $nombreTutor,
            'telefono'  => $telefonoTutor,
            'dni'       => $dniTutor
        ]);
    }
}