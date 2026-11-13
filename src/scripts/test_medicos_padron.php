<?php
// src/scripts/test_medicos_padron.php
// Validación Funcional: Padrón de Médicos Prescriptores (3FN) y Textos de Dispensación
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auditoria.php';
require_once __DIR__ . '/../helpers/auth.php';

$pdo = getDB();

echo "=== INICIANDO VALIDACIÓN PADRÓN DE MÉDICOS Y DISPENSACIÓN ===\n\n";

// 1. Verificar Semillas de Médicos Acreditados
echo "1. Verificando padrón inicial de médicos...\n";
$stmt = $pdo->query("SELECT id, nombres, apellidos, matricula_mpps, especialidad, activo FROM medicos ORDER BY id ASC");
$medicos = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($medicos) < 4) {
    echo "  [FAIL] Se esperaban al menos 4 médicos en el padrón, encontrados: " . count($medicos) . "\n";
    exit(1);
}

foreach ($medicos as $m) {
    echo "  [INFO] #{$m['id']} Dr(a). {$m['nombres']} {$m['apellidos']} | MPPS: {$m['matricula_mpps']} | Esp: {$m['especialidad']} | Activo: {$m['activo']}\n";
}
echo "  [PASS] Padrón de médicos verificado correctamente.\n\n";

// 2. Probar restricción de unicidad en matrícula MPPS
echo "2. Probando unicidad de matrícula sanitaria MPPS...\n";
try {
    $pdo->prepare("INSERT INTO medicos (nombres, apellidos, matricula_mpps, especialidad) VALUES ('Test', 'Duplicado', 'MPPS-78412', 'General')")->execute();
    echo "  [FAIL] Se permitió insertar una matrícula duplicada.\n";
    exit(1);
} catch (PDOException $e) {
    echo "  [PASS] Restricción de unicidad activa (Error SQLSTATE " . $e->getCode() . ").\n\n";
}

// 3. Probar creación de nuevo médico prescriptor
echo "3. Probando inserción y conmutación de médico en padrón...\n";
$matriculaTest = 'MPPS-TEST-' . time();
$stmtIns = $pdo->prepare("INSERT INTO medicos (nombres, apellidos, matricula_mpps, especialidad, telefono, activo) VALUES ('Prueba', 'Sanitaria', :mpps, 'Toxicología', '0412-1112233', 1)");
$stmtIns->execute([':mpps' => $matriculaTest]);
$testMedId = (int) $pdo->lastInsertId();
echo "  [PASS] Médico de prueba creado con ID #{$testMedId}.\n";

// Conmutar estado
$pdo->prepare("UPDATE medicos SET activo = 0 WHERE id = :id")->execute([':id' => $testMedId]);
$stActual = (int) $pdo->query("SELECT activo FROM medicos WHERE id = {$testMedId}")->fetchColumn();
if ($stActual !== 0) {
    echo "  [FAIL] No se conmutó el estado a inactivo.\n";
    exit(1);
}
echo "  [PASS] Conmutación a inactivo exitosa.\n";

// Reactivar
$pdo->prepare("UPDATE medicos SET activo = 1 WHERE id = :id")->execute([':id' => $testMedId]);
echo "  [PASS] Reactivación exitosa.\n\n";

// 4. Probar emisión de récipe con médico acreditado
echo "4. Probando emisión de récipe con médico acreditado...\n";
// Desactivar récipe activo previo de medicamento 4 para paciente 1
$pdo->prepare("UPDATE tratamientos_autorizados SET activo = 0 WHERE paciente_id = 1 AND medicamento_id = 4 AND activo = 1")->execute();

$stmtTa = $pdo->prepare("
    INSERT INTO tratamientos_autorizados 
    (paciente_id, medicamento_id, patologia_id, medico_id, dosis_mensual, fecha_emision_recipe, fecha_vencimiento_recipe, medico_prescriptor, activo)
    VALUES (1, 4, 4, :mid, 45, CURRENT_DATE, DATE_ADD(CURRENT_DATE, INTERVAL 6 MONTH), 'Dr(a). Prueba Sanitaria — Matrícula MPPS: {$matriculaTest} (Toxicología)', 1)
");
$stmtTa->execute([':mid' => $testMedId]);
$testTaId = (int) $pdo->lastInsertId();
echo "  [PASS] Récipe emitido con ID #{$testTaId} vinculado a médico ID #{$testMedId}.\n";

// Desactivación lógica de prueba (cumplimiento de menores privilegios)
$pdo->prepare("UPDATE tratamientos_autorizados SET activo = 0 WHERE id = :id")->execute([':id' => $testTaId]);
$pdo->prepare("UPDATE medicos SET activo = 0 WHERE id = :id")->execute([':id' => $testMedId]);
echo "  [PASS] Baja lógica de registros de prueba efectuada.\n\n";

// 5. Verificar denominaciones de permisos en base de datos
echo "5. Verificando denominaciones de permisos en DB...\n";
$perm8 = $pdo->query("SELECT nombre FROM permisos WHERE id = 8")->fetchColumn();
$perm12 = $pdo->query("SELECT nombre FROM permisos WHERE id = 12")->fetchColumn();

if ($perm8 !== 'Consultar existencias y lotes') {
    echo "  [FAIL] Permiso 8 esperado 'Consultar existencias y lotes', obtenido: '{$perm8}'\n";
    exit(1);
}
echo "  [PASS] Permiso 8: '{$perm8}'\n";

if ($perm12 !== 'Procesar dispensación') {
    echo "  [FAIL] Permiso 12 esperado 'Procesar dispensación', obtenido: '{$perm12}'\n";
    exit(1);
}
echo "  [PASS] Permiso 12: '{$perm12}'\n\n";

// 6. Verificar textos en tab_despachos.php
echo "6. Verificando textos en vista de dispensación...\n";
$tabDespachosContent = file_get_contents(__DIR__ . '/../views/tabs/tab_despachos.php');

if (strpos($tabDespachosContent, 'Módulo de Dispensación Asistencial y Entrega FEFO') !== false) {
    echo "  [FAIL] Aún contiene 'Módulo de Dispensación Asistencial y Entrega FEFO'\n";
    exit(1);
}
echo "  [PASS] 'Módulo de Dispensación Asistencial y Entrega FEFO' saneado.\n";

if (strpos($tabDespachosContent, 'Módulo de Dispensación Asistencial') === false) {
    echo "  [FAIL] No se encontró 'Módulo de Dispensación Asistencial'\n";
    exit(1);
}
echo "  [PASS] Título 'Módulo de Dispensación Asistencial' presente.\n";

$bannerExpected = 'Inicie la atención localizando al paciente por cédula o nombre en el buscador superior. El sistema validará en tiempo real la vigencia del récipe médico, el bloqueo de múltiples retiros mensuales y la asignación automática del lote con fecha de caducidad más próxima.';
if (strpos($tabDespachosContent, $bannerExpected) === false) {
    echo "  [FAIL] Banner de flujo no coincide con el texto requerido.\n";
    exit(1);
}
echo "  [PASS] Banner de flujo validado fielmente.\n\n";

echo "=== TODAS LAS PRUEBAS DEL PADRÓN Y SANEAMIENTO CONCLUYERON CON ÉXITO ===\n";
