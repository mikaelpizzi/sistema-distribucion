-- ============================================================================
-- SISTEMA INTEGRADO DE CONTROL DE MEDICAMENTOS (SICM) - AMBULATORIOS MUNICIPALES
-- Archivo: docker/mysql/04_views.sql
-- Módulo: Vistas Relacionales (Capa de Abstracción y Reporting)
-- ============================================================================

USE control_medicamentos_red;

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
