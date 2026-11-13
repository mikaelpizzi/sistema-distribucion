<?php
// src/views/tabs/tab_despachos.php
// SICM | Módulo Autosuficiente: Dispensación Asistencial FEFO
declare(strict_types=1);

$moduloNombre = 'Dispensación';
$seccionNombre = 'Gestión Clínica';

// 1. Catálogo de Centros de Salud y Control de Jurisdicción
$catalogoCentros = $pdo->query("
    SELECT id, codigo_sicm, nombre, direccion
    FROM centros_salud
    WHERE activo = 1
    ORDER BY nombre ASC
")->fetchAll(PDO::FETCH_ASSOC);

if ($puedeVerTodaLaRed) {
    $centroOperativoId = !empty($_GET['centro_id']) ? (int) $_GET['centro_id'] : ($centroUsuarioId ?: ($catalogoCentros[0]['id'] ?? 1));
} else {
    $centroOperativoId = (int) $centroUsuarioId;
}

$centroOperativo = null;
foreach ($catalogoCentros as $c) {
    if ((int) $c['id'] === $centroOperativoId) {
        $centroOperativo = $c;
        break;
    }
}
if (!$centroOperativo && !empty($catalogoCentros)) {
    $centroOperativo = $catalogoCentros[0];
    $centroOperativoId = (int) $centroOperativo['id'];
}

// Comprobar soporte de cadena de frío en el ambulatorio operativo
$stmtFrio = $pdo->prepare("
    SELECT COUNT(*)
    FROM centro_recursos cr
    JOIN recursos_operativos ro ON cr.recurso_id = ro.id
    WHERE cr.centro_salud_id = :cid
      AND ro.codigo = 'CADENA_FRIO'
      AND cr.operativo = 1
");
$stmtFrio->execute([':cid' => $centroOperativoId]);
$centroTieneCadenaFrio = ((int) $stmtFrio->fetchColumn()) > 0;

$puedeRegistrarLote = tienePermiso('INV_REGISTRAR_LOTE') || esRol('rol_admin');
$catalogoMedicamentosActivos = $pdo->query("
    SELECT m.id, m.nombre_generico, m.concentracion, ff.nombre AS forma_nombre, m.requiere_cadena_frio
    FROM medicamentos m
    LEFT JOIN formas_farmaceuticas ff ON m.forma_farmaceutica_id = ff.id
    WHERE m.activo = 1
    ORDER BY m.nombre_generico ASC
")->fetchAll(PDO::FETCH_ASSOC);

$periodoActual = date('Y-m');

// 2. Cuadro de Mando Superior (3 KPIs Institucionales)
$paramsKpi = [':periodo' => $periodoActual, ':centro_id' => $centroOperativoId];

// Despachos este Mes
$stmtKpi1 = $pdo->prepare("
    SELECT COUNT(*)
    FROM despachos
    WHERE periodo_mes_anio = :periodo AND centro_salud_id = :centro_id
");
$stmtKpi1->execute($paramsKpi);
$kpiDespachosMes = (int) $stmtKpi1->fetchColumn();

// Pacientes Atendidos
$stmtKpi2 = $pdo->prepare("
    SELECT COUNT(DISTINCT t.paciente_id)
    FROM despachos d
    JOIN tratamientos_autorizados t ON d.tratamiento_id = t.id
    WHERE d.periodo_mes_anio = :periodo AND d.centro_salud_id = :centro_id
");
$stmtKpi2->execute($paramsKpi);
$kpiPacientesAtendidos = (int) $stmtKpi2->fetchColumn();

// Unidades Entregadas
$stmtKpi3 = $pdo->prepare("
    SELECT COALESCE(SUM(dd.cantidad_entregada), 0)
    FROM despachos d
    JOIN despacho_detalles dd ON dd.despacho_id = d.id
    WHERE d.periodo_mes_anio = :periodo AND d.centro_salud_id = :centro_id
");
$stmtKpi3->execute($paramsKpi);
$kpiUnidadesEntregadas = (int) $stmtKpi3->fetchColumn();

// 3. Buscador Asistencial de Paciente
$busquedaPaciente = trim($_GET['q_paciente'] ?? '');
$pacienteEncontrado = null;
$pacientesCoincidentes = [];
$contactosPaciente = [];
$recipesPaciente = [];

if ($busquedaPaciente !== '') {
    $stmtPac = $pdo->prepare("
        SELECT p.id, p.cedula, p.nombre_completo, p.fecha_nacimiento, p.direccion_residencia,
               td.codigo AS tipo_doc_codigo, td.nombre AS tipo_doc_nombre,
               TIMESTAMPDIFF(YEAR, p.fecha_nacimiento, CURDATE()) AS edad
        FROM pacientes p
        LEFT JOIN tipos_documento td ON p.tipo_documento_id = td.id
        WHERE p.cedula = :q_exacta
           OR p.cedula LIKE :q_like1
           OR p.nombre_completo LIKE :q_like2
        ORDER BY (p.cedula = :q_exacta2) DESC, p.nombre_completo ASC
        LIMIT 10
    ");
    $stmtPac->execute([
        ':q_exacta' => $busquedaPaciente,
        ':q_exacta2' => $busquedaPaciente,
        ':q_like1' => '%' . $busquedaPaciente . '%',
        ':q_like2' => '%' . $busquedaPaciente . '%'
    ]);
    $pacientesCoincidentes = $stmtPac->fetchAll(PDO::FETCH_ASSOC);

    $pacienteSelId = !empty($_GET['paciente_id']) ? (int) $_GET['paciente_id'] : null;
    if ($pacienteSelId) {
        foreach ($pacientesCoincidentes as $pac) {
            if ((int) $pac['id'] === $pacienteSelId) {
                $pacienteEncontrado = $pac;
                break;
            }
        }
        if (!$pacienteEncontrado) {
            $stmtSingle = $pdo->prepare("
                SELECT p.id, p.cedula, p.nombre_completo, p.fecha_nacimiento, p.direccion_residencia,
                       td.codigo AS tipo_doc_codigo, td.nombre AS tipo_doc_nombre,
                       TIMESTAMPDIFF(YEAR, p.fecha_nacimiento, CURDATE()) AS edad
                FROM pacientes p
                LEFT JOIN tipos_documento td ON p.tipo_documento_id = td.id
                WHERE p.id = :id
            ");
            $stmtSingle->execute([':id' => $pacienteSelId]);
            $pacienteEncontrado = $stmtSingle->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } elseif (count($pacientesCoincidentes) === 1) {
        $pacienteEncontrado = $pacientesCoincidentes[0];
    }

    if ($pacienteEncontrado) {
        // Vías de Contacto Normalizadas 1FN
        $stmtCont = $pdo->prepare("
            SELECT pc.id, pc.valor, pc.es_principal, pc.nombre_contacto_emergencia,
                   tc.codigo AS tipo_contacto_codigo, tc.nombre AS tipo_contacto_nombre
            FROM paciente_contactos pc
            JOIN tipos_contacto tc ON pc.tipo_contacto_id = tc.id
            WHERE pc.paciente_id = :paciente_id
            ORDER BY pc.es_principal DESC, pc.id ASC
        ");
        $stmtCont->execute([':paciente_id' => $pacienteEncontrado['id']]);
        $contactosPaciente = $stmtCont->fetchAll(PDO::FETCH_ASSOC);

        // Récipes Médicos Autorizados (activos)
        $stmtRec = $pdo->prepare("
            SELECT t.id, t.paciente_id, t.medicamento_id, t.patologia_id, t.dosis_mensual,
                   t.fecha_emision_recipe, t.fecha_vencimiento_recipe, t.medico_prescriptor,
                   t.activo,
                   (t.fecha_vencimiento_recipe < CURRENT_DATE) AS esta_vencido,
                   m.nombre_generico AS medicamento_nombre, m.concentracion AS medicamento_concentracion,
                   m.requiere_cadena_frio,
                   ff.nombre AS forma_farmaceutica,
                   pat.codigo_cie10 AS patologia_cie10, pat.nombre AS patologia_nombre
            FROM tratamientos_autorizados t
            JOIN medicamentos m ON t.medicamento_id = m.id
            LEFT JOIN formas_farmaceuticas ff ON m.forma_farmaceutica_id = ff.id
            JOIN patologias pat ON t.patologia_id = pat.id
            WHERE t.paciente_id = :paciente_id
              AND t.activo = 1
            ORDER BY t.id DESC
        ");
        $stmtRec->execute([':paciente_id' => $pacienteEncontrado['id']]);
        $recipesCrudos = $stmtRec->fetchAll(PDO::FETCH_ASSOC);

        // Atributos Farmacológicos Normalizados (3FN - N:M) para los fármacos del paciente
        $medIds = array_unique(array_filter(array_column($recipesCrudos, 'medicamento_id')));
        $atributosPorMedicamento = [];
        if (!empty($medIds)) {
            $inClause = implode(',', array_map('intval', $medIds));
            $stmtAtr = $pdo->query("
                SELECT ma.medicamento_id, af.codigo, af.nombre, af.icono, af.color_badge
                FROM medicamento_atributos ma
                JOIN atributos_farmacologicos af ON ma.atributo_id = af.id
                WHERE ma.medicamento_id IN ({$inClause}) AND af.activo = 1
                ORDER BY af.id ASC
            ");
            while ($rowAtr = $stmtAtr->fetch(PDO::FETCH_ASSOC)) {
                $atributosPorMedicamento[(int) $rowAtr['medicamento_id']][] = $rowAtr;
            }
        }

        foreach ($recipesCrudos as $rec) {
            // Comprobar si ya retiró este mes (uk_retiro_mensual)
            $stmtRetiro = $pdo->prepare("
                SELECT d.id, d.fecha_hora, d.periodo_mes_anio, d.observaciones,
                       cs.nombre AS centro_nombre, u.nombre AS operador_nombre,
                       dd.cantidad_entregada, l.numero_lote
                FROM despachos d
                JOIN centros_salud cs ON d.centro_salud_id = cs.id
                JOIN usuarios u ON d.usuario_id = u.id
                LEFT JOIN despacho_detalles dd ON dd.despacho_id = d.id
                LEFT JOIN lotes_inventario l ON dd.lote_id = l.id
                WHERE d.tratamiento_id = :tid AND d.periodo_mes_anio = :periodo
                LIMIT 1
            ");
            $stmtRetiro->execute([
                ':tid' => $rec['id'],
                ':periodo' => $periodoActual
            ]);
            $despachoPrevio = $stmtRetiro->fetch(PDO::FETCH_ASSOC) ?: null;

            // Consulta de lotes FEFO disponibles en el ambulatorio: fecha_vencimiento ASC, id ASC
            $stmtLotes = $pdo->prepare("
                SELECT l.id, l.numero_lote, l.fecha_vencimiento, l.cantidad_actual,
                       DATEDIFF(l.fecha_vencimiento, CURDATE()) AS dias_para_vencer,
                       fn_evaluar_caducidad_lote(l.fecha_vencimiento) AS estado_caducidad
                FROM lotes_inventario l
                WHERE l.centro_salud_id = :cid
                  AND l.medicamento_id = :mid
                  AND l.cantidad_actual > 0
                  AND l.fecha_vencimiento >= CURDATE()
                ORDER BY l.fecha_vencimiento ASC, l.id ASC
            ");
            $stmtLotes->execute([
                ':cid' => $centroOperativoId,
                ':mid' => $rec['medicamento_id']
            ]);
            $lotesDisponibles = $stmtLotes->fetchAll(PDO::FETCH_ASSOC);

            $stockTotalAmbulatorio = 0;
            foreach ($lotesDisponibles as $lt) {
                $stockTotalAmbulatorio += (int) $lt['cantidad_actual'];
            }

            $rec['despacho_previo'] = $despachoPrevio;
            $rec['ya_retiro'] = ($despachoPrevio !== null);
            $rec['lotes_fefo'] = $lotesDisponibles;
            $rec['stock_total_centro'] = $stockTotalAmbulatorio;
            $rec['lote_prioritario'] = !empty($lotesDisponibles) ? $lotesDisponibles[0] : null;
            $rec['atributos_farmacologicos'] = $atributosPorMedicamento[(int) $rec['medicamento_id']] ?? [];

            $recipesPaciente[] = $rec;
        }
    }
}

// 4. Historial de Despachos Recientes en este Ambulatorio
$stmtHist = $pdo->prepare("
    SELECT d.id, d.fecha_hora, d.periodo_mes_anio, d.observaciones,
           cs.codigo_sicm AS centro_codigo, cs.nombre AS centro_nombre,
           p.cedula AS paciente_cedula, p.nombre_completo AS paciente_nombre, td.codigo AS tipo_doc_codigo,
           m.nombre_generico AS medicamento_nombre, m.concentracion AS medicamento_concentracion,
           ff.nombre AS forma_farmaceutica,
           GROUP_CONCAT(DISTINCT l.numero_lote SEPARATOR ', ') AS numero_lote,
           MIN(l.fecha_vencimiento) AS lote_vencimiento,
           COALESCE(SUM(dd.cantidad_entregada), 0) AS cantidad_entregada,
           u.nombre AS operador_nombre
    FROM despachos d
    JOIN centros_salud cs ON d.centro_salud_id = cs.id
    JOIN tratamientos_autorizados t ON d.tratamiento_id = t.id
    JOIN pacientes p ON t.paciente_id = p.id
    LEFT JOIN tipos_documento td ON p.tipo_documento_id = td.id
    JOIN medicamentos m ON t.medicamento_id = m.id
    LEFT JOIN formas_farmaceuticas ff ON m.forma_farmaceutica_id = ff.id
    LEFT JOIN despacho_detalles dd ON dd.despacho_id = d.id
    LEFT JOIN lotes_inventario l ON dd.lote_id = l.id
    JOIN usuarios u ON d.usuario_id = u.id
    WHERE d.centro_salud_id = :cid
    GROUP BY d.id, d.fecha_hora, d.periodo_mes_anio, d.observaciones, cs.codigo_sicm, cs.nombre, p.cedula, p.nombre_completo, td.codigo, m.nombre_generico, m.concentracion, ff.nombre, u.nombre
    ORDER BY d.id DESC
    LIMIT 20
");
$stmtHist->execute([':cid' => $centroOperativoId]);
$ultimosDespachos = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
?>
<!-- BARRA DE JURISDICCIÓN Y AMBULATORIO CLÍNICO OPERATIVO -->
                        <div class="card bg-white border shadow-sm mb-3">
                            <div class="card-body p-3">
                                <div class="row align-items-center g-2">
                                    <div class="col-md-7">
                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                                <i class="bi bi-hospital me-1"></i> Jurisdicción Operativa
                                            </span>
                                            <span
                                                class="fw-bold text-dark fs-6"><?= htmlspecialchars($centroOperativo['nombre'] ?? 'Ambulatorio') ?></span>
                                            <span
                                                class="badge bg-light text-secondary border font-monospace"><?= htmlspecialchars($centroOperativo['codigo_sicm'] ?? '') ?></span>
                                            <?php if ($centroTieneCadenaFrio): ?>
                                                <span class="badge bg-info-subtle text-info border border-info-subtle"
                                                    title="Refrigeración operativa disponible en sede">
                                                    <i class="bi bi-snow2 me-1"></i> Cadena de Frío Activa
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!tienePermiso('DESP_PROCESAR')): ?>
                                                <span
                                                    class="badge bg-secondary-subtle text-secondary border border-secondary-subtle font-monospace"
                                                    title="Rol de supervisión sanitaria en modo consulta">
                                                    <i class="bi bi-eye me-1"></i> Modo Fiscalización / Solo Lectura
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-muted small mt-1" style="font-size: 0.74rem;">
                                            <i class="bi bi-pin-map me-1"></i>
                                            <?= htmlspecialchars($centroOperativo['direccion'] ?? 'Dirección municipal') ?>
                                        </div>
                                    </div>
                                    <div class="col-md-5 d-flex align-items-center justify-content-md-end gap-2 flex-wrap">
                                        <?php if ($puedeRegistrarLote): ?>
                                            <button type="button" class="btn btn-success btn-sm fw-semibold shadow-sm text-nowrap"
                                                data-bs-toggle="modal" data-bs-target="#modalEntradaLote">
                                                <i class="bi bi-box-arrow-in-down me-1"></i> + Registrar Entrada de Lote
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($puedeVerTodaLaRed): ?>
                                            <form method="GET" action="index.php"
                                                class="d-inline-flex align-items-center gap-2 justify-content-md-end">
                                                <input type="hidden" name="tab" value="despachos">
                                                <?php if ($busquedaPaciente !== ''): ?>
                                                    <input type="hidden" name="q_paciente"
                                                        value="<?= htmlspecialchars($busquedaPaciente) ?>">
                                                <?php endif; ?>
                                                <label class="form-label small text-secondary fw-semibold mb-0 text-nowrap">Cambiar Centro:</label>
                                                <select name="centro_id" class="form-select form-select-sm"
                                                    style="max-width: 220px;" onchange="this.form.submit()">
                                                    <?php foreach ($catalogoCentros as $c): ?>
                                                        <option value="<?= (int) $c['id'] ?>" <?= ((int) $c['id'] === $centroOperativoId) ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($c['codigo_sicm']) ?> —
                                                            <?= htmlspecialchars($c['nombre']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </form>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                                                <i class="bi bi-lock-fill me-1"></i> Jurisdicción Asignada a Farmaceuta
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- CUADRO DE MANDO SUPERIOR: 3 KPIS INSTITUCIONALES -->
                        <div class="row g-2 mb-3">
                            <div class="col-sm-6 col-md-4">
                                <div class="card bg-white border shadow-sm p-3 d-flex flex-row align-items-center gap-3">
                                    <div class="bg-primary-subtle text-primary p-2.5 rounded-3 d-flex align-items-center justify-content-center"
                                        style="width: 48px; height: 48px;">
                                        <i class="bi bi-box-seam fs-4 text-primary"></i>
                                    </div>
                                    <div>
                                        <span class="text-muted small d-block fw-semibold text-uppercase"
                                            style="font-size: 0.72rem; letter-spacing: 0.04em;">Despachos este Mes</span>
                                        <div class="fs-4 fw-bold text-dark font-monospace">
                                            <?= number_format($kpiDespachosMes) ?></div>
                                        <span class="text-muted small" style="font-size: 0.70rem;">Entregas en
                                            <?= date('m/Y') ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-6 col-md-4">
                                <div class="card bg-white border shadow-sm p-3 d-flex flex-row align-items-center gap-3">
                                    <div class="bg-success-subtle text-success p-2.5 rounded-3 d-flex align-items-center justify-content-center"
                                        style="width: 48px; height: 48px;">
                                        <i class="bi bi-people fs-4 text-success"></i>
                                    </div>
                                    <div>
                                        <span class="text-muted small d-block fw-semibold text-uppercase"
                                            style="font-size: 0.72rem; letter-spacing: 0.04em;">Pacientes Atendidos</span>
                                        <div class="fs-4 fw-bold text-success font-monospace">
                                            <?= number_format($kpiPacientesAtendidos) ?></div>
                                        <span class="text-muted small" style="font-size: 0.70rem;">Beneficiarios únicos del
                                            mes</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12 col-md-4">
                                <div class="card bg-white border shadow-sm p-3 d-flex flex-row align-items-center gap-3">
                                    <div class="bg-info-subtle text-info-emphasis p-2.5 rounded-3 d-flex align-items-center justify-content-center"
                                        style="width: 48px; height: 48px;">
                                        <i class="bi bi-capsule fs-4 text-info"></i>
                                    </div>
                                    <div>
                                        <span class="text-muted small d-block fw-semibold text-uppercase"
                                            style="font-size: 0.72rem; letter-spacing: 0.04em;">Unidades Entregadas</span>
                                        <div class="fs-4 fw-bold text-info-emphasis font-monospace">
                                            <?= number_format($kpiUnidadesEntregadas) ?></div>
                                        <span class="text-muted small" style="font-size: 0.70rem;">Total dosis físicas
                                            dispensadas</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- GUÍA VISUAL DE FLUJO DE ATENCIÓN Y DISPENSACIÓN -->
                        <div class="card bg-white border shadow-sm mb-3">
                            <div class="card-body py-2.5 px-3">
                                <div class="row align-items-center g-2 text-center text-md-start">
                                    <div class="col-12 col-md-auto text-muted small fw-semibold text-uppercase"
                                        style="font-size: 0.72rem; letter-spacing: 0.04em;">
                                        <i class="bi bi-diagram-3 text-primary me-1"></i> Flujo Asistencial:
                                    </div>
                                    <div class="col-12 col-md">
                                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 small">
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="badge bg-primary rounded-pill px-2 py-1">Paso 1</span>
                                                <span class="fw-semibold text-dark">Localizar paciente por documento</span>
                                            </div>
                                            <i class="bi bi-chevron-right text-muted d-none d-lg-inline"></i>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-2 py-1">Paso 2</span>
                                                <span class="fw-semibold text-dark">Validación automática de récipe y lote</span>
                                            </div>
                                            <i class="bi bi-chevron-right text-muted d-none d-lg-inline"></i>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1">Paso 3</span>
                                                <span class="fw-semibold text-dark">Confirmación de entrega</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- BUSCADOR ASISTENCIAL DE PACIENTE -->
                        <div class="card bg-white border shadow-sm mb-3">
                            <div
                                class="card-header bg-white border-bottom py-2.5 px-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="bi bi-search text-primary fs-5"></i>
                                    <h6 class="fw-bold m-0 text-dark">Búsqueda Asistencial de Paciente</h6>
                                </div>
                            </div>
                            <div class="card-body p-3">
                                <form method="GET" action="index.php" class="row g-2 align-items-center">
                                    <input type="hidden" name="tab" value="despachos">
                                    <?php if ($puedeVerTodaLaRed): ?>
                                        <input type="hidden" name="centro_id" value="<?= (int) $centroOperativoId ?>">
                                    <?php endif; ?>
                                    <div class="col-md-9 col-lg-10">
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text bg-light text-secondary border">
                                                <i class="bi bi-person-vcard"></i>
                                            </span>
                                            <input type="text" name="q_paciente" class="form-control"
                                                placeholder="Ingrese cédula de identidad o nombre del paciente..."
                                                value="<?= htmlspecialchars($busquedaPaciente) ?>" autocomplete="off" autofocus
                                                required>
                                            <?php if ($busquedaPaciente !== ''): ?>
                                                <a href="index.php?tab=despachos<?= $puedeVerTodaLaRed ? '&centro_id=' . (int) $centroOperativoId : '' ?>"
                                                    class="btn btn-outline-secondary" title="Limpiar consulta">
                                                    <i class="bi bi-x-lg"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-3 col-lg-2 d-grid">
                                        <button type="submit" class="btn btn-primary btn-sm fw-semibold">
                                            <i class="bi bi-search me-1"></i> Consultar Ficha
                                        </button>
                                    </div>
                                </form>

                                <?php if ($busquedaPaciente !== '' && count($pacientesCoincidentes) > 1 && !$pacienteEncontrado): ?>
                                    <!-- Selector de múltiples coincidencias -->
                                    <div class="mt-3 pt-3 border-top">
                                        <div class="small fw-semibold text-secondary mb-2">
                                            <i class="bi bi-people-fill text-primary me-1"></i> Se encontraron
                                            <?= count($pacientesCoincidentes) ?> pacientes. Seleccione el titular:
                                        </div>
                                        <div class="list-group list-group-flush border rounded-2">
                                            <?php foreach ($pacientesCoincidentes as $pMatch): ?>
                                                <a href="index.php?tab=despachos<?= $puedeVerTodaLaRed ? '&centro_id=' . (int) $centroOperativoId : '' ?>&q_paciente=<?= urlencode($busquedaPaciente) ?>&paciente_id=<?= (int) $pMatch['id'] ?>"
                                                    class="list-group-item list-group-item-action d-flex align-items-center justify-content-between p-2.5 small">
                                                    <div>
                                                        <span class="badge bg-light text-dark border font-monospace me-2">
                                                            <?= htmlspecialchars($pMatch['tipo_doc_codigo'] ?? 'V') ?>-<?= htmlspecialchars($pMatch['cedula']) ?>
                                                        </span>
                                                        <span
                                                            class="fw-bold text-dark"><?= htmlspecialchars($pMatch['nombre_completo']) ?></span>
                                                        <?php if (!empty($pMatch['edad'])): ?>
                                                            <span class="text-muted ms-1">(<?= (int) $pMatch['edad'] ?> años)</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <span class="btn btn-outline-primary btn-sm py-0 px-2 fw-semibold"
                                                        style="font-size: 0.72rem;">
                                                        Seleccionar <i class="bi bi-chevron-right ms-1"></i>
                                                    </span>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php elseif ($busquedaPaciente !== '' && empty($pacientesCoincidentes)): ?>
                                    <div
                                        class="alert alert-info border d-flex align-items-center gap-2 mt-3 mb-0 py-2.5 px-3 small">
                                        <i class="bi bi-info-circle-fill text-info fs-5 flex-shrink-0"></i>
                                        <div>
                                            No se localizó ningún paciente registrado con el criterio
                                            «<strong><?= htmlspecialchars($busquedaPaciente) ?></strong>». Verifique el número de
                                            cédula o regístrelo en el Padrón de Pacientes.
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!$pacienteEncontrado && $busquedaPaciente === ''): ?>
                            <!-- MENSAJE DE BIENVENIDA Y PRUEBAS ASISTENCIALES RÁPIDAS -->
                            <div class="card bg-white border shadow-sm mb-3">
                                <div class="card-body p-4 text-center">
                                    <div class="mx-auto mb-3 bg-primary-subtle text-primary rounded-circle d-flex align-items-center justify-content-center"
                                        style="width: 52px; height: 52px;">
                                        <i class="bi bi-person-vcard fs-3"></i>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1">Módulo de Dispensación Asistencial</h6>
                                    <p class="text-muted small mx-auto mb-3" style="max-width: 650px;">
                                        Inicie la atención localizando al paciente por cédula o nombre en el buscador superior. El sistema validará en tiempo real la vigencia del récipe médico, el bloqueo de múltiples retiros mensuales y la asignación automática del lote con fecha de caducidad más próxima.
                                    </p>
                                    <div class="d-flex flex-wrap justify-content-center align-items-center gap-2 pt-2 border-top">
                                        <span class="small text-secondary fw-semibold">Consultas de prueba inmediata:</span>
                                        <a href="index.php?tab=despachos<?= $puedeVerTodaLaRed ? '&centro_id=' . (int) $centroOperativoId : '' ?>&q_paciente=14285714"
                                            class="btn btn-outline-primary btn-sm py-1 px-2.5 font-monospace">
                                            <i class="bi bi-search me-1"></i> 14285714 — Juan Morales
                                        </a>
                                        <a href="index.php?tab=despachos<?= $puedeVerTodaLaRed ? '&centro_id=' . (int) $centroOperativoId : '' ?>&q_paciente=11400219"
                                            class="btn btn-outline-primary btn-sm py-1 px-2.5 font-monospace">
                                            <i class="bi bi-search me-1"></i> 11400219 — Pedro Albarrán
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($pacienteEncontrado): ?>
                            <!-- FICHA CLÍNICA ASISTENCIAL DEL PACIENTE -->
                            <div class="card bg-white border shadow-sm mb-3">
                                <div
                                    class="card-header bg-light py-2 px-3 border-bottom d-flex align-items-center justify-content-between">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-person-badge-fill text-primary fs-5"></i>
                                        <h6 class="fw-bold m-0 text-dark">Ficha Clínica Asistencial</h6>
                                        <span class="badge bg-primary font-monospace">
                                            <?= htmlspecialchars($pacienteEncontrado['tipo_doc_codigo'] ?? 'V') ?>-<?= htmlspecialchars($pacienteEncontrado['cedula']) ?>
                                        </span>
                                    </div>
                                    <a href="index.php?tab=pacientes&q_paciente=<?= urlencode($pacienteEncontrado['cedula']) ?>"
                                        class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size: 0.72rem;"
                                        target="_blank">
                                        <i class="bi bi-box-arrow-up-right me-1"></i> Expediente
                                    </a>
                                </div>
                                <div class="card-body p-3">
                                    <div class="row g-3 small align-items-start">
                                        <div class="col-12 col-md-4 col-lg-3" style="min-width: 0;">
                                            <span class="text-muted fw-semibold d-block">Nombre del Paciente:</span>
                                            <span
                                                class="fs-6 fw-bold text-dark d-block text-truncate" title="<?= htmlspecialchars($pacienteEncontrado['nombre_completo']) ?>"><?= htmlspecialchars($pacienteEncontrado['nombre_completo']) ?></span>
                                            <div class="text-muted" style="font-size: 0.72rem;">
                                                <?php if (!empty($pacienteEncontrado['fecha_nacimiento'])): ?>
                                                    Nacimiento: <?= date('d/m/Y', strtotime($pacienteEncontrado['fecha_nacimiento'])) ?>
                                                    (<?= (int) $pacienteEncontrado['edad'] ?> años)
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="col-12 col-md-4 col-lg-4" style="min-width: 0;">
                                            <span class="text-muted fw-semibold d-block">Dirección de Residencia:</span>
                                            <span
                                                class="text-dark d-block" style="word-break: break-word;"><?= htmlspecialchars($pacienteEncontrado['direccion_residencia'] ?: 'No registrada') ?></span>
                                        </div>
                                        <div class="col-12 col-md-4 col-lg-5" style="min-width: 0;">
                                            <span class="text-muted fw-semibold d-block mb-1">Vías de Contacto:</span>
                                            <?php if (empty($contactosPaciente)): ?>
                                                <span class="text-muted font-italic">Sin contactos registrados</span>
                                            <?php else: ?>
                                                <div class="d-flex flex-column gap-1.5 w-100" style="min-width: 0;">
                                                    <?php foreach ($contactosPaciente as $c): ?>
                                                        <?php
                                                        $isPri = ((int) $c['es_principal'] === 1);
                                                        $titularEmergencia = trim($c['nombre_contacto_emergencia'] ?? '');
                                                        $esTitularRelevante = !empty($titularEmergencia)
                                                            && !in_array(mb_strtolower($titularEmergencia), ['paciente directo', 'personal', 'personal movil', 'titular', 'propio', 'directo']);
                                                        $isEmg = ($c['tipo_contacto_codigo'] === 'CONTACTO_EMERGENCIA' || $esTitularRelevante);
                                                        $isWp = ($c['tipo_contacto_codigo'] === 'WHATSAPP');
                                                        $bClass = $isPri ? 'bg-primary-subtle text-primary border-primary-subtle' : ($isEmg ? 'bg-danger-subtle text-danger border-danger-subtle' : 'bg-light text-secondary border');
                                                        $icon = $isPri ? 'bi-star-fill text-primary' : ($isEmg ? 'bi-shield-exclamation text-danger' : ($isWp ? 'bi-whatsapp text-success' : 'bi-telephone'));

                                                        // Simplificación estética de etiquetas para evitar desbordamiento
                                                        $tipoMap = [
                                                            'TEL_MOVIL' => 'Móvil Personal',
                                                            'TEL_FIJO' => 'Fijo / Habitación',
                                                            'WHATSAPP' => 'WhatsApp',
                                                            'EMAIL_ALTERNO' => 'Correo',
                                                            'CONTACTO_EMERGENCIA' => 'Emergencia',
                                                        ];
                                                        $tipoLabel = $tipoMap[$c['tipo_contacto_codigo'] ?? ''] ?? $c['tipo_contacto_nombre'];
                                                        ?>
                                                        <span
                                                            class="badge <?= $bClass ?> badge-contacto-ficha">
                                                            <i class="bi <?= $icon ?> flex-shrink-0"></i>
                                                            <span class="badge-contacto-label"><?= htmlspecialchars($tipoLabel) ?>:</span>
                                                            <span class="badge-contacto-valor font-monospace"><?= htmlspecialchars($c['valor']) ?></span>
                                                            <?php if ($esTitularRelevante): ?>
                                                                <span class="badge-contacto-titular text-muted">(<?= htmlspecialchars($titularEmergencia) ?>)</span>
                                                            <?php endif; ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- RÉCIPES MÉDICOS Y DOTACIÓN MENSUAL FEFO -->
                            <div class="card bg-white border shadow-sm mb-3">
                                <div
                                    class="card-header bg-white border-bottom py-2.5 px-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-file-earmark-medical-fill text-primary fs-5"></i>
                                        <h6 class="fw-bold m-0 text-dark">Récipes Médicos Autorizados y Dotación Mensual</h6>
                                    </div>
                                    <span class="badge bg-light text-secondary border font-monospace">
                                        Periodo Activo: <?= htmlspecialchars($periodoActual) ?>
                                    </span>
                                </div>
                                <div class="card-body p-3">
                                    <?php if (empty($recipesPaciente)): ?>
                                        <div class="alert alert-warning border d-flex align-items-center gap-2 mb-0 py-2.5 px-3 small">
                                            <i class="bi bi-exclamation-triangle-fill text-warning fs-5 flex-shrink-0"></i>
                                            <div>
                                                El paciente no posee ningún récipe médico autorizado activo y vigente para dispensar en
                                                este momento.
                                                <a href="index.php?tab=recipes&paciente_id=<?= (int) $pacienteEncontrado['id'] ?>"
                                                    class="alert-link ms-1">
                                                    Emitir un nuevo récipe médico aquí.
                                                </a>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="row g-3">
                                            <?php foreach ($recipesPaciente as $rec): ?>
                                                <?php
                                                $yaRetiro = $rec['ya_retiro'];
                                                $estaVencido = !empty($rec['esta_vencido']);
                                                $hayStock = ($rec['stock_total_centro'] > 0);
                                                $reqFrio = ((int) $rec['requiere_cadena_frio'] === 1);
                                                $frioBloquea = ($reqFrio && !$centroTieneCadenaFrio);
                                                ?>
                                                <div class="col-12">
                                                    <div
                                                        class="card border <?= $yaRetiro ? 'border-secondary-subtle bg-light' : ($estaVencido ? 'border-danger-subtle bg-light' : ($hayStock && !$frioBloquea ? 'border-success-subtle' : 'border-warning-subtle')) ?> shadow-sm">
                                                        <div class="card-body p-3">
                                                            <div class="row align-items-center g-3">
                                                                <!-- Columna 1: Datos Prescriptivos del Medicamento -->
                                                                <div class="col-lg-5 col-md-6">
                                                                    <div class="d-flex align-items-start gap-2">
                                                                        <div
                                                                            class="p-2 rounded-2 <?= $yaRetiro ? 'bg-secondary-subtle text-secondary' : ($estaVencido ? 'bg-danger-subtle text-danger' : 'bg-primary-subtle text-primary') ?> mt-1">
                                                                            <i class="bi bi-capsule fs-4"></i>
                                                                        </div>
                                                                        <div>
                                                                            <span class="fw-bold text-dark fs-6 d-block">
                                                                                <?= htmlspecialchars($rec['medicamento_nombre']) ?>
                                                                                <?= htmlspecialchars($rec['medicamento_concentracion']) ?>
                                                                            </span>
                                                                            <span class="text-muted small">
                                                                                <?= htmlspecialchars($rec['forma_farmaceutica']) ?> —
                                                                                <strong
                                                                                    class="text-primary font-monospace"><?= (int) $rec['dosis_mensual'] ?>
                                                                                    unid./mes</strong>
                                                                            </span>
                                                                            <div class="mt-1 small">
                                                                                <span class="badge bg-light text-dark border">
                                                                                    CIE-10: <?= htmlspecialchars($rec['patologia_cie10']) ?>
                                                                                </span>
                                                                                <span
                                                                                    class="text-muted ms-1"><?= htmlspecialchars($rec['patologia_nombre']) ?></span>
                                                                            </div>
                                                                            <div class="text-muted mt-1" style="font-size: 0.70rem;">
                                                                                <i class="bi bi-person-check me-1"></i> Prescriptor:
                                                                                <?= htmlspecialchars($rec['medico_prescriptor'] ?? 'MPPS') ?>
                                                                            </div>
                                                                            <?php if ($estaVencido): ?>
                                                                                <div class="mt-1">
                                                                                    <span
                                                                                        class="badge bg-danger-subtle text-danger border border-danger-subtle small">
                                                                                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                                                                        Récipe Vencido el
                                                                                        <?= date('d/m/Y', strtotime($rec['fecha_vencimiento_recipe'])) ?>
                                                                                    </span>
                                                                                </div>
                                                                            <?php endif; ?>
                                                                            <?php if (!empty($rec['atributos_farmacologicos'])): ?>
                                                                                <div class="d-flex flex-wrap gap-1 mt-1.5">
                                                                                    <?php foreach ($rec['atributos_farmacologicos'] as $atr): ?>
                                                                                        <span class="badge bg-<?= htmlspecialchars($atr['color_badge']) ?>-subtle text-<?= htmlspecialchars($atr['color_badge']) ?> border border-<?= htmlspecialchars($atr['color_badge']) ?>-subtle"
                                                                                            style="font-size: 0.68rem;">
                                                                                            <i class="bi <?= htmlspecialchars($atr['icono']) ?> me-1"></i><?= htmlspecialchars($atr['nombre']) ?>
                                                                                        </span>
                                                                                    <?php endforeach; ?>
                                                                                </div>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>
                                                                </div>

                                                                <!-- Columna 2: Estado de Cadena de Frío e Inventario FEFO -->
                                                                <div class="col-lg-4 col-md-6">
                                                                    <?php if ($reqFrio): ?>
                                                                        <div class="mb-1.5">
                                                                            <?php if ($centroTieneCadenaFrio): ?>
                                                                                <span
                                                                                    class="badge bg-info-subtle text-info border border-info-subtle small">
                                                                                    <i class="bi bi-snow2 me-1"></i> Cadena de Frío: Refrigeración
                                                                                    Operativa
                                                                                </span>
                                                                            <?php else: ?>
                                                                                <span
                                                                                    class="badge bg-danger-subtle text-danger border border-danger-subtle small">
                                                                                    <i class="bi bi-exclamation-triangle-fill me-1"></i> Ambulatorio
                                                                                    Sin Refrigeración
                                                                                </span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    <?php endif; ?>

                                                                    <?php if ($estaVencido): ?>
                                                                        <div class="alert alert-danger border py-1.5 px-2 mb-0 small"
                                                                            style="font-size: 0.74rem;">
                                                                            <div class="fw-bold d-flex align-items-center gap-1 text-danger">
                                                                                <i class="bi bi-exclamation-triangle-fill"></i> Récipe Vencido
                                                                                el
                                                                                <?= date('d/m/Y', strtotime($rec['fecha_vencimiento_recipe'])) ?>
                                                                            </div>
                                                                            <div class="text-muted mt-0.5">
                                                                                La prescripción médica ha caducado. Se requiere evaluación
                                                                                facultativa para renovar la vigencia del récipe.
                                                                            </div>
                                                                        </div>
                                                                    <?php elseif ($yaRetiro): ?>
                                                                        <div class="alert alert-danger border py-1.5 px-2 mb-0 small"
                                                                            style="font-size: 0.74rem;">
                                                                            <div class="fw-bold d-flex align-items-center gap-1 text-danger">
                                                                                <i class="bi bi-lock-fill"></i> Dotación Mensual Ya Retirada
                                                                                (Periodo <?= htmlspecialchars($periodoActual) ?>)
                                                                            </div>
                                                                            <div class="text-muted mt-0.5">
                                                                                Entregado el
                                                                                <?= date('d/m/Y H:i', strtotime($rec['despacho_previo']['fecha_hora'])) ?>
                                                                                en
                                                                                <?= htmlspecialchars($rec['despacho_previo']['centro_nombre']) ?>
                                                                                por
                                                                                <?= htmlspecialchars($rec['despacho_previo']['operador_nombre']) ?>
                                                                                (<?= (int) $rec['despacho_previo']['cantidad_entregada'] ?>
                                                                                unid., Lote
                                                                                <?= htmlspecialchars($rec['despacho_previo']['numero_lote'] ?? 'S/L') ?>).
                                                                            </div>
                                                                        </div>
                                                                    <?php else: ?>
                                                                        <div>
                                                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                                                <span
                                                                                    class="badge bg-success-subtle text-success border border-success-subtle">
                                                                                    <i class="bi bi-check-circle-fill me-1"></i> Habilitado para Entrega
                                                                                </span>
                                                                                <span class="text-muted small">Periodo:
                                                                                    <?= htmlspecialchars($periodoActual) ?></span>
                                                                            </div>

                                                                            <?php if ($hayStock): ?>
                                                                                <div class="small text-muted" style="font-size: 0.74rem;">
                                                                                    <div>
                                                                                        <strong>Lote Prioritario FEFO:</strong>
                                                                                        <code
                                                                                            class="text-dark fw-bold"><?= htmlspecialchars($rec['lote_prioritario']['numero_lote']) ?></code>
                                                                                    </div>
                                                                                    <div>
                                                                                        Vence: <strong
                                                                                            class="text-dark"><?= date('d/m/Y', strtotime($rec['lote_prioritario']['fecha_vencimiento'])) ?></strong>
                                                                                        (en <?= (int) $rec['lote_prioritario']['dias_para_vencer'] ?>
                                                                                        días)
                                                                                    </div>
                                                                                    <div>
                                                                                        Stock disponible en sede: <strong
                                                                                            class="text-success font-monospace"><?= (int) $rec['stock_total_centro'] ?>
                                                                                            unidades</strong>
                                                                                        <?php if (count($rec['lotes_fefo']) > 1): ?>
                                                                                            <span
                                                                                                class="badge bg-light text-secondary border font-monospace ms-1"><?= count($rec['lotes_fefo']) ?>
                                                                                                lotes</span>
                                                                                        <?php endif; ?>
                                                                                    </div>
                                                                                </div>
                                                                            <?php else: ?>
                                                                                <div
                                                                                    class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle p-2 text-start text-wrap d-block">
                                                                                    <i class="bi bi-exclamation-triangle-fill me-1"></i> Sin
                                                                                    existencias en este ambulatorio
                                                                                    <div class="text-muted fw-normal" style="font-size: 0.68rem;">
                                                                                        No hay lotes con saldo positivo para dispensar en esta sede.
                                                                                    </div>
                                                                                </div>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </div>

                                                                <!-- Columna 3: Acción de Dispensación Asistencial -->
                                                                <div class="col-lg-3 col-md-12 text-lg-end text-start">
                                                                    <?php if (!tienePermiso('DESP_PROCESAR')): ?>
                                                                        <div
                                                                            class="d-flex flex-column align-items-lg-end align-items-start gap-1">
                                                                            <span
                                                                                class="badge bg-secondary-subtle text-secondary border border-secondary-subtle font-monospace"
                                                                                style="font-size: 0.72rem;">
                                                                                <i class="bi bi-eye me-1"></i> Modo Fiscalización / Solo Lectura
                                                                            </span>
                                                                            <button type="button"
                                                                                class="btn btn-outline-secondary btn-sm px-3 w-100 w-lg-auto"
                                                                                disabled
                                                                                title="Su rol cuenta con permisos de fiscalización y auditoría exclusivamente. Dispensación deshabilitada.">
                                                                                <i class="bi bi-lock me-1"></i> Dispensación Restringida
                                                                            </button>
                                                                        </div>
                                                                    <?php elseif ($estaVencido): ?>
                                                                        <button type="button"
                                                                            class="btn btn-outline-danger btn-sm px-3 w-100 w-lg-auto" disabled
                                                                            title="Récipe médico vencido. Requiere renovación facultativa antes de dispensar.">
                                                                            <i class="bi bi-exclamation-triangle-fill me-1"></i> Récipe Vencido
                                                                            - Renovar Prescripción
                                                                        </button>
                                                                    <?php elseif ($yaRetiro): ?>
                                                                        <button type="button"
                                                                            class="btn btn-secondary btn-sm px-3 w-100 w-lg-auto" disabled>
                                                                            <i class="bi bi-lock me-1"></i> Dotación Ya Retirada
                                                                        </button>
                                                                    <?php elseif ($frioBloquea): ?>
                                                                        <button type="button"
                                                                            class="btn btn-outline-danger btn-sm px-3 w-100 w-lg-auto" disabled
                                                                            title="Se requiere ambulatorio con refrigeración operativa para cadena de frío">
                                                                            <i class="bi bi-snow2 me-1"></i> Requiere Frío
                                                                        </button>
                                                                    <?php elseif ($hayStock): ?>
                                                                        <button type="button"
                                                                            class="btn btn-success btn-sm fw-semibold px-3 w-100 w-lg-auto btn-abrir-dispensar"
                                                                            data-recipe='<?= htmlspecialchars(json_encode($rec, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>'
                                                                            data-paciente='<?= htmlspecialchars(json_encode($pacienteEncontrado, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>'>
                                                                            <i class="bi bi-capsule me-1"></i> Dispensar Dotación
                                                                        </button>
                                                                    <?php else: ?>
                                                                        <button type="button"
                                                                            class="btn btn-outline-secondary btn-sm px-3 w-100 w-lg-auto"
                                                                            disabled>
                                                                            <i class="bi bi-slash-circle me-1"></i> Sin Stock
                                                                        </button>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- HISTORIAL DE DESPACHOS RECIENTES -->
                        <div class="card bg-white border shadow-sm">
                            <div
                                class="card-header bg-white border-bottom py-2.5 px-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="bi bi-clock-history text-primary fs-5"></i>
                                    <h6 class="fw-bold m-0 text-dark">Historial de Despachos Recientes en este Ambulatorio</h6>
                                </div>
                                <span class="badge bg-light text-secondary border font-monospace">
                                    <?= count($ultimosDespachos) ?> últimos despachos
                                </span>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive" style="max-height: 480px; overflow-y: auto;">
                                    <table class="table table-hover table-striped align-middle mb-0 small text-nowrap w-100">
                                        <thead class="table-light text-secondary text-uppercase border-bottom"
                                            style="font-size: 0.70rem; letter-spacing: 0.03em; position: sticky; top: 0; z-index: 1;">
                                            <tr>
                                                <th class="ps-3 py-2 text-center" style="width: 50px;">#ID</th>
                                                <th class="py-2">Fecha y Hora</th>
                                                <th class="py-2">Paciente</th>
                                                <th class="py-2">Fármaco Dispensado</th>
                                                <th class="py-2">Lote FEFO</th>
                                                <th class="py-2 text-center">Cantidad</th>
                                                <th class="py-2 text-center">Periodo</th>
                                                <th class="pe-3 py-2">Operador</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($ultimosDespachos)): ?>
                                                <tr>
                                                    <td colspan="8" class="text-center py-4 text-muted">
                                                        <i class="bi bi-inbox fs-3 d-block text-secondary opacity-50 mb-1"></i>
                                                        No se registran despachos asistenciales recientes en este ambulatorio.
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($ultimosDespachos as $d): ?>
                                                    <tr>
                                                        <td class="ps-3 py-2 text-center font-monospace fw-bold text-secondary">
                                                            #<?= (int) $d['id'] ?>
                                                        </td>
                                                        <td class="py-2">
                                                            <div class="font-monospace fw-semibold text-dark">
                                                                <?= date('d/m/Y', strtotime($d['fecha_hora'])) ?>
                                                            </div>
                                                            <div class="text-muted" style="font-size: 0.70rem;">
                                                                <?= date('H:i:s', strtotime($d['fecha_hora'])) ?>
                                                            </div>
                                                        </td>
                                                        <td class="py-2">
                                                            <div class="fw-bold text-dark">
                                                                <?= htmlspecialchars($d['paciente_nombre']) ?>
                                                            </div>
                                                            <div class="font-monospace text-muted" style="font-size: 0.70rem;">
                                                                <?= htmlspecialchars($d['tipo_doc_codigo'] ?? 'V') ?>-<?= htmlspecialchars($d['paciente_cedula']) ?>
                                                            </div>
                                                        </td>
                                                        <td class="py-2">
                                                            <div class="fw-semibold text-primary">
                                                                <?= htmlspecialchars($d['medicamento_nombre']) ?>
                                                            </div>
                                                            <div class="text-muted" style="font-size: 0.70rem;">
                                                                <?= htmlspecialchars($d['medicamento_concentracion']) ?> —
                                                                <?= htmlspecialchars($d['forma_farmaceutica'] ?? '') ?>
                                                            </div>
                                                        </td>
                                                        <td class="py-2">
                                                            <span class="badge bg-light text-dark border font-monospace">
                                                                <?= htmlspecialchars($d['numero_lote'] ?? 'S/L') ?>
                                                            </span>
                                                            <?php if (!empty($d['lote_vencimiento'])): ?>
                                                                <div class="text-muted font-monospace" style="font-size: 0.68rem;">
                                                                    Vence: <?= date('d/m/Y', strtotime($d['lote_vencimiento'])) ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="py-2 text-center">
                                                            <span class="badge bg-primary font-monospace fs-6 px-2.5 py-1">
                                                                <?= (int) $d['cantidad_entregada'] ?>
                                                            </span>
                                                        </td>
                                                        <td class="py-2 text-center font-monospace">
                                                            <span class="badge bg-secondary-subtle text-secondary border">
                                                                <?= htmlspecialchars($d['periodo_mes_anio']) ?>
                                                            </span>
                                                        </td>
                                                        <td class="pe-3 py-2">
                                                            <div class="fw-semibold text-dark">
                                                                <?= htmlspecialchars($d['operador_nombre']) ?>
                                                            </div>
                                                            <div class="text-muted" style="font-size: 0.70rem;">
                                                                <?= htmlspecialchars($d['centro_nombre']) ?>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- MODAL DE DISPENSACIÓN ASISTENCIAL FEFO -->
                        <div class="modal fade" id="modalDispensar" tabindex="-1" aria-labelledby="modalDispensarLabel"
                            aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-centered">
                                <div class="modal-content border-0 shadow">
                                    <form action="procesar.php" method="POST" id="form-dispensar" novalidate>
                                        <input type="hidden" name="csrf_token"
                                            value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="accion" value="procesar_despacho_fefo">
                                        <input type="hidden" name="tratamiento_id" id="disp_tratamiento_id" value="">
                                        <input type="hidden" name="centro_salud_id" id="disp_centro_salud_id"
                                            value="<?= (int) $centroOperativoId ?>">
                                        <input type="hidden" name="q_paciente_retorno"
                                            value="<?= htmlspecialchars($busquedaPaciente) ?>">

                                        <div class="modal-header bg-success text-white py-2.5 px-3">
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="bi bi-capsule fs-5"></i>
                                                <h6 class="modal-title fw-bold m-0" id="modalDispensarLabel">
                                                    Dispensación Asistencial FEFO — Dotación Mensual
                                                </h6>
                                            </div>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                                                aria-label="Cerrar"></button>
                                        </div>

                                        <div class="modal-body p-3">
                                            <!-- Resumen Clínico del Paciente y Prescripción -->
                                            <div class="card bg-light border mb-3">
                                                <div class="card-body p-3">
                                                    <div class="row g-2 small">
                                                        <div class="col-sm-6">
                                                            <span class="text-muted fw-semibold d-block">Paciente
                                                                Titular:</span>
                                                            <span id="disp-txt-paciente" class="fw-bold text-dark fs-6"></span>
                                                            <div class="text-muted font-monospace" id="disp-txt-cedula"
                                                                style="font-size: 0.75rem;"></div>
                                                        </div>
                                                        <div class="col-sm-6">
                                                            <span class="text-muted fw-semibold d-block">Fármaco
                                                                Prescrito:</span>
                                                            <span id="disp-txt-medicamento"
                                                                class="fw-bold text-primary fs-6"></span>
                                                            <div class="text-muted" id="disp-txt-forma"
                                                                style="font-size: 0.75rem;"></div>
                                                        </div>
                                                        <div class="col-sm-6">
                                                            <span class="text-muted fw-semibold d-block">Patología Diagnosticada
                                                                (CIE-10):</span>
                                                            <span id="disp-txt-patologia" class="fw-semibold text-dark"></span>
                                                        </div>
                                                        <div class="col-sm-6">
                                                            <span class="text-muted fw-semibold d-block">Dosis Mensual
                                                                Autorizada:</span>
                                                            <span id="disp-txt-dosis"
                                                                class="badge bg-primary fs-6 font-monospace"></span>
                                                            <span class="text-muted ms-1" style="font-size: 0.75rem;">unidades /
                                                                mes</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Selección de Lote FEFO y Parámetros de Entrega -->
                                            <div class="row g-3">
                                                <div class="col-md-7">
                                                    <label class="form-label small fw-semibold text-secondary mb-1">
                                                        <i class="bi bi-sort-numeric-down text-success me-1"></i> Seleccionar
                                                        Lote FEFO *
                                                    </label>
                                                    <select name="lote_id" id="disp_lote_id" class="form-select form-select-sm"
                                                        required>
                                                        <!-- Se llena dinámicamente con JavaScript -->
                                                    </select>
                                                    <div class="form-text text-success" id="disp-fefo-help"
                                                        style="font-size: 0.72rem;">
                                                        <i class="bi bi-shield-check me-1"></i> Ordenado según vencimiento más
                                                        próximo (First Expired, First Out).
                                                    </div>
                                                </div>

                                                <div class="col-md-5">
                                                    <label class="form-label small fw-semibold text-secondary mb-1">
                                                        <i class="bi bi-calculator me-1"></i> Cantidad a Dispensar *
                                                    </label>
                                                    <input type="number" name="cantidad" id="disp_cantidad"
                                                        class="form-control form-control-sm font-monospace fw-bold" min="1"
                                                        max="1" value="1" required>
                                                    <div class="form-text" id="disp-max-help" style="font-size: 0.70rem;">
                                                        Máximo disponible en lote / récipe: <strong
                                                            id="disp-txt-max-dosis"></strong> unid.
                                                    </div>
                                                </div>

                                                <div class="col-12" id="disp-alerta-parcial-col" style="display: none;">
                                                    <div class="alert alert-warning border py-2 px-3 mb-0 small"
                                                        style="font-size: 0.74rem;">
                                                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                                        <strong>Aviso Clínico:</strong>
                                                        <span id="disp-txt-alerta-parcial"></span>
                                                    </div>
                                                </div>

                                                <div class="col-md-6">
                                                    <label class="form-label small fw-semibold text-secondary mb-1">
                                                        <i class="bi bi-calendar-check me-1"></i> Periodo de Retiro Mensual
                                                    </label>
                                                    <div class="input-group input-group-sm">
                                                        <input type="text" name="periodo_mes_anio" id="disp_periodo"
                                                            class="form-control font-monospace fw-bold bg-light"
                                                            value="<?= htmlspecialchars($periodoActual) ?>" readonly>
                                                        <span class="input-group-text bg-light text-muted">
                                                            <i class="bi bi-lock-fill text-danger"
                                                                title="Candado relacional mensual contra doble retiro"></i>
                                                        </span>
                                                    </div>
                                                    <div class="form-text text-muted" style="font-size: 0.68rem;">
                                                        Candado <code>uk_retiro_mensual</code> activo para este periodo.
                                                    </div>
                                                </div>

                                                <div class="col-md-6">
                                                    <label class="form-label small fw-semibold text-secondary mb-1">
                                                        <i class="bi bi-hospital me-1"></i> Ambulatorio Dispensador
                                                    </label>
                                                    <input type="text" class="form-control form-control-sm bg-light"
                                                        value="<?= htmlspecialchars($centroOperativo['nombre'] ?? '') ?>"
                                                        readonly>
                                                    <div class="form-text text-muted" style="font-size: 0.68rem;">
                                                        Sede física de custodia del lote e inventario.
                                                    </div>
                                                </div>

                                                <div class="col-12">
                                                    <label class="form-label small fw-semibold text-secondary mb-1">
                                                        Observaciones Clínicas / Notas de Entrega (Opcional)
                                                    </label>
                                                    <textarea name="observaciones" id="disp_observaciones"
                                                        class="form-control form-control-sm" rows="2"
                                                        placeholder="Ej: Entrega conforme de dotación mensual. Presentó récipe original firmado y sellado."
                                                        maxlength="255"></textarea>
                                                    <div class="form-text" style="font-size: 0.68rem;">
                                                        Se registrará de forma inmutable en el acta de despacho y en la bitácora
                                                        de auditoría forense.
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="modal-footer bg-light py-2 px-3 border-top d-flex justify-content-between">
                                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                                data-bs-dismiss="modal">
                                                <i class="bi bi-x-lg me-1"></i> Cancelar
                                            </button>
                                            <button type="submit" class="btn btn-success btn-sm fw-semibold px-4"
                                                id="btn-confirmar-dispensar">
                                                <i class="bi bi-check-circle-fill me-1"></i> Confirmar y Dispensar Fármaco
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- MODAL DE RECEPCIÓN DE LOTE / ENTRADA DE STOCK -->
                        <?php if ($puedeRegistrarLote): ?>
                        <div class="modal fade" id="modalEntradaLote" tabindex="-1" aria-labelledby="modalEntradaLoteLabel" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-centered">
                                <div class="modal-content border-0 shadow">
                                    <form action="procesar.php" method="POST" id="form-entrada-lote" novalidate>
                                        <input type="hidden" name="csrf_token"
                                            value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="accion" value="registrar_entrada_lote">

                                        <div class="modal-header bg-primary text-white py-2.5 px-3">
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="bi bi-box-seam-fill fs-5"></i>
                                                <h6 class="modal-title fw-bold m-0" id="modalEntradaLoteLabel">
                                                    Recepción de Lote — Entrada de Inventario Farmacéutico
                                                </h6>
                                            </div>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                                                aria-label="Cerrar"></button>
                                        </div>

                                        <div class="modal-body p-3">
                                            <div class="row g-3">
                                                <!-- Ambulatorio Destino -->
                                                <div class="col-md-6">
                                                    <label class="form-label small fw-semibold text-secondary mb-1">
                                                        Ambulatorio Destino *
                                                    </label>
                                                    <?php if ($puedeVerTodaLaRed): ?>
                                                        <select name="centro_salud_id" class="form-select form-select-sm" required>
                                                            <?php foreach ($catalogoCentros as $c): ?>
                                                                <option value="<?= (int) $c['id'] ?>" <?= ((int) $c['id'] === $centroOperativoId) ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($c['codigo_sicm']) ?> — <?= htmlspecialchars($c['nombre']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    <?php else: ?>
                                                        <input type="hidden" name="centro_salud_id" value="<?= (int) $centroOperativoId ?>">
                                                        <input type="text" class="form-control form-control-sm bg-light"
                                                            value="<?= htmlspecialchars($centroOperativo['nombre'] ?? '') ?>" readonly>
                                                    <?php endif; ?>
                                                    <div class="form-text" style="font-size: 0.70rem;">Sede donde se custodiará físicamente el lote.</div>
                                                </div>

                                                <!-- Fármaco del Vademécum -->
                                                <div class="col-md-6">
                                                    <label class="form-label small fw-semibold text-secondary mb-1">
                                                        Fármaco (Vademécum Activo) *
                                                    </label>
                                                    <select name="medicamento_id" class="form-select form-select-sm" required>
                                                        <option value="">-- Seleccionar Principio Activo --</option>
                                                        <?php foreach ($catalogoMedicamentosActivos as $m): ?>
                                                            <option value="<?= (int) $m['id'] ?>">
                                                                <?= htmlspecialchars($m['nombre_generico']) ?> (<?= htmlspecialchars($m['concentracion']) ?>) — <?= htmlspecialchars($m['forma_nombre'] ?? '') ?>
                                                                <?= ((int) $m['requiere_cadena_frio'] === 1) ? ' [Cadena de Frío]' : '' ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <div class="form-text" style="font-size: 0.70rem;">Catálogo oficial activo de medicamentos.</div>
                                                </div>

                                                <!-- Número de Lote -->
                                                <div class="col-md-4">
                                                    <label class="form-label small fw-semibold text-secondary mb-1">
                                                        Número de Lote *
                                                    </label>
                                                    <input type="text" name="numero_lote" class="form-control form-control-sm font-monospace text-uppercase"
                                                        placeholder="Ej: LOT-2026-X14" required minlength="2" maxlength="30">
                                                    <div class="form-text" style="font-size: 0.70rem;">Identificador impreso en el empaque.</div>
                                                </div>

                                                <!-- Fecha de Caducidad -->
                                                <div class="col-md-4">
                                                    <label class="form-label small fw-semibold text-secondary mb-1">
                                                        Fecha de Vencimiento *
                                                    </label>
                                                    <input type="date" name="fecha_vencimiento" class="form-control form-control-sm font-monospace"
                                                        required min="<?= date('Y-m-d', strtotime('+1 day')) ?>" value="<?= date('Y-m-d', strtotime('+1 year')) ?>">
                                                    <div class="form-text" style="font-size: 0.70rem;">Criterio primario para despacho FEFO.</div>
                                                </div>

                                                <!-- Cantidad Recibida -->
                                                <div class="col-md-4">
                                                    <label class="form-label small fw-semibold text-secondary mb-1">
                                                        Cantidad Recibida (Unidades) *
                                                    </label>
                                                    <input type="number" name="cantidad" class="form-control form-control-sm font-monospace fw-bold"
                                                        min="1" max="100000" value="100" required>
                                                    <div class="form-text" style="font-size: 0.70rem;">Dosis o cajas físicas ingresadas.</div>
                                                </div>

                                                <!-- Laboratorio / Proveedor -->
                                                <div class="col-md-6">
                                                    <label class="form-label small fw-semibold text-secondary mb-1">
                                                        Laboratorio / Proveedor Sanitario
                                                    </label>
                                                    <input type="text" name="laboratorio_proveedor" class="form-control form-control-sm"
                                                        placeholder="Ej: Laboratorios Behrens / Proveeduría MPPS" maxlength="100">
                                                </div>

                                                <!-- Observaciones / Documento de Entrega -->
                                                <div class="col-md-6">
                                                    <label class="form-label small fw-semibold text-secondary mb-1">
                                                        Acta / Guía de Despacho u Observación
                                                    </label>
                                                    <input type="text" name="observaciones" class="form-control form-control-sm"
                                                        placeholder="Ej: Acta de Recepción N° 2026-048" maxlength="255">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="modal-footer bg-light py-2 px-3 border-top d-flex justify-content-between">
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">
                                                <i class="bi bi-x-lg me-1"></i> Cancelar
                                            </button>
                                            <button type="submit" class="btn btn-primary btn-sm px-3 fw-semibold">
                                                <i class="bi bi-box-arrow-in-down me-1"></i> Registrar Entrada de Lote
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- JAVASCRIPT DEL MÓDULO DE DISPENSACIÓN FEFO -->
                        <script src="public/js/despachos_fefo.js"></script>
