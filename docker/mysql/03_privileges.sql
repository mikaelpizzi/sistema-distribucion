-- ============================================================================
-- PRIVILEGIOS DE MENOR ACCESO (LEAST PRIVILEGE) PARA USUARIO DE APLICACIÓN
-- Archivo: docker/mysql/03_privileges.sql
-- Base de datos: control_medicamentos_red
-- Usuario objetivo: app_salud_user
-- ============================================================================

USE control_medicamentos_red;

-- 1. Asegurar existencia del usuario si no fue creado previamente
CREATE USER IF NOT EXISTS 'app_salud_user'@'%' IDENTIFIED BY 'app_password_seguro_2026';

-- 2. Revocar privilegios globales o amplios creados por variables de entorno
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'app_salud_user'@'%';

-- 3. Otorgar permisos granulares de menor acceso por tabla / vista / rutina

-- Tablas Maestras y Operativas con permiso de Lectura y Modificación
GRANT SELECT, INSERT, UPDATE ON control_medicamentos_red.configuracion_institucional TO 'app_salud_user'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON control_medicamentos_red.roles TO 'app_salud_user'@'%';
GRANT SELECT, INSERT, UPDATE ON control_medicamentos_red.centros_salud TO 'app_salud_user'@'%';
GRANT SELECT, INSERT, UPDATE ON control_medicamentos_red.medicamentos TO 'app_salud_user'@'%';
GRANT SELECT, INSERT, UPDATE ON control_medicamentos_red.patologias TO 'app_salud_user'@'%';
GRANT SELECT, INSERT, UPDATE ON control_medicamentos_red.formas_farmaceuticas TO 'app_salud_user'@'%';
GRANT SELECT, INSERT, UPDATE ON control_medicamentos_red.tipos_centro_salud TO 'app_salud_user'@'%';
GRANT SELECT, INSERT, UPDATE ON control_medicamentos_red.recursos_operativos TO 'app_salud_user'@'%';
GRANT SELECT, INSERT, UPDATE ON control_medicamentos_red.lotes_inventario TO 'app_salud_user'@'%';
GRANT SELECT, INSERT, UPDATE ON control_medicamentos_red.pacientes TO 'app_salud_user'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON control_medicamentos_red.paciente_contactos TO 'app_salud_user'@'%';
GRANT SELECT, INSERT, UPDATE ON control_medicamentos_red.telefonos_centro_salud TO 'app_salud_user'@'%';
GRANT SELECT, INSERT, UPDATE ON control_medicamentos_red.tratamientos_autorizados TO 'app_salud_user'@'%';
GRANT SELECT, INSERT, UPDATE ON control_medicamentos_red.notificaciones_sistema TO 'app_salud_user'@'%';
GRANT SELECT, INSERT, UPDATE ON control_medicamentos_red.tokens_seguridad TO 'app_salud_user'@'%';
GRANT SELECT, INSERT, UPDATE ON control_medicamentos_red.usuarios TO 'app_salud_user'@'%';

-- Tablas Transaccionales y de Auditoría Inmutable (WORM: Solo SELECT e INSERT, prohibido UPDATE y DELETE)
GRANT SELECT, INSERT ON control_medicamentos_red.despachos TO 'app_salud_user'@'%';
GRANT SELECT, INSERT ON control_medicamentos_red.despacho_detalles TO 'app_salud_user'@'%';
GRANT SELECT, INSERT ON control_medicamentos_red.logs_auditoria TO 'app_salud_user'@'%';
GRANT SELECT, INSERT ON control_medicamentos_red.logs_cambios_campos TO 'app_salud_user'@'%';

-- Tablas de Catálogo y Seguridad de Configuración (Solo Lectura)
GRANT SELECT ON control_medicamentos_red.modulos_sistema TO 'app_salud_user'@'%';
GRANT SELECT ON control_medicamentos_red.permisos TO 'app_salud_user'@'%';
GRANT SELECT ON control_medicamentos_red.tipos_documento TO 'app_salud_user'@'%';
GRANT SELECT ON control_medicamentos_red.tipos_contacto TO 'app_salud_user'@'%';

-- Tablas Intermedias que requieren asignación y desasignación
GRANT SELECT, INSERT, DELETE ON control_medicamentos_red.rol_permisos TO 'app_salud_user'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON control_medicamentos_red.centro_recursos TO 'app_salud_user'@'%';

-- Vistas del Sistema (Solo Lectura)
GRANT SELECT ON control_medicamentos_red.vw_centros_salud TO 'app_salud_user'@'%';
GRANT SELECT ON control_medicamentos_red.vw_logs_auditoria TO 'app_salud_user'@'%';
GRANT SELECT ON control_medicamentos_red.vw_inventario_fefo_activo TO 'app_salud_user'@'%';

-- Rutinas Almacenadas y Funciones Deterministas
GRANT EXECUTE ON PROCEDURE control_medicamentos_red.sp_procesar_despacho_fefo TO 'app_salud_user'@'%';
GRANT EXECUTE ON FUNCTION control_medicamentos_red.fn_evaluar_caducidad_lote TO 'app_salud_user'@'%';
GRANT EXECUTE ON FUNCTION control_medicamentos_red.fn_evaluar_nivel_stock TO 'app_salud_user'@'%';

-- 4. Aplicar cambios inmediatamente en el catálogo de permisos (app_salud_user solo posee los GRANT explícitos anteriores, sin permisos DDL ni DROP)
FLUSH PRIVILEGES;
