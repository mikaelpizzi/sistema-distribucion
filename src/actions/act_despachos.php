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

    default:
        header('Location: index.php?tab=despachos');
        exit;
}
