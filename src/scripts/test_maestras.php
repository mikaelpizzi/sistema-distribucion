<?php
// src/scripts/test_maestras.php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auditoria.php';

$pdo = getDB();

echo "=== PRUEBA DE ADAPTABILIDAD EMPRESARIAL Y VISTAS SQL ===\n";

// 1. Probar inserción de un nuevo tipo de centro para otra empresa (ej: Centro de Distribución Logística)
$tipoCodigo = 'CEDI_LOGISTICA';
$tipoNombre = 'Centro de Distribución y Logística Farmacéutica';
$tipoDesc = 'Almacén central automatizado con muelles de carga y control FEFO';

$stmt = $pdo->prepare("INSERT INTO tipos_centro_salud (codigo, nombre, descripcion) VALUES (:c, :n, :d)");
$stmt->execute([':c' => $tipoCodigo, ':n' => $tipoNombre, ':d' => $tipoDesc]);
$nuevoTipoId = (int)$pdo->lastInsertId();

registrarAuditoria($pdo, 1, 'CREAR_TIPO_CENTRO', 'tipos_centro_salud', $nuevoTipoId, [
    'evento' => 'Adaptación a empresa de logística farmacéutica',
    'datos_creados' => ['codigo' => $tipoCodigo, 'nombre' => $tipoNombre]
]);
echo "1. Nueva clasificación de empresa creada: #{$nuevoTipoId} ({$tipoCodigo})\n";

// 2. Probar inserción de un nuevo recurso operativo empresarial (ej: Cava Frigorífica Industrial)
$recCodigo = 'CAVA_IND_20C';
$recNombre = 'Cava Frigorífica Industrial (-20°C a -40°C)';
$recDesc = 'Cámara frigorífica de congelación profunda para biológicos ultra-refrigerados';

$stmtR = $pdo->prepare("INSERT INTO recursos_operativos (codigo, nombre, descripcion, activo) VALUES (:c, :n, :d, 1)");
$stmtR->execute([':c' => $recCodigo, ':n' => $recNombre, ':d' => $recDesc]);
$nuevoRecId = (int)$pdo->lastInsertId();

registrarAuditoria($pdo, 1, 'CREAR_RECURSO_OPERATIVO', 'recursos_operativos', $nuevoRecId, [
    'evento' => 'Adaptación a capacidad de ultra-congelación',
    'datos_creados' => ['codigo' => $recCodigo, 'nombre' => $recNombre]
]);
echo "2. Nueva capacidad técnica empresarial creada: #{$nuevoRecId} ({$recCodigo})\n";

// 3. Verificar que las vistas SQL leen los nuevos datos sin problemas
$centroEnVista = $pdo->query("SELECT COUNT(*) FROM vw_centros_salud")->fetchColumn();
$logsEnVista = $pdo->query("SELECT COUNT(*) FROM vw_logs_auditoria")->fetchColumn();
echo "3. Vistas SQL operativas: Centros en vw_centros_salud={$centroEnVista}, Logs en vw_logs_auditoria={$logsEnVista}\n";

echo "=== TEST DE ADAPTABILIDAD EXITOSO ===\n";
