-- Migración: Padrón de Médicos Prescriptores (3FN) y Actualización de Permisos
USE control_medicamentos_red;

CREATE TABLE IF NOT EXISTS medicos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombres VARCHAR(100) NOT NULL,
    apellidos VARCHAR(100) NOT NULL,
    matricula_mpps VARCHAR(50) NOT NULL UNIQUE,
    especialidad VARCHAR(100) NOT NULL,
    telefono VARCHAR(50) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Agregar columna medico_id en tratamientos_autorizados si no existe
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = 'control_medicamentos_red' 
      AND TABLE_NAME = 'tratamientos_autorizados' 
      AND COLUMN_NAME = 'medico_id');

SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE tratamientos_autorizados ADD COLUMN medico_id INT NULL AFTER patologia_id, ADD CONSTRAINT fk_tratamientos_medico FOREIGN KEY (medico_id) REFERENCES medicos(id)', 
    'SELECT "Columna medico_id ya existe"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Seeders de médicos representativos acreditados
INSERT INTO medicos (id, nombres, apellidos, matricula_mpps, especialidad, telefono, activo) VALUES
(1, 'Carlos', 'Mendoza Albornoz', 'MPPS-78412', 'Medicina Interna', '0414-7841201', 1),
(2, 'Elena', 'Rivas Briceño', 'MPPS-65239', 'Cardiología', '0424-6523902', 1),
(3, 'Marcos', 'Paredes Uzcátegui', 'MPPS-91044', 'Medicina General', '0412-9104403', 1),
(4, 'Sofía', 'Valero Márquez', 'MPPS-84310', 'Endocrinología', '0416-8431004', 1)
ON DUPLICATE KEY UPDATE 
    nombres = VALUES(nombres),
    apellidos = VALUES(apellidos),
    especialidad = VALUES(especialidad),
    telefono = VALUES(telefono),
    activo = VALUES(activo);

-- Actualizar tratamientos existentes para vincularlos con el padrón
UPDATE tratamientos_autorizados SET medico_id = 1, medico_prescriptor = 'Dr(a). Carlos Mendoza Albornoz — Matrícula MPPS: MPPS-78412 (Medicina Interna)' WHERE id = 1;
UPDATE tratamientos_autorizados SET medico_id = 2, medico_prescriptor = 'Dr(a). Elena Rivas Briceño — Matrícula MPPS: MPPS-65239 (Cardiología)' WHERE id = 2;
UPDATE tratamientos_autorizados SET medico_id = 4, medico_prescriptor = 'Dr(a). Sofía Valero Márquez — Matrícula MPPS: MPPS-84310 (Endocrinología)' WHERE id = 3;

-- Privilegios para app_salud_user
GRANT SELECT, INSERT, UPDATE ON control_medicamentos_red.medicos TO 'app_salud_user'@'%';

-- Ajuste de denominación técnica en permisos
UPDATE permisos SET nombre = 'Consultar existencias y lotes' WHERE id = 8;
UPDATE permisos SET nombre = 'Procesar dispensación' WHERE id = 12;

FLUSH PRIVILEGES;
