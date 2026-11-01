<?php
// src/maestras/procesar.php
// Controlador para administración dinámica de Tablas Maestras (Catálogos Parametrizables)
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auditoria.php';

$pdo = getDB();
$accion = $_REQUEST['accion'] ?? '';
$usuarioSesionId = 1; // Administrador Central

try {
    switch ($accion) {
        // ----------------------------------------------------------------------
        // 1. CREAR NUEVA CLASIFICACIÓN / TIPO DE SEDE (tipos_centro_salud)
        // ----------------------------------------------------------------------
        case 'crear_tipo_centro':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Location: index.php');
                exit;
            }

            $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
            $nombre = trim($_POST['nombre'] ?? '');
            $descripcion = trim($_POST['descripcion'] ?? '');

            if (mb_strlen($codigo, 'UTF-8') < 2 || mb_strlen($nombre, 'UTF-8') < 3) {
                header('Location: index.php?tab=tipos&error=' . urlencode('El código y nombre de la clasificación son obligatorios.'));
                exit;
            }

            $pdo->beginTransaction();

            $sql = "INSERT INTO tipos_centro_salud (codigo, nombre, descripcion) VALUES (:codigo, :nombre, :descripcion)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':codigo'      => $codigo,
                ':nombre'      => $nombre,
                ':descripcion' => $descripcion !== '' ? $descripcion : null
            ]);
            $nuevoId = (int)$pdo->lastInsertId();

            // Auditoría Forense
            registrarAuditoria($pdo, $usuarioSesionId, 'CREAR_TIPO_CENTRO', 'tipos_centro_salud', $nuevoId, [
                'evento' => 'Parametrización de nueva clasificación sanitaria / sede',
                'datos_creados' => [
                    'codigo'      => $codigo,
                    'nombre'      => $nombre,
                    'descripcion' => $descripcion
                ]
            ]);

            $pdo->commit();
            header('Location: index.php?tab=tipos&msg=' . urlencode("Clasificación '{$nombre}' ({$codigo}) creada exitosamente."));
            exit;

        // ----------------------------------------------------------------------
        // 2. CREAR NUEVO RECURSO / CAPACIDAD OPERATIVA (recursos_operativos)
        // ----------------------------------------------------------------------
        case 'crear_recurso':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Location: index.php');
                exit;
            }

            $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
            $nombre = trim($_POST['nombre'] ?? '');
            $descripcion = trim($_POST['descripcion'] ?? '');

            if (mb_strlen($codigo, 'UTF-8') < 2 || mb_strlen($nombre, 'UTF-8') < 3) {
                header('Location: index.php?tab=recursos&error=' . urlencode('El código y nombre del recurso son obligatorios.'));
                exit;
            }

            $pdo->beginTransaction();

            $sql = "INSERT INTO recursos_operativos (codigo, nombre, descripcion, activo) VALUES (:codigo, :nombre, :descripcion, 1)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':codigo'      => $codigo,
                ':nombre'      => $nombre,
                ':descripcion' => $descripcion !== '' ? $descripcion : null
            ]);
            $nuevoId = (int)$pdo->lastInsertId();

            // Auditoría Forense
            registrarAuditoria($pdo, $usuarioSesionId, 'CREAR_RECURSO_OPERATIVO', 'recursos_operativos', $nuevoId, [
                'evento' => 'Parametrización de nuevo recurso/capacidad operativa N:M',
                'datos_creados' => [
                    'codigo'      => $codigo,
                    'nombre'      => $nombre,
                    'descripcion' => $descripcion
                ]
            ]);

            $pdo->commit();
            header('Location: index.php?tab=recursos&msg=' . urlencode("Recurso operativo '{$nombre}' ({$codigo}) agregado al catálogo maestro."));
            exit;

        // ----------------------------------------------------------------------
        // 3. CONMUTAR ESTADO DE RECURSO OPERATIVO (Soft Toggle)
        // ----------------------------------------------------------------------
        case 'toggle_recurso':
            $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
            $estado = isset($_GET['estado']) ? (int)$_GET['estado'] : null;

            if (!$id || ($estado !== 0 && $estado !== 1)) {
                header('Location: index.php?tab=recursos&error=' . urlencode('Parámetros de estado inválidos.'));
                exit;
            }

            $pdo->beginTransaction();

            $stmtPrev = $pdo->prepare("SELECT codigo, nombre, activo FROM recursos_operativos WHERE id = :id");
            $stmtPrev->execute([':id' => $id]);
            $recurso = $stmtPrev->fetch();

            if (!$recurso) {
                $pdo->rollBack();
                header('Location: index.php?tab=recursos&error=' . urlencode('Recurso no encontrado.'));
                exit;
            }

            $pdo->prepare("UPDATE recursos_operativos SET activo = :estado WHERE id = :id")->execute([
                ':estado' => $estado,
                ':id'     => $id
            ]);

            $accionLog = ($estado === 1) ? 'REACTIVAR_RECURSO_OPERATIVO' : 'DESACTIVAR_RECURSO_OPERATIVO';
            registrarAuditoria($pdo, $usuarioSesionId, $accionLog, 'recursos_operativos', $id, [
                'evento'          => ($estado === 1) ? 'Reactivación de recurso en catálogo maestro' : 'Inactivación de recurso en catálogo maestro',
                'codigo'          => $recurso['codigo'],
                'nombre'          => $recurso['nombre'],
                'estado_anterior' => (int)$recurso['activo'],
                'nuevo_estado'    => $estado
            ]);

            $pdo->commit();
            $msg = ($estado === 1) ? "Recurso '{$recurso['nombre']}' activado." : "Recurso '{$recurso['nombre']}' marcado como inactivo.";
            header('Location: index.php?tab=recursos&msg=' . urlencode($msg));
            exit;

        default:
            header('Location: index.php');
            exit;
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e->getCode() === '23000') {
        $error = "Error de Integridad: Ya existe un registro con ese código en el catálogo maestro.";
    } else {
        $error = "Fallo de Base de Datos: " . $e->getMessage();
    }
    header('Location: index.php?error=' . urlencode($error));
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header('Location: index.php?error=' . urlencode($e->getMessage()));
    exit;
}
