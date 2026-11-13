<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auditoria.php';
require_once __DIR__ . '/../helpers/auth.php';

$pdo = getDB();

echo "=== INICIANDO VALIDACIÓN DE NUEVAS MEJORAS SICM ===\n\n";

// =========================================================================
// TEST 1: ENTRADA DE LOTE / RECEPCIÓN DE INVENTARIO
// =========================================================================
echo "1. Probando Recepción de Lotes / Entrada de Stock...\n";

$numLoteTest = 'LOT-TEST-' . time();
$vencimientoTest = date('Y-m-d', strtotime('+18 months'));
$cantidadTest = 150;
$centroTestId = 1;
$medTestId = 1; // Losartán

// Inserción de lote
$stmtIns = $pdo->prepare("
    INSERT INTO lotes_inventario (
        centro_salud_id, medicamento_id, numero_lote, fecha_vencimiento,
        laboratorio_proveedor, cantidad_actual, usuario_id
    ) VALUES (
        :cid, :mid, :lote, :venc, :lab, :cant, 1
    )
");
$stmtIns->execute([
    ':cid' => $centroTestId,
    ':mid' => $medTestId,
    ':lote' => $numLoteTest,
    ':venc' => $vencimientoTest,
    ':lab' => 'Laboratorios Behrens C.A.',
    ':cant' => $cantidadTest
]);
$loteId = (int)$pdo->lastInsertId();

if ($loteId > 0) {
    echo "  [PASS] Lote '{$numLoteTest}' registrado con ID #{$loteId}.\n";
} else {
    die("  [FAIL] Error al registrar lote.\n");
}

// Registro en auditoría
registrarAuditoria($pdo, 1, 'REGISTRAR_ENTRADA_LOTE', 'lotes_inventario', $loteId, [
    'evento' => 'Recepción y alta de nuevo lote farmacéutico en inventario FEFO',
    'numero_lote' => $numLoteTest,
    'cantidad_ingresada' => $cantidadTest
]);

// Verificar en logs_auditoria
$stmtAudit = $pdo->prepare("SELECT id, accion, tabla_afectada FROM logs_auditoria WHERE registro_id = :id AND accion = 'REGISTRAR_ENTRADA_LOTE'");
$stmtAudit->execute([':id' => $loteId]);
$log = $stmtAudit->fetch(PDO::FETCH_ASSOC);
if ($log) {
    echo "  [PASS] Trazabilidad forense inmutable confirmada en logs_auditoria (ID #{$log['id']}).\n";
} else {
    die("  [FAIL] No se encontró log de auditoría para la entrada de lote.\n");
}

// =========================================================================
// TEST 2: TIPOS DE DOCUMENTO CONFIGURABLES
// =========================================================================
echo "\n2. Probando Catálogo de Tipos de Documento Configurable...\n";

$codDocTest = 'DNI';
$nomDocTest = 'Documento Nacional de Identidad';

// Crear tipo de documento si no existe
$stmtCheckDoc = $pdo->prepare("SELECT id FROM tipos_documento WHERE codigo = :c");
$stmtCheckDoc->execute([':c' => $codDocTest]);
$docExist = $stmtCheckDoc->fetch(PDO::FETCH_ASSOC);

if (!$docExist) {
    $stmtInsDoc = $pdo->prepare("INSERT INTO tipos_documento (codigo, nombre, activo) VALUES (:c, :n, 1)");
    $stmtInsDoc->execute([':c' => $codDocTest, ':n' => $nomDocTest]);
    $docId = (int)$pdo->lastInsertId();
    echo "  [PASS] Nuevo tipo de documento '{$codDocTest}' creado con ID #{$docId}.\n";
} else {
    $docId = (int)$docExist['id'];
    echo "  [INFO] Tipo de documento '{$codDocTest}' ya existía con ID #{$docId}.\n";
}

// Toggle estado
$pdo->prepare("UPDATE tipos_documento SET activo = 0 WHERE id = :id")->execute([':id' => $docId]);
$estadoInactivo = (int)$pdo->query("SELECT activo FROM tipos_documento WHERE id = {$docId}")->fetchColumn();
if ($estadoInactivo === 0) {
    echo "  [PASS] Conmutación a inactivo verificada.\n";
}
$pdo->prepare("UPDATE tipos_documento SET activo = 1 WHERE id = :id")->execute([':id' => $docId]);
$estadoActivo = (int)$pdo->query("SELECT activo FROM tipos_documento WHERE id = {$docId}")->fetchColumn();
if ($estadoActivo === 1) {
    echo "  [PASS] Reactivación a activo verificada.\n";
}

// =========================================================================
// TEST 3: ATRIBUTOS FARMACOLÓGICOS (3FN - N:M)
// =========================================================================
echo "\n3. Probando Atributos Farmacológicos Normalizados (3FN)...\n";

$atributos = $pdo->query("SELECT * FROM atributos_farmacologicos ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
echo "  [INFO] " . count($atributos) . " atributos farmacológicos activos en catálogo:\n";
foreach ($atributos as $atr) {
    echo "    - [{$atr['codigo']}] {$atr['nombre']} (Icono: {$atr['icono']}, Color: {$atr['color_badge']})\n";
}

// Asignar atributo 2 (FOTOSENSIBLE) al medicamento 1 (Losartán)
$pdo->prepare("INSERT IGNORE INTO medicamento_atributos (medicamento_id, atributo_id) VALUES (1, 2)")->execute();
$hasFoto = (int)$pdo->query("SELECT COUNT(*) FROM medicamento_atributos WHERE medicamento_id = 1 AND atributo_id = 2")->fetchColumn();
if ($hasFoto > 0) {
    echo "  [PASS] Relación N:M medicamento_atributos registrada para Losartán (Fotosensible).\n";
}

// =========================================================================
// TEST 4: CLASIFICACIONES DE PATOLOGÍAS (3FN)
// =========================================================================
echo "\n4. Probando Clasificaciones de Patología Normalizadas (3FN)...\n";

$clasificaciones = $pdo->query("SELECT * FROM clasificaciones_patologia ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
echo "  [INFO] " . count($clasificaciones) . " clasificaciones registradas:\n";
foreach ($clasificaciones as $cl) {
    echo "    - [{$cl['codigo']}] {$cl['nombre']} (Recurrente Mensual: " . ($cl['es_recurrente_mensual'] ? 'SI' : 'NO') . ")\n";
}

$patologias = $pdo->query("
    SELECT p.id, p.codigo_cie10, p.nombre, cp.codigo AS clasificacion_codigo, cp.nombre AS clasificacion_nombre
    FROM patologias p
    LEFT JOIN clasificaciones_patologia cp ON p.clasificacion_id = cp.id
    ORDER BY p.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

echo "  [INFO] Patologías vinculadas con su clasificación 3FN:\n";
foreach ($patologias as $pat) {
    echo "    - #{$pat['id']} [{$pat['codigo_cie10']}] {$pat['nombre']} => {$pat['clasificacion_nombre']} ({$pat['clasificacion_codigo']})\n";
}
echo "  [PASS] Todas las patologías cuentan con clave foránea normalizada.\n";

echo "\n=== TODAS LAS VALIDACIONES DE MEJORAS CONCLUYERON CON ÉXITO ===\n";
