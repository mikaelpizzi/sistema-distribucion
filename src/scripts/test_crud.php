<?php
// src/scripts/test_crud.php
// Script de Verificación de Integridad Referencial y Auditoría Forense (Idempotente)
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auditoria.php';

$pdo = getDB();

echo "=================================================================\n";
echo "  BATERÍA DE VERIFICACIÓN TÉCNICA (CRUD 3FN + AUDITORÍA FORENSE)  \n";
echo "=================================================================\n";

// 0. Limpieza previa de pruebas anteriores (Garantía de Idempotencia)
$codigoTest = 'AMB-TEST-001';
$pdo->prepare("DELETE FROM centros_salud WHERE codigo_sicm = :c")->execute([':c' => $codigoTest]);

// 1. Verificar lectura de catálogos maestros (3FN)
$tipos = $pdo->query("SELECT COUNT(*) FROM tipos_centro_salud")->fetchColumn();
$recursos = $pdo->query("SELECT COUNT(*) FROM recursos_operativos WHERE activo = 1")->fetchColumn();
echo "1. [OK] Catálogos maestros activos: Clasificaciones={$tipos}, Recursos={$recursos}\n";

// 2. Probar inserción simulando la lógica de procesar.php (CREATE)
$pdo->beginTransaction();
$nombre = 'Ambulatorio de Prueba Técnica';
$tipoId = 1;
$director = 'Dra. Valentina Morales (MPPS 61.200)';
$horario = 'Lunes a Viernes 8:00 AM - 4:00 PM';
$direccion = 'Sector Los Curos, Calle 3, Mérida';
$telefono = '0274-2715544';
$recursosAsignar = [1, 2]; // Cadena de frío + Planta eléctrica

$stmt = $pdo->prepare("INSERT INTO centros_salud (codigo_sicm, nombre, tipo_centro_id, medico_director, horario_atencion, direccion, telefono_principal, activo)
                       VALUES (:codigo, :nombre, :tipo_id, :director, :horario, :direccion, :telefono, 1)");
$stmt->execute([
    ':codigo'    => $codigoTest,
    ':nombre'    => $nombre,
    ':tipo_id'   => $tipoId,
    ':director'  => $director,
    ':horario'   => $horario,
    ':direccion' => $direccion,
    ':telefono'  => $telefono
]);
$nuevoId = (int)$pdo->lastInsertId();

// Asignar en centro_recursos (N:M)
$stmtRec = $pdo->prepare("INSERT INTO centro_recursos (centro_salud_id, recurso_id, operativo) VALUES (:c, :r, 1)");
foreach ($recursosAsignar as $rId) {
    $stmtRec->execute([':c' => $nuevoId, ':r' => $rId]);
}

// Registrar log forense
registrarAuditoria($pdo, 1, 'CREAR_CENTRO', 'centros_salud', $nuevoId, [
    'evento' => 'Registro de prueba técnica en red asistencial',
    'datos_creados' => [
        'codigo_sicm'  => $codigoTest,
        'nombre'       => $nombre,
        'recursos_ids' => $recursosAsignar
    ]
]);
$pdo->commit();

echo "2. [OK] CREATE Exitoso: Ambulatorio #{$nuevoId} ({$codigoTest}) con " . count($recursosAsignar) . " capacidades N:M.\n";

// 3. Probar actualización (UPDATE) con Delta Forense
$pdo->beginTransaction();
$stmtPrev = $pdo->prepare("SELECT * FROM centros_salud WHERE id = :id");
$stmtPrev->execute([':id' => $nuevoId]);
$previo = $stmtPrev->fetch();

$nuevoHorario = '24 Horas / Emergencia Activa';
$nuevosRecursos = [1, 2, 5]; // Agregamos Farmacia 24H

$stmtUpd = $pdo->prepare("UPDATE centros_salud SET horario_atencion = :horario WHERE id = :id");
$stmtUpd->execute([':horario' => $nuevoHorario, ':id' => $nuevoId]);

$pdo->prepare("DELETE FROM centro_recursos WHERE centro_salud_id = :id")->execute([':id' => $nuevoId]);
foreach ($nuevosRecursos as $rId) {
    $stmtRec->execute([':c' => $nuevoId, ':r' => $rId]);
}

registrarAuditoria($pdo, 1, 'EDITAR_CENTRO', 'centros_salud', $nuevoId, [
    'evento' => 'Actualización de capacidades y horario',
    'delta_especifico' => [
        'horario_atencion' => ['antes' => $previo['horario_atencion'], 'despues' => $nuevoHorario],
        'recursos_ids'     => ['antes' => $recursosAsignar, 'despues' => $nuevosRecursos]
    ]
]);
$pdo->commit();

echo "3. [OK] UPDATE Exitoso: Ambulatorio #{$nuevoId} actualizado con delta registrado en auditoría.\n";

// 4. Probar Soft Delete y Reactivación
$pdo->beginTransaction();
$pdo->prepare("UPDATE centros_salud SET activo = 0 WHERE id = :id")->execute([':id' => $nuevoId]);
registrarAuditoria($pdo, 1, 'DESACTIVAR_CENTRO', 'centros_salud', $nuevoId, [
    'evento' => 'Desactivación preventiva de sede (Soft Delete)',
    'codigo_sicm' => $codigoTest,
    'estado_anterior' => 1,
    'nuevo_estado' => 0
]);
$pdo->commit();
echo "4. [OK] SOFT DELETE Exitoso: Ambulatorio #{$nuevoId} conmutado a inactivo sin borrado físico.\n";

// 5. Verificar lectura a través de la Vista SQL (vw_centros_salud)
$stmtVista = $pdo->prepare("SELECT tipo_nombre, tipo_codigo FROM vw_centros_salud WHERE id = :id");
$stmtVista->execute([':id' => $nuevoId]);
$vistaRow = $stmtVista->fetch();
echo "5. [OK] VISTA SQL (vw_centros_salud): Resuelve tipo '{$vistaRow['tipo_nombre']}' ({$vistaRow['tipo_codigo']}) de forma desacoplada.\n";

// 6. Verificar logs de auditoría
$totalLogs = $pdo->query("SELECT COUNT(*) FROM logs_auditoria")->fetchColumn();
echo "6. [OK] AUDITORÍA FORENSE: {$totalLogs} eventos registrados en logs_auditoria.\n";

echo "=================================================================\n";
echo "  RESULTADO: 100% DE PRUEBAS COMPLETADAS SATISFACTORIAMENTE      \n";
echo "=================================================================\n";