<?php
// controllers/AlumnoController.php

require_once __DIR__ . '/../models/Usuario.php';
require_once __DIR__ . '/../models/Alumno.php';

class AlumnoController {
    private Usuario $usuarioModel;
    private Alumno $alumnoModel;

    public function __construct() {
        $this->usuarioModel = new Usuario();
        $this->alumnoModel  = new Alumno();
    }

    public function registrar(): void {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $email     = trim($_POST['email'] ?? '');
            $password  = $_POST['password'] ?? '';
            $nss       = trim($_POST['nss'] ?? '');
            $telefono  = trim($_POST['telefono'] ?? '');
            $direccion = trim($_POST['direccion'] ?? '');
            $fechaNac  = $_POST['fecha_nacimiento'] ?? '';
            $espId     = (int)($_POST['especialidad_id'] ?? 1);

            // Validar edad minima (16 años)
            $fechaNacObj = new DateTime($fechaNac);
            $hoy = new DateTime();
            $edad = $hoy->diff($fechaNacObj)->y;

            if ($edad < 16) {
                echo "<p style='color:red;'>Error: El alumno debe tener al menos 16 años.</p>";
                return;
            }

            // Crear el registro de usuario base con rol 'alumno'
            $usuarioId = $this->usuarioModel->crear($email, $password, 'alumno');

            // Crear el registro específico del alumno
            $alumnoId = $this->alumnoModel->crear($usuarioId, $nss, $telefono, $direccion, $fechaNac, $espId);

            // Si es menor de 18 años, guardar tutor
            if ($edad < 18) {
                $tutorNombre   = trim($_POST['tutor_nombre'] ?? '');
                $tutorTelefono = trim($_POST['tutor_telefono'] ?? '');
                $tutorDni      = trim($_POST['tutor_dni'] ?? '');

                if (empty($tutorNombre) || empty($tutorTelefono) || empty($tutorDni)) {
                    echo "<p style='color:red;'>Error: Para menores de 18 años es obligatorio indicar el tutor.</p>";
                    return;
                }

                $this->alumnoModel->registrarTutor($alumnoId, $tutorNombre, $tutorTelefono, $tutorDni);
            }

            echo "<p style='color:green;'>Alumno registrado correctamente.</p>";
            return;
        }

        // Vista de prueba básica
        echo '
        <h2>Registro de Alumno</h2>
        <form method="POST" action="index.php?action=registro-alumno">
            <input type="email" name="email" placeholder="Correo" required><br><br>
            <input type="password" name="password" placeholder="Contraseña" required><br><br>
            <input type="text" name="nss" placeholder="Nº Seguridad Social" required><br><br>
            <input type="text" name="telefono" placeholder="Teléfono" required><br><br>
            <input type="text" name="direccion" placeholder="Dirección" required><br><br>
            <label>Fecha de Nacimiento:</label><br>
            <input type="date" name="fecha_nacimiento" required><br><br>
            
            <h3>Datos del Tutor (Obligatorio si es menor de 18 años)</h3>
            <input type="text" name="tutor_nombre" placeholder="Nombre Completo Tutor"><br><br>
            <input type="text" name="tutor_telefono" placeholder="Teléfono Tutor"><br><br>
            <input type="text" name="tutor_dni" placeholder="DNI/NIE Tutor"><br><br>

            <button type="submit">Registrar Alumno</button>
        </form>';
    }
}