<?php
// src/actions/act_medicamentos.php
// SICM | Action Handler: Catálogo de Fármacos y Vademécum
declare(strict_types=1);

switch ($accion) {
    case 'guardar_medicamento':
        if (!empty($_POST['id'])) {
            $accion = 'editar_medicamento';
        } else {
            $accion = 'crear_medicamento';
        }
        // Fallthrough to appropriate branch below
        if ($accion === 'crear_medicamento') {
            goto handle_crear_medicamento;
        } else {
            goto handle_editar_medicamento;
        }

    case 'crear_medicamento':
        handle_crear_medicamento:
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: index.php?tab=medicamentos');
            exit;
        }

        $nombre = trim($_POST['nombre_generico'] ?? '');
        $concentracion = trim($_POST['concentracion'] ?? '');
        $formaFarmaceuticaId = (int) ($_POST['forma_farmaceutica_id'] ?? 0);
        $requiereFrio = !empty($_POST['requiere_cadena_frio']) ? 1 : 0;
        $stockMinimo = max(1, (int) ($_POST['stock_minimo_sugerido'] ?? 20));

        if (mb_strlen($nombre, 'UTF-8') < 2 || mb_strlen($concentracion, 'UTF-8') < 1 || $formaFarmaceuticaId <= 0) {
            header('Location: index.php?tab=medicamentos&subtab=vademecum&error=' . urlencode('Verifique los campos requeridos: Nombre Genérico, Concentración y Forma Farmacéutica.'));
            exit;
        }

        $stmtCheckF = $pdo->prepare("SELECT id, nombre FROM formas_farmaceuticas WHERE id = :id");
        $stmtCheckF->execute([':id' => $formaFarmaceuticaId]);
        $formaInfo = $stmtCheckF->fetch();
        if (!$formaInfo) {
            header('Location: index.php?tab=medicamentos&subtab=vademecum&error=' . urlencode('La forma farmacéutica seleccionada no es válida.'));
            exit;
        }

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        $stmtIns = $pdo->prepare("
            INSERT INTO medicamentos (nombre_generico, concentracion, forma_farmaceutica_id, requiere_cadena_frio, stock_minimo_sugerido, activo)
            VALUES (:nombre, :conc, :forma_id, :frio, :stock, 1)
        ");
        $stmtIns->execute([
            ':nombre' => $nombre,
            ':conc' => $concentracion,
            ':forma_id' => $formaFarmaceuticaId,
            ':frio' => $requiereFrio,
            ':stock' => $stockMinimo
        ]);
        $nuevoId = (int) $pdo->lastInsertId();

        registrarAuditoria($pdo, $usuarioSesionId, 'CREAR_MEDICAMENTO', 'medicamentos', $nuevoId, [
            'evento' => 'Alta de fármaco en Vademécum Institucional',
            'nombre_generico' => $nombre,
            'concentracion' => $concentracion,
            'forma_farmaceutica' => $formaInfo['nombre'],
            'requiere_cadena_frio' => $requiereFrio,
            'stock_minimo_sugerido' => $stockMinimo
        ]);

        $pdo->commit();
        header('Location: index.php?tab=medicamentos&subtab=vademecum&msg=' . urlencode("Medicamento '{$nombre} ({$concentracion})' registrado exitosamente en el vademécum municipal."));
        exit;

    case 'editar_medicamento':
        handle_editar_medicamento:
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: index.php?tab=medicamentos');
            exit;
        }

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $nombre = trim($_POST['nombre_generico'] ?? '');
        $concentracion = trim($_POST['concentracion'] ?? '');
        $formaFarmaceuticaId = (int) ($_POST['forma_farmaceutica_id'] ?? 0);
        $requiereFrio = !empty($_POST['requiere_cadena_frio']) ? 1 : 0;
        $stockMinimo = max(1, (int) ($_POST['stock_minimo_sugerido'] ?? 20));

        if (!$id || mb_strlen($nombre, 'UTF-8') < 2 || mb_strlen($concentracion, 'UTF-8') < 1 || $formaFarmaceuticaId <= 0) {
            header('Location: index.php?tab=medicamentos&subtab=vademecum&error=' . urlencode('Verifique los campos requeridos: ID, Nombre Genérico, Concentración y Forma Farmacéutica.'));
            exit;
        }

        $stmtOld = $pdo->prepare("SELECT * FROM medicamentos WHERE id = :id");
        $stmtOld->execute([':id' => $id]);
        $antiguo = $stmtOld->fetch();
        if (!$antiguo) {
            header('Location: index.php?tab=medicamentos&subtab=vademecum&error=' . urlencode('El medicamento especificado no existe.'));
            exit;
        }

        $stmtCheckF = $pdo->prepare("SELECT id, nombre FROM formas_farmaceuticas WHERE id = :id");
        $stmtCheckF->execute([':id' => $formaFarmaceuticaId]);
        $formaInfo = $stmtCheckF->fetch();
        if (!$formaInfo) {
            header('Location: index.php?tab=medicamentos&subtab=vademecum&error=' . urlencode('La forma farmacéutica seleccionada no es válida.'));
            exit;
        }

        $delta = [];
        if ($antiguo['nombre_generico'] !== $nombre) {
            $delta['nombre_generico'] = ['antes' => $antiguo['nombre_generico'], 'despues' => $nombre];
        }
        if ($antiguo['concentracion'] !== $concentracion) {
            $delta['concentracion'] = ['antes' => $antiguo['concentracion'], 'despues' => $concentracion];
        }
        if ((int) $antiguo['forma_farmaceutica_id'] !== $formaFarmaceuticaId) {
            $delta['forma_farmaceutica_id'] = ['antes' => (int) $antiguo['forma_farmaceutica_id'], 'despues' => $formaFarmaceuticaId];
        }
        if ((int) $antiguo['requiere_cadena_frio'] !== $requiereFrio) {
            $delta['requiere_cadena_frio'] = ['antes' => (int) $antiguo['requiere_cadena_frio'], 'despues' => $requiereFrio];
        }
        if ((int) $antiguo['stock_minimo_sugerido'] !== $stockMinimo) {
            $delta['stock_minimo_sugerido'] = ['antes' => (int) $antiguo['stock_minimo_sugerido'], 'despues' => $stockMinimo];
        }

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        // La actualización dispara automáticamente el trigger trg_medicamentos_audit_delta
        $stmtUpd = $pdo->prepare("
            UPDATE medicamentos
            SET nombre_generico = :nombre,
                concentracion = :conc,
                forma_farmaceutica_id = :forma_id,
                requiere_cadena_frio = :frio,
                stock_minimo_sugerido = :stock
            WHERE id = :id
        ");
        $stmtUpd->execute([
            ':nombre' => $nombre,
            ':conc' => $concentracion,
            ':forma_id' => $formaFarmaceuticaId,
            ':frio' => $requiereFrio,
            ':stock' => $stockMinimo,
            ':id' => $id
        ]);

        registrarAuditoria($pdo, $usuarioSesionId, 'EDITAR_MEDICAMENTO', 'medicamentos', $id, [
            'evento' => 'Actualización de especificaciones de fármaco en Vademécum',
            'nombre_generico' => $nombre,
            'delta' => !empty($delta) ? $delta : 'Sin modificaciones detectadas'
        ]);

        $pdo->commit();
        header('Location: index.php?tab=medicamentos&subtab=vademecum&msg=' . urlencode("Medicamento '{$nombre}' actualizado correctamente."));
        exit;

    case 'cambiar_estado_medicamento':
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

        if (!$id) {
            header('Location: index.php?tab=medicamentos&subtab=vademecum&error=' . urlencode('Identificador de fármaco inválido.'));
            exit;
        }

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        $stmt = $pdo->prepare("SELECT id, nombre_generico, concentracion, activo FROM medicamentos WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $med = $stmt->fetch();

        if (!$med) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            header('Location: index.php?tab=medicamentos&subtab=vademecum&error=' . urlencode('Fármaco no encontrado.'));
            exit;
        }

        $nuevoEstado = ((int) $med['activo'] === 1) ? 0 : 1;
        $stmtUpd = $pdo->prepare("UPDATE medicamentos SET activo = :activo WHERE id = :id");
        $stmtUpd->execute([':activo' => $nuevoEstado, ':id' => $id]);

        $accionLog = ($nuevoEstado === 1) ? 'REACTIVAR_MEDICAMENTO' : 'DESACTIVAR_MEDICAMENTO';
        registrarAuditoria($pdo, $usuarioSesionId, $accionLog, 'medicamentos', $id, [
            'evento' => ($nuevoEstado === 1) ? 'Reactivación de fármaco en vademécum' : 'Descontinuación de fármaco (Soft Delete)',
            'nombre_generico' => $med['nombre_generico'],
            'concentracion' => $med['concentracion'],
            'estado_anterior' => (int) $med['activo'],
            'nuevo_estado' => $nuevoEstado
        ]);

        $pdo->commit();
        $msg = ($nuevoEstado === 1)
            ? "Fármaco '{$med['nombre_generico']}' reactivado exitosamente en el vademécum."
            : "Fármaco '{$med['nombre_generico']}' descontinuado del vademécum operativo.";
        header('Location: index.php?tab=medicamentos&subtab=vademecum&msg=' . urlencode($msg));
        exit;

    default:
        header('Location: index.php?tab=medicamentos');
        exit;
}
