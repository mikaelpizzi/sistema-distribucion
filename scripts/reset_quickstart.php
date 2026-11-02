<?php
// src/scripts/reset_quickstart.php
// Prepara el entorno para probar o grabar el Asistente de Primer Arranque (Quick Start / setup.php)
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$pdo = getDB();

echo "=================================================================\n";
echo "  PREPARANDO ENTORNO PARA QUICK START (SETUP DE PRIMER ARRANQUE) \n";
echo "=================================================================\n";

try {
    // 1. Desactivar temporalmente restricciones para reinicio limpio
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

    // 2. Limpiar tablas transaccionales y de usuarios
    $pdo->exec("DROP TRIGGER IF EXISTS trg_bloquear_delete_logs;");
    $pdo->exec("DROP TRIGGER IF EXISTS trg_bloquear_update_logs;");
    
    $pdo->exec("TRUNCATE TABLE logs_auditoria;");
    if ($pdo->query("SHOW TABLES LIKE 'despacho_detalles'")->fetch()) {
        $pdo->exec("TRUNCATE TABLE despacho_detalles;");
    }
    if ($pdo->query("SHOW TABLES LIKE 'despachos'")->fetch()) {
        $pdo->exec("TRUNCATE TABLE despachos;");
    }
    if ($pdo->query("SHOW TABLES LIKE 'lotes_inventario'")->fetch()) {
        $pdo->exec("TRUNCATE TABLE lotes_inventario;");
    }
    if ($pdo->query("SHOW TABLES LIKE 'tratamientos_autorizados'")->fetch()) {
        $pdo->exec("TRUNCATE TABLE tratamientos_autorizados;");
    }
    if ($pdo->query("SHOW TABLES LIKE 'pacientes'")->fetch()) {
        $pdo->exec("TRUNCATE TABLE pacientes;");
    }
    $pdo->exec("TRUNCATE TABLE centro_recursos;");
    $pdo->exec("TRUNCATE TABLE centros_salud;");
    $pdo->exec("TRUNCATE TABLE usuarios;");

    // 3. Restaurar los Triggers WORM sobre logs_auditoria
    $pdo->exec("
        CREATE TRIGGER trg_bloquear_update_logs
        BEFORE UPDATE ON logs_auditoria
        FOR EACH ROW
        BEGIN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Integridad Forense Violada: La tabla logs_auditoria es estrictamente inmutable (WORM). No se permiten modificaciones.';
        END;
    ");

    $pdo->exec("
        CREATE TRIGGER trg_bloquear_delete_logs
        BEFORE DELETE ON logs_auditoria
        FOR EACH ROW
        BEGIN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Integridad Forense Violada: La tabla logs_auditoria es estrictamente inmutable (WORM). No se permiten eliminaciones físicas.';
        END;
    ");

    // 4. Reactivar Foreign Keys
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    // 5. Verificar que usuarios quedó en 0
    $totalUsuarios = (int)$pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
    
    if ($totalUsuarios === 0) {
        echo "1. [OK] Tabla 'usuarios' vaciada: 0 registros existentes.\n";
        echo "2. [OK] Bitácora 'logs_auditoria' reiniciada con triggers WORM activos.\n";
        echo "3. [OK] Catálogos maestros preservados para operación inmediata.\n";
        echo "=================================================================\n";
        echo "  LISTO: Abre en tu navegador http://localhost:8080/              \n";
        echo "  El sistema te redirigirá automáticamente a setup.php           \n";
        echo "=================================================================\n";
        exit(0);
    } else {
        echo "ERROR: La tabla de usuarios aún contiene registros ({$totalUsuarios}).\n";
        exit(1);
    }

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

