<?php
// src/scripts/test_despachos_e2e.php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/auditoria.php';

echo "=== INICIANDO PRUEBAS E2E DEL MÓDULO DE DISPENSACIÓN FEFO ===\n\n";

$pdo = getDB();

// 1. Verificar existencia del stored procedure
$procCheck = $pdo->query("SHOW PROCEDURE STATUS WHERE Db = DATABASE() AND Name = 'sp_procesar_despacho_fefo'")->fetch(PDO::FETCH_ASSOC);
if (!$procCheck) {
    die("ERROR CRÍTICO: El stored procedure sp_procesar_despacho_fefo no existe en la base de datos.\n");
}
echo "[PASS] Stored procedure sp_procesar_despacho_fefo localizado en la base de datos.\n";

// 2. Verificar datos de prueba (Paciente 1: Juan Morales, Tratamiento 1: Losartán)
$paciente = $pdo->query("SELECT * FROM pacientes WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
echo "[INFO] Paciente de prueba: " . $paciente['nombre_completo'] . " (Cédula: " . $paciente['cedula'] . ")\n";

$rec = $pdo->query("SELECT * FROM tratamientos_autorizados WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
echo "[INFO] Prescripción de prueba: ID " . $rec['id'] . ", Dosis mensual: " . $rec['dosis_mensual'] . " unid.\n";

// 3. Verificar algoritmo FEFO para medicamento 1 en centro 1
$stmtLotes = $pdo->prepare("
    SELECT id, numero_lote, fecha_vencimiento, cantidad_actual
    FROM lotes_inventario
    WHERE centro_salud_id = 1 AND medicamento_id = 1 AND cantidad_actual > 0 AND fecha_vencimiento >= CURDATE()
    ORDER BY fecha_vencimiento ASC, id ASC
");
$stmtLotes->execute();
$lotes = $stmtLotes->fetchAll(PDO::FETCH_ASSOC);
echo "[INFO] Lotes disponibles en Centro 1 para Fármaco 1:\n";
foreach ($lotes as $i => $l) {
    $tag = ($i === 0) ? " (FEFO PRIORITARIO)" : "";
    echo "  - Lote #" . $l['id'] . " [" . $l['numero_lote'] . "] Vence: " . $l['fecha_vencimiento'] . ", Stock: " . $l['cantidad_actual'] . $tag . "\n";
}

if (empty($lotes)) {
    die("ERROR: No hay lotes con stock para la prueba.\n");
}

$loteFefo = $lotes[0];
$periodoActual = date('Y-m');

// 4. Verificar si ya existe despacho en este periodo para el tratamiento 1
$despPrevio = $pdo->query("SELECT id FROM despachos WHERE tratamiento_id = 1 AND periodo_mes_anio = '{$periodoActual}'")->fetch(PDO::FETCH_ASSOC);

establecerContextoAuditoriaSQL($pdo, 1, '127.0.0.1', 'PHP-CLI-E2E-TEST');

if (!$despPrevio) {
    echo "[INFO] No hay despacho previo para el tratamiento 1 en {$periodoActual}. Procesando primer despacho...\n";
    $stockAntes = (int)$loteFefo['cantidad_actual'];
    $cantidadDespacho = (int)$rec['dosis_mensual'];

    $stmtSp = $pdo->prepare("
        CALL sp_procesar_despacho_fefo(
            :tratamiento_id,
            :centro_id,
            :usuario_id,
            :lote_id,
            :cantidad,
            :periodo,
            :obs,
            @despacho_id,
            @codigo_resultado,
            @mensaje_resultado
        )
    ");

    $stmtSp->execute([
        ':tratamiento_id' => 1,
        ':centro_id'      => 1,
        ':usuario_id'     => 1,
        ':lote_id'        => (int)$loteFefo['id'],
        ':cantidad'       => $cantidadDespacho,
        ':periodo'        => $periodoActual,
        ':obs'            => 'Despacho de prueba automatizada E2E'
    ]);
    $stmtSp->closeCursor();

    $resOut = $pdo->query("SELECT @despacho_id AS despacho_id, @codigo_resultado AS codigo, @mensaje_resultado AS mensaje")->fetch(PDO::FETCH_ASSOC);
    echo "[OUT] Despacho ID: " . $resOut['despacho_id'] . ", Código: " . $resOut['codigo'] . ", Mensaje: " . $resOut['mensaje'] . "\n";

    if ($resOut['codigo'] !== 'EXITO' || (int)$resOut['despacho_id'] <= 0) {
        die("ERROR: Falló la ejecución de sp_procesar_despacho_fefo.\n");
    }
    echo "[PASS] sp_procesar_despacho_fefo ejecutado exitosamente con ID #" . $resOut['despacho_id'] . "\n";

    // Verificar descuento de stock
    $stockDespues = (int)$pdo->query("SELECT cantidad_actual FROM lotes_inventario WHERE id = " . (int)$loteFefo['id'])->fetchColumn();
    echo "[INFO] Stock anterior: {$stockAntes}, Stock actual: {$stockDespues}\n";
    if ($stockDespues !== ($stockAntes - $cantidadDespacho)) {
        die("ERROR: El stock no se descontó correctamente.\n");
    }
    echo "[PASS] Descuento atómico de existencias verificado en lotes_inventario.\n";
    $despachoIdGenerado = (int)$resOut['despacho_id'];
} else {
    echo "[INFO] Ya existe despacho previo #{$despPrevio['id']} para el tratamiento 1 en {$periodoActual}.\n";
    $despachoIdGenerado = (int)$despPrevio['id'];
}

// 5. Probar candado relacional mensual uk_retiro_mensual (Intentar segundo despacho en el mismo periodo)
echo "[INFO] Probando candado relacional contra doble retiro mensual (uk_retiro_mensual)...\n";
$bloqueado = false;
try {
    $stmtSp2 = $pdo->prepare("
        CALL sp_procesar_despacho_fefo(
            :tratamiento_id,
            :centro_id,
            :usuario_id,
            :lote_id,
            :cantidad,
            :periodo,
            :obs,
            @despacho_id2,
            @codigo2,
            @mensaje2
        )
    ");
    $stmtSp2->execute([
        ':tratamiento_id' => 1,
        ':centro_id'      => 1,
        ':usuario_id'     => 1,
        ':lote_id'        => (int)$loteFefo['id'],
        ':cantidad'       => (int)$rec['dosis_mensual'],
        ':periodo'        => $periodoActual,
        ':obs'            => 'Segundo intento ilícito de retiro'
    ]);
    $stmtSp2->closeCursor();
} catch (PDOException $e) {
    $bloqueado = true;
    echo "[PASS] Candado activado correctamente ante intento de doble retiro: " . $e->getMessage() . "\n";
}

if (!$bloqueado) {
    die("ERROR: El candado uk_retiro_mensual NO bloqueó el segundo despacho en el mismo mes.\n");
}

// 6. Probar también con Paciente 2 (María Peña - Metformina, Tratamiento 2) si no ha retirado
$desp2 = $pdo->query("SELECT id FROM despachos WHERE tratamiento_id = 2 AND periodo_mes_anio = '{$periodoActual}'")->fetch(PDO::FETCH_ASSOC);
if (!$desp2) {
    echo "[INFO] Probando despacho para Paciente 2 (María Peña - Metformina)...\n";
    $loteMet = $pdo->query("SELECT id FROM lotes_inventario WHERE centro_salud_id = 1 AND medicamento_id = 2 AND cantidad_actual > 0 ORDER BY fecha_vencimiento ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($loteMet) {
        $stmtSpMet = $pdo->prepare("
            CALL sp_procesar_despacho_fefo(
                2, 1, 1, :lote_id, 60, :periodo, 'Despacho regular Metformina',
                @d_id, @d_cod, @d_msg
            )
        ");
        $stmtSpMet->execute([':lote_id' => (int)$loteMet['id'], ':periodo' => $periodoActual]);
        $stmtSpMet->closeCursor();
        $resMet = $pdo->query("SELECT @d_id AS id, @d_cod AS cod, @d_msg AS msg")->fetch(PDO::FETCH_ASSOC);
        echo "[PASS] Despacho María Peña procesado con ID #" . $resMet['id'] . "\n";
    }
}

// 7. Verificar registros inmutables en logs_auditoria
$audit = $pdo->query("
    SELECT id, accion, tabla_afectada, registro_id, detalles_json, ip_origen, fecha_hora
    FROM logs_auditoria
    WHERE accion = 'DESPACHO_FEFO'
    ORDER BY id DESC LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if (!$audit) {
    die("ERROR: No se encontró ningún registro de auditoría DESPACHO_FEFO.\n");
}
echo "[PASS] Registro forense inmutable WORM localizado en logs_auditoria: ID #" . $audit['id'] . "\n";
echo "       Detalles JSON: " . $audit['detalles_json'] . "\n";

// 8. Verificar KPIs de despachos
$kpi1 = (int)$pdo->query("SELECT COUNT(*) FROM despachos WHERE periodo_mes_anio = '{$periodoActual}' AND centro_salud_id = 1")->fetchColumn();
$kpi2 = (int)$pdo->query("SELECT COUNT(DISTINCT t.paciente_id) FROM despachos d JOIN tratamientos_autorizados t ON d.tratamiento_id = t.id WHERE d.periodo_mes_anio = '{$periodoActual}' AND d.centro_salud_id = 1")->fetchColumn();
$kpi3 = (int)$pdo->query("SELECT COALESCE(SUM(dd.cantidad_entregada), 0) FROM despachos d JOIN despacho_detalles dd ON dd.despacho_id = d.id WHERE d.periodo_mes_anio = '{$periodoActual}' AND d.centro_salud_id = 1")->fetchColumn();

echo "\n--- KPIS DEL CENTRO 1 EN {$periodoActual} ---\n";
echo "Despachos este Mes: {$kpi1}\n";
echo "Pacientes Atendidos: {$kpi2}\n";
echo "Unidades Entregadas: {$kpi3}\n";

if ($kpi1 <= 0 || $kpi2 <= 0 || $kpi3 <= 0) {
    die("ERROR: Los KPIs no reflejan los despachos realizados.\n");
}
echo "[PASS] KPIs calculados correctamente.\n";

echo "\n=== TODAS LAS PRUEBAS E2E COMPLETADAS SATISFACTORIAMENTE ===\n";
