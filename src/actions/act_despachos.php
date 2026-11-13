<?php
declare(strict_types=1);

/**
 * Action Handler: Dispensación Asistencial y Despachos FEFO
 * Sistema Integrado de Control de Medicamentos (SICM)
 */

if (!defined('SICM_CONTROLADOR')) {
    http_response_code(403);
    exit('Acceso denegado.');
}

switch ($accion) {
    case 'procesar_despacho_fefo':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: index.php?tab=despachos');
            exit;
        }

        $tratamientoId = filter_input(INPUT_POST, 'tratamiento_id', FILTER_VALIDATE_INT);
        $loteId = filter_input(INPUT_POST, 'lote_id', FILTER_VALIDATE_INT);
        $cantidad = filter_input(INPUT_POST, 'cantidad', FILTER_VALIDATE_INT);
        $centroIdInput = filter_input(INPUT_POST, 'centro_salud_id', FILTER_VALIDATE_INT);
        $periodo = trim((string) ($_POST['periodo_mes_anio'] ?? date('Y-m')));
        $observaciones = trim((string) ($_POST['observaciones'] ?? ''));
        $qRetorno = trim((string) ($_POST['q_paciente_retorno'] ?? ''));

        $urlRetorno = 'index.php?tab=despachos' . ($qRetorno !== '' ? '&q_paciente=' . urlencode($qRetorno) : '');

        // Control de Jurisdicción Sanitaria
        $centroSaludId = puedeSupervisarTodaLaRed() ? ($centroIdInput ?: (int) $usuario['centro_salud_id']) : (int) $usuario['centro_salud_id'];

        if (!$tratamientoId || $tratamientoId <= 0) {
            header("Location: {$urlRetorno}&error=" . urlencode('Tratamiento o récipe médico inválido.'));
            exit;
        }

        if (!$loteId || $loteId <= 0) {
            header("Location: {$urlRetorno}&error=" . urlencode('Debe seleccionar un lote válido de inventario FEFO.'));
            exit;
        }

        if (!$cantidad || $cantidad <= 0) {
            header("Location: {$urlRetorno}&error=" . urlencode('La cantidad a dispensar debe ser un valor entero positivo mayor a cero.'));
            exit;
        }

        if (!$centroSaludId || $centroSaludId <= 0) {
            header("Location: {$urlRetorno}&error=" . urlencode('No se ha determinado el ambulatorio operativo para el despacho.'));
            exit;
        }

        if (!preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $periodo)) {
            $periodo = date('Y-m');
        }

        // Inyección de contexto forense SQL antes de la invocación
        establecerContextoAuditoriaSQL(
            $pdo,
            $usuarioSesionId,
            $_SESSION['sesion_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
            $_SESSION['sesion_user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null)
        );

        // Invocación del Stored Procedure transaccional
        $stmtSp = $pdo->prepare("
            CALL sp_procesar_despacho_fefo(
                :tratamiento_id,
                :centro_id,
                :usuario_id,
                :lote_id,
                :cantidad,
                :periodo,
                :obs,
                @despacho_id,
                @codigo_resultado,
                @mensaje_resultado
            )
        ");

        $stmtSp->execute([
            ':tratamiento_id' => $tratamientoId,
            ':centro_id' => $centroSaludId,
            ':usuario_id' => $usuarioSesionId,
            ':lote_id' => $loteId,
            ':cantidad' => $cantidad,
            ':periodo' => $periodo,
            ':obs' => $observaciones !== '' ? substr($observaciones, 0, 255) : null
        ]);
        $stmtSp->closeCursor();

        // Rescate de parámetros OUT del Stored Procedure
        $resOut = $pdo->query("SELECT @despacho_id AS despacho_id, @codigo_resultado AS codigo, @mensaje_resultado AS mensaje")->fetch(PDO::FETCH_ASSOC);

        $nuevoDespachoId = (int) ($resOut['despacho_id'] ?? 0);
        $codigoResultado = trim((string) ($resOut['codigo'] ?? ''));
        $mensajeProc = trim((string) ($resOut['mensaje'] ?? ''));

        if ($codigoResultado === 'ERROR' || $nuevoDespachoId <= 0) {
            $errorMsg = $mensajeProc !== '' ? $mensajeProc : 'No fue posible completar el despacho en el motor de base de datos.';
            header("Location: {$urlRetorno}&error=" . urlencode($errorMsg));
            exit;
        }

        $exitoMsg = "Dispensación FEFO #{$nuevoDespachoId} procesada satisfactoriamente con trazabilidad atómica (Periodo {$periodo}).";
        header("Location: {$urlRetorno}&msg=" . urlencode($exitoMsg));
        exit;

    case 'registrar_entrada_lote':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: index.php?tab=despachos');
            exit;
        }

        if (!tienePermiso('INV_REGISTRAR_LOTE') && !esRol('rol_admin')) {
            header('Location: index.php?tab=despachos&error=' . urlencode('No posee permisos para ingresar nuevos lotes al inventario.'));
            exit;
        }

        $medicamentoId = filter_input(INPUT_POST, 'medicamento_id', FILTER_VALIDATE_INT);
        $centroIdInput = filter_input(INPUT_POST, 'centro_salud_id', FILTER_VALIDATE_INT);
        $numeroLote = strtoupper(trim((string) ($_POST['numero_lote'] ?? '')));
        $fechaVencimiento = trim((string) ($_POST['fecha_vencimiento'] ?? ''));
        $cantidad = filter_input(INPUT_POST, 'cantidad', FILTER_VALIDATE_INT);
        $laboratorio = trim((string) ($_POST['laboratorio_proveedor'] ?? ''));
        $observaciones = trim((string) ($_POST['observaciones'] ?? ''));

        // Control de Jurisdicción Sanitaria
        $centroSaludId = puedeSupervisarTodaLaRed() ? ($centroIdInput ?: (int) $usuario['centro_salud_id']) : (int) $usuario['centro_salud_id'];

        $urlRetorno = 'index.php?tab=despachos' . ($centroSaludId > 0 ? '&centro_id=' . $centroSaludId : '');

        if (!$medicamentoId || $medicamentoId <= 0) {
            header("Location: {$urlRetorno}&error=" . urlencode('Debe seleccionar un medicamento válido del vademécum.'));
            exit;
        }

        if (!$centroSaludId || $centroSaludId <= 0) {
            header("Location: {$urlRetorno}&error=" . urlencode('Ambulatorio de destino no válido.'));
            exit;
        }

        if (mb_strlen($numeroLote, 'UTF-8') < 2 || mb_strlen($numeroLote, 'UTF-8') > 30) {
            header("Location: {$urlRetorno}&error=" . urlencode('El número de lote debe contener entre 2 y 30 caracteres.'));
            exit;
        }

        if (!preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$/', $fechaVencimiento)) {
            header("Location: {$urlRetorno}&error=" . urlencode('Formato de fecha de caducidad inválido.'));
            exit;
        }

        if ($fechaVencimiento <= date('Y-m-d')) {
            header("Location: {$urlRetorno}&error=" . urlencode('La fecha de vencimiento del lote debe ser posterior al día de hoy.'));
            exit;
        }

        if (!$cantidad || $cantidad <= 0) {
            header("Location: {$urlRetorno}&error=" . urlencode('La cantidad ingresada debe ser un número entero mayor a cero.'));
            exit;
        }

        // Validar existencia de medicamento y compatibilidad de cadena de frío
        $stmtM = $pdo->prepare("SELECT id, nombre_generico, concentracion, requiere_cadena_frio FROM medicamentos WHERE id = :id AND activo = 1");
        $stmtM->execute([':id' => $medicamentoId]);
        $medInfo = $stmtM->fetch(PDO::FETCH_ASSOC);

        if (!$medInfo) {
            header("Location: {$urlRetorno}&error=" . urlencode('El medicamento especificado no existe o no se encuentra activo en el vademécum.'));
            exit;
        }

        if ((int) $medInfo['requiere_cadena_frio'] === 1) {
            $stmtFrio = $pdo->prepare("
                SELECT COUNT(*) FROM centro_recursos cr
                JOIN recursos_operativos ro ON cr.recurso_id = ro.id
                WHERE cr.centro_salud_id = :cid AND ro.codigo = 'CADENA_FRIO' AND cr.operativo = 1
            ");
            $stmtFrio->execute([':cid' => $centroSaludId]);
            if (((int) $stmtFrio->fetchColumn()) === 0) {
                header("Location: {$urlRetorno}&error=" . urlencode('Violación sanitaria: El ambulatorio destino no cuenta con equipo operativo de Cadena de Frío para este medicamento.'));
                exit;
            }
        }

        // Obtener nombre del ambulatorio para logs
        $stmtC = $pdo->prepare("SELECT nombre, codigo_sicm FROM centros_salud WHERE id = :id");
        $stmtC->execute([':id' => $centroSaludId]);
        $centroInfo = $stmtC->fetch(PDO::FETCH_ASSOC);
        $centroNombre = $centroInfo['nombre'] ?? "Centro #{$centroSaludId}";

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        // Inyección de contexto forense SQL
        establecerContextoAuditoriaSQL(
            $pdo,
            $usuarioSesionId,
            $_SESSION['sesion_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
            $_SESSION['sesion_user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null)
        );

        // Verificar si ya existe el lote en ese ambulatorio para ese medicamento
        $stmtExist = $pdo->prepare("
            SELECT id, cantidad_actual, fecha_vencimiento
            FROM lotes_inventario
            WHERE centro_salud_id = :cid AND medicamento_id = :mid AND numero_lote = :lote
        ");
        $stmtExist->execute([
            ':cid' => $centroSaludId,
            ':mid' => $medicamentoId,
            ':lote' => $numeroLote
        ]);
        $loteExistente = $stmtExist->fetch(PDO::FETCH_ASSOC);

        if ($loteExistente) {
            $loteId = (int) $loteExistente['id'];
            $cantidadAnterior = (int) $loteExistente['cantidad_actual'];
            $nuevaCantidad = $cantidadAnterior + $cantidad;

            $stmtUpd = $pdo->prepare("
                UPDATE lotes_inventario
                SET cantidad_actual = :cant,
                    fecha_vencimiento = :venc,
                    laboratorio_proveedor = COALESCE(:lab, laboratorio_proveedor),
                    usuario_id = :uid
                WHERE id = :id
            ");
            $stmtUpd->execute([
                ':cant' => $nuevaCantidad,
                ':venc' => $fechaVencimiento,
                ':lab' => $laboratorio !== '' ? mb_substr($laboratorio, 0, 100) : null,
                ':uid' => $usuarioSesionId,
                ':id' => $loteId
            ]);

            $eventoLog = 'Entrada adicional de existencias a lote farmacéutico existente';
            $detallesAudit = [
                'evento' => $eventoLog,
                'ambulatorio' => $centroNombre,
                'medicamento' => $medInfo['nombre_generico'] . ' ' . $medInfo['concentracion'],
                'numero_lote' => $numeroLote,
                'cantidad_recibida' => $cantidad,
                'cantidad_previa' => $cantidadAnterior,
                'cantidad_total' => $nuevaCantidad,
                'fecha_vencimiento' => $fechaVencimiento,
                'laboratorio_proveedor' => $laboratorio !== '' ? $laboratorio : null,
                'observaciones' => $observaciones !== '' ? $observaciones : null
            ];
        } else {
            $stmtIns = $pdo->prepare("
                INSERT INTO lotes_inventario (
                    centro_salud_id, medicamento_id, numero_lote, fecha_vencimiento,
                    laboratorio_proveedor, cantidad_actual, usuario_id
                ) VALUES (
                    :cid, :mid, :lote, :venc, :lab, :cant, :uid
                )
            ");
            $stmtIns->execute([
                ':cid' => $centroSaludId,
                ':mid' => $medicamentoId,
                ':lote' => $numeroLote,
                ':venc' => $fechaVencimiento,
                ':lab' => $laboratorio !== '' ? mb_substr($laboratorio, 0, 100) : null,
                ':cant' => $cantidad,
                ':uid' => $usuarioSesionId
            ]);
            $loteId = (int) $pdo->lastInsertId();

            $eventoLog = 'Recepción y alta de nuevo lote farmacéutico en inventario FEFO';
            $detallesAudit = [
                'evento' => $eventoLog,
                'ambulatorio' => $centroNombre,
                'medicamento' => $medInfo['nombre_generico'] . ' ' . $medInfo['concentracion'],
                'numero_lote' => $numeroLote,
                'cantidad_ingresada' => $cantidad,
                'fecha_vencimiento' => $fechaVencimiento,
                'laboratorio_proveedor' => $laboratorio !== '' ? $laboratorio : null,
                'observaciones' => $observaciones !== '' ? $observaciones : null
            ];
        }

        registrarAuditoria($pdo, $usuarioSesionId, 'REGISTRAR_ENTRADA_LOTE', 'lotes_inventario', $loteId, $detallesAudit);

        $pdo->commit();

        $msgExito = "Entrada de stock registrada: Lote '{$numeroLote}' ({$medInfo['nombre_generico']}) incorporó {$cantidad} unidades en {$centroNombre}.";
        header("Location: {$urlRetorno}&msg=" . urlencode($msgExito));
        exit;

    default:
        header('Location: index.php?tab=despachos');
        exit;
}
