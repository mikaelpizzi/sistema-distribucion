<?php
// src/views/tabs/tab_recipes.php
// SICM | Módulo Autosuficiente: Récipes Médicos y Tratamientos Autorizados
declare(strict_types=1);

$moduloNombre = 'Récipes Médicos';
$seccionNombre = 'Gestión Clínica';

// 1. Catálogos Normalizados para Selectores Clínicos
$catalogoPacientes = $pdo->query("
    SELECT p.id, p.cedula, td.codigo AS tipo_doc_codigo, p.nombre_completo
    FROM pacientes p
    LEFT JOIN tipos_documento td ON p.tipo_documento_id = td.id
    ORDER BY p.nombre_completo ASC
")->fetchAll(PDO::FETCH_ASSOC);

$catalogoMedicamentos = $pdo->query("
    SELECT m.id, m.nombre_generico, m.concentracion, ff.nombre AS forma_nombre
    FROM medicamentos m
    LEFT JOIN formas_farmaceuticas ff ON m.forma_farmaceutica_id = ff.id
    WHERE m.activo = 1
    ORDER BY m.nombre_generico ASC
")->fetchAll(PDO::FETCH_ASSOC);

$catalogoPatologias = $pdo->query("
    SELECT id, codigo_cie10, nombre
    FROM patologias
    WHERE activo = 1
    ORDER BY nombre ASC
")->fetchAll(PDO::FETCH_ASSOC);

// 2. Métricas del Cuadro de Mando de Récipes (KPIs Institucionales)
$kpiTotalRecipes = (int) ($pdo->query("SELECT COUNT(*) FROM tratamientos_autorizados")->fetchColumn() ?: 0);
$kpiRecipesVigentes = (int) ($pdo->query("SELECT COUNT(*) FROM tratamientos_autorizados WHERE activo = 1 AND fecha_vencimiento_recipe >= CURRENT_DATE")->fetchColumn() ?: 0);
$kpiRecipesVencidos = (int) ($pdo->query("SELECT COUNT(*) FROM tratamientos_autorizados WHERE fecha_vencimiento_recipe < CURRENT_DATE OR activo = 0")->fetchColumn() ?: 0);

// 3. Parámetros de Búsqueda y Filtros
$busquedaRecipe = trim($_GET['q_recipe'] ?? '');
$filtroEstadoRecipe = trim($_GET['filtro_estado'] ?? 'todos');
$filtroPatologiaRecipe = trim($_GET['filtro_patologia'] ?? 'todas');
$filtroPacienteId = !empty($_GET['paciente_id']) ? (int) $_GET['paciente_id'] : null;

$condRecipes = [];
$paramsRecipes = [];

if ($busquedaRecipe !== '') {
    $condRecipes[] = "(p.cedula LIKE :q1 OR p.nombre_completo LIKE :q2 OR m.nombre_generico LIKE :q3 OR t.medico_prescriptor LIKE :q4)";
    $paramsRecipes[':q1'] = '%' . $busquedaRecipe . '%';
    $paramsRecipes[':q2'] = '%' . $busquedaRecipe . '%';
    $paramsRecipes[':q3'] = '%' . $busquedaRecipe . '%';
    $paramsRecipes[':q4'] = '%' . $busquedaRecipe . '%';
}

if ($filtroEstadoRecipe === 'vigentes') {
    $condRecipes[] = "(t.activo = 1 AND t.fecha_vencimiento_recipe >= CURRENT_DATE)";
} elseif ($filtroEstadoRecipe === 'vencidos') {
    $condRecipes[] = "(t.fecha_vencimiento_recipe < CURRENT_DATE OR t.activo = 0)";
}

if ($filtroPatologiaRecipe !== 'todas' && $filtroPatologiaRecipe !== '' && ctype_digit($filtroPatologiaRecipe)) {
    $condRecipes[] = "t.patologia_id = :patologia_id";
    $paramsRecipes[':patologia_id'] = (int) $filtroPatologiaRecipe;
}

if ($filtroPacienteId !== null && $filtroPacienteId > 0) {
    $condRecipes[] = "t.paciente_id = :paciente_id";
    $paramsRecipes[':paciente_id'] = $filtroPacienteId;
}

$whereRecipesSql = !empty($condRecipes) ? 'WHERE ' . implode(' AND ', $condRecipes) : '';

// 4. Conteo y Paginación
$stmtCountRec = $pdo->prepare("
    SELECT COUNT(*)
    FROM tratamientos_autorizados t
    JOIN pacientes p ON t.paciente_id = p.id
    LEFT JOIN tipos_documento td ON p.tipo_documento_id = td.id
    JOIN medicamentos m ON t.medicamento_id = m.id
    LEFT JOIN formas_farmaceuticas ff ON m.forma_farmaceutica_id = ff.id
    JOIN patologias pat ON t.patologia_id = pat.id
    {$whereRecipesSql}
");
$stmtCountRec->execute($paramsRecipes);
$totalRegistrosRecipes = (int) $stmtCountRec->fetchColumn();
$totalRecipesFiltrados = $totalRegistrosRecipes;

$limitePorPagina = 15;
$paginaActual = max(1, (int) ($_GET['p_recipe'] ?? 1));
$totalPaginas = max(1, (int) ceil($totalRegistrosRecipes / $limitePorPagina));
if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
}
$offsetRecipes = ($paginaActual - 1) * $limitePorPagina;

// 5. Consulta Principal de Récipes
$sqlRecipes = "
    SELECT t.id, t.paciente_id, t.medicamento_id, t.patologia_id, t.dosis_mensual,
           t.fecha_emision_recipe, t.fecha_vencimiento_recipe, t.medico_prescriptor,
           t.activo, t.created_at,
           (t.fecha_vencimiento_recipe < CURRENT_DATE) AS esta_vencido,
           p.cedula AS paciente_cedula, p.nombre_completo AS paciente_nombre,
           td.codigo AS tipo_doc_codigo,
           m.nombre_generico AS medicamento_nombre, m.concentracion AS medicamento_concentracion,
           ff.nombre AS forma_nombre,
           pat.codigo_cie10 AS patologia_cie10, pat.nombre AS patologia_nombre
    FROM tratamientos_autorizados t
    JOIN pacientes p ON t.paciente_id = p.id
    LEFT JOIN tipos_documento td ON p.tipo_documento_id = td.id
    JOIN medicamentos m ON t.medicamento_id = m.id
    LEFT JOIN formas_farmaceuticas ff ON m.forma_farmaceutica_id = ff.id
    JOIN patologias pat ON t.patologia_id = pat.id
    {$whereRecipesSql}
    ORDER BY t.id DESC
    LIMIT :limite OFFSET :offset
";
$stmtRec = $pdo->prepare($sqlRecipes);
foreach ($paramsRecipes as $k => $v) {
    $stmtRec->bindValue($k, $v);
}
$stmtRec->bindValue(':limite', $limitePorPagina, PDO::PARAM_INT);
$stmtRec->bindValue(':offset', $offsetRecipes, PDO::PARAM_INT);
$stmtRec->execute();
$recipes = $stmtRec->fetchAll(PDO::FETCH_ASSOC);

// 6. Carga de Récipe para Edición si aplica
$recipeEdicion = null;
if (!empty($_GET['editar_id']) || !empty($_GET['editar'])) {
    $edId = (int) (!empty($_GET['editar_id']) ? $_GET['editar_id'] : $_GET['editar']);
    $stmtEd = $pdo->prepare("SELECT * FROM tratamientos_autorizados WHERE id = :id");
    $stmtEd->execute([':id' => $edId]);
    $recipeEdicion = $stmtEd->fetch(PDO::FETCH_ASSOC) ?: null;
}
?>
<!-- Cuadro de Mando de Récipes (3 Tarjetas KPIs Institucionales) -->
                        <div class="row g-2 mb-3">
                            <div class="col-sm-6 col-md-4">
                                <div class="card bg-white border shadow-sm p-3 d-flex flex-row align-items-center gap-3">
                                    <div class="bg-primary-subtle text-primary p-2.5 rounded-3 d-flex align-items-center justify-content-center"
                                        style="width: 48px; height: 48px;">
                                        <i class="bi bi-file-earmark-medical fs-4"></i>
                                    </div>
                                    <div>
                                        <span class="text-muted small d-block fw-semibold text-uppercase"
                                            style="font-size: 0.72rem; letter-spacing: 0.04em;">Total Récipes</span>
                                        <div class="fs-4 fw-bold text-dark font-monospace">
                                            <?= number_format($kpiTotalRecipes) ?></div>
                                        <span class="text-muted small" style="font-size: 0.70rem;">Tratamientos autorizados
                                            registrados</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-6 col-md-4">
                                <div class="card bg-white border shadow-sm p-3 d-flex flex-row align-items-center gap-3">
                                    <div class="bg-success-subtle text-success p-2.5 rounded-3 d-flex align-items-center justify-content-center"
                                        style="width: 48px; height: 48px;">
                                        <i class="bi bi-patch-check fs-4 text-success"></i>
                                    </div>
                                    <div>
                                        <span class="text-muted small d-block fw-semibold text-uppercase"
                                            style="font-size: 0.72rem; letter-spacing: 0.04em;">Récipes Vigentes</span>
                                        <div class="fs-4 fw-bold text-success font-monospace">
                                            <?= number_format($kpiRecipesVigentes) ?></div>
                                        <span class="text-muted small" style="font-size: 0.70rem;">Activos y aptos para
                                            dispensación</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12 col-md-4">
                                <div class="card bg-white border shadow-sm p-3 d-flex flex-row align-items-center gap-3">
                                    <div class="bg-warning-subtle text-warning-emphasis p-2.5 rounded-3 d-flex align-items-center justify-content-center"
                                        style="width: 48px; height: 48px;">
                                        <i class="bi bi-clock-history fs-4 text-warning"></i>
                                    </div>
                                    <div>
                                        <span class="text-muted small d-block fw-semibold text-uppercase"
                                            style="font-size: 0.72rem; letter-spacing: 0.04em;">Vencidos / A Renovar</span>
                                        <div class="fs-4 fw-bold text-warning-emphasis font-monospace">
                                            <?= number_format($kpiRecipesVencidos) ?></div>
                                        <span class="text-muted small" style="font-size: 0.70rem;">Requieren renovación
                                            médica</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Modal de Registro / Edición de Récipe Médico -->
                        <div class="modal fade" id="modalRecipe" tabindex="-1" aria-labelledby="modalRecipeLabel"
                            aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-centered">
                                <div class="modal-content border-0 shadow">
                                    <form action="procesar.php" method="POST" id="form-recipe" novalidate>
                                        <div class="modal-header bg-light py-2 px-3 border-bottom">
                                            <h6 class="modal-title fw-bold" id="modalRecipeLabel">
                                                <i
                                                    class="bi <?= $recipeEdicion ? 'bi-pencil-square text-warning' : 'bi-file-earmark-medical-fill text-primary' ?> me-1"></i>
                                                <?= $recipeEdicion ? 'Actualizar Récipe Médico #' . (int) $recipeEdicion['id'] : 'Emitir Nuevo Récipe Médico' ?>
                                            </h6>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                                aria-label="Cerrar"></button>
                                        </div>
                                        <div class="modal-body p-3">
                                            <input type="hidden" name="csrf_token"
                                                value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="accion" value="guardar_recipe">
                                            <input type="hidden" name="id"
                                                value="<?= $recipeEdicion ? (int) $recipeEdicion['id'] : '' ?>">

                                            <div class="row g-2 mb-2">
                                                <div class="col-md-6">
                                                    <label class="form-label small fw-semibold text-secondary mb-1">Paciente
                                                        Titular *</label>
                                                    <select name="paciente_id" id="recipe_paciente_id"
                                                        class="form-select form-select-sm" required>
                                                        <option value="">-- Seleccionar Paciente Registrado --</option>
                                                        <?php foreach ($catalogoPacientes as $pac): ?>
                                                            <?php
                                                            $selPac = ($recipeEdicion && (int) $recipeEdicion['paciente_id'] === (int) $pac['id'])
                                                                || (!$recipeEdicion && $filtroPacienteId && $filtroPacienteId === (int) $pac['id']);
                                                            ?>
                                                            <option value="<?= (int) $pac['id'] ?>" <?= $selPac ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($pac['tipo_doc_codigo'] ?? 'V') ?>-<?= htmlspecialchars($pac['cedula']) ?>
                                                                — <?= htmlspecialchars($pac['nombre_completo']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <div class="form-text" style="font-size: 0.68rem;">Paciente registrado en el
                                                        padrón municipal.</div>
                                                </div>

                                                <div class="col-md-6">
                                                    <label class="form-label small fw-semibold text-secondary mb-1">Patología
                                                        Asociada (CIE-10) *</label>
                                                    <select name="patologia_id" id="recipe_patologia_id"
                                                        class="form-select form-select-sm" required>
                                                        <option value="">-- Seleccionar Diagnóstico CIE-10 --</option>
                                                        <?php foreach ($catalogoPatologias as $pat): ?>
                                                            <?php $selPat = ($recipeEdicion && (int) $recipeEdicion['patologia_id'] === (int) $pat['id']); ?>
                                                            <option value="<?= (int) $pat['id'] ?>" <?= $selPat ? 'selected' : '' ?>>
                                                                [<?= htmlspecialchars($pat['codigo_cie10'] ?? 'S/C') ?>]
                                                                <?= htmlspecialchars($pat['nombre']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <div class="form-text" style="font-size: 0.68rem;">Diagnóstico clínico
                                                        clasificado bajo estándar internacional CIE-10.</div>
                                                </div>
                                            </div>

                                            <div class="row g-2 mb-2">
                                                <div class="col-md-8">
                                                    <label class="form-label small fw-semibold text-secondary mb-1">Medicamento
                                                        Prescrito *</label>
                                                    <select name="medicamento_id" id="recipe_medicamento_id"
                                                        class="form-select form-select-sm" required>
                                                        <option value="">-- Seleccionar Fármaco del Vademécum --</option>
                                                        <?php foreach ($catalogoMedicamentos as $med): ?>
                                                            <?php $selMed = ($recipeEdicion && (int) $recipeEdicion['medicamento_id'] === (int) $med['id']); ?>
                                                            <option value="<?= (int) $med['id'] ?>" <?= $selMed ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($med['nombre_generico']) ?>
                                                                <?= htmlspecialchars($med['concentracion']) ?>
                                                                (<?= htmlspecialchars($med['forma_nombre'] ?? 'Sin forma') ?>)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <div class="form-text" style="font-size: 0.68rem;">Principio activo oficial
                                                        autorizado en el catálogo farmacéutico.</div>
                                                </div>

                                                <div class="col-md-4">
                                                    <label class="form-label small fw-semibold text-secondary mb-1">Dosis
                                                        Mensual Autorizada *</label>
                                                    <div class="input-group input-group-sm">
                                                        <input type="number" name="dosis_mensual" id="recipe_dosis_mensual"
                                                            class="form-control" min="1" max="999" step="1" required
                                                            placeholder="Ej: 30"
                                                            value="<?= $recipeEdicion ? (int) $recipeEdicion['dosis_mensual'] : '30' ?>">
                                                        <span class="input-group-text small text-muted">unidades</span>
                                                    </div>
                                                    <div class="form-text" style="font-size: 0.68rem;">Unidades mensuales
                                                        máximas para dispensación.</div>
                                                </div>
                                            </div>

                                            <div class="row g-2 mb-2">
                                                <div class="col-md-6">
                                                    <label class="form-label small fw-semibold text-secondary mb-1">Fecha de
                                                        Emisión *</label>
                                                    <input type="date" name="fecha_emision_recipe" id="recipe_fecha_emision"
                                                        class="form-control form-control-sm" required
                                                        value="<?= $recipeEdicion ? htmlspecialchars($recipeEdicion['fecha_emision_recipe']) : date('Y-m-d') ?>">
                                                    <div class="form-text" style="font-size: 0.68rem;">Fecha de expedición
                                                        médica del récipe.</div>
                                                </div>

                                                <div class="col-md-6">
                                                    <label class="form-label small fw-semibold text-secondary mb-1">Fecha de
                                                        Vencimiento *</label>
                                                    <input type="date" name="fecha_vencimiento_recipe"
                                                        id="recipe_fecha_vencimiento" class="form-control form-control-sm"
                                                        required
                                                        min="<?= $recipeEdicion ? htmlspecialchars($recipeEdicion['fecha_emision_recipe']) : date('Y-m-d') ?>"
                                                        value="<?= $recipeEdicion ? htmlspecialchars($recipeEdicion['fecha_vencimiento_recipe']) : date('Y-m-d', strtotime('+6 months')) ?>">
                                                    <div class="form-text" style="font-size: 0.68rem;">Límite temporal de
                                                        vigencia para despachos asistenciales.</div>
                                                </div>
                                            </div>

                                            <div class="mb-2">
                                                <label class="form-label small fw-semibold text-secondary mb-1">Médico
                                                    Prescriptor y Matrícula MPPS *</label>
                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text bg-white text-muted"><i
                                                            class="bi bi-person-badge"></i></span>
                                                    <input type="text" name="medico_prescriptor" id="recipe_medico_prescriptor"
                                                        class="form-control" maxlength="120" required
                                                        placeholder="Ej: Dra. Elena Rivas - MPPS 52.880"
                                                        value="<?= $recipeEdicion ? htmlspecialchars($recipeEdicion['medico_prescriptor'] ?? '') : '' ?>">
                                                </div>
                                                <div class="form-text" style="font-size: 0.68rem;">Nombre completo del
                                                    facultativo tratante y su credencial sanitaria oficial.</div>
                                            </div>
                                        </div>
                                        <div class="modal-footer bg-light py-2 px-3 border-top d-flex justify-content-between">
                                            <?php if ($recipeEdicion): ?>
                                                <a href="index.php?tab=recipes" class="btn btn-outline-secondary btn-sm">
                                                    <i class="bi bi-x-circle me-1"></i>Cancelar Edición
                                                </a>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-outline-secondary btn-sm"
                                                    data-bs-dismiss="modal">
                                                    Cerrar
                                                </button>
                                            <?php endif; ?>
                                            <button type="submit" class="btn btn-primary btn-sm px-3 shadow-sm">
                                                <i
                                                    class="bi bi-check-lg me-1"></i><?= $recipeEdicion ? 'Actualizar Récipe' : 'Guardar y Emitir Récipe' ?>
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-12">
                                <!-- Barra de Búsqueda y Filtros -->
                                <div class="card bg-white border shadow-sm p-3 mb-3">
                                    <form method="GET" action="index.php" class="row g-2 align-items-center">
                                        <input type="hidden" name="tab" value="recipes">
                                        <?php if ($filtroPacienteId): ?>
                                            <input type="hidden" name="paciente_id" value="<?= (int) $filtroPacienteId ?>">
                                        <?php endif; ?>
                                        <div class="col-md-4">
                                            <div class="input-group input-group-sm">
                                                <span class="input-group-text bg-white text-muted"><i
                                                        class="bi bi-search"></i></span>
                                                <input type="search" name="q_recipe" class="form-control"
                                                    placeholder="Buscar por cédula, paciente, fármaco o médico..."
                                                    value="<?= htmlspecialchars($busquedaRecipe) ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <select name="filtro_estado" class="form-select form-select-sm">
                                                <option value="todos" <?= $filtroEstadoRecipe === 'todos' ? 'selected' : '' ?>>
                                                    Todos los estados</option>
                                                <option value="vigentes" <?= $filtroEstadoRecipe === 'vigentes' ? 'selected' : '' ?>>Solo Récipes Vigentes</option>
                                                <option value="vencidos" <?= $filtroEstadoRecipe === 'vencidos' ? 'selected' : '' ?>>Vencidos o Inactivos</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <select name="filtro_patologia" class="form-select form-select-sm">
                                                <option value="todas">Todas las patologías CIE-10</option>
                                                <?php foreach ($catalogoPatologias as $pat): ?>
                                                    <option value="<?= (int) $pat['id'] ?>"
                                                        <?= ((string) $filtroPatologiaRecipe === (string) $pat['id']) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($pat['codigo_cie10'] ?? 'S/C') ?> -
                                                        <?= htmlspecialchars($pat['nombre']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2 d-flex gap-1">
                                            <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                                                <i class="bi bi-funnel"></i> Filtrar
                                            </button>
                                            <?php if ($busquedaRecipe !== '' || $filtroEstadoRecipe !== 'todos' || $filtroPatologiaRecipe !== 'todas' || $filtroPacienteId): ?>
                                                <a href="index.php?tab=recipes" class="btn btn-outline-secondary btn-sm"
                                                    title="Limpiar Filtros">
                                                    <i class="bi bi-x-circle"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </form>
                                </div>

                                <!-- Tabla Institucional de Récipes Médicos -->
                                <div class="card-sicm shadow-sm">
                                    <div
                                        class="card-header-sicm d-flex flex-wrap justify-content-between align-items-center gap-2">
                                        <span class="fw-bold">
                                            <i class="bi bi-file-earmark-medical me-1"></i> Récipes Médicos y Tratamientos
                                            Autorizados
                                        </span>
                                        <div class="d-flex align-items-center gap-2">
                                            <span
                                                class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace"
                                                style="font-size: 0.74rem;">
                                                <?= number_format($totalRecipesFiltrados) ?> récipes registrados
                                            </span>
                                            <?php if (tienePermiso('RECIPES_GESTIONAR')): ?>
                                                <button type="button"
                                                    class="btn btn-primary btn-sm px-3 d-inline-flex align-items-center gap-1.5 shadow-sm"
                                                    data-bs-toggle="modal" data-bs-target="#modalRecipe">
                                                    <i class="bi bi-plus-circle me-1"></i>Emitir Nuevo Récipe
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0 sicm-table"
                                            style="font-size: 0.82rem;">
                                            <thead class="table-light text-secondary text-uppercase"
                                                style="font-size: 0.72rem; letter-spacing: 0.03em;">
                                                <tr>
                                                    <th style="width: 50px;">#ID</th>
                                                    <th style="width: 170px;">Paciente</th>
                                                    <th style="width: 180px;">Patología CIE-10</th>
                                                    <th>Medicamento y Dosis</th>
                                                    <th style="width: 150px;">Vigencia</th>
                                                    <th style="width: 180px;">Médico Prescriptor</th>
                                                    <th style="width: 90px;" class="text-center">Estado</th>
                                                    <th style="width: 100px;" class="text-end">Acciones</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($recipes)): ?>
                                                    <tr>
                                                        <td colspan="8" class="text-center py-4 text-muted">
                                                            <i class="bi bi-inbox fs-3 d-block text-secondary mb-1"></i>
                                                            <span>No se encontraron récipes médicos que coincidan con los criterios
                                                                de búsqueda.</span>
                                                        </td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($recipes as $r): ?>
                                                        <?php
                                                        $vencido = (int) $r['esta_vencido'] === 1;
                                                        $activo = (int) $r['activo'] === 1;
                                                        $emisionFmt = !empty($r['fecha_emision_recipe']) ? date('d/m/Y', strtotime($r['fecha_emision_recipe'])) : '—';
                                                        $vencimientoFmt = !empty($r['fecha_vencimiento_recipe']) ? date('d/m/Y', strtotime($r['fecha_vencimiento_recipe'])) : '—';
                                                        ?>
                                                        <tr class="<?= !$activo ? 'opacity-75 bg-light' : '' ?>">
                                                            <td class="text-muted font-monospace"><?= (int) $r['id'] ?></td>
                                                            <td>
                                                                <div class="fw-bold text-dark">
                                                                    <?= htmlspecialchars($r['paciente_nombre']) ?></div>
                                                                <span
                                                                    class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace py-0.5 px-1.5"
                                                                    style="font-size: 0.72rem;">
                                                                    <i
                                                                        class="bi bi-person-vcard me-1"></i><?= htmlspecialchars($r['tipo_doc_codigo'] ?? 'V') ?>-<?= htmlspecialchars($r['paciente_cedula']) ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <span
                                                                    class="badge bg-secondary-subtle text-dark border border-secondary-subtle font-monospace py-0.5 px-1.5 mb-1 d-inline-block"
                                                                    style="font-size: 0.70rem;">
                                                                    <?= htmlspecialchars($r['patologia_cie10'] ?? 'S/C') ?>
                                                                </span>
                                                                <div class="text-secondary small fw-medium"
                                                                    style="font-size: 0.76rem; line-height: 1.2;">
                                                                    <?= htmlspecialchars($r['patologia_nombre']) ?>
                                                                </div>
                                                            </td>
                                                            <td>
                                                                <div class="fw-bold text-dark">
                                                                    <?= htmlspecialchars($r['medicamento_nombre']) ?>
                                                                    <span class="text-muted fw-normal"
                                                                        style="font-size: 0.76rem;">(<?= htmlspecialchars($r['medicamento_concentracion']) ?>)</span>
                                                                </div>
                                                                <div class="d-flex align-items-center gap-1.5 mt-0.5">
                                                                    <span class="badge bg-light text-secondary border py-0.5 px-1.5"
                                                                        style="font-size: 0.70rem;">
                                                                        <?= htmlspecialchars($r['forma_nombre'] ?? 'Forma general') ?>
                                                                    </span>
                                                                    <span
                                                                        class="badge bg-info-subtle text-info-emphasis border border-info-subtle font-monospace py-0.5 px-1.5"
                                                                        style="font-size: 0.72rem;">
                                                                        <i
                                                                            class="bi bi-capsule me-1"></i><?= (int) $r['dosis_mensual'] ?>
                                                                        und/mes
                                                                    </span>
                                                                </div>
                                                            </td>
                                                            <td>
                                                                <div class="d-flex flex-column gap-0.5">
                                                                    <div style="font-size: 0.74rem;">
                                                                        <span class="text-muted">Emi:</span> <span
                                                                            class="font-monospace fw-semibold text-dark"><?= $emisionFmt ?></span>
                                                                    </div>
                                                                    <div style="font-size: 0.74rem;">
                                                                        <span class="text-muted">Ven:</span> <span
                                                                            class="font-monospace fw-semibold <?= $vencido ? 'text-danger' : 'text-dark' ?>"><?= $vencimientoFmt ?></span>
                                                                    </div>
                                                                    <div>
                                                                        <?php if ($vencido): ?>
                                                                            <span
                                                                                class="badge bg-danger-subtle text-danger border border-danger-subtle py-0.5 px-1"
                                                                                style="font-size: 0.67rem;">
                                                                                <i class="bi bi-clock-history me-0.5"></i>Vencido
                                                                            </span>
                                                                        <?php else: ?>
                                                                            <span
                                                                                class="badge bg-success-subtle text-success border border-success-subtle py-0.5 px-1"
                                                                                style="font-size: 0.67rem;">
                                                                                <i class="bi bi-check2-circle me-0.5"></i>Vigente
                                                                            </span>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td>
                                                                <div class="small fw-semibold text-dark" style="font-size: 0.78rem;">
                                                                    <i
                                                                        class="bi bi-person-badge me-1 text-primary"></i><?= htmlspecialchars($r['medico_prescriptor'] ?? 'No especificado') ?>
                                                                </div>
                                                            </td>
                                                            <td class="text-center">
                                                                <?php if ($activo): ?>
                                                                    <span
                                                                        class="badge bg-success-subtle text-success border border-success-subtle"
                                                                        style="font-size: 0.70rem;">
                                                                        Activo
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span
                                                                        class="badge bg-secondary-subtle text-secondary border border-secondary-subtle"
                                                                        style="font-size: 0.70rem;">
                                                                        Inactivo
                                                                    </span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="text-end">
                                                                <div class="d-inline-flex align-items-center gap-1">
                                                                    <?php if (tienePermiso('RECIPES_GESTIONAR')): ?>
                                                                        <a href="index.php?tab=recipes&editar_id=<?= (int) $r['id'] ?>"
                                                                            class="btn btn-outline-secondary btn-sm py-0.5 px-1.5"
                                                                            title="Editar Récipe" style="font-size: 0.75rem;">
                                                                            <i class="bi bi-pencil-square"></i>
                                                                        </a>
                                                                        <form action="procesar.php" method="POST" class="d-inline"
                                                                            onsubmit="return confirm('¿Confirma que desea <?= $activo ? 'desactivar' : 'reactivar' ?> el récipe #<?= (int) $r['id'] ?>?');">
                                                                            <input type="hidden" name="csrf_token"
                                                                                value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                                            <input type="hidden" name="accion" value="toggle_estado_recipe">
                                                                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                                                            <button type="submit"
                                                                                class="btn btn-sm py-0.5 px-1.5 <?= $activo ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                                                                style="font-size: 0.75rem;"
                                                                                title="<?= $activo ? 'Desactivar récipe' : 'Reactivar récipe' ?>">
                                                                                <i
                                                                                    class="bi <?= $activo ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                                                                            </button>
                                                                        </form>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <!-- Paginador Institucional -->
                                    <?php if ($totalPaginas > 1): ?>
                                        <div
                                            class="card-footer bg-light py-2 px-3 border-top d-flex justify-content-between align-items-center">
                                            <span class="small text-muted" style="font-size: 0.75rem;">
                                                Página <?= $paginaActual ?> de <?= $totalPaginas ?>
                                                (<?= number_format($totalRecipesFiltrados) ?> registros)
                                            </span>
                                            <nav>
                                                <ul class="pagination pagination-sm mb-0">
                                                    <?php
                                                    $qs = $_GET;
                                                    unset($qs['p_recipe']);
                                                    $baseQs = http_build_query($qs);
                                                    $urlPag = 'index.php?' . ($baseQs ? $baseQs . '&' : '') . 'p_recipe=';
                                                    ?>
                                                    <li class="page-item <?= ($paginaActual <= 1) ? 'disabled' : '' ?>">
                                                        <a class="page-link" href="<?= $urlPag . ($paginaActual - 1) ?>">«</a>
                                                    </li>
                                                    <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                                                        <li class="page-item <?= ($paginaActual === $i) ? 'active' : '' ?>">
                                                            <a class="page-link" href="<?= $urlPag . $i ?>"><?= $i ?></a>
                                                        </li>
                                                    <?php endfor; ?>
                                                    <li class="page-item <?= ($paginaActual >= $totalPaginas) ? 'disabled' : '' ?>">
                                                        <a class="page-link" href="<?= $urlPag . ($paginaActual + 1) ?>">»</a>
                                                    </li>
                                                </ul>
                                            </nav>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const recEmision = document.getElementById('recipe_fecha_emision');
        const recVenc = document.getElementById('recipe_fecha_vencimiento');
        if (recEmision && recVenc) {
            recEmision.addEventListener('change', function () {
                recVenc.min = this.value;
                if (recVenc.value && recVenc.value < this.value) {
                    recVenc.value = this.value;
                }
            });
        }
        <?php if (!empty($recipeEdicion)): ?>
            const mRec = document.getElementById('modalRecipe');
            if (mRec) new bootstrap.Modal(mRec).show();
        <?php endif; ?>
    });
</script>
