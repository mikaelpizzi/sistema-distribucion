<?php
// src/actions/act_recipes.php
// SICM | Action Handler: Récipes Médicos y Tratamientos Autorizados
declare(strict_types=1);

switch ($accion) {
    case 'guardar_recipe':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: index.php?tab=recipes');
            exit;
        }

        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        $pacienteId = (int) ($_POST['paciente_id'] ?? 0);
        $medicamentoId = (int) ($_POST['medicamento_id'] ?? 0);
        $patologiaId = (int) ($_POST['patologia_id'] ?? 0);
        $dosisMensual = (int) ($_POST['dosis_mensual'] ?? 0);
        $fechaEmision = trim((string) ($_POST['fecha_emision_recipe'] ?? ''));
        $fechaVencimiento = trim((string) ($_POST['fecha_vencimiento_recipe'] ?? ''));
        $medicoPrescriptor = trim((string) ($_POST['medico_prescriptor'] ?? ''));

        if ($pacienteId <= 0) {
            header('Location: index.php?tab=recipes&error=' . urlencode('Debe seleccionar un paciente registrado en el padrón.'));
            exit;
        }

        if ($medicamentoId <= 0) {
            header('Location: index.php?tab=recipes&error=' . urlencode('Debe seleccionar un medicamento del vademécum.'));
            exit;
        }

        if ($patologiaId <= 0) {
            header('Location: index.php?tab=recipes&error=' . urlencode('Debe seleccionar una patología CIE-10 válida.'));
            exit;
        }

        if ($dosisMensual <= 0) {
            header('Location: index.php?tab=recipes&error=' . urlencode('La dosis mensual autorizada debe ser mayor a cero.'));
            exit;
        }

        if (empty($fechaEmision) || !DateTime::createFromFormat('Y-m-d', $fechaEmision)) {
            header('Location: index.php?tab=recipes&error=' . urlencode('La fecha de emisión del récipe es inválida.'));
            exit;
        }

        if (empty($fechaVencimiento) || !DateTime::createFromFormat('Y-m-d', $fechaVencimiento)) {
            header('Location: index.php?tab=recipes&error=' . urlencode('La fecha de vencimiento del récipe es inválida.'));
            exit;
        }

        if ($fechaVencimiento < $fechaEmision) {
            header('Location: index.php?tab=recipes&error=' . urlencode('La fecha de vencimiento no puede ser anterior a la fecha de emisión del récipe.'));
            exit;
        }

        if (mb_strlen($medicoPrescriptor, 'UTF-8') < 3) {
            header('Location: index.php?tab=recipes&error=' . urlencode('El médico prescriptor y matrícula MPPS deben contener al menos 3 caracteres.'));
            exit;
        }

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        // Validar paciente existente
        $stmtPac = $pdo->prepare("SELECT id, cedula, nombre_completo FROM pacientes WHERE id = :id");
        $stmtPac->execute([':id' => $pacienteId]);
        $paciente = $stmtPac->fetch(PDO::FETCH_ASSOC);
        if (!$paciente) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            header('Location: index.php?tab=recipes&error=' . urlencode('El paciente seleccionado no existe en el sistema.'));
            exit;
        }

        // Validar medicamento existente y activo
        $stmtMed = $pdo->prepare("SELECT id, nombre_generico, concentracion FROM medicamentos WHERE id = :id AND activo = 1");
        $stmtMed->execute([':id' => $medicamentoId]);
        $med = $stmtMed->fetch(PDO::FETCH_ASSOC);
        if (!$med) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            header('Location: index.php?tab=recipes&error=' . urlencode('El medicamento seleccionado no está disponible en el vademécum.'));
            exit;
        }

        // Validar patología existente y activa
        $stmtPat = $pdo->prepare("SELECT id, codigo_cie10, nombre FROM patologias WHERE id = :id AND activo = 1");
        $stmtPat->execute([':id' => $patologiaId]);
        $pat = $stmtPat->fetch(PDO::FETCH_ASSOC);
        if (!$pat) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            header('Location: index.php?tab=recipes&error=' . urlencode('La patología CIE-10 seleccionada no se encuentra activa.'));
            exit;
        }

        if ($id === null) {
            // Arquitectura Híbrida: Desactivar previamente cualquier prescripción activa y vigente del mismo fármaco
            // Esto garantiza continuidad asistencial limpia para ajustes de dosis o renovación de recetas
            $stmtDesactivar = $pdo->prepare("UPDATE tratamientos_autorizados 
                                            SET activo = 0 
                                            WHERE paciente_id = :p_id 
                                              AND medicamento_id = :m_id 
                                              AND activo = 1");
            $stmtDesactivar->execute([
                ':p_id' => $pacienteId,
                ':m_id' => $medicamentoId
            ]);

            // CREATE
            $sql = "INSERT INTO tratamientos_autorizados 
                    (paciente_id, medicamento_id, patologia_id, dosis_mensual, fecha_emision_recipe, fecha_vencimiento_recipe, medico_prescriptor, activo)
                    VALUES (:paciente_id, :medicamento_id, :patologia_id, :dosis_mensual, :fecha_emision, :fecha_vencimiento, :medico, 1)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':paciente_id' => $pacienteId,
                ':medicamento_id' => $medicamentoId,
                ':patologia_id' => $patologiaId,
                ':dosis_mensual' => $dosisMensual,
                ':fecha_emision' => $fechaEmision,
                ':fecha_vencimiento' => $fechaVencimiento,
                ':medico' => $medicoPrescriptor
            ]);
            $nuevoId = (int) $pdo->lastInsertId();

            registrarAuditoria($pdo, $usuarioSesionId, 'CREAR_RECIPE', 'tratamientos_autorizados', $nuevoId, [
                'evento' => 'Prescripción médica emitida y tratamiento autorizado registrado',
                'paciente_id' => $pacienteId,
                'paciente' => $paciente['nombre_completo'],
                'cedula' => $paciente['cedula'],
                'medicamento_id' => $medicamentoId,
                'medicamento' => $med['nombre_generico'] . ' ' . $med['concentracion'],
                'patologia_id' => $patologiaId,
                'patologia' => "[{$pat['codigo_cie10']}] {$pat['nombre']}",
                'dosis_mensual' => $dosisMensual,
                'fecha_emision' => $fechaEmision,
                'fecha_vencimiento' => $fechaVencimiento,
                'medico_prescriptor' => $medicoPrescriptor
            ]);

            $pdo->commit();
            header('Location: index.php?tab=recipes&msg=' . urlencode("Récipe médico emitido exitosamente para '{$paciente['nombre_completo']}'."));
            exit;

        } else {
            // UPDATE
            $stmtPrev = $pdo->prepare("SELECT * FROM tratamientos_autorizados WHERE id = :id");
            $stmtPrev->execute([':id' => $id]);
            $prev = $stmtPrev->fetch(PDO::FETCH_ASSOC);

            if (!$prev) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                header('Location: index.php?tab=recipes&error=' . urlencode('El récipe médico especificado no existe en el sistema.'));
                exit;
            }

            $sql = "UPDATE tratamientos_autorizados 
                    SET paciente_id = :paciente_id,
                        medicamento_id = :medicamento_id,
                        patologia_id = :patologia_id,
                        dosis_mensual = :dosis_mensual,
                        fecha_emision_recipe = :fecha_emision,
                        fecha_vencimiento_recipe = :fecha_vencimiento,
                        medico_prescriptor = :medico
                    WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':paciente_id' => $pacienteId,
                ':medicamento_id' => $medicamentoId,
                ':patologia_id' => $patologiaId,
                ':dosis_mensual' => $dosisMensual,
                ':fecha_emision' => $fechaEmision,
                ':fecha_vencimiento' => $fechaVencimiento,
                ':medico' => $medicoPrescriptor,
                ':id' => $id
            ]);

            registrarAuditoria($pdo, $usuarioSesionId, 'EDITAR_RECIPE', 'tratamientos_autorizados', $id, [
                'evento' => 'Actualización de prescripción médica y tratamiento autorizado',
                'paciente_id' => $pacienteId,
                'paciente' => $paciente['nombre_completo'],
                'medicamento_id' => $medicamentoId,
                'medicamento' => $med['nombre_generico'] . ' ' . $med['concentracion'],
                'patologia_id' => $patologiaId,
                'patologia' => "[{$pat['codigo_cie10']}] {$pat['nombre']}",
                'dosis_mensual' => $dosisMensual,
                'fecha_emision' => $fechaEmision,
                'fecha_vencimiento' => $fechaVencimiento,
                'medico_prescriptor' => $medicoPrescriptor
            ]);

            $pdo->commit();
            header('Location: index.php?tab=recipes&msg=' . urlencode("Récipe médico #{$id} actualizado exitosamente."));
            exit;
        }

    case 'desactivar_recipe':
    case 'toggle_estado_recipe':
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

        if (!$id) {
            header('Location: index.php?tab=recipes&error=' . urlencode('Identificador de récipe médico inválido.'));
            exit;
        }

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        $stmt = $pdo->prepare("SELECT id, paciente_id, medicamento_id, activo FROM tratamientos_autorizados WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $rec = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rec) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            header('Location: index.php?tab=recipes&error=' . urlencode('Récipe médico no encontrado.'));
            exit;
        }

        $nuevoEstado = ((int) $rec['activo'] === 1) ? 0 : 1;
        $stmtUpd = $pdo->prepare("UPDATE tratamientos_autorizados SET activo = :activo WHERE id = :id");
        $stmtUpd->execute([':activo' => $nuevoEstado, ':id' => $id]);

        $accionLog = ($nuevoEstado === 1) ? 'ACTIVAR_RECIPE' : 'DESACTIVAR_RECIPE';
        registrarAuditoria($pdo, $usuarioSesionId, $accionLog, 'tratamientos_autorizados', $id, [
            'evento' => ($nuevoEstado === 1) ? 'Reactivación de prescripción médica' : 'Suspensión/desactivación de prescripción médica',
            'paciente_id' => $rec['paciente_id'],
            'medicamento_id' => $rec['medicamento_id'],
            'estado_anterior' => (int) $rec['activo'],
            'nuevo_estado' => $nuevoEstado
        ]);

        $pdo->commit();
        $msg = ($nuevoEstado === 1)
            ? "Récipe médico #{$id} reactivado exitosamente."
            : "Récipe médico #{$id} desactivado correctamente.";
        header('Location: index.php?tab=recipes&msg=' . urlencode($msg));
        exit;

    default:
        header('Location: index.php?tab=recipes');
        exit;
}
