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

