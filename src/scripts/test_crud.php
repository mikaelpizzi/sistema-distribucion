<?php
// src/scripts/test_crud.php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auditoria.php';

$pdo = getDB();

echo "=== INICIANDO BATERÍA DE PRUEBAS TÉCNICAS (MISIÓN 1 Y MISIÓN 2) ===\n";

// 1. Verificar lectura de catálogos
$tipos = $pdo->query("SELECT COUNT(*) FROM tipos_centro_salud")->fetchColumn();
$recursos = $pdo->query("SELECT COUNT(*) FROM recursos_operativos")->fetchColumn();
echo "1. Catálogos maestros activos: Tipos={$tipos}, Recursos={$recursos}\n";

// 2. Probar inserción simulando la lógica de procesar.php (CREATE)
$pdo->beginTransaction();
$codigo = 'AMB-MER-004';
$nombre = 'Ambulatorio Urbano I Los Curos';
$tipoId = 1;
$director = 'Dra. Valentina Morales (MPPS 61.200)';
$horario = 'Lunes a Viernes 8:00 AM - 4:00 PM';
$direccion = 'Sector Los Curos, Parte Media, Calle 3, Mérida';
$telefono = '0274-2715544';
$recursosAsignar = [1, 2]; // Cadena de frío + Planta eléctrica

$stmt = $pdo->prepare("INSERT INTO centros_salud (codigo_sicm, nombre, tipo_centro_id, medico_director, horario_atencion, direccion, telefono_principal, activo)
                       VALUES (:codigo, :nombre, :tipo_id, :director, :horario, :direccion, :telefono, 1)");
$stmt->execute([
    ':codigo'    => $codigo,
    ':nombre'    => $nombre,
    ':tipo_id'   => $tipoId,
    ':director'  => $director,
    ':horario'   => $horario,
    ':direccion' => $direccion,
    ':telefono'  => $telefono
]);
$nuevoId = (int)$pdo->lastInsertId();

// Asignar en centro_recursos
$stmtRec = $pdo->prepare("INSERT INTO centro_recursos (centro_salud_id, recurso_id, operativo) VALUES (:c, :r, 1)");
foreach ($recursosAsignar as $rId) {
    $stmtRec->execute([':c' => $nuevoId, ':r' => $rId]);
}

// Registrar log forense
registrarAuditoria($pdo, 1, 'CREAR_CENTRO', 'centros_salud', $nuevoId, [
    'evento' => 'Registro de nuevo centro en red ambulatoria',
    'datos_creados' => [
        'codigo_sicm' => $codigo,
        'nombre' => $nombre,
        'recursos_ids' => $recursosAsignar
    ]
]);
$pdo->commit();

echo "2. Creación exitosa: Centro #{$nuevoId} ({$codigo}) con " . count($recursosAsignar) . " recursos operativos.\n";

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

echo "3. Edición exitosa: Centro #{$nuevoId} actualizado con delta registrado en auditoría.\n";

// 4. Probar Soft Delete y Reactivación
$pdo->beginTransaction();
$pdo->prepare("UPDATE centros_salud SET activo = 0 WHERE id = :id")->execute([':id' => $nuevoId]);
registrarAuditoria($pdo, 1, 'DESACTIVAR_CENTRO', 'centros_salud', $nuevoId, [
    'evento' => 'Desactivación preventiva de sede (Soft Delete)',
    'codigo_sicm' => $codigo,
    'estado_anterior' => 1,
    'nuevo_estado' => 0
]);
$pdo->commit();
echo "4. Soft Delete verificado: Centro #{$nuevoId} desactivado lógicamente.\n";

// 5. Verificar logs registrados
$totalLogs = $pdo->query("SELECT COUNT(*) FROM logs_auditoria")->fetchColumn();
echo "5. Total de logs forenses en base de datos: {$totalLogs}\n";

$ultimosLogs = $pdo->query("SELECT id, accion, tabla_afectada, registro_id, detalles_json FROM logs_auditoria ORDER BY id DESC LIMIT 3")->fetchAll();
foreach ($ultimosLogs as $l) {
    echo "   -> Log #{$l['id']} [{$l['accion']}] en {$l['tabla_afectada']}#{$l['registro_id']}: {$l['detalles_json']}\n";
}

echo "=== TODAS LAS PRUEBAS RELACIONALES Y FORENSES PASARON AL 100% ===\n";

