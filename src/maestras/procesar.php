<?php
// src/maestras/procesar.php
// Controlador para Ficha Maestra, Configuración EAV y Catálogos Parametrizables
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auditoria.php';

$pdo = getDB();
$accion = $_REQUEST['accion'] ?? '';
$usuarioSesionId = 1;

try {
    switch ($accion) {
        // ----------------------------------------------------------------------
        // 1. CONMUTAR ESTADO DE CAMPO EAV (ON / OFF)
        // ----------------------------------------------------------------------
        case 'toggle_campo_eav':
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?? filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
            $estado = isset($_REQUEST['estado']) ? (int)$_REQUEST['estado'] : null;

            if (!$id || ($estado !== 0 && $estado !== 1)) {
                header('Location: index.php?error=' . urlencode('Parámetros de campo dinámico inválidos.'));
                exit;
            }

            $stmt = $pdo->prepare("UPDATE campos_configurables SET activo = :estado WHERE id = :id");
            $stmt->execute([':estado' => $estado, ':id' => $id]);

            header('Location: index.php?msg=' . urlencode('Configuración del formulario actualizada en caliente.'));
            exit;

        // ----------------------------------------------------------------------
        // 2. CREAR NUEVO CAMPO EAV
        // ----------------------------------------------------------------------
        case 'crear_campo_eav':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Location: index.php');
                exit;
            }

            $nombre = trim($_POST['nombre'] ?? '');
            $tipoDato = trim($_POST['tipo_dato'] ?? 'texto');

            if (!in_array($tipoDato, ['texto', 'numero', 'booleano'], true)) {
                $tipoDato = 'texto';
            }

            if (mb_strlen($nombre, 'UTF-8') < 2) {
                header('Location: index.php?error=' . urlencode('El nombre del campo es obligatorio.'));
                exit;
            }

            // Generar clave slug
            $clave = strtolower(trim(preg_replace('/[^A-Za-z0-9_]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $nombre)), '_'));
            if ($clave === '') {
                $clave = 'campo_' . time();
            }

            $stmt = $pdo->prepare("INSERT INTO campos_configurables (nombre, clave, tipo_dato, activo) VALUES (:nombre, :clave, :tipo, 1)");
            $stmt->execute([':nombre' => $nombre, ':clave' => $clave, ':tipo' => $tipoDato]);
            $nuevoId = (int)$pdo->lastInsertId();

            registrarAuditoria($pdo, $usuarioSesionId, 'CREAR_CAMPO_EAV', 'campos_configurables', $nuevoId, [
                'evento' => 'Nuevo campo dinámico añadido al formulario en caliente',
                'nombre' => $nombre,
                'clave'  => $clave,
                'tipo'   => $tipoDato
            ]);

            header('Location: index.php?msg=' . urlencode("Campo '{$nombre}' añadido al formulario."));
            exit;

        // ----------------------------------------------------------------------
        // 3. ELIMINAR CAMPO EAV
        // ----------------------------------------------------------------------
        case 'eliminar_campo_eav':
            $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?? filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            if (!$id) {
                header('Location: index.php?error=' . urlencode('Campo no especificado.'));
                exit;
            }

            $stmtPrev = $pdo->prepare("SELECT nombre FROM campos_configurables WHERE id = :id");
            $stmtPrev->execute([':id' => $id]);
            $nombreCampo = $stmtPrev->fetchColumn() ?: 'Campo #' . $id;

            $pdo->prepare("DELETE FROM campos_configurables WHERE id = :id")->execute([':id' => $id]);

            registrarAuditoria($pdo, $usuarioSesionId, 'ELIMINAR_CAMPO_EAV', 'campos_configurables', $id, [
                'evento' => 'Campo dinámico retirado de la configuración',
                'nombre' => $nombreCampo
            ]);

            header('Location: index.php?msg=' . urlencode("Campo '{$nombreCampo}' eliminado del formulario."));
            exit;

        // ----------------------------------------------------------------------
        // 4. GUARDAR FICHA DE CENTRO (DATOS BÁSICOS + N:M + VALORES EAV)
        // ----------------------------------------------------------------------
        case 'guardar_ficha_centro':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Location: index.php');
                exit;
            }

            $nombre    = trim($_POST['nombre'] ?? '');
            $codigo    = strtoupper(trim($_POST['codigo_sicm'] ?? ''));
            $direccion = trim($_POST['direccion'] ?? '');
            $tipoId    = (int)($_POST['tipo_centro_id'] ?? 0);
            
            $recursos = isset($_POST['recursos']) && is_array($_POST['recursos']) 
                ? array_map('intval', $_POST['recursos']) 
                : [];

            $eavValores = isset($_POST['eav']) && is_array($_POST['eav']) 
                ? $_POST['eav'] 
                : [];

            if (mb_strlen($codigo, 'UTF-8') < 3 || mb_strlen($nombre, 'UTF-8') < 4 || $tipoId <= 0 || mb_strlen($direccion, 'UTF-8') < 5) {
                header('Location: index.php?error=' . urlencode('Verifique los campos obligatorios: Código SICM, Nombre, Dirección y Clasificación.'));
                exit;
            }

            $pdo->beginTransaction();

            // 1. Insertar centro de salud base
            $sql = "INSERT INTO centros_salud (codigo_sicm, nombre, tipo_centro_id, direccion, activo)
                    VALUES (:codigo, :nombre, :tipo_id, :direccion, 1)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':codigo'    => $codigo,
                ':nombre'    => $nombre,
                ':tipo_id'   => $tipoId,
                ':direccion' => $direccion
            ]);
            $nuevoCentroId = (int)$pdo->lastInsertId();

            // 2. Insertar capacidades N:M
            if (!empty($recursos)) {
                $stmtRec = $pdo->prepare("INSERT INTO centro_recursos (centro_salud_id, recurso_id, operativo) VALUES (:c, :r, 1)");
                foreach ($recursos as $rId) {
                    $stmtRec->execute([':c' => $nuevoCentroId, ':r' => $rId]);
                }
            }

            // 3. Insertar valores dinámicos EAV
            $stmtEav = $pdo->prepare("INSERT INTO centro_valores_eav (centro_salud_id, campo_id, valor) VALUES (:c, :campo, :val)");
            $eavGuardados = [];
            foreach ($eavValores as $campoId => $val) {
                $campoIdInt = (int)$campoId;
                $valStr = trim((string)$val);
                if ($campoIdInt > 0 && $valStr !== '') {
                    $stmtEav->execute([
                        ':c'     => $nuevoCentroId,
                        ':campo' => $campoIdInt,
                        ':val'   => $valStr
                    ]);
                    $eavGuardados[$campoIdInt] = $valStr;
                }
            }

            // 4. Registrar auditoría forense completa
            registrarAuditoria($pdo, $usuarioSesionId, 'REGISTRAR_FICHA_MAESTRA', 'centros_salud', $nuevoCentroId, [
                'evento' => 'Registro de ficha de centro con capacidades N:M y atributos EAV',
                'codigo_sicm'     => $codigo,
                'nombre'          => $nombre,
                'tipo_centro_id'  => $tipoId,
                'recursos_ids'    => $recursos,
                'atributos_eav'   => $eavGuardados
            ]);

            $pdo->commit();
            header('Location: index.php?msg=' . urlencode("Ficha del centro '{$nombre}' ({$codigo}) registrada exitosamente."));
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
        $msg = "Error de Integridad: El código SICM '{$codigo}' ya se encuentra registrado.";
    } else {
        $msg = "Fallo de Base de Datos: " . $e->getMessage();
    }
    header('Location: index.php?error=' . urlencode($msg));
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header('Location: index.php?error=' . urlencode($e->getMessage()));
    exit;
}
