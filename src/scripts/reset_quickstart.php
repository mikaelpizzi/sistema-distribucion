<?php
// src/scripts/reset_quickstart.php
// Prepara el entorno para probar o grabar el Asistente de Primer Arranque (Quick Start / setup.php)
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$pdo = getDB();

echo "=================================================================\n";
echo "  PREPARANDO ENTORNO PARA QUICK START (REINICIO TOTAL DE BD)     \n";
echo "=================================================================\n";

try {
    // 1. Desactivar temporalmente restricciones de clave foránea
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

    // 2. Limpiar bitácora y disparadores WORM
    $pdo->exec("DROP TRIGGER IF EXISTS trg_bloquear_delete_logs;");
    $pdo->exec("DROP TRIGGER IF EXISTS trg_bloquear_update_logs;");
    $pdo->exec("TRUNCATE TABLE logs_auditoria;");

    // 3. Limpiar tablas transaccionales y asistenciales
    $tablasTransaccionales = [
        'despacho_detalles',
        'despachos',
        'lotes_inventario',
        'tratamientos_autorizados',
        'pacientes',
        'centro_recursos',
        'centros_salud',
        'usuarios'
    ];

    foreach ($tablasTransaccionales as $t) {
        if ($pdo->query("SHOW TABLES LIKE '{$t}'")->fetch()) {
            $pdo->exec("TRUNCATE TABLE {$t};");
        }
    }

    // 4. Limpiar y Restaurar Catálogos Maestros Parametrizables a su estado canónico
    // (Elimina registros de prueba como FVTYU, MOD_TEST, etc.)
    $pdo->exec("TRUNCATE TABLE tipos_centro_salud;");
    $pdo->exec("
        INSERT INTO tipos_centro_salud (id, codigo, nombre, descripcion, activo) VALUES
        (1, 'AMB_URB_1', 'Ambulatorio Urbano I', 'Atención médica general y preventiva sectorial', 1),
        (2, 'AMB_URB_2', 'Ambulatorio Urbano II', 'Medicina general y farmacia sectorial', 1),
        (3, 'AMB_URB_3', 'Ambulatorio Urbano III', 'Centro de salud cabecera de red y distribución', 1),
        (4, 'AMB_RUR', 'Ambulatorio Rural', 'Dispensario foráneo en parroquias rurales', 1),
        (5, 'CLIN_MUN', 'Clínica Popular Municipal', 'Centro de atención especializada y resolución', 1);
    ");

    $pdo->exec("TRUNCATE TABLE recursos_operativos;");
    $pdo->exec("
        INSERT INTO recursos_operativos (id, codigo, nombre, descripcion, activo) VALUES
        (1, 'CADENA_FRIO', 'Refrigeración / Cadena de Frío', 'Neveras grado médico certificadas para vacunas e insulinas (2°C - 8°C)', 1),
        (2, 'PLANTA_ELEC', 'Planta Eléctrica de Emergencia', 'Generador diésel auxiliar para contingencias eléctricas y soporte de frío', 1),
        (3, 'AMBULANCIA', 'Ambulancia de Soporte Vital', 'Unidad de traslado asistencial y atención médica prehospitalaria', 1),
        (4, 'ESTERILIZACION', 'Central de Esterilización', 'Autoclave hospitalario para instrumental quirúrgico y odontológico', 1),
        (5, 'FARMACIA_24H', 'Farmacia Nocturna / 24 Horas', 'Ventanilla de despacho continuo durante turnos nocturnos y festivos', 1);
    ");

    $pdo->exec("TRUNCATE TABLE roles;");
    $pdo->exec("
        INSERT INTO roles (id, nombre) VALUES 
        (1, 'rol_admin'),
        (2, 'rol_farmaceuta');
    ");

    $pdo->exec("TRUNCATE TABLE tipos_documento;");
    $pdo->exec("
        INSERT INTO tipos_documento (id, codigo, nombre) VALUES 
        (1, 'V', 'Venezolano'),
        (2, 'E', 'Extranjero'),
        (3, 'P', 'Pasaporte');
    ");

    $pdo->exec("TRUNCATE TABLE formas_farmaceuticas;");
    $pdo->exec("
        INSERT INTO formas_farmaceuticas (id, nombre) VALUES 
        (1, 'Tableta / Comprimido'),
        (2, 'Cápsula'),
        (3, 'Jarabe / Solución Oral'),
        (4, 'Suspensión'),
        (5, 'Ampolla Inyectable');
    ");

    // 5. Restaurar los Triggers WORM sobre logs_auditoria
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

    // 6. Reactivar Foreign Keys
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    // 7. Verificación final
    $totalUsuarios = (int)$pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
    $totalCentros  = (int)$pdo->query("SELECT COUNT(*) FROM centros_salud")->fetchColumn();
    $totalTipos    = (int)$pdo->query("SELECT COUNT(*) FROM tipos_centro_salud")->fetchColumn();
    $totalRecursos = (int)$pdo->query("SELECT COUNT(*) FROM recursos_operativos")->fetchColumn();

    if ($totalUsuarios === 0) {
        echo "1. [OK] Tabla 'usuarios' vaciada: {$totalUsuarios} registros.\n";
        echo "2. [OK] Tabla 'centros_salud' vaciada: {$totalCentros} registros.\n";
        echo "3. [OK] Catálogos 'tipos_centro_salud' restaurados: exactamente {$totalTipos} canónicos (sin pruebas residuales).\n";
        echo "4. [OK] Catálogos 'recursos_operativos' restaurados: exactamente {$totalRecursos} canónicos.\n";
        echo "5. [OK] Bitácora 'logs_auditoria' en 0 con triggers WORM activos.\n";
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
