-- ============================================================================
-- SCRIPT DE RESETEO RÁPIDO DE DATOS OPERATIVOS Y TRANSACCIONALES
-- Archivo: scripts/reset_db.sql
-- Propósito: Vaciar tablas transaccionales en 2 segundos sin violar FK ni triggers WORM.
-- ============================================================================

USE control_medicamentos_red;

SET FOREIGN_KEY_CHECKS = 0;

-- Truncate de tablas operativas y transaccionales
TRUNCATE TABLE logs_cambios_campos;
TRUNCATE TABLE logs_auditoria;
TRUNCATE TABLE despacho_detalles;
TRUNCATE TABLE despachos;
TRUNCATE TABLE lotes_inventario;
TRUNCATE TABLE tratamientos_autorizados;
TRUNCATE TABLE paciente_contactos;
TRUNCATE TABLE pacientes;
TRUNCATE TABLE notificaciones_sistema;
TRUNCATE TABLE tokens_seguridad;

SET FOREIGN_KEY_CHECKS = 1;
