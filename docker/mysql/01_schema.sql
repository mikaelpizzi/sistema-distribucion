-- ============================================================================
-- ESQUEMA EVOLUCIONADO: control_medicamentos_red (3FN + Auditoría Forense)
-- ============================================================================
SET NAMES utf8mb4;
SET TIME_ZONE = '-04:00';

CREATE DATABASE IF NOT EXISTS control_medicamentos_red
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE control_medicamentos_red;

-- ----------------------------------------------------------------------------
-- 1. TABLAS MAESTRAS Y PARAMETRIZACIÓN (Cero ENUMs, Cero datos quemados)
-- ----------------------------------------------------------------------------

CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(40) NOT NULL UNIQUE,
    nombre VARCHAR(80) NOT NULL,
    descripcion VARCHAR(255) NULL,
    permisos_json JSON NOT NULL COMMENT 'Permisos modulares configurables',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE tipos_documento (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(10) NOT NULL UNIQUE COMMENT 'Ej: V, E, P, J, G',
    nombre VARCHAR(80) NOT NULL,
    patron_regex VARCHAR(120) NULL COMMENT 'Validación parametrizable desde BD',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE tipos_contacto (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(30) NOT NULL UNIQUE COMMENT 'Ej: MOVIL, LOCAL, EMAIL, DIRECCION',
    nombre VARCHAR(60) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE formas_farmaceuticas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(80) NOT NULL UNIQUE COMMENT 'Ej: Tableta, Cápsula, Ampolla, Jarabe',
    unidad_base VARCHAR(40) NOT NULL COMMENT 'Ej: mg, ml, UI',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE configuraciones_sistema (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clave VARCHAR(80) NOT NULL UNIQUE COMMENT 'Ej: wizard_completado, mensajes_ui_es',
    valor_json JSON NOT NULL COMMENT 'Soporte multi-idioma, alertas FEFO y parámetros',
    descripcion VARCHAR(255) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- 2. ENTIDADES OPERATIVAS Y DE SEGURIDAD
-- ----------------------------------------------------------------------------

CREATE TABLE centros_salud (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo_sicm VARCHAR(30) NOT NULL UNIQUE COMMENT 'Código sanitario único del ambulatorio',
    nombre VARCHAR(120) NOT NULL,
    direccion VARCHAR(255) NOT NULL,
    telefono_principal VARCHAR(30) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo_documento_id INT NOT NULL,
    numero_documento VARCHAR(25) NOT NULL,
    nombre_completo VARCHAR(120) NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    rol_id INT NOT NULL,
    centro_salud_id INT NULL COMMENT 'NULL para Administrador Central',
    actualizado_por_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL DEFAULT NULL,
    UNIQUE KEY uk_usuario_documento (tipo_documento_id, numero_documento),
    CONSTRAINT fk_usuarios_tipo_doc FOREIGN KEY (tipo_documento_id) REFERENCES tipos_documento(id),
    CONSTRAINT fk_usuarios_rol FOREIGN KEY (rol_id) REFERENCES roles(id),
    CONSTRAINT fk_usuarios_centro FOREIGN KEY (centro_salud_id) REFERENCES centros_salud(id),
    CONSTRAINT fk_usuarios_actualizado_por FOREIGN KEY (actualizado_por_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- 3. CATÁLOGO FARMACÉUTICO Y ALMACÉN FEFO (Esta será nuestra 1ra tabla CRUD)
-- ----------------------------------------------------------------------------

CREATE TABLE medicamentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo_atc VARCHAR(25) NOT NULL UNIQUE COMMENT 'Código anatómico terapéutico químico',
    nombre_generico VARCHAR(120) NOT NULL,
    concentracion VARCHAR(50) NOT NULL COMMENT 'Ej: 50mg, 850mg, 100UI/ml',
    forma_farmaceutica_id INT NOT NULL,
    requiere_cadena_frio TINYINT(1) NOT NULL DEFAULT 0,
    metadatos_json JSON NULL COMMENT 'Datos ocasionales: contraindicaciones, laboratorio, etc.',
    actualizado_por_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL DEFAULT NULL,
    CONSTRAINT fk_medicamentos_forma FOREIGN KEY (forma_farmaceutica_id) REFERENCES formas_farmaceuticas(id),
    CONSTRAINT fk_medicamentos_actualizado_por FOREIGN KEY (actualizado_por_id) REFERENCES usuarios(id),
    INDEX idx_medicamentos_nombre (nombre_generico)
) ENGINE=InnoDB;

CREATE TABLE lotes_inventario (
    id INT AUTO_INCREMENT PRIMARY KEY,
    centro_salud_id INT NOT NULL,
    medicamento_id INT NOT NULL,
    numero_lote VARCHAR(40) NOT NULL,
    fecha_vencimiento DATE NOT NULL,
    cantidad_inicial INT NOT NULL CHECK (cantidad_inicial > 0),
    cantidad_actual INT NOT NULL CHECK (cantidad_actual >= 0),
    registrado_por_id INT NOT NULL,
    actualizado_por_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL DEFAULT NULL,
    UNIQUE KEY uk_lote_centro_med (centro_salud_id, medicamento_id, numero_lote),
    -- Índice Compuesto FEFO (Regla de izquierda a derecha: filtra centro y med, ordena por vencimiento)
    INDEX idx_inventario_fefo (centro_salud_id, medicamento_id, fecha_vencimiento ASC),
    CONSTRAINT fk_lotes_centro FOREIGN KEY (centro_salud_id) REFERENCES centros_salud(id),
    CONSTRAINT fk_lotes_medicamento FOREIGN KEY (medicamento_id) REFERENCES medicamentos(id),
    CONSTRAINT fk_lotes_registrado_por FOREIGN KEY (registrado_por_id) REFERENCES usuarios(id),
    CONSTRAINT fk_lotes_actualizado_por FOREIGN KEY (actualizado_por_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- 4. PADRÓN DE PACIENTES, MÚLTIPLES CONTACTOS (1FN) Y TRATAMIENTOS
-- ----------------------------------------------------------------------------

CREATE TABLE pacientes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo_documento_id INT NOT NULL,
    numero_documento VARCHAR(25) NOT NULL,
    nombres VARCHAR(80) NOT NULL,
    apellidos VARCHAR(80) NOT NULL,
    fecha_nacimiento DATE NOT NULL,
    centro_adscrito_id INT NOT NULL,
    actualizado_por_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL DEFAULT NULL,
    UNIQUE KEY uk_paciente_documento (tipo_documento_id, numero_documento),
    INDEX idx_pacientes_apellidos_nombres (apellidos, nombres),
    CONSTRAINT fk_pacientes_tipo_doc FOREIGN KEY (tipo_documento_id) REFERENCES tipos_documento(id),
    CONSTRAINT fk_pacientes_centro FOREIGN KEY (centro_adscrito_id) REFERENCES centros_salud(id),
    CONSTRAINT fk_pacientes_actualizado_por FOREIGN KEY (actualizado_por_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- Tabla multivaluada (1FN): Permite N teléfonos, correos y direcciones por paciente
CREATE TABLE paciente_contactos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    paciente_id INT NOT NULL,
    tipo_contacto_id INT NOT NULL,
    valor VARCHAR(255) NOT NULL,
    es_principal TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL DEFAULT NULL,
    CONSTRAINT fk_pac_contactos_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id),
    CONSTRAINT fk_pac_contactos_tipo FOREIGN KEY (tipo_contacto_id) REFERENCES tipos_contacto(id),
    INDEX idx_pac_contactos_paciente (paciente_id)
) ENGINE=InnoDB;

CREATE TABLE tratamientos_autorizados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    paciente_id INT NOT NULL,
    medicamento_id INT NOT NULL,
    dosis_mensual INT NOT NULL CHECK (dosis_mensual > 0),
    fecha_vencimiento_recipe DATE NOT NULL,
    autorizado_por_id INT NOT NULL,
    actualizado_por_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL DEFAULT NULL,
    INDEX idx_tratamientos_paciente_med (paciente_id, medicamento_id),
    CONSTRAINT fk_tratamientos_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id),
    CONSTRAINT fk_tratamientos_medicamento FOREIGN KEY (medicamento_id) REFERENCES medicamentos(id),
    CONSTRAINT fk_tratamientos_autorizado_por FOREIGN KEY (autorizado_por_id) REFERENCES usuarios(id),
    CONSTRAINT fk_tratamientos_actualizado_por FOREIGN KEY (actualizado_por_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- 5. DESPACHOS (Candado Anti-Doble Retiro) Y DETALLES DE LOTE (1FN)
-- ----------------------------------------------------------------------------

CREATE TABLE despachos (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    tratamiento_id INT NOT NULL,
    centro_salud_id INT NOT NULL,
    usuario_id INT NOT NULL COMMENT 'Farmaceuta que efectuó la entrega',
    fecha_hora DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    periodo_mes_anio CHAR(7) NOT NULL COMMENT 'Formato YYYY-MM para bloqueo mensual',
    observaciones VARCHAR(255) NULL,
    deleted_at DATETIME NULL DEFAULT NULL,
    -- Candado transaccional a nivel de motor contra retiros duplicados en el mismo mes
    UNIQUE KEY uk_retiro_mensual (tratamiento_id, periodo_mes_anio),
    INDEX idx_despachos_centro_fecha (centro_salud_id, fecha_hora DESC),
    CONSTRAINT fk_despachos_tratamiento FOREIGN KEY (tratamiento_id) REFERENCES tratamientos_autorizados(id),
    CONSTRAINT fk_despachos_centro FOREIGN KEY (centro_salud_id) REFERENCES centros_salud(id),
    CONSTRAINT fk_despachos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE despacho_detalles (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    despacho_id BIGINT NOT NULL,
    lote_id INT NOT NULL,
    cantidad_entregada INT NOT NULL CHECK (cantidad_entregada > 0),
    CONSTRAINT fk_detalle_despacho FOREIGN KEY (despacho_id) REFERENCES despachos(id),
    CONSTRAINT fk_detalle_lote FOREIGN KEY (lote_id) REFERENCES lotes_inventario(id),
    INDEX idx_detalle_lote (lote_id)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- 6. BITÁCORA DE AUDITORÍA FORENSE (Sin sobre-indexar para proteger INSERTs)
-- ----------------------------------------------------------------------------

CREATE TABLE logs_auditoria (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NULL,
    accion VARCHAR(50) NOT NULL COMMENT 'Ej: LOGIN, CREATE, UPDATE, SOFT_DELETE, INTENTO_DOBLE_RETIRO',
    tabla_afectada VARCHAR(60) NOT NULL,
    registro_id BIGINT NULL,
    ip_origen VARCHAR(45) NOT NULL,
    user_agent VARCHAR(255) NULL,
    detalles_json JSON NULL COMMENT 'Valores anteriores y nuevos',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================================
-- 7. OBJETOS AVANZADOS: FUNCIÓN DETERMINÍSTICA, TRIGGER Y VISTA (CTEs)
-- ============================================================================

DELIMITER $$

-- Función Determinística (Clase 21/10): Clasifica el riesgo de caducidad de un lote
CREATE FUNCTION fn_estado_caducidad(dias_restantes INT)
RETURNS VARCHAR(25)
DETERMINISTIC
BEGIN
    IF dias_restantes < 0 THEN
        RETURN 'VENCIDO';
    ELSEIF dias_restantes <= 30 THEN
        RETURN 'CRITICO_FEFO';
    ELSEIF dias_restantes <= 90 THEN
        RETURN 'PRIORIDAD_SALIDA';
    ELSE
        RETURN 'OPTIMO';
    END IF;
END$$

-- Trigger en Tiempo Real: Descuenta stock automáticamente al insertar un detalle de despacho
CREATE TRIGGER trg_descontar_stock_lote
BEFORE INSERT ON despacho_detalles
FOR EACH ROW
BEGIN
    DECLARE v_stock_actual INT;
    DECLARE v_vencimiento DATE;

    SELECT cantidad_actual, fecha_vencimiento
      INTO v_stock_actual, v_vencimiento
      FROM lotes_inventario
     WHERE id = NEW.lote_id
       AND deleted_at IS NULL
     FOR UPDATE;

    IF v_stock_actual IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Error Forense: El lote especificado no existe o fue desincorporado.';
    END IF;

    IF v_vencimiento < CURDATE() THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Bloqueo Sanitario: Prohibido despachar de un lote con fecha vencida.';
    END IF;

    IF v_stock_actual < NEW.cantidad_entregada THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Stock Insuficiente: La cantidad solicitada supera la existencia física del lote.';
    END IF;

    UPDATE lotes_inventario
       SET cantidad_actual = cantidad_actual - NEW.cantidad_entregada
     WHERE id = NEW.lote_id;
END$$

DELIMITER ;

-- Vista de Abstracción para el Catálogo (Clase 21/10): Capa de abstracción del JOIN
CREATE OR REPLACE VIEW vw_catalogo_medicamentos AS
SELECT 
    m.id,
    m.codigo_atc,
    m.nombre_generico,
    m.concentracion,
    m.forma_farmaceutica_id,
    f.nombre AS forma_nombre,
    f.unidad_base,
    m.requiere_cadena_frio,
    m.deleted_at
FROM medicamentos m
INNER JOIN formas_farmaceuticas f ON f.id = m.forma_farmaceutica_id;

-- Vista + CTE (Clase 21/10): Capa de abstracción para consultar el inventario FEFO
CREATE OR REPLACE VIEW vw_radar_inventario_fefo AS
WITH calculo_dias AS (
    SELECT
        l.id AS lote_id,
        c.id AS centro_salud_id,
        c.nombre AS ambulatorio,
        m.id AS medicamento_id,
        m.codigo_atc,
        m.nombre_generico,
        m.concentracion,
        f.nombre AS forma_farmaceutica,
        l.numero_lote,
        l.fecha_vencimiento,
        l.cantidad_actual,
        DATEDIFF(l.fecha_vencimiento, CURDATE()) AS dias_para_vencer
    FROM lotes_inventario l
    INNER JOIN centros_salud c ON c.id = l.centro_salud_id
    INNER JOIN medicamentos m ON m.id = l.medicamento_id
    INNER JOIN formas_farmaceuticas f ON f.id = m.forma_farmaceutica_id
    WHERE l.deleted_at IS NULL
      AND m.deleted_at IS NULL
      AND c.deleted_at IS NULL
)
SELECT
    lote_id,
    centro_salud_id,
    ambulatorio,
    medicamento_id,
    codigo_atc,
    nombre_generico,
    concentracion,
    forma_farmaceutica,
    numero_lote,
    fecha_vencimiento,
    cantidad_actual,
    dias_para_vencer,
    fn_estado_caducidad(dias_para_vencer) AS estado_lote
FROM calculo_dias
ORDER BY fecha_vencimiento ASC;