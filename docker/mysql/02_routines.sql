-- ============================================================================
-- SISTEMA INTEGRADO DE CONTROL DE MEDICAMENTOS (SICM) - AMBULATORIOS MUNICIPALES
-- Archivo: docker/mysql/02_routines.sql
-- Módulo: Funciones Determinísticas y Procedimientos Almacenados Transaccionales
-- ============================================================================

USE control_medicamentos_red;

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

