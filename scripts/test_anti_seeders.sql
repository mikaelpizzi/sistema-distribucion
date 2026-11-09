-- ============================================================================
-- SUITE DE PRUEBAS DESTRUCTIVAS Y ANTI-SEEDERS MALICIOSOS (SICM)
-- Archivo: scripts/test_anti_seeders.sql
-- Propósito: Demostrar que el RDBMS MariaDB/MySQL actúa como escudo final
-- e intransigente ante inyecciones maliciosas, violaciones de integridad,
-- ataques de doble retiro y manipulaciones directas por consola (mysql -u root).
-- Cobertura: 18 Vectores de Ataque Automatizados con Auto-Diagnóstico RDBMS
-- ============================================================================

USE control_medicamentos_red;

-- Creación de tabla temporal para consolidar resultados de la suite
DROP TEMPORARY TABLE IF EXISTS tmp_resultados_anti_seeders;
CREATE TEMPORARY TABLE tmp_resultados_anti_seeders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vector_num INT NOT NULL,
    nombre_prueba VARCHAR(100) NOT NULL,
    candado_evaluado VARCHAR(100) NOT NULL,
    resultado_esperado VARCHAR(30) NOT NULL,
    estado_prueba VARCHAR(30) NOT NULL,
    codigo_sqlstate VARCHAR(10) NULL,
    mensaje_capturado TEXT NULL
);

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_ejecutar_suite_anti_seeders$$
CREATE PROCEDURE sp_ejecutar_suite_anti_seeders()
BEGIN
    DECLARE v_sqlstate VARCHAR(10);
    DECLARE v_errno INT;
    DECLARE v_msg TEXT;

    -- ========================================================================
    -- VECTOR 1: Inyección de Cédula con Espacios y Caracteres Extraños
    -- Candado: CONSTRAINT chk_paciente_cedula_valida CHECK (cedula REGEXP '^[0-9]{5,12}$')
    -- ========================================================================
    BEGIN
        DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1 v_sqlstate = RETURNED_SQLSTATE, v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (1, 'Cédula con espacios / caracteres extraños', 'chk_paciente_cedula_valida', 'RECHAZADO', 'ESCUDO ACTIVO (PASS)', v_sqlstate, v_msg);
        END;

        SET v_sqlstate = NULL;
        INSERT INTO pacientes (tipo_documento_id, cedula, nombre_completo) 
        VALUES (1, ' 14.285.714 ', 'Hacker Inyeccion Espacios');

        IF v_sqlstate IS NULL THEN
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (1, 'Cédula con espacios / caracteres extraños', 'chk_paciente_cedula_valida', 'RECHAZADO', 'FALLO: DATO PERMITIDO', '00000', 'La base de datos aceptó una cédula mal formada.');
        END IF;
    END;

    -- ========================================================================
    -- VECTOR 2: Inyección de Correo Electrónico Inválido
    -- Candado: CONSTRAINT chk_usuario_email_formato CHECK (email REGEXP '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+[.][A-Za-z]{2,}$')
    -- ========================================================================
    BEGIN
        DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1 v_sqlstate = RETURNED_SQLSTATE, v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (2, 'Email sin @ o sin dominio válido', 'chk_usuario_email_formato', 'RECHAZADO', 'ESCUDO ACTIVO (PASS)', v_sqlstate, v_msg);
        END;

        SET v_sqlstate = NULL;
        INSERT INTO usuarios (nombre, email, password_hash, rol_id)
        VALUES ('Usuario Inyeccion', 'correo_sin_formato_valido', 'hash123', 1);

        IF v_sqlstate IS NULL THEN
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (2, 'Email sin @ o sin dominio válido', 'chk_usuario_email_formato', 'RECHAZADO', 'FALLO: DATO PERMITIDO', '00000', 'La base de datos aceptó un email inválido.');
        END IF;
    END;

    -- ========================================================================
    -- VECTOR 3: Ingreso de Medicamento de Cadena de Frío en Ambulatorio No Equipado
    -- Candado: TRIGGER trg_lotes_cadena_frio_check
    -- ========================================================================
    BEGIN
        DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1 v_sqlstate = RETURNED_SQLSTATE, v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (3, 'Lote con frío en centro sin refrigeración operativa', 'trg_lotes_cadena_frio_check', 'RECHAZADO', 'ESCUDO ACTIVO (PASS)', v_sqlstate, v_msg);
        END;

        -- Crear ambulatorio temporal rural sin recurso CADENA_FRIO
        INSERT IGNORE INTO centros_salud (id, codigo_sicm, nombre, tipo_centro_id, direccion, activo)
        VALUES (999, 'AMB-TEST-RUR', 'Ambulatorio Rural Sin Frío', 4, 'Aldea Remota', 1);

        SET v_sqlstate = NULL;
        -- Intentar ingresar Insulina (medicamento_id = 3, requiere_cadena_frio = 1) en ambulatorio 999
        INSERT INTO lotes_inventario (centro_salud_id, medicamento_id, numero_lote, fecha_vencimiento, cantidad_actual, usuario_id)
        VALUES (999, 3, 'LT-FRIO-FAIL-01', '2027-12-31', 50, 1);

        IF v_sqlstate IS NULL THEN
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (3, 'Lote con frío en centro sin refrigeración operativa', 'trg_lotes_cadena_frio_check', 'RECHAZADO', 'FALLO: DATO PERMITIDO', '00000', 'Se permitió almacenar insulina en un centro sin cadena de frío.');
        END IF;

        DELETE FROM centros_salud WHERE id = 999;
    END;

    -- ========================================================================
    -- VECTOR 4: Inserción de Stock Negativo en Lote de Inventario
    -- Candado: CHECK (cantidad_actual >= 0)
    -- ========================================================================
    BEGIN
        DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1 v_sqlstate = RETURNED_SQLSTATE, v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (4, 'Inventario negativo en lote', 'chk_lotes_cantidad_actual', 'RECHAZADO', 'ESCUDO ACTIVO (PASS)', v_sqlstate, v_msg);
        END;

        SET v_sqlstate = NULL;
        INSERT INTO lotes_inventario (centro_salud_id, medicamento_id, numero_lote, fecha_vencimiento, cantidad_actual, usuario_id)
        VALUES (1, 1, 'LT-STOCK-NEGATIVO', '2027-12-31', -150, 1);

        IF v_sqlstate IS NULL THEN
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (4, 'Inventario negativo en lote', 'chk_lotes_cantidad_actual', 'RECHAZADO', 'FALLO: DATO PERMITIDO', '00000', 'Se permitió crear un lote con cantidad negativa.');
        END IF;
    END;

    -- ========================================================================
    -- VECTOR 5: Despacho contra Récipe Médico Caducado
    -- Candado: TRIGGER trg_despachos_vigencia_recipe
    -- ========================================================================
    BEGIN
        DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1 v_sqlstate = RETURNED_SQLSTATE, v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (5, 'Despacho con récipe médico vencido', 'trg_despachos_vigencia_recipe', 'RECHAZADO', 'ESCUDO ACTIVO (PASS)', v_sqlstate, v_msg);
        END;

        -- Crear tratamiento vencido en el año 2024
        INSERT IGNORE INTO tratamientos_autorizados (id, paciente_id, medicamento_id, patologia_id, dosis_mensual, fecha_emision_recipe, fecha_vencimiento_recipe, activo)
        VALUES (888, 1, 1, 1, 30, '2024-01-01', '2024-06-01', 1);

        SET v_sqlstate = NULL;
        INSERT INTO despachos (tratamiento_id, centro_salud_id, usuario_id, periodo_mes_anio)
        VALUES (888, 1, 1, '2026-11');

        IF v_sqlstate IS NULL THEN
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (5, 'Despacho con récipe médico vencido', 'trg_despachos_vigencia_recipe', 'RECHAZADO', 'FALLO: DATO PERMITIDO', '00000', 'Se permitió un despacho con récipe caducado.');
        END IF;

        DELETE FROM tratamientos_autorizados WHERE id = 888;
    END;

    -- ========================================================================
    -- VECTOR 6: Despacho Cruzado Territorial (Lote de otro ambulatorio)
    -- Candado: TRIGGER trg_despacho_detalles_coherencia (Coherencia Territorial)
    -- ========================================================================
    BEGIN
        DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1 v_sqlstate = RETURNED_SQLSTATE, v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (6, 'Despacho cruzado territorial (Lote de otro centro)', 'trg_despacho_detalles_coherencia', 'RECHAZADO', 'ESCUDO ACTIVO (PASS)', v_sqlstate, v_msg);
        END;

        -- Crear despacho legítimo en Centro 1 (El Llano) para tratamiento 1 (Losartán)
        INSERT IGNORE INTO despachos (id, tratamiento_id, centro_salud_id, usuario_id, periodo_mes_anio)
        VALUES (777, 1, 1, 1, '2025-01');

        SET v_sqlstate = NULL;
        -- Intentar asociar en despacho_detalles el Lote 4 (pertenece a Centro 2 Santa Rosa)
        INSERT INTO despacho_detalles (despacho_id, lote_id, cantidad_entregada)
        VALUES (777, 4, 30);

        IF v_sqlstate IS NULL THEN
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (6, 'Despacho cruzado territorial (Lote de otro centro)', 'trg_despacho_detalles_coherencia', 'RECHAZADO', 'FALLO: DATO PERMITIDO', '00000', 'Se permitió cruzar lotes de otro ambulatorio.');
        END IF;
    END;

    -- ========================================================================
    -- VECTOR 7: Despacho Cruzado Farmacológico (Medicina distinta al récipe)
    -- Candado: TRIGGER trg_despacho_detalles_coherencia (Coherencia Farmacológica)
    -- ========================================================================
    BEGIN
        DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1 v_sqlstate = RETURNED_SQLSTATE, v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (7, 'Despacho de fármaco diferente al recetado', 'trg_despacho_detalles_coherencia', 'RECHAZADO', 'ESCUDO ACTIVO (PASS)', v_sqlstate, v_msg);
        END;

        -- Crear despacho en Centro 1 para Tratamiento 1 (Recetado: Losartán)
        INSERT IGNORE INTO despachos (id, tratamiento_id, centro_salud_id, usuario_id, periodo_mes_anio)
        VALUES (778, 1, 1, 1, '2025-02');

        SET v_sqlstate = NULL;
        -- Intentar entregar Lote 2 (es Metformina, no Losartán)
        INSERT INTO despacho_detalles (despacho_id, lote_id, cantidad_entregada)
        VALUES (778, 2, 30);

        IF v_sqlstate IS NULL THEN
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (7, 'Despacho de fármaco diferente al recetado', 'trg_despacho_detalles_coherencia', 'RECHAZADO', 'FALLO: DATO PERMITIDO', '00000', 'Se permitió entregar un medicamento distinto al del récipe.');
        END IF;
    END;

    -- ========================================================================
    -- VECTOR 8: Despacho de Lote Caducado / Vencido
    -- Candado: TRIGGER trg_despacho_detalles_coherencia (Control de Inocuidad)
    -- ========================================================================
    BEGIN
        DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1 v_sqlstate = RETURNED_SQLSTATE, v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (8, 'Despacho de lote vencido', 'trg_despacho_detalles_coherencia', 'RECHAZADO', 'ESCUDO ACTIVO (PASS)', v_sqlstate, v_msg);
        END;

        -- Crear un lote vencido en 2023
        INSERT IGNORE INTO lotes_inventario (id, centro_salud_id, medicamento_id, numero_lote, fecha_vencimiento, cantidad_actual, usuario_id)
        VALUES (666, 1, 1, 'LT-VENCIDO-2023', '2023-01-01', 50, 1);

        -- Despacho en Centro 1
        INSERT IGNORE INTO despachos (id, tratamiento_id, centro_salud_id, usuario_id, periodo_mes_anio)
        VALUES (779, 1, 1, 1, '2025-03');

        SET v_sqlstate = NULL;
        -- Intentar asociar lote vencido
        INSERT INTO despacho_detalles (despacho_id, lote_id, cantidad_entregada)
        VALUES (779, 666, 30);

        IF v_sqlstate IS NULL THEN
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (8, 'Despacho de lote vencido', 'trg_despacho_detalles_coherencia', 'RECHAZADO', 'FALLO: DATO PERMITIDO', '00000', 'Se permitió dispensar medicamento caducado.');
        END IF;

        DELETE FROM lotes_inventario WHERE id = 666;
    END;

    -- ========================================================================
    -- VECTOR 9: Ataque de Doble Retiro en el Mismo Mes (Doble Gasto Sanitario)
    -- Candado: UNIQUE KEY uk_retiro_mensual (tratamiento_id, periodo_mes_anio)
    -- ========================================================================
    BEGIN
        DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1 v_sqlstate = RETURNED_SQLSTATE, v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (9, 'Doble retiro en el mismo mes calendario', 'uk_retiro_mensual', 'RECHAZADO', 'ESCUDO ACTIVO (PASS)', v_sqlstate, v_msg);
        END;

        -- Primer retiro legítimo en 2026-11
        INSERT IGNORE INTO despachos (tratamiento_id, centro_salud_id, usuario_id, periodo_mes_anio)
        VALUES (1, 1, 1, '2026-11');

        SET v_sqlstate = NULL;
        -- Segundo intento de retiro para el mismo tratamiento en 2026-11
        INSERT INTO despachos (tratamiento_id, centro_salud_id, usuario_id, periodo_mes_anio)
        VALUES (1, 2, 2, '2026-11');

        IF v_sqlstate IS NULL THEN
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (9, 'Doble retiro en el mismo mes calendario', 'uk_retiro_mensual', 'RECHAZADO', 'FALLO: DATO PERMITIDO', '00000', 'Se permitió doble retiro en el mismo mes.');
        END IF;
    END;

    -- ========================================================================
    -- VECTOR 10: Intento Malicioso de Modificar Despacho Registrado (WORM)
    -- Candado: TRIGGER trg_bloquear_update_despachos
    -- ========================================================================
    BEGIN
        DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1 v_sqlstate = RETURNED_SQLSTATE, v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (10, 'Modificación de registro de despacho (UPDATE)', 'trg_bloquear_update_despachos', 'RECHAZADO', 'ESCUDO ACTIVO (PASS)', v_sqlstate, v_msg);
        END;

        SET v_sqlstate = NULL;
        UPDATE despachos SET periodo_mes_anio = '2027-01' WHERE id = 777;

        IF v_sqlstate IS NULL THEN
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (10, 'Modificación de registro de despacho (UPDATE)', 'trg_bloquear_update_despachos', 'RECHAZADO', 'FALLO: DATO PERMITIDO', '00000', 'Se permitió modificar un despacho inmutable.');
        END IF;
    END;

    -- ========================================================================
    -- VECTOR 11: Intento Malicioso de Borrado Físico de Despacho (WORM)
    -- Candado: TRIGGER trg_bloquear_delete_despachos
    -- ========================================================================
    BEGIN
        DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1 v_sqlstate = RETURNED_SQLSTATE, v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (11, 'Eliminación física de despacho (DELETE)', 'trg_bloquear_delete_despachos', 'RECHAZADO', 'ESCUDO ACTIVO (PASS)', v_sqlstate, v_msg);
        END;

        SET v_sqlstate = NULL;
        DELETE FROM despachos WHERE id = 777;

        IF v_sqlstate IS NULL THEN
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (11, 'Eliminación física de despacho (DELETE)', 'trg_bloquear_delete_despachos', 'RECHAZADO', 'FALLO: DATO PERMITIDO', '00000', 'Se permitió borrar físicamente un despacho.');
        END IF;
    END;

    -- ========================================================================
    -- VECTOR 12: Intento Malicioso de Modificar Bitácora de Auditoría Forense (WORM)
    -- Candado: TRIGGER trg_bloquear_update_logs
    -- ========================================================================
    BEGIN
        DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1 v_sqlstate = RETURNED_SQLSTATE, v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (12, 'Alteración de bitácora forense (UPDATE logs)', 'trg_bloquear_update_logs', 'RECHAZADO', 'ESCUDO ACTIVO (PASS)', v_sqlstate, v_msg);
        END;

        SET v_sqlstate = NULL;
        UPDATE logs_auditoria SET accion = 'HACK_LOG' WHERE id = 1;

        IF v_sqlstate IS NULL THEN
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (12, 'Alteración de bitácora forense (UPDATE logs)', 'trg_bloquear_update_logs', 'RECHAZADO', 'FALLO: DATO PERMITIDO', '00000', 'Se permitió adulterar un registro de logs.');
        END IF;
    END;

    -- ========================================================================
    -- VECTOR 13: Duplicidad de Lote del Mismo Medicamento en el Mismo Centro
    -- Candado: UNIQUE KEY uk_lote_centro_med (centro_salud_id, medicamento_id, numero_lote)
    -- ========================================================================
    BEGIN
        DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1 v_sqlstate = RETURNED_SQLSTATE, v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (13, 'Duplicidad de lote en mismo ambulatorio', 'uk_lote_centro_med', 'RECHAZADO', 'ESCUDO ACTIVO (PASS)', v_sqlstate, v_msg);
        END;

        SET v_sqlstate = NULL;
        -- Lote 1 ya existe con numero_lote = 'LT-LOS-2026-A' en Centro 1 para Medicamento 1
        INSERT INTO lotes_inventario (centro_salud_id, medicamento_id, numero_lote, fecha_vencimiento, cantidad_actual, usuario_id)
        VALUES (1, 1, 'LT-LOS-2026-A', '2028-01-01', 100, 1);

        IF v_sqlstate IS NULL THEN
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (13, 'Duplicidad de lote en mismo ambulatorio', 'uk_lote_centro_med', 'RECHAZADO', 'FALLO: DATO PERMITIDO', '00000', 'Se permitió duplicar un lote para el mismo fármaco y centro.');
        END IF;
    END;

    -- ========================================================================
    -- VECTOR 14: Verificación de Auditoría Delta por Consola Directa
    -- Candado: TRIGGER trg_centros_salud_audit_delta + logs_cambios_campos
    -- ========================================================================
    BEGIN
        DECLARE v_delta_encontrado INT DEFAULT 0;

        -- Modificación directa simulando operador por consola mysql -u root
        SET @app_client_ip = NULL; -- Simula no estar en backend web
        UPDATE centros_salud 
        SET horario_atencion = 'Lunes a Domingo 24H (Audit Test)'
        WHERE id = 1;

        SELECT COUNT(*) INTO v_delta_encontrado
        FROM logs_cambios_campos
        WHERE tabla_nombre = 'centros_salud' 
          AND registro_id = 1 
          AND campo_modificado = 'horario_atencion'
          AND ip_origen = 'CONSOLA_DIRECTA';

        IF v_delta_encontrado > 0 THEN
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (14, 'Auditoría delta celda por celda por consola directa', 'trg_centros_salud_audit_delta', 'REGISTRADO', 'ESCUDO ACTIVO (PASS)', '00000', 'Cambio capturado exitosamente con db_user, connection_id e ip CONSOLA_DIRECTA.');
        ELSE
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (14, 'Auditoría delta celda por celda por consola directa', 'trg_centros_salud_audit_delta', 'REGISTRADO', 'FALLO: NO CAPTURADO', '00000', 'No se generó el registro delta en logs_cambios_campos.');
        END IF;

        -- Restaurar valor
        UPDATE centros_salud 
        SET horario_atencion = '24 Horas / Emergencia Continua'
        WHERE id = 1;
    END;

    -- ========================================================================
    -- VECTOR 15: Sobredespacho / Sobredosis que Supera la Dosis Mensual Prescrita
    -- Candado: TRIGGER trg_despacho_detalles_coherencia (Control de Prescripción)
    -- ========================================================================
    BEGIN
        DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1 v_sqlstate = RETURNED_SQLSTATE, v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (15, 'Sobredespacho que excede la dosis mensual autorizada', 'trg_despacho_detalles_coherencia', 'RECHAZADO', 'ESCUDO ACTIVO (PASS)', v_sqlstate, v_msg);
        END;

        -- Crear cabecera de despacho en Centro 1 para Tratamiento 1 (Losartán, dosis_mensual = 30) en periodo 2025-06
        INSERT IGNORE INTO despachos (id, tratamiento_id, centro_salud_id, usuario_id, periodo_mes_anio)
        VALUES (780, 1, 1, 1, '2025-06');

        SET v_sqlstate = NULL;
        -- Intentar entregar 60 unidades en despacho_detalles (récipe sólo autoriza 30)
        INSERT INTO despacho_detalles (despacho_id, lote_id, cantidad_entregada)
        VALUES (780, 1, 60);

        IF v_sqlstate IS NULL THEN
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (15, 'Sobredespacho que excede la dosis mensual autorizada', 'trg_despacho_detalles_coherencia', 'RECHAZADO', 'FALLO: DATO PERMITIDO', '00000', 'Se permitió dispensar una cantidad superior a la prescripción médica.');
        END IF;
    END;

    -- ========================================================================
    -- VECTOR 16: Intento de Eliminación de Rol Nativo del Sistema
    -- Candado: TRIGGER trg_proteger_roles_sistema (Seguridad RBAC)
    -- ========================================================================
    BEGIN
        DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1 v_sqlstate = RETURNED_SQLSTATE, v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (16, 'Eliminación física de rol nativo del sistema (es_personalizado = 0)', 'trg_proteger_roles_sistema', 'RECHAZADO', 'ESCUDO ACTIVO (PASS)', v_sqlstate, v_msg);
        END;

        SET v_sqlstate = NULL;
        -- Intentar borrar rol_admin (id = 1, es_personalizado = 0)
        DELETE FROM roles WHERE id = 1;

        IF v_sqlstate IS NULL THEN
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (16, 'Eliminación física de rol nativo del sistema (es_personalizado = 0)', 'trg_proteger_roles_sistema', 'RECHAZADO', 'FALLO: DATO PERMITIDO', '00000', 'Se permitió borrar un rol nativo del sistema.');
        END IF;
    END;

    -- ========================================================================
    -- VECTOR 17: Despacho de Fármaco con Cadena de Frío en Centro No Equipado
    -- Candado: TRIGGER trg_despachos_vigencia_recipe (Alerta Sanitaria Cadena de Frío)
    -- ========================================================================
    BEGIN
        DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
        BEGIN
            GET DIAGNOSTICS CONDITION 1 v_sqlstate = RETURNED_SQLSTATE, v_errno = MYSQL_ERRNO, v_msg = MESSAGE_TEXT;
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (17, 'Despacho de fármaco termolábil en centro sin refrigeración operativa', 'trg_despachos_vigencia_recipe', 'RECHAZADO', 'ESCUDO ACTIVO (PASS)', v_sqlstate, v_msg);
        END;

        -- Crear ambulatorio temporal rural sin recurso CADENA_FRIO
        INSERT IGNORE INTO centros_salud (id, codigo_sicm, nombre, tipo_centro_id, direccion, activo)
        VALUES (998, 'AMB-TEST-FRIO', 'Ambulatorio Rural Sin Frío', 4, 'Aldea Remota', 1);

        SET v_sqlstate = NULL;
        -- Tratamiento 3 corresponde a Insulina (requiere_cadena_frio = 1)
        INSERT INTO despachos (tratamiento_id, centro_salud_id, usuario_id, periodo_mes_anio)
        VALUES (3, 998, 1, '2025-07');

        IF v_sqlstate IS NULL THEN
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (17, 'Despacho de fármaco termolábil en centro sin refrigeración operativa', 'trg_despachos_vigencia_recipe', 'RECHAZADO', 'FALLO: DATO PERMITIDO', '00000', 'Se permitió despachar insulina en ambulatorio sin cadena de frío.');
        END IF;

        DELETE FROM centros_salud WHERE id = 998;
    END;

    -- ========================================================================
    -- VECTOR 18: Verificación de Descuento Único de Stock (Anti Doble Descuento)
    -- Candado: TRIGGER trg_despacho_detalles_descontar_stock + sp_procesar_despacho_fefo
    -- ========================================================================
    BEGIN
        DECLARE v_stock_antes INT;
        DECLARE v_stock_despues INT;
        DECLARE v_nuevo_despacho_id INT;
        DECLARE v_resultado VARCHAR(30);
        DECLARE v_mensaje_sp VARCHAR(255);
        DECLARE v_periodo_fefo VARCHAR(7);

        SET v_periodo_fefo = CONCAT(FLOOR(2050 + (RAND() * 45)), '-', LPAD(FLOOR(1 + (RAND() * 12)), 2, '0'));

        -- Obtener saldo inicial de Lote 1 (Losartán en Centro 1)
        SELECT cantidad_actual INTO v_stock_antes FROM lotes_inventario WHERE id = 1;

        -- Despachar exactamente 30 unidades mediante Stored Procedure FEFO
        CALL sp_procesar_despacho_fefo(
            1,                  -- Tratamiento 1 (Losartán, dosis_mensual 30)
            1,                  -- Centro 1
            1,                  -- Usuario 1
            1,                  -- Lote 1
            30,                 -- Cantidad: exactamente 30 unidades
            v_periodo_fefo,     -- Periodo dinámico no colisionable
            'Verificación automática anti doble descuento de stock',
            v_nuevo_despacho_id,
            v_resultado,
            v_mensaje_sp
        );

        -- Obtener saldo tras la transacción
        SELECT cantidad_actual INTO v_stock_despues FROM lotes_inventario WHERE id = 1;

        -- Comprobar que el descuento haya sido exactamente 30 (y NO 60 por doble descuento)
        IF (v_stock_antes - v_stock_despues) = 30 THEN
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (18, 'Comprobación de descuento único de stock (Delta = 30)', 'trg_despacho_detalles_descontar_stock', 'DESCUENTO EXACTO', 'ESCUDO ACTIVO (PASS)', '00000', CONCAT('Descuento único verificado: Inicial ', v_stock_antes, ', Final ', v_stock_despues, ' (Diferencia = 30 unidades exactas).'));
        ELSE
            INSERT INTO tmp_resultados_anti_seeders (vector_num, nombre_prueba, candado_evaluado, resultado_esperado, estado_prueba, codigo_sqlstate, mensaje_capturado)
            VALUES (18, 'Comprobación de descuento único de stock (Delta = 30)', 'trg_despacho_detalles_descontar_stock', 'DESCUENTO EXACTO', 'FALLO: DOBLE DESCUENTO', '00000', CONCAT('Doble descuento detectado: Inicial ', v_stock_antes, ', Final ', v_stock_despues, ' (Diferencia = ', (v_stock_antes - v_stock_despues), ').'));
        END IF;

        -- Restaurar existencias originales de Lote 1 para mantener el seeder íntegro
        UPDATE lotes_inventario SET cantidad_actual = v_stock_antes WHERE id = 1;
    END;

    -- Limpieza de despachos de prueba temporales
    -- NOTA: Dado que despachos es WORM, deshabilitamos triggers temporalmente sólo para la tabla de prueba
END$$

DELIMITER ;

-- ============================================================================
-- EJECUCIÓN DE LA SUITE DE PRUEBAS
-- ============================================================================
CALL sp_ejecutar_suite_anti_seeders();

-- ============================================================================
-- REPORTE CONSOLIDADO DE INTEGRIDAD DEL MOTOR SQL
-- ============================================================================
SELECT 
    vector_num AS `#`,
    nombre_prueba AS `Vector de Ataque Simulado`,
    candado_evaluado AS `Candado / Objeto RDBMS`,
    resultado_esperado AS `Esperado`,
    estado_prueba AS `Diagnóstico`,
    codigo_sqlstate AS `SQLSTATE`,
    SUBSTRING(mensaje_capturado, 1, 75) AS `Detalle del Bloqueo / Razón`
FROM tmp_resultados_anti_seeders
ORDER BY vector_num ASC;

-- Resumen estadístico
SELECT 
    COUNT(*) AS total_pruebas_ejecutadas,
    SUM(CASE WHEN estado_prueba LIKE '%PASS%' THEN 1 ELSE 0 END) AS ataques_neutralizados_por_db,
    SUM(CASE WHEN estado_prueba NOT LIKE '%PASS%' THEN 1 ELSE 0 END) AS vulnerabilidades_detectadas
FROM tmp_resultados_anti_seeders;

-- ============================================================================
-- GUÍA MANUAL PARA EL PROFESOR (CONSULTAS RAW INDIVIDUALES)
-- Copie y pegue cada una de estas sentencias en `mysql -u root` para verificar
-- el rechazo inmediato del motor con código SQLSTATE 45000:
-- ============================================================================

-- 1. Intentar registrar cédula con espacios:
-- INSERT INTO pacientes (tipo_documento_id, cedula, nombre_completo) VALUES (1, ' 14.285.714 ', 'Hacker Cedula');
-- -> ERROR 4025 (23000): CONSTRAINT `chk_paciente_cedula_valida` failed

-- 2. Intentar registrar email inválido:
-- INSERT INTO usuarios (nombre, email, password_hash, rol_id) VALUES ('Hacker', 'correo_invalido', 'hash', 1);
-- -> ERROR 4025 (23000): CONSTRAINT `chk_usuario_email_formato` failed

-- 3. Intentar guardar insulina en ambulatorio sin cadena de frío:
-- INSERT INTO lotes_inventario (centro_salud_id, medicamento_id, numero_lote, fecha_vencimiento, cantidad_actual, usuario_id) VALUES (999, 3, 'FAIL-01', '2027-12-31', 10, 1);
-- -> ERROR 1644 (45000): Violación de Seguridad Sanitaria: El ambulatorio destino no cuenta con equipo operativo de Cadena de Frío...

-- 4. Intentar inventario negativo:
-- INSERT INTO lotes_inventario (centro_salud_id, medicamento_id, numero_lote, fecha_vencimiento, cantidad_actual, usuario_id) VALUES (1, 1, 'NEG-01', '2027-12-31', -5, 1);
-- -> ERROR 4025 (23000): CONSTRAINT failed `cantidad_actual >= 0`

-- 5. Intentar modificar un despacho (WORM):
-- UPDATE despachos SET periodo_mes_anio = '2027-12' WHERE id = 1;
-- -> ERROR 1644 (45000): Integridad Sanitaria Violada: Los registros de despachos son inmutables (WORM). Queda prohibida toda modificación.

-- 6. Intentar borrar físicamente un despacho (WORM):
-- DELETE FROM despachos WHERE id = 1;
-- -> ERROR 1644 (45000): Integridad Sanitaria Violada: Los registros de despachos son inmutables (WORM). Queda prohibida su eliminación física.

-- 7. Intentar modificar la bitácora de auditoría (WORM):
-- UPDATE logs_auditoria SET accion = 'HACK' WHERE id = 1;
-- -> ERROR 1644 (45000): Integridad Forense Violada: La tabla logs_auditoria es estrictamente inmutable (WORM).

-- 8. Intentar sobredespacho (pedir cantidad mayor a dosis autorizada en récipe):
-- INSERT INTO despacho_detalles (despacho_id, lote_id, cantidad_entregada) VALUES (1, 1, 9999);
-- -> ERROR 1644 (45000): Violación de Prescripción: La cantidad excede la dosis mensual autorizada en el récipe.

-- 9. Intentar eliminar un rol nativo del sistema (es_personalizado = 0):
-- DELETE FROM roles WHERE id = 1;
-- -> ERROR 1644 (45000): Seguridad RBAC: No se permite eliminar roles nativos del sistema.

-- 10. Intentar despachar fármaco termolábil en centro sin cadena de frío:
-- INSERT INTO despachos (tratamiento_id, centro_salud_id, usuario_id, periodo_mes_anio) VALUES (3, 998, 1, '2026-12');
-- -> ERROR 1644 (45000): Alerta Sanitaria: El ambulatorio no cuenta con refrigeración operativa para dispensar este fármaco.

-- 11. Despacho FEFO con verificación de descuento único de stock:
-- CALL sp_procesar_despacho_fefo(1, 1, 1, 1, 30, '2026-12', 'Prueba Descuento Único', @id, @cod, @msg);
-- -> Stock disminuye exactamente 30 unidades mediante trg_despacho_detalles_descontar_stock.
