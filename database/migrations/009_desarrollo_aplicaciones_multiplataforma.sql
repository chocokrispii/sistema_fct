-- Garantiza que DAM esté disponible en bases existentes sin duplicar el ciclo.
INSERT INTO especialidades (nombre_especialidad)
SELECT 'Desarrollo de Aplicaciones Multiplataforma (DAM)'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM especialidades
    WHERE nombre_especialidad = 'Desarrollo de Aplicaciones Multiplataforma (DAM)'
);
