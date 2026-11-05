-- 1. INSERTAR ESPECIALIDADES DE EJEMPLO
INSERT INTO especialidades (nombre_especialidad) VALUES
('Desarrollo de Aplicaciones Web (DAW)'),
('Desarrollo de Aplicaciones Multiplataforma (DAM)'),
('Sistemas Microinformáticos y Redes (SMR)');

-- 2. INSERTAR USUARIOS DE PRUEBA
INSERT INTO usuarios (id, email, identificador_fiscal, password_hash, rol) VALUES
(1, 'admin@centro.com', '12345678Z', '$2y$10$M4pZD5zTKpxWz0juq02YmeG7F5fKnjwunCIitduJMcvdogYaOPzlC', 'Administrador'),
(2, 'coordinador@centro.com', '87654321X', '$2y$10$M4pZD5zTKpxWz0juq02YmeG7F5fKnjwunCIitduJMcvdogYaOPzlC', 'Coordinador'),
(3, 'empresa.tech@gmail.com', NULL, '$2y$10$M4pZD5zTKpxWz0juq02YmeG7F5fKnjwunCIitduJMcvdogYaOPzlC', 'Empresa'),
(4, 'alumno.menor@alumno.com', NULL, '$2y$10$M4pZD5zTKpxWz0juq02YmeG7F5fKnjwunCIitduJMcvdogYaOPzlC', 'Alumno'),
(5, 'alumno.mayor@alumno.com', NULL, '$2y$10$M4pZD5zTKpxWz0juq02YmeG7F5fKnjwunCIitduJMcvdogYaOPzlC', 'Alumno');

-- 3. INSERTAR COORDINADOR
INSERT INTO coordinadores (usuario_id, nombre, apellidos) VALUES
(2, 'Carlos', 'Martínez Gómez');

-- 4. INSERTAR EMPRESA
INSERT INTO empresas (id, usuario_id, nombre_empresa, cif_empresa, pagina_web, categoria) VALUES
(1, 3, 'Tech Solutions S.L.', 'B12345678', 'https://techsolutions.com', 'Desarrollo de Software');

-- 5. CREAR LA SEDE DE TRABAJO E INSERTAR OFERTAS
INSERT INTO empresa_sedes (id, empresa_id, nombre, direccion, telefono) VALUES
(1, 1, 'Sede principal', 'Avenida de la Industry 45, Planta 2, Madrid', '910111213');

INSERT INTO empresa_especialidades_plazas (empresa_id, sede_id, especialidad_id, plazas_cuantas, fecha_inicio, fecha_fin) VALUES
(1, 1, 1, 2, '2027-03-01', '2027-06-15'),
(1, 1, 2, 1, '2027-03-01', '2027-06-15');

-- 6. INSERTAR ALUMNOS (Aquí se corrigió la coma de la línea 35)
INSERT INTO alumnos (id, usuario_id, nombre, apellidos, fecha_nacimiento, dni, numero_seguridad_social, correo, especialidad_id) VALUES
(1, 4, 'Lucas', 'Pérez Ruiz', '2009-01-01', '12345678A', 'SS-11111111', 'lucas@correo.com', 1),
(2, 5, 'Sofía', 'García López', '2000-01-01', '87654321B', 'SS-22222222', 'sofia@correo.com', 2);

-- 7. INSERTAR TUTOR LEGAL
INSERT INTO tutores_legales (alumno_id, nombre_completo, dni, numero_seguridad_social, direccion, telefono, correo) VALUES
(1, 'Padre de Lucas Pérez', '11223344Z', 'SS-33333333', 'Calle Mayor 12, Madrid', '600123456', 'tutor@correo.com');

-- 8. INSERTAR TELÉFONOS Y DIRECCIONES
INSERT INTO direcciones_telefonos (empresa_id, alumno_id, tutor_id, telefono, direccion_completa, es_principal) VALUES
(1, NULL, NULL, '910111213', 'Avenida de la Industry 45, Planta 2', 1),
(NULL, 2, NULL, '611222333', 'Calle del Alumno Mayor 7, Madrid', 1);
