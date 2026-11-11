<?php
// src/views/tabs/tab_medicamentos.php
// SICM | Módulo Autosuficiente: Catálogo de Fármacos y Vademécum
declare(strict_types=1);

$moduloNombre = 'Catálogo de Fármacos';
$seccionNombre = 'Vademécum y Red';

$subtabActiva = $_GET['subtab'] ?? 'vademecum';
if (!in_array($subtabActiva, ['vademecum', 'patologias', 'formas'], true)) {
    $subtabActiva = 'vademecum';
}
$subtabMedicamentos = $subtabActiva;

// 1. KPIs Rápidos Institucionales
$kpiMedicamentosActivos = (int) $pdo->query("SELECT COUNT(*) FROM medicamentos WHERE activo = 1")->fetchColumn();
$kpiCadenaFrio = (int) $pdo->query("SELECT COUNT(*) FROM medicamentos WHERE activo = 1 AND requiere_cadena_frio = 1")->fetchColumn();
$kpiPatologiasCie = (int) $pdo->query("SELECT COUNT(*) FROM patologias WHERE activo = 1")->fetchColumn();

// 2. Catálogo de Formas Farmacéuticas con conteo de medicamentos vinculados
$formasFarmaceuticas = $pdo->query("
    SELECT ff.id, ff.nombre, COUNT(m.id) AS total_medicamentos
    FROM formas_farmaceuticas ff
    LEFT JOIN medicamentos m ON m.forma_farmaceutica_id = ff.id
    GROUP BY ff.id, ff.nombre
    ORDER BY ff.nombre ASC
")->fetchAll(PDO::FETCH_ASSOC);

// 3. Vademécum de Medicamentos
$filtroEstadoMed = $_GET['estado_med'] ?? 'todos';
$busquedaMed = trim($_GET['q_med'] ?? '');
$condMed = [];
$paramsMed = [];

if ($filtroEstadoMed === 'activos') {
    $condMed[] = 'm.activo = 1';
} elseif ($filtroEstadoMed === 'descontinuados') {
    $condMed[] = 'm.activo = 0';
}

if ($busquedaMed !== '') {
    $condMed[] = '(m.nombre_generico LIKE :qm1 OR m.concentracion LIKE :qm2 OR ff.nombre LIKE :qm3)';
    $paramsMed[':qm1'] = '%' . $busquedaMed . '%';
    $paramsMed[':qm2'] = '%' . $busquedaMed . '%';
    $paramsMed[':qm3'] = '%' . $busquedaMed . '%';
}

$whereMed = !empty($condMed) ? 'WHERE ' . implode(' AND ', $condMed) : '';
$sqlMed = "SELECT m.*, ff.nombre AS forma_nombre
           FROM medicamentos m
           LEFT JOIN formas_farmaceuticas ff ON m.forma_farmaceutica_id = ff.id
           {$whereMed}
           ORDER BY m.nombre_generico ASC";
$stmtMed = $pdo->prepare($sqlMed);
$stmtMed->execute($paramsMed);
$medicamentos = $stmtMed->fetchAll(PDO::FETCH_ASSOC);

// 4. Catálogo CIE-10 de Patologías
$filtroEstadoPat = $_GET['estado_pat'] ?? 'todos';
$busquedaPat = trim($_GET['q_pat'] ?? '');
$condPat = [];
$paramsPat = [];

if ($filtroEstadoPat === 'activos') {
    $condPat[] = 'p.activo = 1';
} elseif ($filtroEstadoPat === 'inactivos') {
    $condPat[] = 'p.activo = 0';
}

if ($busquedaPat !== '') {
    $condPat[] = '(p.codigo_cie10 LIKE :qp1 OR p.nombre LIKE :qp2 OR p.descripcion LIKE :qp3)';
    $paramsPat[':qp1'] = '%' . $busquedaPat . '%';
    $paramsPat[':qp2'] = '%' . $busquedaPat . '%';
    $paramsPat[':qp3'] = '%' . $busquedaPat . '%';
}

$wherePat = !empty($condPat) ? 'WHERE ' . implode(' AND ', $condPat) : '';
$sqlPat = "SELECT p.* FROM patologias p {$wherePat} ORDER BY p.codigo_cie10 ASC";
$stmtPat = $pdo->prepare($sqlPat);
$stmtPat->execute($paramsPat);
$patologias = $stmtPat->fetchAll(PDO::FETCH_ASSOC);

// Edición si aplica
$medicamentoEdicion = null;
$editarMId = filter_input(INPUT_GET, 'editar_m', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_GET, 'editar_med', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT);
if ($editarMId) {
    $stmtMEd = $pdo->prepare("SELECT * FROM medicamentos WHERE id = :id");
    $stmtMEd->execute([':id' => $editarMId]);
    $medicamentoEdicion = $stmtMEd->fetch(PDO::FETCH_ASSOC);
}
?>
<!-- Cuadro de Mando de Vademécum (3 Tarjetas KPIs Institucionales) -->
<div class="row g-2 mb-3">
    <div class="col-sm-6 col-md-4">
        <div class="card bg-white border shadow-sm p-3 d-flex flex-row align-items-center gap-3">
            <div class="bg-primary-subtle text-primary p-2.5 rounded-3 d-flex align-items-center justify-content-center"
                style="width: 48px; height: 48px;">
                <i class="bi bi-capsule fs-4"></i>
            </div>
            <div>
                <span class="text-muted small d-block fw-semibold text-uppercase"
                    style="font-size: 0.72rem; letter-spacing: 0.04em;">Medicamentos Activos</span>
                <div class="fs-4 fw-bold text-dark font-monospace">
                    <?= number_format($kpiMedicamentosActivos) ?></div>
                <span class="text-muted small" style="font-size: 0.70rem;">Catálogo formal del vademécum</span>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-md-4">
        <div class="card bg-white border shadow-sm p-3 d-flex flex-row align-items-center gap-3">
            <div class="bg-info-subtle text-info-emphasis p-2.5 rounded-3 d-flex align-items-center justify-content-center"
                style="width: 48px; height: 48px;">
                <i class="bi bi-snow2 fs-4 text-info"></i>
            </div>
            <div>
                <span class="text-muted small d-block fw-semibold text-uppercase"
                    style="font-size: 0.72rem; letter-spacing: 0.04em;">Cadena de Frío (2°C - 8°C)</span>
                <div class="fs-4 fw-bold text-dark font-monospace"><?= number_format($kpiCadenaFrio) ?></div>
                <span class="text-muted small" style="font-size: 0.70rem;">Refrigeración clínica requerida</span>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card bg-white border shadow-sm p-3 d-flex flex-row align-items-center gap-3">
            <div class="bg-success-subtle text-success p-2.5 rounded-3 d-flex align-items-center justify-content-center"
                style="width: 48px; height: 48px;">
                <i class="bi bi-clipboard2-pulse fs-4 text-success"></i>
            </div>
            <div>
                <span class="text-muted small d-block fw-semibold text-uppercase"
                    style="font-size: 0.72rem; letter-spacing: 0.04em;">Patologías CIE-10</span>
                <div class="fs-4 fw-bold text-dark font-monospace"><?= number_format($kpiPatologiasCie) ?></div>
                <span class="text-muted small" style="font-size: 0.70rem;">Diagnósticos clínicos registrados</span>
            </div>
        </div>
    </div>
</div>

<!-- Navegación de Subpestañas del Módulo -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div class="nav nav-pills gap-1">
        <a href="index.php?tab=medicamentos&subtab=vademecum"
            class="nav-link btn-sm py-1.5 px-3 <?= $subtabActiva === 'vademecum' ? 'active bg-primary fw-bold' : 'bg-white border text-secondary' ?>"
            style="font-size: 0.82rem;">
            <i class="bi bi-prescription2 me-1"></i> Vademécum de Medicamentos
        </a>
        <a href="index.php?tab=medicamentos&subtab=patologias"
            class="nav-link btn-sm py-1.5 px-3 <?= $subtabActiva === 'patologias' ? 'active bg-primary fw-bold' : 'bg-white border text-secondary' ?>"
            style="font-size: 0.82rem;">
            <i class="bi bi-heart-pulse me-1"></i> Catálogo de Patologías (CIE-10)
        </a>
        <a href="index.php?tab=medicamentos&subtab=formas"
            class="nav-link btn-sm py-1.5 px-3 <?= $subtabActiva === 'formas' ? 'active bg-primary fw-bold' : 'bg-white border text-secondary' ?>"
            style="font-size: 0.82rem;">
            <i class="bi bi-boxes me-1"></i> Formas Farmacéuticas
        </a>
    </div>
</div>

<!-- Carga Dinámica de la Subpestaña Correspondiente -->
<?php
$subtabFile = __DIR__ . "/medicamentos/subtab_{$subtabActiva}.php";
if (file_exists($subtabFile)) {
    require $subtabFile;
} else {
    echo '<div class="alert alert-danger py-2 px-3 small">Subpestaña no encontrada.</div>';
}
?>
