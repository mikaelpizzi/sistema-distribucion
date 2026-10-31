SET NAMES utf8mb4;
SET TIME_ZONE = '-04:00';

CREATE DATABASE IF NOT EXISTS control_medicamentos_red
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE control_medicamentos_red;

-- ============================================================================
-- 1. CATÁLOGOS BASE Y TABLAS MAESTRAS (3FN - Cero datos quemados)
-- ============================================================================

CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL UNIQUE COMMENT 'Ej: rol_admin, rol_farmaceuta'
) ENGINE=InnoDB;

CREATE TABLE tipos_documento (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo CHAR(1) NOT NULL UNIQUE COMMENT 'V, E, P',
    nombre VARCHAR(40) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE formas_farmaceuticas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(60) NOT NULL UNIQUE COMMENT 'Tableta, Jarabe, Ampolla'
) ENGINE=InnoDB;

-- 1.1 Catálogo Maestro Parametrizable: Tipos de Centros de Salud (Clases 26 y 42)
CREATE TABLE tipos_centro_salud (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(30) NOT NULL UNIQUE COMMENT 'Ej: AMB_URB_1, AMB_URB_2, CLIN_MUN',
    nombre VARCHAR(80) NOT NULL,
    descripcion VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 1.2 Catálogo Maestro Parametrizable: Recursos y Capacidades Operativas (Relación N:M)
CREATE TABLE recursos_operativos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(30) NOT NULL UNIQUE COMMENT 'Ej: CADENA_FRIO, PLANTA_ELEC, AMBULANCIA',
    nombre VARCHAR(80) NOT NULL,
    descripcion VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Activo en catálogo, 0 = Obsoleto',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 1.3 Entidad Principal: Centros de Salud (Ambulatorios de la Red)
CREATE TABLE centros_salud (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo_sicm VARCHAR(30) NOT NULL UNIQUE COMMENT 'Código sanitario único del ambulatorio',
    nombre VARCHAR(120) NOT NULL,
    tipo_centro_id INT NOT NULL,
    medico_director VARCHAR(120) NULL COMMENT 'Director médico o responsable sanitario',
    horario_atencion VARCHAR(100) NULL COMMENT 'Ej: 24 Horas o Lunes a Viernes 7:00 AM - 5:00 PM',
    direccion VARCHAR(255) NOT NULL,
    telefono_principal VARCHAR(30) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Operativo, 0 = Inactivo (Soft Delete)',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_centros_tipo FOREIGN KEY (tipo_centro_id) REFERENCES tipos_centro_salud(id)
) ENGINE=InnoDB;

-- 1.4 Tabla Puente Muchos a Muchos (N:M): Centro de Salud <-> Recursos Operativos
CREATE TABLE centro_recursos (
    centro_salud_id INT NOT NULL,
    recurso_id INT NOT NULL,
    operativo TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = En funcionamiento, 0 = En mantenimiento/averiado',
    detalle_capacidad VARCHAR(100) NULL COMMENT 'Ej: Capacidad 300L 2-8°C, Generador 25kVA diésel',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (centro_salud_id, recurso_id),
    CONSTRAINT fk_cr_centro FOREIGN KEY (centro_salud_id) REFERENCES centros_salud(id) ON DELETE CASCADE,
    CONSTRAINT fk_cr_recurso FOREIGN KEY (recurso_id) REFERENCES recursos_operativos(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ============================================================================
-- 2. SEGURIDAD Y OPERADORES (Fiel a tu diccionario de datos)
-- ============================================================================

CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(80) NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    rol_id INT NOT NULL,
    centro_salud_id INT NULL COMMENT 'NULL si es Administrador Central',
    activo TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Soft delete',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_usuarios_rol FOREIGN KEY (rol_id) REFERENCES roles(id),
    CONSTRAINT fk_usuarios_centro FOREIGN KEY (centro_salud_id) REFERENCES centros_salud(id)
) ENGINE=InnoDB;

-- ============================================================================
-- 3. CATÁLOGO DE MEDICAMENTOS Y LOTES
-- ============================================================================

CREATE TABLE medicamentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre_generico VARCHAR(120) NOT NULL,
    concentracion VARCHAR(50) NOT NULL COMMENT 'Ej: 500mg, 100UI/ml',
    forma_farmaceutica_id INT NOT NULL,
    requiere_cadena_frio TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Requiere refrigeración estricta',
    activo TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Soft delete si se descontinúa',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_medicamentos_nombre (nombre_generico),
    CONSTRAINT fk_medicamentos_forma FOREIGN KEY (forma_farmaceutica_id) REFERENCES formas_farmaceuticas(id)
) ENGINE=InnoDB;

-- POLÍTICA APLICADA: Sin columna de borrado (activo/deleted_at) porque un lote NUNCA se borra
CREATE TABLE lotes_inventario (
    id INT AUTO_INCREMENT PRIMARY KEY,
    centro_salud_id INT NOT NULL,
    medicamento_id INT NOT NULL,
    numero_lote VARCHAR(30) NOT NULL,
    fecha_vencimiento DATE NOT NULL,
    cantidad_actual INT NOT NULL CHECK (cantidad_actual >= 0),
    usuario_id INT NOT NULL COMMENT 'Trazabilidad: Farmaceuta que recibió el lote',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inventario_fefo (centro_salud_id, medicamento_id, fecha_vencimiento ASC),
    CONSTRAINT fk_lotes_centro FOREIGN KEY (centro_salud_id) REFERENCES centros_salud(id),
    CONSTRAINT fk_lotes_medicamento FOREIGN KEY (medicamento_id) REFERENCES medicamentos(id),
    CONSTRAINT fk_lotes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- ============================================================================
-- 4. PACIENTES, RÉCIPES Y DESPACHOS (Bloqueo de doble retiro y 1FN)
-- ============================================================================

CREATE TABLE pacientes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo_documento_id INT NOT NULL,
    cedula VARCHAR(20) NOT NULL,
    nombre_completo VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_paciente_cedula (tipo_documento_id, cedula),
    CONSTRAINT fk_pacientes_tipo_doc FOREIGN KEY (tipo_documento_id) REFERENCES tipos_documento(id)
) ENGINE=InnoDB;

CREATE TABLE tratamientos_autorizados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    paciente_id INT NOT NULL,
    medicamento_id INT NOT NULL,
    dosis_mensual INT NOT NULL CHECK (dosis_mensual > 0),
    fecha_vencimiento_recipe DATE NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tratamientos_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id),
    CONSTRAINT fk_tratamientos_medicamento FOREIGN KEY (medicamento_id) REFERENCES medicamentos(id)
) ENGINE=InnoDB;

-- POLÍTICA APLICADA: Cero borrado y cero edición de despachos
CREATE TABLE despachos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tratamiento_id INT NOT NULL,
    centro_salud_id INT NOT NULL,
    usuario_id INT NOT NULL,
    fecha_hora DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    periodo_mes_anio CHAR(7) NOT NULL COMMENT 'Ej: 2026-10',
    UNIQUE KEY uk_retiro_mensual (tratamiento_id, periodo_mes_anio),
    CONSTRAINT fk_despachos_tratamiento FOREIGN KEY (tratamiento_id) REFERENCES tratamientos_autorizados(id),
    CONSTRAINT fk_despachos_centro FOREIGN KEY (centro_salud_id) REFERENCES centros_salud(id),
    CONSTRAINT fk_despachos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE despacho_detalles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    despacho_id INT NOT NULL,
    lote_id INT NOT NULL,
    cantidad_entregada INT NOT NULL CHECK (cantidad_entregada > 0),
    CONSTRAINT fk_detalles_despacho FOREIGN KEY (despacho_id) REFERENCES despachos(id),
    CONSTRAINT fk_detalles_lote FOREIGN KEY (lote_id) REFERENCES lotes_inventario(id)
) ENGINE=InnoDB;

-- ============================================================================
-- 5. TABLA DE LOGS / AUDITORÍA FORENSE (Exigencia directa del profesor - Misión 2)
-- ============================================================================

CREATE TABLE logs_auditoria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NULL COMMENT 'Operador responsable de la acción',
    accion VARCHAR(50) NOT NULL COMMENT 'Ej: CREAR_CENTRO, EDITAR_CENTRO, DESACTIVAR_CENTRO, REACTIVAR_CENTRO',
    tabla_afectada VARCHAR(50) NOT NULL COMMENT 'Tabla intervenida en la base de datos',
    registro_id INT NULL COMMENT 'ID del registro modificado',
    detalles_json JSON NULL COMMENT 'Snapshot estructurado en JSON con valores anteriores y nuevos',
    ip_origen VARCHAR(45) NOT NULL COMMENT 'Dirección IP del cliente (IPv4 o IPv6)',
    fecha_hora DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_logs_fecha (fecha_hora DESC),
    INDEX idx_logs_tabla_registro (tabla_afectada, registro_id),
    CONSTRAINT fk_logs_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;
