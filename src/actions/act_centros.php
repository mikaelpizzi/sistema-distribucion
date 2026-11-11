<?php
// src/actions/act_centros.php
// SICM | Action Handler: Ambulatorios y Red Asistencial
declare(strict_types=1);

switch ($accion) {
    case 'guardar_centro':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: index.php?tab=centros');
            exit;
        }

        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        $codigo = strtoupper(trim($_POST['codigo_sicm'] ?? ''));
        $nombre = trim($_POST['nombre'] ?? '');
        $tipoId = (int) ($_POST['tipo_centro_id'] ?? 0);
        $medicoDirector = trim($_POST['medico_director'] ?? '');
        $horarioAtencion = trim($_POST['horario_atencion'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $telefono = trim($_POST['telefono_principal'] ?? '');
        $recursos = isset($_POST['recursos']) && is_array($_POST['recursos'])
            ? array_map('intval', $_POST['recursos'])
            : [];

        if (mb_strlen($codigo, 'UTF-8') < 3 || mb_strlen($nombre, 'UTF-8') < 4 || $tipoId <= 0 || mb_strlen($direccion, 'UTF-8') < 6) {
            header('Location: index.php?tab=centros&error=' . urlencode('Verifique los campos requeridos: Código SICM, Nombre, Clasificación y Dirección.'));
            exit;
        }

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        if ($id === null) {
            // CREATE
            $sql = "INSERT INTO centros_salud (codigo_sicm, nombre, tipo_centro_id, medico_director, horario_atencion, direccion, telefono_principal, activo)
                    VALUES (:codigo, :nombre, :tipo_id, :director, :horario, :direccion, :telefono, 1)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':codigo' => $codigo,
                ':nombre' => $nombre,
                ':tipo_id' => $tipoId,
                ':director' => $medicoDirector !== '' ? $medicoDirector : null,
                ':horario' => $horarioAtencion !== '' ? $horarioAtencion : null,
                ':direccion' => $direccion,
                ':telefono' => $telefono !== '' ? $telefono : null
            ]);
            $nuevoId = (int) $pdo->lastInsertId();

            // Sincronizar Relación N:M
            if (!empty($recursos)) {
                $stmtRec = $pdo->prepare("INSERT INTO centro_recursos (centro_salud_id, recurso_id, operativo) VALUES (:c, :r, 1)");
                foreach ($recursos as $rId) {
                    $stmtRec->execute([':c' => $nuevoId, ':r' => $rId]);
                }
            }

            registrarAuditoria($pdo, $usuarioSesionId, 'CREAR_CENTRO', 'centros_salud', $nuevoId, [
                'evento' => 'Registro de ambulatorio en la red municipal',
                'codigo_sicm' => $codigo,
                'nombre' => $nombre,
                'recursos_ids' => $recursos
            ]);

            $pdo->commit();
            header('Location: index.php?tab=centros&msg=' . urlencode("Ambulatorio '{$nombre}' ({$codigo}) registrado exitosamente."));
            exit;

        } else {
            // UPDATE con Delta Forense
            $stmtPrev = $pdo->prepare("SELECT * FROM centros_salud WHERE id = :id");
            $stmtPrev->execute([':id' => $id]);
            $prev = $stmtPrev->fetch();

            if (!$prev) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                header('Location: index.php?tab=centros&error=' . urlencode('El ambulatorio no existe.'));
                exit;
            }

            $stmtRecPrev = $pdo->prepare("SELECT recurso_id FROM centro_recursos WHERE centro_salud_id = :id");
            $stmtRecPrev->execute([':id' => $id]);
            $recursosPrevios = array_map('intval', $stmtRecPrev->fetchAll(PDO::FETCH_COLUMN));

            $sql = "UPDATE centros_salud 
                    SET codigo_sicm = :codigo, nombre = :nombre, tipo_centro_id = :tipo_id, 
                        medico_director = :director, horario_atencion = :horario,
                        direccion = :direccion, telefono_principal = :telefono
                    WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':codigo' => $codigo,
                ':nombre' => $nombre,
                ':tipo_id' => $tipoId,
                ':director' => $medicoDirector !== '' ? $medicoDirector : null,
                ':horario' => $horarioAtencion !== '' ? $horarioAtencion : null,
                ':direccion' => $direccion,
                ':telefono' => $telefono !== '' ? $telefono : null,
                ':id' => $id
            ]);

            // Sincronización 1FN: telefonos_centro_salud
            if ($telefono !== '') {
                $stmtTipoTel = $pdo->query("SELECT id FROM tipos_contacto WHERE codigo IN ('TEL_FIJO', 'TEL_MOVIL') ORDER BY id ASC LIMIT 1");
                $tipoTelId = (int) ($stmtTipoTel->fetchColumn() ?: 1);

                $stmtCheckTel = $pdo->prepare("SELECT id FROM telefonos_centro_salud WHERE centro_salud_id = :cid AND es_principal = 1 LIMIT 1");
                $stmtCheckTel->execute([':cid' => $id]);
                $telId = $stmtCheckTel->fetchColumn();

                if ($telId) {
                    $stmtUpdTel = $pdo->prepare("UPDATE telefonos_centro_salud SET numero = :num WHERE id = :id");
                    $stmtUpdTel->execute([':num' => $telefono, ':id' => $telId]);
                } else {
                    $stmtInsTel = $pdo->prepare("INSERT INTO telefonos_centro_salud (centro_salud_id, tipo_contacto_id, numero, es_principal, observaciones) VALUES (:cid, :tipo_id, :num, 1, 'Teléfono Principal de Contacto')");
                    $stmtInsTel->execute([':cid' => $id, ':tipo_id' => $tipoTelId, ':num' => $telefono]);
                }
            } else {
                $pdo->prepare("DELETE FROM telefonos_centro_salud WHERE centro_salud_id = :cid AND es_principal = 1")->execute([':cid' => $id]);
            }

            $pdo->prepare("DELETE FROM centro_recursos WHERE centro_salud_id = :id")->execute([':id' => $id]);
            if (!empty($recursos)) {
                $stmtRec = $pdo->prepare("INSERT INTO centro_recursos (centro_salud_id, recurso_id, operativo) VALUES (:c, :r, 1)");
                foreach ($recursos as $rId) {
                    $stmtRec->execute([':c' => $id, ':r' => $rId]);
                }
            }

            // Cálculo de Campos Alterados
            $antes = [
                'codigo_sicm' => $prev['codigo_sicm'],
                'nombre' => $prev['nombre'],
                'tipo_centro_id' => (int) $prev['tipo_centro_id'],
                'medico_director' => $prev['medico_director'],
                'horario' => $prev['horario_atencion'],
                'direccion' => $prev['direccion'],
                'telefono' => $prev['telefono_principal'],
                'recursos_ids' => $recursosPrevios
            ];
            $despues = [
                'codigo_sicm' => $codigo,
                'nombre' => $nombre,
                'tipo_centro_id' => $tipoId,
                'medico_director' => $medicoDirector !== '' ? $medicoDirector : null,
                'horario' => $horarioAtencion !== '' ? $horarioAtencion : null,
                'direccion' => $direccion,
                'telefono' => $telefono !== '' ? $telefono : null,
                'recursos_ids' => $recursos
            ];

            $delta = [];
            foreach ($despues as $k => $v) {
                if ($antes[$k] != $v) {
                    $delta[$k] = ['antes' => $antes[$k], 'despues' => $v];
                }
            }

            registrarAuditoria($pdo, $usuarioSesionId, 'EDITAR_CENTRO', 'centros_salud', $id, [
                'evento' => 'Actualización de ficha operativa de ambulatorio',
                'nombre' => $nombre,
                'codigo_sicm' => $codigo,
                'campos_alterados' => array_keys($delta),
                'delta' => $delta
            ]);

            $pdo->commit();
            header('Location: index.php?tab=centros&msg=' . urlencode("Ambulatorio #{$id} actualizado."));
            exit;
        }

    case 'cambiar_estado_centro':
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        $nuevoEstado = isset($_POST['estado']) ? (int) $_POST['estado'] : (isset($_GET['estado']) ? (int) $_GET['estado'] : null);

        if (!$id || ($nuevoEstado !== 0 && $nuevoEstado !== 1)) {
            header('Location: index.php?tab=centros&error=' . urlencode('Solicitud inválida.'));
            exit;
        }

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }
        $stmt = $pdo->prepare("SELECT codigo_sicm, nombre FROM centros_salud WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $centro = $stmt->fetch();

        if (!$centro) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            header('Location: index.php?tab=centros&error=' . urlencode('Ambulatorio no encontrado.'));
            exit;
        }

        $pdo->prepare("UPDATE centros_salud SET activo = :st WHERE id = :id")->execute([':st' => $nuevoEstado, ':id' => $id]);

        $accionLog = ($nuevoEstado === 1) ? 'REACTIVAR_CENTRO' : 'DESACTIVAR_CENTRO';
        registrarAuditoria($pdo, $usuarioSesionId, $accionLog, 'centros_salud', $id, [
            'evento' => ($nuevoEstado === 1) ? 'Reactivación en red' : 'Soft Delete (activo=0)',
            'codigo_sicm' => $centro['codigo_sicm'],
            'nombre' => $centro['nombre']
        ]);

        $pdo->commit();
        $msg = ($nuevoEstado === 1)
            ? "Ambulatorio '{$centro['nombre']}' reactivado exitosamente."
            : "Ambulatorio '{$centro['nombre']}' desactivado lógicamente (Soft Delete).";
        header('Location: index.php?tab=centros&msg=' . urlencode($msg));
        exit;

    default:
        header('Location: index.php?tab=centros');
        exit;
}
