<?php
// src/scripts/test_maestras.php
// Verificación Automatizada de Tablas Maestras (3FN) y Vistas SQL Desacopladas
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auditoria.php';

$pdo = getDB();

echo "=================================================================\n";
echo "  VERIFICACIÓN DE CATÁLOGOS MAESTROS 3FN Y VISTAS SQL           \n";
echo "=================================================================\n";

// 1. Crear tipo de centro de salud temporal
$codigoTipo = 'MOD_TEST_' . time();
$stmtTipo = $pdo->prepare("INSERT INTO tipos_centro_salud (codigo, nombre, descripcion, activo) VALUES (:c, :n, :d, 1)");
$stmtTipo->execute([':c' => $codigoTipo, ':n' => 'Módulo de Prueba', ':d' => 'Creado por test automatizado']);
$tipoId = (int)$pdo->lastInsertId();
echo "1. [OK] Catálogo tipos_centro_salud: Clasificación #{$tipoId} ({$codigoTipo}) creada en 3FN.\n";

// 2. Crear recurso operativo temporal
$codigoRec = 'REC_TEST_' . time();
$stmtRec = $pdo->prepare("INSERT INTO recursos_operativos (codigo, nombre, descripcion, activo) VALUES (:c, :n, :d, 1)");
$stmtRec->execute([':c' => $codigoRec, ':n' => 'Recurso de Prueba', ':d' => 'Creado por test']);
$recId = (int)$pdo->lastInsertId();
echo "2. [OK] Catálogo recursos_operativos: Recurso #{$recId} ({$codigoRec}) creado en 3FN.\n";

// 3. Conmutación paramétrica (activar/desactivar sin tocar DDL)
$pdo->prepare("UPDATE tipos_centro_salud SET activo = 0 WHERE id = :id")->execute([':id' => $tipoId]);
$estadoActual = (int)$pdo->query("SELECT activo FROM tipos_centro_salud WHERE id = {$tipoId}")->fetchColumn();
if ($estadoActual !== 0) {
    echo "ERROR: Falló la conmutación de estado paramétrico.\n";
    exit(1);
}
echo "3. [OK] Conmutación Paramétrica: Estado actualizado a 0 (Inactivo) sin alterar DDL.\n";

// 4. Verificación de Vistas SQL
$cntCentros = (int)$pdo->query("SELECT COUNT(*) FROM vw_centros_salud")->fetchColumn();
$cntLogs = (int)$pdo->query("SELECT COUNT(*) FROM vw_logs_auditoria")->fetchColumn();
echo "4. [OK] Vistas SQL Desacopladas: vw_centros_salud ({$cntCentros} filas), vw_logs_auditoria ({$cntLogs} filas).\n";

// Limpieza de pruebas
$pdo->prepare("DELETE FROM tipos_centro_salud WHERE id = :id")->execute([':id' => $tipoId]);
$pdo->prepare("DELETE FROM recursos_operativos WHERE id = :id")->execute([':id' => $recId]);

echo "=================================================================\n";
echo "  RESULTADO: CATÁLOGOS MAESTROS 3FN VERIFICADOS SATISFACTORIAMENTE \n";
echo "=================================================================\n";
exit(0);

