<?php
// scripts/reset_quickstart.php
// Prepara el entorno para probar o grabar el Asistente de Primer Arranque (Quick Start / setup.php)
declare(strict_types=1);

require_once __DIR__ . '/../src/config/database.php';

$pdo = getDB();

echo "=================================================================\n";
echo "  PREPARANDO ENTORNO PARA QUICK START (REINICIO TOTAL DE BD)     \n";
echo "=================================================================\n";

try {
    // 1. Desactivar temporalmente restricciones de clave foránea
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

    // 2. Limpiar bitácora y disparadores WORM para permitir truncado
    $triggersWORM = [
        'trg_bloquear_delete_logs',
        'trg_bloquear_update_logs',
        'trg_bloquear_delete_delta',
        'trg_bloquear_update_delta',
        'trg_bloquear_delete_despachos',
        'trg_bloquear_update_despachos',
        'trg_bloquear_delete_despacho_detalles',
        'trg_bloquear_update_despacho_detalles',
        'trg_proteger_roles_sistema',
        'trg_proteger_roles_sistema_update'
    ];

    foreach ($triggersWORM as $trg) {
        $pdo->exec("DROP TRIGGER IF EXISTS {$trg};");
    }

    // 3. Limpiar tablas transaccionales y asistenciales
    $tablasTransaccionales = [
        'logs_cambios_campos',
        'logs_auditoria',
        'despacho_detalles',
        'despachos',
        'lotes_inventario',
        'tratamientos_autorizados',
        'paciente_contactos',
        'pacientes',
        'tokens_seguridad',
        'notificaciones_sistema',
        'telefonos_centro_salud',
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
    $pdo->exec("
        INSERT INTO configuracion_institucional (id, nombre_sistema, siglas_sistema, ente_rector, municipio, logotipo_ruta, dias_alerta_fefo)
        VALUES (1, 'Sistema Integrado de Control de Medicamentos', 'SICM', 'Dirección de Salud Municipal', 'Municipio Baruta', NULL, 60)
        ON DUPLICATE KEY UPDATE 
            nombre_sistema = VALUES(nombre_sistema),
            siglas_sistema = VALUES(siglas_sistema),
            ente_rector = VALUES(ente_rector),
            municipio = VALUES(municipio),
            dias_alerta_fefo = VALUES(dias_alerta_fefo);
    ");

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

    $pdo->exec("TRUNCATE TABLE rol_permisos;");
    $pdo->exec("TRUNCATE TABLE permisos;");
    $pdo->exec("TRUNCATE TABLE modulos_sistema;");
    $pdo->exec("
        INSERT INTO modulos_sistema (id, codigo, nombre, descripcion, activo) VALUES
        (1, 'CENTROS', 'Red de Ambulatorios y Centros de Salud', 'Gestión de infraestructura física', 1),
        (2, 'MEDICAMENTOS', 'Catálogo Maestro de Fármacos', 'Gestión de principios activos', 1),
        (3, 'INVENTARIO', 'Gestión de Lotes y Almacenamiento', 'Control de stock físico y FEFO', 1),
        (4, 'DESPACHOS', 'Dispensación y Prescripciones Médicas', 'Validación de recetas y entregas', 1),
        (5, 'USUARIOS', 'Seguridad, Credenciales y RBAC', 'Administración de cuentas y permisos', 1),
        (6, 'AUDITORIA', 'Bitácora Forense WORM y Auditoría Delta', 'Trazabilidad de eventos', 1);
    ");

    $pdo->exec("
        INSERT INTO permisos (id, modulo_id, codigo, nombre, descripcion) VALUES
        (1,  1, 'CENTROS_VER', 'Consultar ambulatorios', 'Visualizar ambulatorios'),
        (2,  1, 'CENTROS_CREAR', 'Registrar nuevo ambulatorio', 'Incorporar ambulatorios'),
        (3,  1, 'CENTROS_EDITAR', 'Modificar datos de ambulatorio', 'Actualizar centros'),
        (4,  1, 'CENTROS_ESTADO', 'Desactivar/Reactivar ambulatorio', 'Baja lógica o reactivación'),
        (5,  2, 'MED_VER', 'Consultar catálogo de medicamentos', 'Visualizar fármacos'),
        (6,  2, 'MED_CREAR', 'Registrar nuevo fármaco', 'Incorporar fármacos'),
        (7,  2, 'MED_EDITAR', 'Editar características de fármacos', 'Modificar fármacos'),
        (8,  3, 'INV_VER', 'Consultar existencias y lotes FEFO', 'Inspección de existencias'),
        (9,  3, 'INV_REGISTRAR_LOTE', 'Ingresar nuevo lote al inventario', 'Recepción de lotes'),
        (10, 3, 'INV_AJUSTAR', 'Ajustar mermas de inventario', 'Averías y mermas'),
        (11, 4, 'DESP_VER', 'Consultar despachos realizados', 'Histórico de despachos'),
        (12, 4, 'DESP_PROCESAR', 'Procesar dispensación FEFO', 'Despacho atómico'),
        (13, 5, 'USR_VER', 'Consultar usuarios y perfiles', 'Visualización de nómina'),
        (14, 5, 'USR_GESTIONAR', 'Administrar usuarios y permisos', 'Gestión RBAC'),
        (15, 6, 'AUDIT_VER', 'Consultar bitácoras forenses', 'Acceso a auditoría'),
        (16, 6, 'AUDIT_EXPORTAR', 'Exportar reportes de trazabilidad', 'Exportar evidencias');
    ");

    $pdo->exec("TRUNCATE TABLE roles;");
    $pdo->exec("
        INSERT INTO roles (id, nombre, es_personalizado, descripcion) VALUES 
        (1, 'rol_admin', 0, 'Administrador Central de la Red Asistencial Municipal'),
        (2, 'rol_farmaceuta', 0, 'Operador Farmacéutico Sectorial y Dispensación FEFO'),
        (3, 'rol_auditor', 0, 'Auditor Sanitario y Supervisor de Control Forense');
    ");

    $pdo->exec("
        INSERT INTO rol_permisos (rol_id, permiso_id) VALUES
        (1, 1), (1, 2), (1, 3), (1, 4), (1, 5), (1, 6), (1, 7), (1, 8),
        (1, 9), (1, 10), (1, 11), (1, 12), (1, 13), (1, 14), (1, 15), (1, 16),
        (2, 1), (2, 5), (2, 8), (2, 9), (2, 11), (2, 12),
        (3, 1), (3, 5), (3, 8), (3, 11), (3, 13), (3, 15), (3, 16);
    ");

    $pdo->exec("TRUNCATE TABLE tipos_documento;");
    $pdo->exec("
        INSERT INTO tipos_documento (id, codigo, nombre) VALUES 
        (1, 'V', 'Venezolano'),
        (2, 'E', 'Extranjero'),
        (3, 'P', 'Pasaporte');
    ");

    $pdo->exec("TRUNCATE TABLE tipos_contacto;");
    $pdo->exec("
        INSERT INTO tipos_contacto (id, codigo, nombre, descripcion) VALUES
        (1, 'TEL_FIJO', 'Teléfono de Habitación / Fijo', 'Línea de telefonía fija de habitación o residencia'),
        (2, 'TEL_MOVIL', 'Teléfono Celular / Personal', 'Línea móvil o celular personal'),
        (3, 'WHATSAPP', 'WhatsApp Personal', 'Canal de mensajería instantánea'),
        (4, 'EMAIL_ALTERNO', 'Correo Electrónico', 'Buzón de correo electrónico'),
        (5, 'CONTACTO_EMERGENCIA', 'Contacto de Emergencia / Familiar', 'Teléfono de contacto de familiar o representante');
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

    $pdo->exec("TRUNCATE TABLE patologias;");
    $pdo->exec("
        INSERT INTO patologias (id, codigo_cie10, nombre, cronica, descripcion, activo) VALUES
        (1, 'I10', 'Hipertensión Arterial Primaria (Esencial)', 1, 'Presión arterial sistólica >= 140 mmHg y/o diastólica >= 90 mmHg', 1),
        (2, 'E11', 'Diabetes Mellitus Tipo 2', 1, 'Alteración metabólica de hiperglucemia crónica', 1),
        (3, 'E10', 'Diabetes Mellitus Tipo 1', 1, 'Destrucción autoinmune de células beta pancreáticas', 1),
        (4, 'J45', 'Asma Bronquial Crónica', 1, 'Inflamación crónica de vías respiratorias', 1),
        (5, 'E03', 'Hipotiroidismo Primario', 1, 'Déficit en la producción de hormonas tiroideas', 1);
    ");

    // 5. Restaurar los Triggers WORM sobre logs_auditoria, logs_cambios_campos, despachos y despacho_detalles
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

    $pdo->exec("
        CREATE TRIGGER trg_bloquear_update_delta
        BEFORE UPDATE ON logs_cambios_campos
        FOR EACH ROW
        BEGIN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Integridad Forense Violada: La tabla logs_cambios_campos es inmutable (WORM). No se permiten modificaciones.';
        END;
    ");

    $pdo->exec("
        CREATE TRIGGER trg_bloquear_delete_delta
        BEFORE DELETE ON logs_cambios_campos
        FOR EACH ROW
        BEGIN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Integridad Forense Violada: La tabla logs_cambios_campos es inmutable (WORM). No se permiten eliminaciones físicas.';
        END;
    ");

    $pdo->exec("
        CREATE TRIGGER trg_bloquear_update_despachos
        BEFORE UPDATE ON despachos
        FOR EACH ROW
        BEGIN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Integridad Sanitaria Violada: Los registros de despachos son inmutables (WORM). Queda prohibida toda modificación.';
        END;
    ");

    $pdo->exec("
        CREATE TRIGGER trg_bloquear_delete_despachos
        BEFORE DELETE ON despachos
        FOR EACH ROW
        BEGIN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Integridad Sanitaria Violada: Los registros de despachos son inmutables (WORM). Queda prohibida su eliminación física.';
        END;
    ");

    $pdo->exec("
        CREATE TRIGGER trg_bloquear_update_despacho_detalles
        BEFORE UPDATE ON despacho_detalles
        FOR EACH ROW
        BEGIN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Integridad Sanitaria Violada: Las líneas de detalle de despacho son inmutables. No se admiten modificaciones.';
        END;
    ");

    $pdo->exec("
        CREATE TRIGGER trg_bloquear_delete_despacho_detalles
        BEFORE DELETE ON despacho_detalles
        FOR EACH ROW
        BEGIN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Integridad Sanitaria Violada: Las líneas de detalle de despacho son inmutables. No se admiten eliminaciones físicas.';
        END;
    ");

    $pdo->exec("
        CREATE TRIGGER trg_proteger_roles_sistema
        BEFORE DELETE ON roles
        FOR EACH ROW
        BEGIN
            IF OLD.es_personalizado = 0 THEN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Seguridad RBAC: No se permite eliminar roles nativos del sistema.';
            END IF;
        END;
    ");

    $pdo->exec("
        CREATE TRIGGER trg_proteger_roles_sistema_update
        BEFORE UPDATE ON roles
        FOR EACH ROW
        BEGIN
            IF OLD.es_personalizado = 0 AND NEW.es_personalizado <> 0 THEN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Seguridad RBAC: No se permite alterar la condición de rol nativo del sistema.';
            END IF;
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
        echo "3. [OK] Catálogos 'tipos_centro_salud' restaurados: exactamente {$totalTipos} canónicos.\n";
        echo "4. [OK] Catálogos 'recursos_operativos' restaurados: exactamente {$totalRecursos} canónicos.\n";
        echo "5. [OK] Bitácora 'logs_auditoria' y 'logs_cambios_campos' en 0 con triggers WORM activos.\n";
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
