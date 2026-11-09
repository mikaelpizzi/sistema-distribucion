SET NAMES utf8mb4;
SET TIME_ZONE = '-04:00';

CREATE DATABASE IF NOT EXISTS control_medicamentos_red
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE control_medicamentos_red;

-- ============================================================================
-- 1. CATÁLOGOS BASE Y TABLAS MAESTRAS (3FN - Cero datos quemados)
-- ============================================================================

-- 1.0 Identidad Institucional Paramétrica (Cero datos quemados / White-labeling)
CREATE TABLE IF NOT EXISTS configuracion_institucional (
    id INT PRIMARY KEY DEFAULT 1,
    nombre_sistema VARCHAR(120) NOT NULL DEFAULT 'Sistema Integrado de Control de Medicamentos',
    siglas_sistema VARCHAR(20) NOT NULL DEFAULT 'SICM',
    ente_rector VARCHAR(150) NOT NULL DEFAULT 'Red de Salud Municipal',
    municipio VARCHAR(100) NOT NULL DEFAULT 'Municipio Autónomo',
    logotipo_ruta VARCHAR(255) NULL,
    dias_alerta_fefo INT NOT NULL DEFAULT 60 COMMENT 'Días previos a caducidad para semáforo crítico/alerta',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_solo_una_fila CHECK (id = 1)
) ENGINE=InnoDB;

-- 1.1 Catálogo Maestro Parametrizable: Roles de Usuario (RBAC Dinámico)
CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL UNIQUE COMMENT 'Ej: rol_admin, rol_farmaceuta, rol_auditor',
    es_personalizado TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 = Rol base de sistema, 1 = Rol dinámico parametrizable',
    descripcion VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 1.2 Catálogo Maestro: Módulos del Sistema
CREATE TABLE modulos_sistema (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(40) NOT NULL UNIQUE COMMENT 'Ej: CENTROS, MEDICAMENTOS, INVENTARIO, DESPACHOS, USUARIOS, AUDITORIA',
    nombre VARCHAR(80) NOT NULL,
    descripcion VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 1.3 Catálogo Maestro: Permisos Atómicos por Módulo
CREATE TABLE permisos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    modulo_id INT NOT NULL,
    codigo VARCHAR(50) NOT NULL UNIQUE COMMENT 'Ej: CENTROS_CREAR, CENTROS_EDITAR, DESPACHOS_PROCESAR',
    nombre VARCHAR(100) NOT NULL,
    descripcion VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_permisos_modulo FOREIGN KEY (modulo_id) REFERENCES modulos_sistema(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 1.4 Tabla Puente Muchos a Muchos (N:M): Asignación Rol <-> Permisos (Matriz RBAC)
CREATE TABLE rol_permisos (
    rol_id INT NOT NULL,
    permiso_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (rol_id, permiso_id),
    CONSTRAINT fk_rp_rol FOREIGN KEY (rol_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_permiso FOREIGN KEY (permiso_id) REFERENCES permisos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 1.5 Tipos de Documento de Identidad Oficiales
CREATE TABLE tipos_documento (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo CHAR(1) NOT NULL UNIQUE COMMENT 'V, E, P',
    nombre VARCHAR(40) NOT NULL
) ENGINE=InnoDB;

-- 1.6 Catálogo de Tipos de Vías de Contacto (1FN Normalización)
CREATE TABLE tipos_contacto (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(30) NOT NULL UNIQUE COMMENT 'TEL_FIJO, TEL_MOVIL, WHATSAPP, EMAIL_ALTERNO, CONTACTO_EMERGENCIA',
    nombre VARCHAR(60) NOT NULL,
    descripcion VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 1.7 Formas Farmacéuticas
CREATE TABLE formas_farmaceuticas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(60) NOT NULL UNIQUE COMMENT 'Tableta / Comprimido, Cápsula, Jarabe, Suspensión, Ampolla'
) ENGINE=InnoDB;

-- 1.8 Catálogo Maestro Parametrizable: Tipos de Centros de Salud (3FN)
CREATE TABLE tipos_centro_salud (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(30) NOT NULL UNIQUE COMMENT 'Ej: AMB_URB_1, AMB_URB_2, CLIN_MUN',
    nombre VARCHAR(80) NOT NULL,
    descripcion VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Activo en catálogo, 0 = Inactivo',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 1.9 Catálogo Maestro Parametrizable: Recursos y Capacidades Operativas (N:M)
CREATE TABLE recursos_operativos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(30) NOT NULL UNIQUE COMMENT 'Ej: CADENA_FRIO, PLANTA_ELEC, AMBULANCIA, ESTERILIZACION',
    nombre VARCHAR(80) NOT NULL,
    descripcion VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Activo en catálogo, 0 = Obsoleto',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 1.10 Catálogo Clínico Parametrizable: Patologías y Diagnósticos (3FN)
CREATE TABLE patologias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo_cie10 VARCHAR(10) NULL UNIQUE COMMENT 'Código internacional CIE-10 (ej: I10, E11)',
    nombre VARCHAR(120) NOT NULL UNIQUE COMMENT 'Ej: Hipertensión Arterial Primaria, Diabetes Mellitus Tipo 2',
    cronica TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Enfermedad crónica no transmisible, 0 = Aguda',
    descripcion VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================================
-- 2. RED ASISTENCIAL Y NORMALIZACIÓN 1FN
-- ============================================================================

-- 2.1 Entidad Principal: Centros de Salud (Ambulatorios de la Red Municipal)
CREATE TABLE centros_salud (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo_sicm VARCHAR(30) NOT NULL UNIQUE COMMENT 'Código sanitario único del ambulatorio',
    nombre VARCHAR(120) NOT NULL,
    tipo_centro_id INT NOT NULL,
    medico_director VARCHAR(120) NULL COMMENT 'Director médico o responsable sanitario',
    horario_atencion VARCHAR(100) NULL COMMENT 'Ej: 24 Horas o Lunes a Viernes 7:00 AM - 5:00 PM',
    direccion VARCHAR(255) NOT NULL,
    telefono_principal VARCHAR(30) NULL COMMENT 'Teléfono de contacto asistencial rápido (sincronizado con telefonos_centro_salud)',
    activo TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Operativo, 0 = Inactivo (Soft Delete)',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_centros_tipo FOREIGN KEY (tipo_centro_id) REFERENCES tipos_centro_salud(id)
) ENGINE=InnoDB;

-- 2.2 Normalización 1FN: Directorio Telefónico y Vías de Contacto de Ambulatorios
CREATE TABLE telefonos_centro_salud (
    id INT AUTO_INCREMENT PRIMARY KEY,
    centro_salud_id INT NOT NULL,
    tipo_contacto_id INT NOT NULL,
    numero VARCHAR(30) NOT NULL,
    es_principal TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Teléfono principal de referencia',
    observaciones VARCHAR(100) NULL COMMENT 'Ej: Sala de parto, Dirección, Emergencia nocturna',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tcs_centro FOREIGN KEY (centro_salud_id) REFERENCES centros_salud(id) ON DELETE CASCADE,
    CONSTRAINT fk_tcs_tipo FOREIGN KEY (tipo_contacto_id) REFERENCES tipos_contacto(id)
) ENGINE=InnoDB;

-- 2.3 Tabla Puente Muchos a Muchos (N:M): Centro de Salud <-> Recursos Operativos
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
-- 3. SEGURIDAD, AUTENTICACIÓN, TOKENS Y NOTIFICACIONES
-- ============================================================================

-- 3.1 Usuarios del Sistema (Operadores, Farmaceutas y Administradores)
CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(80) NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL COMMENT 'Hash criptográfico Bcrypt o SHA-256',
    rol_id INT NOT NULL,
    centro_salud_id INT NULL COMMENT 'NULL si es Administrador Central',
    activo TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Soft delete',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_usuarios_rol FOREIGN KEY (rol_id) REFERENCES roles(id),
    CONSTRAINT fk_usuarios_centro FOREIGN KEY (centro_salud_id) REFERENCES centros_salud(id),
    CONSTRAINT chk_usuario_email_formato CHECK (email REGEXP '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+[.][A-Za-z]{2,}$')
) ENGINE=InnoDB;

-- 3.2 Tokens de Seguridad de Expiración Corta (Sin inflar usuarios)
CREATE TABLE tokens_seguridad (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    tipo_token VARCHAR(30) NOT NULL COMMENT 'ACTIVACION, RECUPERACION_PASS, 2FA_CHALLENGE',
    expiracion DATETIME NOT NULL,
    usado TINYINT(1) NOT NULL DEFAULT 0,
    ip_solicitud VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tokens_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_tokens_hash (token_hash),
    INDEX idx_tokens_expiracion (expiracion, usado)
) ENGINE=InnoDB;

-- 3.3 Sistema de Notificaciones y Alertas Automáticas
CREATE TABLE notificaciones_sistema (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NULL COMMENT 'Destinatario específico o NULL para administradores',
    centro_salud_id INT NULL COMMENT 'Ambulatorio asociado a la alerta',
    tipo VARCHAR(30) NOT NULL COMMENT 'STOCK_MINIMO, CADUCIDAD_PROXIMA, SISTEMA, SEGURIDAD',
    titulo VARCHAR(120) NOT NULL,
    mensaje TEXT NOT NULL,
    leida TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notif_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_centro FOREIGN KEY (centro_salud_id) REFERENCES centros_salud(id) ON DELETE CASCADE,
    INDEX idx_notif_leida (leida, created_at)
) ENGINE=InnoDB;

-- ============================================================================
-- 4. CATÁLOGO DE MEDICAMENTOS Y GESTIÓN DE LOTES (FEFO)
-- ============================================================================

CREATE TABLE medicamentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre_generico VARCHAR(120) NOT NULL,
    concentracion VARCHAR(50) NOT NULL COMMENT 'Ej: 500mg, 100UI/ml, 10mg',
    forma_farmaceutica_id INT NOT NULL,
    requiere_cadena_frio TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Requiere refrigeración estricta (2°C - 8°C)',
    stock_minimo_sugerido INT NOT NULL DEFAULT 20 COMMENT 'Umbral de disparo para reposición y alertas',
    activo TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Soft delete si se descontinúa',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_medicamentos_nombre (nombre_generico),
    CONSTRAINT fk_medicamentos_forma FOREIGN KEY (forma_farmaceutica_id) REFERENCES formas_farmaceuticas(id)
) ENGINE=InnoDB;

-- POLÍTICA APLICADA: Un lote NUNCA se borra físicamente para trazabilidad sanitaria
CREATE TABLE lotes_inventario (
    id INT AUTO_INCREMENT PRIMARY KEY,
    centro_salud_id INT NOT NULL,
    medicamento_id INT NOT NULL,
    numero_lote VARCHAR(30) NOT NULL,
    fecha_vencimiento DATE NOT NULL,
    cantidad_actual INT NOT NULL CHECK (cantidad_actual >= 0),
    usuario_id INT NOT NULL COMMENT 'Trazabilidad: Operador que registró el lote',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_lote_centro_med (centro_salud_id, medicamento_id, numero_lote),
    INDEX idx_inventario_fefo (centro_salud_id, medicamento_id, fecha_vencimiento ASC),
    CONSTRAINT fk_lotes_centro FOREIGN KEY (centro_salud_id) REFERENCES centros_salud(id),
    CONSTRAINT fk_lotes_medicamento FOREIGN KEY (medicamento_id) REFERENCES medicamentos(id),
    CONSTRAINT fk_lotes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- ============================================================================
-- 5. PACIENTES, RÉCIPES Y DESPACHOS (Bloqueo de Doble Retiro y 1FN)
-- ============================================================================

CREATE TABLE pacientes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo_documento_id INT NOT NULL,
    cedula VARCHAR(20) NOT NULL,
    nombre_completo VARCHAR(120) NOT NULL,
    fecha_nacimiento DATE NULL,
    direccion_residencia VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_paciente_cedula (tipo_documento_id, cedula),
    CONSTRAINT fk_pacientes_tipo_doc FOREIGN KEY (tipo_documento_id) REFERENCES tipos_documento(id),
    CONSTRAINT chk_paciente_cedula_valida CHECK (cedula REGEXP '^[0-9]{5,12}$')
) ENGINE=InnoDB;

-- 5.1 Normalización 1FN: Contactos Múltiples de Pacientes
CREATE TABLE paciente_contactos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    paciente_id INT NOT NULL,
    tipo_contacto_id INT NOT NULL,
    valor VARCHAR(120) NOT NULL COMMENT 'Teléfono, correo o vía de contacto',
    es_principal TINYINT(1) NOT NULL DEFAULT 0,
    nombre_contacto_emergencia VARCHAR(100) NULL COMMENT 'Nombre del familiar o representante',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pcont_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
    CONSTRAINT fk_pcont_tipo FOREIGN KEY (tipo_contacto_id) REFERENCES tipos_contacto(id)
) ENGINE=InnoDB;

-- 5.2 Tratamientos y Prescripciones Médicas Autorizadas
CREATE TABLE tratamientos_autorizados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    paciente_id INT NOT NULL,
    medicamento_id INT NOT NULL,
    patologia_id INT NOT NULL,
    dosis_mensual INT NOT NULL CHECK (dosis_mensual > 0),
    fecha_emision_recipe DATE NOT NULL,
    fecha_vencimiento_recipe DATE NOT NULL,
    medico_prescriptor VARCHAR(120) NULL COMMENT 'Médico tratante y matrícula MPPS',
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tratamientos_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id),
    CONSTRAINT fk_tratamientos_medicamento FOREIGN KEY (medicamento_id) REFERENCES medicamentos(id),
    CONSTRAINT fk_tratamientos_patologia FOREIGN KEY (patologia_id) REFERENCES patologias(id),
    CONSTRAINT chk_recipe_rango_fechas CHECK (fecha_vencimiento_recipe >= fecha_emision_recipe)
) ENGINE=InnoDB;

-- POLÍTICA APLICADA: Despachos estrictamente inmutables con candado contra doble retiro mensual
CREATE TABLE despachos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tratamiento_id INT NOT NULL,
    centro_salud_id INT NOT NULL,
    usuario_id INT NOT NULL,
    fecha_hora DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    periodo_mes_anio CHAR(7) NOT NULL COMMENT 'Ej: 2026-11',
    observaciones VARCHAR(255) NULL,
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
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_detalles_despacho FOREIGN KEY (despacho_id) REFERENCES despachos(id),
    CONSTRAINT fk_detalles_lote FOREIGN KEY (lote_id) REFERENCES lotes_inventario(id)
) ENGINE=InnoDB;

-- ============================================================================
-- 6. AUDITORÍA FORENSE INMUTABLE (WORM) Y AUDITORÍA DELTA
-- ============================================================================

-- 6.1 Bitácora Forense General (Eventos de alto nivel)
CREATE TABLE logs_auditoria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NULL COMMENT 'Usuario que realizó la acción',
    accion VARCHAR(50) NOT NULL COMMENT 'Ej: CREAR_CENTRO, EDITAR_CENTRO, DESPACHO_FEFO, INICIALIZAR_SISTEMA',
    tabla_afectada VARCHAR(50) NOT NULL COMMENT 'Tabla modificada',
    registro_id INT NULL COMMENT 'ID del registro modificado',
    detalles_json JSON NULL COMMENT 'Valores anteriores y nuevos en JSON estructurado',
    ip_origen VARCHAR(45) NOT NULL COMMENT 'Dirección IP del cliente',
    user_agent VARCHAR(255) NULL COMMENT 'Navegador, cliente o consola',
    fecha_hora DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_logs_fecha (fecha_hora DESC),
    INDEX idx_logs_accion (accion),
    INDEX idx_logs_tabla_registro (tabla_afectada, registro_id),
    CONSTRAINT fk_logs_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- 6.2 Auditoría Forense Delta Celda por Celda (Control granular de cambios directos)
CREATE TABLE logs_cambios_campos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tabla_nombre VARCHAR(60) NOT NULL,
    registro_id INT NOT NULL,
    campo_modificado VARCHAR(60) NOT NULL,
    valor_anterior TEXT NULL,
    valor_nuevo TEXT NULL,
    usuario_app_id INT NULL COMMENT 'ID del usuario autenticado en app si está disponible',
    db_user VARCHAR(80) NOT NULL COMMENT 'Usuario del RDBMS (CURRENT_USER())',
    connection_id BIGINT NOT NULL COMMENT 'Identificador de conexión (CONNECTION_ID())',
    ip_origen VARCHAR(45) NOT NULL COMMENT 'IP del cliente web o CONSOLA_DIRECTA',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_delta_tabla_reg (tabla_nombre, registro_id),
    INDEX idx_delta_created (created_at DESC)
) ENGINE=InnoDB;

-- ============================================================================
-- 7. FUNCIONES DETERMINÍSTICAS DEL SISTEMA
-- ============================================================================

DELIMITER $$

-- 7.1 Evaluación Determinística de Caducidad de Lotes (Priorización FEFO)
DROP FUNCTION IF EXISTS fn_evaluar_caducidad_lote$$
CREATE FUNCTION fn_evaluar_caducidad_lote(p_fecha_vencimiento DATE)
RETURNS VARCHAR(20)
DETERMINISTIC
BEGIN
    IF p_fecha_vencimiento IS NULL THEN
        RETURN 'DESCONOCIDO';
    ELSEIF p_fecha_vencimiento < CURDATE() THEN
        RETURN 'VENCIDO';
    ELSEIF p_fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN
        RETURN 'CRITICO_FEFO';
    ELSEIF p_fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN
        RETURN 'PROXIMO';
    ELSE
        RETURN 'OPTIMO';
    END IF;
END$$

-- 7.2 Evaluación Determinística de Nivel de Stock
DROP FUNCTION IF EXISTS fn_evaluar_nivel_stock$$
CREATE FUNCTION fn_evaluar_nivel_stock(p_cantidad INT, p_stock_minimo INT)
RETURNS VARCHAR(20)
DETERMINISTIC
BEGIN
    IF p_cantidad IS NULL OR p_cantidad <= 0 THEN
        RETURN 'AGOTADO';
    ELSEIF p_cantidad <= COALESCE(p_stock_minimo, 10) THEN
        RETURN 'CRITICO';
    ELSEIF p_cantidad <= (COALESCE(p_stock_minimo, 10) * 2) THEN
        RETURN 'BAJO';
    ELSE
        RETURN 'ADECUADO';
    END IF;
END$$

DELIMITER ;

-- ============================================================================
-- 8. TRIGGERS CENTINELA: SEGURIDAD WORM, INTEGRIDAD SANITARIA Y AUDITORÍA DELTA
-- ============================================================================

DELIMITER $$

-- 8.1 Inmutabilidad WORM en logs_auditoria
DROP TRIGGER IF EXISTS trg_bloquear_update_logs$$
CREATE TRIGGER trg_bloquear_update_logs
BEFORE UPDATE ON logs_auditoria
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Integridad Forense Violada: La tabla logs_auditoria es estrictamente inmutable (WORM). No se permiten modificaciones.';
END$$

DROP TRIGGER IF EXISTS trg_bloquear_delete_logs$$
CREATE TRIGGER trg_bloquear_delete_logs
BEFORE DELETE ON logs_auditoria
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Integridad Forense Violada: La tabla logs_auditoria es estrictamente inmutable (WORM). No se permiten eliminaciones físicas.';
END$$

-- 8.2 Inmutabilidad WORM en logs_cambios_campos
DROP TRIGGER IF EXISTS trg_bloquear_update_delta$$
CREATE TRIGGER trg_bloquear_update_delta
BEFORE UPDATE ON logs_cambios_campos
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Integridad Forense Violada: La tabla logs_cambios_campos es inmutable (WORM). No se permiten modificaciones.';
END$$

DROP TRIGGER IF EXISTS trg_bloquear_delete_delta$$
CREATE TRIGGER trg_bloquear_delete_delta
BEFORE DELETE ON logs_cambios_campos
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Integridad Forense Violada: La tabla logs_cambios_campos es inmutable (WORM). No se permiten eliminaciones físicas.';
END$$

-- 8.3 Inmutabilidad WORM en despachos
DROP TRIGGER IF EXISTS trg_bloquear_update_despachos$$
CREATE TRIGGER trg_bloquear_update_despachos
BEFORE UPDATE ON despachos
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Integridad Sanitaria Violada: Los registros de despachos son inmutables (WORM). Queda prohibida toda modificación.';
END$$

DROP TRIGGER IF EXISTS trg_bloquear_delete_despachos$$
CREATE TRIGGER trg_bloquear_delete_despachos
BEFORE DELETE ON despachos
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Integridad Sanitaria Violada: Los registros de despachos son inmutables (WORM). Queda prohibida su eliminación física.';
END$$

-- 8.4 Inmutabilidad WORM en despacho_detalles
DROP TRIGGER IF EXISTS trg_bloquear_update_despacho_detalles$$
CREATE TRIGGER trg_bloquear_update_despacho_detalles
BEFORE UPDATE ON despacho_detalles
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Integridad Sanitaria Violada: Las líneas de detalle de despacho son inmutables. No se admiten modificaciones.';
END$$

DROP TRIGGER IF EXISTS trg_bloquear_delete_despacho_detalles$$
CREATE TRIGGER trg_bloquear_delete_despacho_detalles
BEFORE DELETE ON despacho_detalles
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Integridad Sanitaria Violada: Las líneas de detalle de despacho son inmutables. No se admiten eliminaciones físicas.';
END$$

-- 8.5 Centinela de Cadena de Frío en Almacenamiento de Lotes
DROP TRIGGER IF EXISTS trg_lotes_cadena_frio_check$$
CREATE TRIGGER trg_lotes_cadena_frio_check
BEFORE INSERT ON lotes_inventario
FOR EACH ROW
BEGIN
    DECLARE v_req_frio TINYINT(1) DEFAULT 0;
    DECLARE v_tiene_frio INT DEFAULT 0;

    SELECT requiere_cadena_frio INTO v_req_frio
    FROM medicamentos
    WHERE id = NEW.medicamento_id;

    IF v_req_frio = 1 THEN
        SELECT COUNT(*) INTO v_tiene_frio
        FROM centro_recursos cr
        INNER JOIN recursos_operativos ro ON ro.id = cr.recurso_id
        WHERE cr.centro_salud_id = NEW.centro_salud_id
          AND ro.codigo = 'CADENA_FRIO'
          AND cr.operativo = 1;

        IF v_tiene_frio = 0 THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Violación de Seguridad Sanitaria: El ambulatorio destino no cuenta con equipo operativo de Cadena de Frío para almacenar este medicamento termolábil.';
        END IF;
    END IF;
END$$

-- 8.5b Centinela de Unicidad y Coherencia Clínica de Récipes Activos
DROP TRIGGER IF EXISTS trg_tratamientos_no_duplicados_activos_ins$$
CREATE TRIGGER trg_tratamientos_no_duplicados_activos_ins
BEFORE INSERT ON tratamientos_autorizados
FOR EACH ROW
BEGIN
    DECLARE v_existentes INT DEFAULT 0;
    IF NEW.activo = 1 THEN
        SELECT COUNT(*) INTO v_existentes
        FROM tratamientos_autorizados
        WHERE paciente_id = NEW.paciente_id
          AND medicamento_id = NEW.medicamento_id
          AND activo = 1
          AND fecha_vencimiento_recipe >= CURDATE();

        IF v_existentes > 0 THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Conflicto Clínico: El paciente ya posee un récipe activo y vigente para este mismo medicamento. Desactive o expire el anterior antes de emitir uno nuevo.';
        END IF;
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_tratamientos_no_duplicados_activos_upd$$
CREATE TRIGGER trg_tratamientos_no_duplicados_activos_upd
BEFORE UPDATE ON tratamientos_autorizados
FOR EACH ROW
BEGIN
    DECLARE v_existentes INT DEFAULT 0;
    IF NEW.activo = 1 THEN
        SELECT COUNT(*) INTO v_existentes
        FROM tratamientos_autorizados
        WHERE paciente_id = NEW.paciente_id
          AND medicamento_id = NEW.medicamento_id
          AND activo = 1
          AND fecha_vencimiento_recipe >= CURDATE()
          AND id != NEW.id;

        IF v_existentes > 0 THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Conflicto Clínico: El paciente ya posee un récipe activo y vigente para este mismo medicamento. Desactive o expire el anterior antes de emitir uno nuevo.';
        END IF;
    END IF;
END$$

-- 8.6 Centinela de Vigencia de Récipe y Cadena de Frío en Despachos
DROP TRIGGER IF EXISTS trg_despachos_vigencia_recipe$$
CREATE TRIGGER trg_despachos_vigencia_recipe
BEFORE INSERT ON despachos
FOR EACH ROW
BEGIN
    DECLARE v_activo TINYINT(1);
    DECLARE v_vencimiento DATE;
    DECLARE v_req_frio TINYINT(1) DEFAULT 0;
    DECLARE v_tiene_frio INT DEFAULT 0;

    SELECT ta.activo, ta.fecha_vencimiento_recipe, m.requiere_cadena_frio
    INTO v_activo, v_vencimiento, v_req_frio
    FROM tratamientos_autorizados ta
    INNER JOIN medicamentos m ON m.id = ta.medicamento_id
    WHERE ta.id = NEW.tratamiento_id;

    IF v_activo IS NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Incoherencia Prescriptiva: El tratamiento autorizado especificado no existe.';
    ELSEIF v_activo = 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Prescripción Inactiva: El récipe médico autorizado se encuentra suspendido o inactivo.';
    ELSEIF v_vencimiento < CURDATE() THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Prescripción Caducada: El récipe médico presentado ha superado su fecha de vigencia y no puede recibir nuevos suministros.';
    END IF;

    -- Validación Sanitaria de Cadena de Frío en Despacho
    IF v_req_frio = 1 THEN
        SELECT COUNT(*) INTO v_tiene_frio
        FROM centro_recursos cr
        INNER JOIN recursos_operativos ro ON ro.id = cr.recurso_id
        WHERE cr.centro_salud_id = NEW.centro_salud_id
          AND ro.codigo = 'CADENA_FRIO'
          AND cr.operativo = 1;

        IF v_tiene_frio = 0 THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Alerta Sanitaria: El ambulatorio no cuenta con refrigeración operativa para dispensar este fármaco.';
        END IF;
    END IF;
END$$

-- 8.7 Centinela de Coherencia Territorial, Farmacológica, Inocuidad y Sobredosis en Detalle de Despacho
DROP TRIGGER IF EXISTS trg_despacho_detalles_coherencia$$
CREATE TRIGGER trg_despacho_detalles_coherencia
BEFORE INSERT ON despacho_detalles
FOR EACH ROW
BEGIN
    DECLARE v_centro_despacho INT;
    DECLARE v_tratamiento_id INT;
    DECLARE v_med_recipe INT;
    DECLARE v_dosis_autorizada INT;
    DECLARE v_centro_lote INT;
    DECLARE v_med_lote INT;
    DECLARE v_vencimiento_lote DATE;
    DECLARE v_stock_lote INT;

    SELECT centro_salud_id, tratamiento_id
    INTO v_centro_despacho, v_tratamiento_id
    FROM despachos
    WHERE id = NEW.despacho_id;

    SELECT medicamento_id, dosis_mensual
    INTO v_med_recipe, v_dosis_autorizada
    FROM tratamientos_autorizados
    WHERE id = v_tratamiento_id;

    SELECT centro_salud_id, medicamento_id, fecha_vencimiento, cantidad_actual
    INTO v_centro_lote, v_med_lote, v_vencimiento_lote, v_stock_lote
    FROM lotes_inventario
    WHERE id = NEW.lote_id;

    -- 1. Coherencia territorial: El lote debe pertenecer al ambulatorio del despacho
    IF v_centro_lote <> v_centro_despacho THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Incoherencia Territorial: El lote seleccionado no pertenece al ambulatorio donde se realiza el despacho.';
    END IF;

    -- 2. Coherencia farmacológica: El lote debe corresponder al principio activo recetado
    IF v_med_lote <> v_med_recipe THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Incoherencia Farmacológica: El medicamento del lote no coincide con el récipe autorizado.';
    END IF;

    -- 3. Inocuidad: No se puede despachar medicamento vencido
    IF v_vencimiento_lote < CURDATE() THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Violación de Inocuidad: El lote seleccionado se encuentra vencido y no puede ser dispensado.';
    END IF;

    -- 4. Control de Sobredespacho / Sobredosis: La cantidad no puede exceder la dosis autorizada en el récipe
    IF v_dosis_autorizada IS NOT NULL AND NEW.cantidad_entregada > v_dosis_autorizada THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Violación de Prescripción: La cantidad excede la dosis mensual autorizada en el récipe.';
    END IF;

    -- 5. Control de Disponibilidad: El lote debe poseer saldo suficiente
    IF v_stock_lote IS NOT NULL AND v_stock_lote < NEW.cantidad_entregada THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Stock Insuficiente: La cantidad requerida supera el inventario disponible en el lote.';
    END IF;
END$$

-- 8.8 Descuento Atómico y Único de Stock por Detalle de Despacho
DROP TRIGGER IF EXISTS trg_despacho_detalles_descontar_stock$$
CREATE TRIGGER trg_despacho_detalles_descontar_stock
AFTER INSERT ON despacho_detalles
FOR EACH ROW
BEGIN
    UPDATE lotes_inventario
    SET cantidad_actual = cantidad_actual - NEW.cantidad_entregada
    WHERE id = NEW.lote_id;
END$$

-- 8.9 Sincronización Automática 1FN de Teléfono de Ambulatorio (Cero IDs quemados)
DROP TRIGGER IF EXISTS trg_centros_salud_sync_telefono$$
CREATE TRIGGER trg_centros_salud_sync_telefono
AFTER INSERT ON centros_salud
FOR EACH ROW
BEGIN
    DECLARE v_tipo_id INT;

    IF NEW.telefono_principal IS NOT NULL AND TRIM(NEW.telefono_principal) <> '' THEN
        SELECT id INTO v_tipo_id 
        FROM tipos_contacto 
        WHERE codigo IN ('TEL_FIJO', 'TEL_MOVIL') 
        ORDER BY id ASC 
        LIMIT 1;

        IF v_tipo_id IS NOT NULL THEN
            INSERT INTO telefonos_centro_salud (centro_salud_id, tipo_contacto_id, numero, es_principal, observaciones)
            VALUES (NEW.id, v_tipo_id, NEW.telefono_principal, 1, 'Teléfono Principal de Contacto');
        END IF;
    END IF;
END$$

-- 8.10 Protección de Roles Nativos del Sistema (Seguridad RBAC)
DROP TRIGGER IF EXISTS trg_proteger_roles_sistema$$
CREATE TRIGGER trg_proteger_roles_sistema
BEFORE DELETE ON roles
FOR EACH ROW
BEGIN
    IF OLD.es_personalizado = 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Seguridad RBAC: No se permite eliminar roles nativos del sistema.';
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_proteger_roles_sistema_update$$
CREATE TRIGGER trg_proteger_roles_sistema_update
BEFORE UPDATE ON roles
FOR EACH ROW
BEGIN
    IF OLD.es_personalizado = 0 AND NEW.es_personalizado <> 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Seguridad RBAC: No se permite alterar la condición de rol nativo del sistema.';
    END IF;
END$$

-- 8.11 Auditoría Delta Celda por Celda en Catálogo de Medicamentos
DROP TRIGGER IF EXISTS trg_medicamentos_audit_delta$$
CREATE TRIGGER trg_medicamentos_audit_delta
AFTER UPDATE ON medicamentos
FOR EACH ROW
BEGIN
    DECLARE v_ip VARCHAR(45);
    SET v_ip = COALESCE(@app_client_ip, 'CONSOLA_DIRECTA');

    IF NOT (OLD.nombre_generico <=> NEW.nombre_generico) THEN
        INSERT INTO logs_cambios_campos (tabla_nombre, registro_id, campo_modificado, valor_anterior, valor_nuevo, usuario_app_id, db_user, connection_id, ip_origen, created_at)
        VALUES ('medicamentos', NEW.id, 'nombre_generico', OLD.nombre_generico, NEW.nombre_generico, @app_user_id, CURRENT_USER(), CONNECTION_ID(), v_ip, NOW());
    END IF;

    IF NOT (OLD.concentracion <=> NEW.concentracion) THEN
        INSERT INTO logs_cambios_campos (tabla_nombre, registro_id, campo_modificado, valor_anterior, valor_nuevo, usuario_app_id, db_user, connection_id, ip_origen, created_at)
        VALUES ('medicamentos', NEW.id, 'concentracion', OLD.concentracion, NEW.concentracion, @app_user_id, CURRENT_USER(), CONNECTION_ID(), v_ip, NOW());
    END IF;

    IF NOT (OLD.forma_farmaceutica_id <=> NEW.forma_farmaceutica_id) THEN
        INSERT INTO logs_cambios_campos (tabla_nombre, registro_id, campo_modificado, valor_anterior, valor_nuevo, usuario_app_id, db_user, connection_id, ip_origen, created_at)
        VALUES ('medicamentos', NEW.id, 'forma_farmaceutica_id', CAST(OLD.forma_farmaceutica_id AS CHAR), CAST(NEW.forma_farmaceutica_id AS CHAR), @app_user_id, CURRENT_USER(), CONNECTION_ID(), v_ip, NOW());
    END IF;

    IF NOT (OLD.requiere_cadena_frio <=> NEW.requiere_cadena_frio) THEN
        INSERT INTO logs_cambios_campos (tabla_nombre, registro_id, campo_modificado, valor_anterior, valor_nuevo, usuario_app_id, db_user, connection_id, ip_origen, created_at)
        VALUES ('medicamentos', NEW.id, 'requiere_cadena_frio', CAST(OLD.requiere_cadena_frio AS CHAR), CAST(NEW.requiere_cadena_frio AS CHAR), @app_user_id, CURRENT_USER(), CONNECTION_ID(), v_ip, NOW());
    END IF;

    IF NOT (OLD.stock_minimo_sugerido <=> NEW.stock_minimo_sugerido) THEN
        INSERT INTO logs_cambios_campos (tabla_nombre, registro_id, campo_modificado, valor_anterior, valor_nuevo, usuario_app_id, db_user, connection_id, ip_origen, created_at)
        VALUES ('medicamentos', NEW.id, 'stock_minimo_sugerido', CAST(OLD.stock_minimo_sugerido AS CHAR), CAST(NEW.stock_minimo_sugerido AS CHAR), @app_user_id, CURRENT_USER(), CONNECTION_ID(), v_ip, NOW());
    END IF;

    IF NOT (OLD.activo <=> NEW.activo) THEN
        INSERT INTO logs_cambios_campos (tabla_nombre, registro_id, campo_modificado, valor_anterior, valor_nuevo, usuario_app_id, db_user, connection_id, ip_origen, created_at)
        VALUES ('medicamentos', NEW.id, 'activo', CAST(OLD.activo AS CHAR), CAST(NEW.activo AS CHAR), @app_user_id, CURRENT_USER(), CONNECTION_ID(), v_ip, NOW());
    END IF;
END$$

-- 8.12 Auditoría Delta Celda por Celda en Centros de Salud
DROP TRIGGER IF EXISTS trg_centros_salud_audit_delta$$
CREATE TRIGGER trg_centros_salud_audit_delta
AFTER UPDATE ON centros_salud
FOR EACH ROW
BEGIN
    DECLARE v_ip VARCHAR(45);
    SET v_ip = COALESCE(@app_client_ip, 'CONSOLA_DIRECTA');

    IF NOT (OLD.codigo_sicm <=> NEW.codigo_sicm) THEN
        INSERT INTO logs_cambios_campos (tabla_nombre, registro_id, campo_modificado, valor_anterior, valor_nuevo, usuario_app_id, db_user, connection_id, ip_origen, created_at)
        VALUES ('centros_salud', NEW.id, 'codigo_sicm', OLD.codigo_sicm, NEW.codigo_sicm, @app_user_id, CURRENT_USER(), CONNECTION_ID(), v_ip, NOW());
    END IF;

    IF NOT (OLD.nombre <=> NEW.nombre) THEN
        INSERT INTO logs_cambios_campos (tabla_nombre, registro_id, campo_modificado, valor_anterior, valor_nuevo, usuario_app_id, db_user, connection_id, ip_origen, created_at)
        VALUES ('centros_salud', NEW.id, 'nombre', OLD.nombre, NEW.nombre, @app_user_id, CURRENT_USER(), CONNECTION_ID(), v_ip, NOW());
    END IF;

    IF NOT (OLD.tipo_centro_id <=> NEW.tipo_centro_id) THEN
        INSERT INTO logs_cambios_campos (tabla_nombre, registro_id, campo_modificado, valor_anterior, valor_nuevo, usuario_app_id, db_user, connection_id, ip_origen, created_at)
        VALUES ('centros_salud', NEW.id, 'tipo_centro_id', CAST(OLD.tipo_centro_id AS CHAR), CAST(NEW.tipo_centro_id AS CHAR), @app_user_id, CURRENT_USER(), CONNECTION_ID(), v_ip, NOW());
    END IF;

    IF NOT (OLD.medico_director <=> NEW.medico_director) THEN
        INSERT INTO logs_cambios_campos (tabla_nombre, registro_id, campo_modificado, valor_anterior, valor_nuevo, usuario_app_id, db_user, connection_id, ip_origen, created_at)
        VALUES ('centros_salud', NEW.id, 'medico_director', OLD.medico_director, NEW.medico_director, @app_user_id, CURRENT_USER(), CONNECTION_ID(), v_ip, NOW());
    END IF;

    IF NOT (OLD.horario_atencion <=> NEW.horario_atencion) THEN
        INSERT INTO logs_cambios_campos (tabla_nombre, registro_id, campo_modificado, valor_anterior, valor_nuevo, usuario_app_id, db_user, connection_id, ip_origen, created_at)
        VALUES ('centros_salud', NEW.id, 'horario_atencion', OLD.horario_atencion, NEW.horario_atencion, @app_user_id, CURRENT_USER(), CONNECTION_ID(), v_ip, NOW());
    END IF;

    IF NOT (OLD.direccion <=> NEW.direccion) THEN
        INSERT INTO logs_cambios_campos (tabla_nombre, registro_id, campo_modificado, valor_anterior, valor_nuevo, usuario_app_id, db_user, connection_id, ip_origen, created_at)
        VALUES ('centros_salud', NEW.id, 'direccion', OLD.direccion, NEW.direccion, @app_user_id, CURRENT_USER(), CONNECTION_ID(), v_ip, NOW());
    END IF;

    IF NOT (OLD.telefono_principal <=> NEW.telefono_principal) THEN
        INSERT INTO logs_cambios_campos (tabla_nombre, registro_id, campo_modificado, valor_anterior, valor_nuevo, usuario_app_id, db_user, connection_id, ip_origen, created_at)
        VALUES ('centros_salud', NEW.id, 'telefono_principal', OLD.telefono_principal, NEW.telefono_principal, @app_user_id, CURRENT_USER(), CONNECTION_ID(), v_ip, NOW());
    END IF;

    IF NOT (OLD.activo <=> NEW.activo) THEN
        INSERT INTO logs_cambios_campos (tabla_nombre, registro_id, campo_modificado, valor_anterior, valor_nuevo, usuario_app_id, db_user, connection_id, ip_origen, created_at)
        VALUES ('centros_salud', NEW.id, 'activo', CAST(OLD.activo AS CHAR), CAST(NEW.activo AS CHAR), @app_user_id, CURRENT_USER(), CONNECTION_ID(), v_ip, NOW());
    END IF;
END$$

CREATE TRIGGER trg_pacientes_audit_delta
AFTER UPDATE ON pacientes
FOR EACH ROW
BEGIN
    DECLARE v_ip VARCHAR(45);
    SET v_ip = COALESCE(@app_client_ip, 'CONSOLA_DIRECTA');

    IF NOT (OLD.tipo_documento_id <=> NEW.tipo_documento_id) THEN
        INSERT INTO logs_cambios_campos (tabla_nombre, registro_id, campo_modificado, valor_anterior, valor_nuevo, usuario_app_id, db_user, connection_id, ip_origen, created_at)
        VALUES ('pacientes', NEW.id, 'tipo_documento_id', CAST(OLD.tipo_documento_id AS CHAR), CAST(NEW.tipo_documento_id AS CHAR), @app_user_id, CURRENT_USER(), CONNECTION_ID(), v_ip, NOW());
    END IF;

    IF NOT (OLD.cedula <=> NEW.cedula) THEN
        INSERT INTO logs_cambios_campos (tabla_nombre, registro_id, campo_modificado, valor_anterior, valor_nuevo, usuario_app_id, db_user, connection_id, ip_origen, created_at)
        VALUES ('pacientes', NEW.id, 'cedula', OLD.cedula, NEW.cedula, @app_user_id, CURRENT_USER(), CONNECTION_ID(), v_ip, NOW());
    END IF;

    IF NOT (OLD.nombre_completo <=> NEW.nombre_completo) THEN
        INSERT INTO logs_cambios_campos (tabla_nombre, registro_id, campo_modificado, valor_anterior, valor_nuevo, usuario_app_id, db_user, connection_id, ip_origen, created_at)
        VALUES ('pacientes', NEW.id, 'nombre_completo', OLD.nombre_completo, NEW.nombre_completo, @app_user_id, CURRENT_USER(), CONNECTION_ID(), v_ip, NOW());
    END IF;

    IF NOT (OLD.fecha_nacimiento <=> NEW.fecha_nacimiento) THEN
        INSERT INTO logs_cambios_campos (tabla_nombre, registro_id, campo_modificado, valor_anterior, valor_nuevo, usuario_app_id, db_user, connection_id, ip_origen, created_at)
        VALUES ('pacientes', NEW.id, 'fecha_nacimiento', CAST(OLD.fecha_nacimiento AS CHAR), CAST(NEW.fecha_nacimiento AS CHAR), @app_user_id, CURRENT_USER(), CONNECTION_ID(), v_ip, NOW());
    END IF;

    IF NOT (OLD.direccion_residencia <=> NEW.direccion_residencia) THEN
        INSERT INTO logs_cambios_campos (tabla_nombre, registro_id, campo_modificado, valor_anterior, valor_nuevo, usuario_app_id, db_user, connection_id, ip_origen, created_at)
        VALUES ('pacientes', NEW.id, 'direccion_residencia', OLD.direccion_residencia, NEW.direccion_residencia, @app_user_id, CURRENT_USER(), CONNECTION_ID(), v_ip, NOW());
    END IF;
END$$

DELIMITER ;

-- ============================================================================
-- 9. PROCEDIMIENTOS ALMACENADOS (TRANSACCIONES ACID CON FOR UPDATE)
-- ============================================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_procesar_despacho_fefo$$
CREATE PROCEDURE sp_procesar_despacho_fefo (
    IN  p_tratamiento_id INT,
    IN  p_centro_salud_id INT,
    IN  p_usuario_id INT,
    IN  p_lote_id INT,
    IN  p_cantidad INT,
    IN  p_periodo_mes_anio VARCHAR(7),
    IN  p_observaciones VARCHAR(255),
    OUT p_despacho_id INT,
    OUT p_codigo_resultado VARCHAR(30),
    OUT p_mensaje VARCHAR(255)
)
proc_label: BEGIN
    DECLARE v_stock_disponible INT DEFAULT 0;
    DECLARE v_stock_restante INT DEFAULT 0;
    DECLARE v_medicamento_id INT;
    DECLARE v_stock_minimo INT DEFAULT 20;
    DECLARE v_dosis_autorizada INT;

    -- Manejo centralizado de excepciones con Rollback atómico y propagación de causa
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 
            @sqlstate = RETURNED_SQLSTATE, 
            @errno = MYSQL_ERRNO, 
            @text = MESSAGE_TEXT;
        ROLLBACK;
        SET p_codigo_resultado = 'ERROR';
        SET p_mensaje = COALESCE(@text, 'Falla de integridad transaccional al procesar despacho FEFO');
        RESIGNAL;
    END;

    -- Validación inicial de argumentos
    IF p_cantidad <= 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Cantidad Inválida: La cantidad a despachar debe ser un entero estrictamente positivo (> 0).';
    END IF;

    IF p_periodo_mes_anio IS NULL OR p_periodo_mes_anio NOT REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$' THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Periodo Inválido: El formato del periodo mensual debe ser AAAA-MM (ej: 2026-11).';
    END IF;

    -- Validación de Prescripción y Control de Sobredosis
    SELECT dosis_mensual INTO v_dosis_autorizada 
    FROM tratamientos_autorizados 
    WHERE id = p_tratamiento_id;

    IF v_dosis_autorizada IS NULL THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Tratamiento Inexistente: No se localizó el récipe médico.';
    END IF;

    IF p_cantidad > v_dosis_autorizada THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Violación de Prescripción: La cantidad excede la dosis mensual autorizada en el récipe.';
    END IF;

    -- Inicio de Transacción ACID Estricta
    START TRANSACTION;

    -- 1. Bloqueo pesimista del lote (SELECT ... FOR UPDATE) para evitar condiciones de carrera concurrentes
    SELECT cantidad_actual, medicamento_id
    INTO v_stock_disponible, v_medicamento_id
    FROM lotes_inventario
    WHERE id = p_lote_id
    FOR UPDATE;

    IF v_stock_disponible IS NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Lote Inexistente: El lote de inventario especificado no existe.';
    END IF;

    IF v_stock_disponible < p_cantidad THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Stock Insuficiente: La cantidad requerida supera el inventario disponible en el lote.';
    END IF;

    -- 2. Inserción de cabecera de despacho (Triggers validarán vigencia de récipe y candado de doble retiro mensual)
    INSERT INTO despachos (
        tratamiento_id, 
        centro_salud_id, 
        usuario_id, 
        fecha_hora, 
        periodo_mes_anio, 
        observaciones
    ) VALUES (
        p_tratamiento_id, 
        p_centro_salud_id, 
        p_usuario_id, 
        NOW(), 
        p_periodo_mes_anio, 
        p_observaciones
    );

    SET p_despacho_id = LAST_INSERT_ID();

    -- 3. Inserción de detalle (Trigger validará pertenencia de ambulatorio, medicina correcta, inocuidad y stock)
    INSERT INTO despacho_detalles (
        despacho_id, 
        lote_id, 
        cantidad_entregada
    ) VALUES (
        p_despacho_id, 
        p_lote_id, 
        p_cantidad
    );

    -- 4. Cálculo de existencias remanentes (el descuento físico atómico lo ejecuta trg_despacho_detalles_descontar_stock)
    SET v_stock_restante = v_stock_disponible - p_cantidad;

    -- 5. Registro obligatorio en bitácora de auditoría forense WORM
    INSERT INTO logs_auditoria (
        usuario_id, 
        accion, 
        tabla_afectada, 
        registro_id, 
        detalles_json, 
        ip_origen, 
        user_agent, 
        fecha_hora
    ) VALUES (
        p_usuario_id, 
        'DESPACHO_FEFO', 
        'despachos', 
        p_despacho_id, 
        JSON_OBJECT(
            'despacho_id', p_despacho_id,
            'tratamiento_id', p_tratamiento_id,
            'centro_salud_id', p_centro_salud_id,
            'lote_id', p_lote_id,
            'cantidad_entregada', p_cantidad,
            'stock_anterior', v_stock_disponible,
            'stock_remanente', v_stock_restante,
            'periodo', p_periodo_mes_anio
        ), 
        COALESCE(@app_client_ip, 'CONSOLA_DIRECTA'), 
        'StoredProcedure/sp_procesar_despacho_fefo', 
        NOW()
    );

    -- 6. Disparo de Alerta Automática de Nivel de Stock si cruza el umbral mínimo
    SELECT stock_minimo_sugerido INTO v_stock_minimo
    FROM medicamentos WHERE id = v_medicamento_id;

    IF v_stock_restante <= COALESCE(v_stock_minimo, 20) THEN
        INSERT INTO notificaciones_sistema (
            usuario_id, 
            centro_salud_id, 
            tipo, 
            titulo, 
            mensaje, 
            leida, 
            created_at
        ) VALUES (
            p_usuario_id, 
            p_centro_salud_id, 
            'STOCK_MINIMO', 
            'Alerta de Stock Crítico por Despacho FEFO', 
            CONCAT('El lote ID #', p_lote_id, ' ha alcanzado nivel crítico de inventario con apenas ', v_stock_restante, ' unidades disponibles.'), 
            0, 
            NOW()
        );
    END IF;

    COMMIT;

    SET p_codigo_resultado = 'EXITO';
    SET p_mensaje = 'Despacho FEFO procesado satisfactoriamente con trazabilidad atómica.';
END proc_label$$

DELIMITER ;

-- ============================================================================
-- 10. VISTAS SQL (Capa de Abstracción y Reporting)
-- ============================================================================

-- Vista 1: Ambulatorios de la Red Municipal con datos resueltos y compatibilidad 1FN
CREATE OR REPLACE VIEW vw_centros_salud AS
SELECT 
    c.id,
    c.codigo_sicm,
    c.nombre,
    c.tipo_centro_id,
    t.codigo AS tipo_codigo,
    t.nombre AS tipo_nombre,
    c.medico_director,
    c.horario_atencion,
    c.direccion,
    COALESCE(
        (SELECT tcs.numero FROM telefonos_centro_salud tcs WHERE tcs.centro_salud_id = c.id AND tcs.es_principal = 1 LIMIT 1),
        c.telefono_principal
    ) AS telefono_principal,
    c.activo,
    c.created_at,
    c.updated_at
FROM centros_salud c
INNER JOIN tipos_centro_salud t ON t.id = c.tipo_centro_id;

-- Vista 2: Bitácora de Auditoría Forense con operador y rol resueltos
CREATE OR REPLACE VIEW vw_logs_auditoria AS
SELECT 
    l.id AS log_id,
    l.fecha_hora,
    l.usuario_id,
    COALESCE(u.nombre, 'Sistema / Consola') AS operador,
    COALESCE(r.nombre, 'Sin Rol') AS rol_operador,
    u.email AS email_operador,
    l.accion,
    l.tabla_afectada,
    l.registro_id,
    l.ip_origen,
    l.user_agent,
    l.detalles_json
FROM logs_auditoria l
LEFT JOIN usuarios u ON u.id = l.usuario_id
LEFT JOIN roles r ON r.id = u.rol_id;

-- Vista 3: Inventario Activo Ordenado Estrictamente por Vencimiento FEFO
CREATE OR REPLACE VIEW vw_inventario_fefo_activo AS
SELECT 
    l.id AS lote_id,
    l.numero_lote,
    l.centro_salud_id,
    cs.codigo_sicm AS centro_codigo,
    cs.nombre AS centro_nombre,
    l.medicamento_id,
    m.nombre_generico,
    m.concentracion,
    ff.nombre AS forma_farmaceutica,
    m.requiere_cadena_frio,
    l.fecha_vencimiento,
    DATEDIFF(l.fecha_vencimiento, CURDATE()) AS dias_para_vencer,
    fn_evaluar_caducidad_lote(l.fecha_vencimiento) AS estado_caducidad,
    l.cantidad_actual,
    m.stock_minimo_sugerido,
    fn_evaluar_nivel_stock(l.cantidad_actual, m.stock_minimo_sugerido) AS estado_stock,
    l.usuario_id AS registrado_por_usuario_id,
    u.nombre AS registrado_por_nombre,
    l.created_at
FROM lotes_inventario l
INNER JOIN centros_salud cs ON cs.id = l.centro_salud_id
INNER JOIN medicamentos m ON m.id = l.medicamento_id
INNER JOIN formas_farmaceuticas ff ON ff.id = m.forma_farmaceutica_id
LEFT JOIN usuarios u ON u.id = l.usuario_id
WHERE l.cantidad_actual > 0 AND cs.activo = 1 AND m.activo = 1
ORDER BY l.centro_salud_id ASC, l.medicamento_id ASC, l.fecha_vencimiento ASC;
