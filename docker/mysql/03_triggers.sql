-- ============================================================================
-- SISTEMA INTEGRADO DE CONTROL DE MEDICAMENTOS (SICM) - AMBULATORIOS MUNICIPALES
-- Archivo: docker/mysql/03_triggers.sql
-- Módulo: Triggers Centinela: Seguridad WORM, Integridad Sanitaria y Auditoría Delta
-- ============================================================================

USE control_medicamentos_red;

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

DROP TRIGGER IF EXISTS trg_pacientes_audit_delta$$
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

-- 8.14 Auditoría Delta Celda por Celda en Lotes de Inventario
DROP TRIGGER IF EXISTS trg_lotes_audit_delta$$
CREATE TRIGGER trg_lotes_audit_delta
AFTER UPDATE ON lotes_inventario
FOR EACH ROW
BEGIN
    DECLARE v_ip VARCHAR(45);
    SET v_ip = COALESCE(@app_client_ip, 'CONSOLA_DIRECTA');

    IF NOT (OLD.numero_lote <=> NEW.numero_lote) THEN
        INSERT INTO logs_cambios_campos (tabla_nombre, registro_id, campo_modificado, valor_anterior, valor_nuevo, usuario_app_id, db_user, connection_id, ip_origen, created_at)
        VALUES ('lotes_inventario', NEW.id, 'numero_lote', OLD.numero_lote, NEW.numero_lote, @app_user_id, CURRENT_USER(), CONNECTION_ID(), v_ip, NOW());
    END IF;

    IF NOT (OLD.fecha_vencimiento <=> NEW.fecha_vencimiento) THEN
        INSERT INTO logs_cambios_campos (tabla_nombre, registro_id, campo_modificado, valor_anterior, valor_nuevo, usuario_app_id, db_user, connection_id, ip_origen, created_at)
        VALUES ('lotes_inventario', NEW.id, 'fecha_vencimiento', CAST(OLD.fecha_vencimiento AS CHAR), CAST(NEW.fecha_vencimiento AS CHAR), @app_user_id, CURRENT_USER(), CONNECTION_ID(), v_ip, NOW());
    END IF;

    IF NOT (OLD.cantidad_actual <=> NEW.cantidad_actual) THEN
        INSERT INTO logs_cambios_campos (tabla_nombre, registro_id, campo_modificado, valor_anterior, valor_nuevo, usuario_app_id, db_user, connection_id, ip_origen, created_at)
        VALUES ('lotes_inventario', NEW.id, 'cantidad_actual', CAST(OLD.cantidad_actual AS CHAR), CAST(NEW.cantidad_actual AS CHAR), @app_user_id, CURRENT_USER(), CONNECTION_ID(), v_ip, NOW());
    END IF;
END$$


-- 8.15 Auditoría Delta Celda por Celda en Tratamientos Autorizados
DROP TRIGGER IF EXISTS trg_tratamientos_audit_delta$$
CREATE TRIGGER trg_tratamientos_audit_delta
AFTER UPDATE ON tratamientos_autorizados
FOR EACH ROW
BEGIN
    DECLARE v_ip VARCHAR(45);
    SET v_ip = COALESCE(@app_client_ip, 'CONSOLA_DIRECTA');

    IF NOT (OLD.dosis_mensual <=> NEW.dosis_mensual) THEN
        INSERT INTO logs_cambios_campos (tabla_nombre, registro_id, campo_modificado, valor_anterior, valor_nuevo, usuario_app_id, db_user, connection_id, ip_origen, created_at)
        VALUES ('tratamientos_autorizados', NEW.id, 'dosis_mensual', CAST(OLD.dosis_mensual AS CHAR), CAST(NEW.dosis_mensual AS CHAR), @app_user_id, CURRENT_USER(), CONNECTION_ID(), v_ip, NOW());
    END IF;

    IF NOT (OLD.fecha_emision_recipe <=> NEW.fecha_emision_recipe) THEN
        INSERT INTO logs_cambios_campos (tabla_nombre, registro_id, campo_modificado, valor_anterior, valor_nuevo, usuario_app_id, db_user, connection_id, ip_origen, created_at)
        VALUES ('tratamientos_autorizados', NEW.id, 'fecha_emision_recipe', CAST(OLD.fecha_emision_recipe AS CHAR), CAST(NEW.fecha_emision_recipe AS CHAR), @app_user_id, CURRENT_USER(), CONNECTION_ID(), v_ip, NOW());
    END IF;

    IF NOT (OLD.fecha_vencimiento_recipe <=> NEW.fecha_vencimiento_recipe) THEN
        INSERT INTO logs_cambios_campos (tabla_nombre, registro_id, campo_modificado, valor_anterior, valor_nuevo, usuario_app_id, db_user, connection_id, ip_origen, created_at)
        VALUES ('tratamientos_autorizados', NEW.id, 'fecha_vencimiento_recipe', CAST(OLD.fecha_vencimiento_recipe AS CHAR), CAST(NEW.fecha_vencimiento_recipe AS CHAR), @app_user_id, CURRENT_USER(), CONNECTION_ID(), v_ip, NOW());
    END IF;

    IF NOT (OLD.medico_prescriptor <=> NEW.medico_prescriptor) THEN
        INSERT INTO logs_cambios_campos (tabla_nombre, registro_id, campo_modificado, valor_anterior, valor_nuevo, usuario_app_id, db_user, connection_id, ip_origen, created_at)
        VALUES ('tratamientos_autorizados', NEW.id, 'medico_prescriptor', OLD.medico_prescriptor, NEW.medico_prescriptor, @app_user_id, CURRENT_USER(), CONNECTION_ID(), v_ip, NOW());
    END IF;

    IF NOT (OLD.activo <=> NEW.activo) THEN
        INSERT INTO logs_cambios_campos (tabla_nombre, registro_id, campo_modificado, valor_anterior, valor_nuevo, usuario_app_id, db_user, connection_id, ip_origen, created_at)
        VALUES ('tratamientos_autorizados', NEW.id, 'activo', CAST(OLD.activo AS CHAR), CAST(NEW.activo AS CHAR), @app_user_id, CURRENT_USER(), CONNECTION_ID(), v_ip, NOW());
    END IF;
END$$

DELIMITER ;
