<?php
// config/database.php

define('DB_HOST', 'localhost');
define('DB_USER', 'root');      // Por defecto en XAMPP es root
define('DB_PASS', '');          // Por defecto en XAMPP está vacío
define('DB_NAME', 'sistema_fct');

try {
    // Creamos la conexión usando PDO con codificación UTF-8
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Activa el reporte de errores
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Devuelve los datos en arrays asociativos
            PDO::ATTR_EMULATE_PREPARES   => false,                  // Mayor seguridad contra Inyección SQL
        ]
    );
} catch (PDOException $e) {
    // Si la conexión falla, detiene el sistema y muestra el error
    die("Error crítico de conexión a la Base de Datos: " . $e->getMessage());
}
