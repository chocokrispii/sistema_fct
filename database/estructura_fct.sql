-- CREACIÓN Y SELECCIÓN DE LA BASE DE DATOS (Añadir al inicio del archivo)
CREATE DATABASE IF NOT EXISTS sistema_fct CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE sistema_fct;

-- 1. CREAR TABLA DE USUARIOS
CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    rol ENUM('Administrador', 'Coordinador', 'Observador', 'Empresa', 'Alumno') NOT NULL,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. CREAR TABLA DE EMPRESAS
CREATE TABLE empresas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNIQUE NOT NULL,
    nombre_empresa VARCHAR(150) NOT NULL,
    cif_empresa VARCHAR(20) UNIQUE NOT NULL,
    pagina_web VARCHAR(255),
    pertenece_grupo_empresa BOOLEAN DEFAULT FALSE,
    categoria VARCHAR(100),
    cnae VARCHAR(10),
    registro_mercantil VARCHAR(255),
    convenios_documentacion TEXT,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

-- 3. CREAR TABLA DE ESPECIALIDADES (CURSOS/CICLOS)
CREATE TABLE especialidades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre_specialidad VARCHAR(150) NOT NULL UNIQUE
);

-- 4. CREAR TABLA DE ALUMNOS
CREATE TABLE alumnos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNIQUE NOT NULL,
    nombre VARCHAR(50) NOT NULL,
    apellidos VARCHAR(100) NOT NULL,
    dni VARCHAR(20) UNIQUE NOT NULL,
    numero_seguridad_social VARCHAR(20) NOT NULL,
    correo VARCHAR(100) NOT NULL,
    especialidad_id INT,
    proceso_solicitud_practicas VARCHAR(50) DEFAULT 'Pendiente', 
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (especialidad_id) REFERENCES especialidades(id) ON DELETE SET NULL
);

-- 5. CREAR TABLA DE TUTORES LEGALES
CREATE TABLE tutores_legales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    alumno_id INT UNIQUE NOT NULL,
    nombre_completo VARCHAR(150) NOT NULL,
    direccion VARCHAR(255),
    telefono VARCHAR(20),
    FOREIGN KEY (alumno_id) REFERENCES alumnos(id) ON DELETE CASCADE
);

-- 6. CREAR TABLA DE COORDINADORES
CREATE TABLE coordinadores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNIQUE NOT NULL,
    nombre VARCHAR(50) NOT NULL,
    apellidos VARCHAR(100) NOT NULL,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

-- 7. CREAR TABLA RELACIONAL DE PLAZAS (EMPRESA + ESPECIALIDAD + FECHAS + CANTIDAD)
CREATE TABLE empresa_especialidades_plazas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT NOT NULL,
    especialidad_id INT NOT NULL,
    plazas_cuantas INT NOT NULL DEFAULT 1,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    FOREIGN KEY (especialidad_id) REFERENCES especialidades(id) ON DELETE CASCADE
);

-- 8. CREAR TABLA DE DIRECCIONES Y TELÉFONOS (Al final, ya que todas las demás existen)
CREATE TABLE direcciones_telefonos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT NULL,
    alumno_id INT NULL,
    tutor_id INT NULL,
    telefono VARCHAR(20),
    direccion_completa VARCHAR(255) NOT NULL,
    es_principal BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    FOREIGN KEY (alumno_id) REFERENCES alumnos(id) ON DELETE CASCADE,
    FOREIGN KEY (tutor_id) REFERENCES tutores_legales(id) ON DELETE CASCADE
);
