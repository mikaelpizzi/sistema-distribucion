<?php
// src/views/tabs/tab_pacientes.php
// SICM | Módulo Autosuficiente: Padrón de Pacientes
declare(strict_types=1);

$moduloNombre = 'Padrón de Pacientes';
$seccionNombre = 'Gestión Clínica';

// 1. Catálogos Normalizados de Tipos de Documento y Vías de Contacto (Cero Hardcoding)
$catalogoTiposDoc = $pdo->query("SELECT * FROM tipos_documento ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$catalogoTiposContacto = $pdo->query("SELECT * FROM tipos_contacto ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// 2. Métricas del Padrón Municipal (KPIs Institucionales)
$kpiTotalPacientes = (int) ($pdo->query("SELECT COUNT(*) FROM pacientes")->fetchColumn() ?: 0);
$kpiConContactos = (int) ($pdo->query("SELECT COUNT(DISTINCT paciente_id) FROM paciente_contactos")->fetchColumn() ?: 0);
$kpiNuevosMes = (int) ($pdo->query("SELECT COUNT(*) FROM pacientes WHERE DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')")->fetchColumn() ?: 0);

// 3. Parámetros de Búsqueda y Filtros
$busquedaPaciente = trim($_GET['q_paciente'] ?? '');
$filtroTipoDoc = trim($_GET['tipo_doc'] ?? 'todos');

$condPacientes = [];
$paramsPacientes = [];

if ($busquedaPaciente !== '') {
    $condPacientes[] = "(p.cedula LIKE :q OR p.nombre_completo LIKE :q OR EXISTS (SELECT 1 FROM paciente_contactos pc_sub WHERE pc_sub.paciente_id = p.id AND pc_sub.valor LIKE :q))";
    $paramsPacientes[':q'] = '%' . $busquedaPaciente . '%';
}

if ($filtroTipoDoc !== 'todos' && $filtroTipoDoc !== '') {
    if (ctype_digit($filtroTipoDoc)) {
        $condPacientes[] = "p.tipo_documento_id = :tipo_doc_id";
        $paramsPacientes[':tipo_doc_id'] = (int) $filtroTipoDoc;
    } else {
        $condPacientes[] = "td.codigo = :tipo_doc_cod";
        $paramsPacientes[':tipo_doc_cod'] = strtoupper($filtroTipoDoc);
    }
}

$wherePacientesSql = !empty($condPacientes) ? 'WHERE ' . implode(' AND ', $condPacientes) : '';

// 4. Paginación de Pacientes
$stmtCountPac = $pdo->prepare("SELECT COUNT(*) FROM pacientes p LEFT JOIN tipos_documento td ON p.tipo_documento_id = td.id {$wherePacientesSql}");
$stmtCountPac->execute($paramsPacientes);
$totalRegistrosPacientes = (int) $stmtCountPac->fetchColumn();
$totalPacientesFiltrados = $totalRegistrosPacientes;

$limitePorPagina = 20;
$paginaActual = max(1, (int) ($_GET['p_paciente'] ?? 1));
$totalPaginas = max(1, (int) ceil($totalRegistrosPacientes / $limitePorPagina));
if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
}
$offsetPacientes = ($paginaActual - 1) * $limitePorPagina;

// 5. Consulta de Pacientes
$sqlPacientes = "
    SELECT p.id, p.tipo_documento_id, td.codigo AS tipo_doc_codigo, td.nombre AS tipo_doc_nombre,
           p.cedula, p.nombre_completo, p.fecha_nacimiento, p.direccion_residencia, p.created_at,
           (SELECT COUNT(*) FROM tratamientos_autorizados ta WHERE ta.paciente_id = p.id) AS total_recipes
    FROM pacientes p
    LEFT JOIN tipos_documento td ON p.tipo_documento_id = td.id
    {$wherePacientesSql}
    ORDER BY p.id DESC
    LIMIT :limite OFFSET :offset
";
$stmtPac = $pdo->prepare($sqlPacientes);
foreach ($paramsPacientes as $k => $v) {
    $stmtPac->bindValue($k, $v);
}
$stmtPac->bindValue(':limite', $limitePorPagina, PDO::PARAM_INT);
$stmtPac->bindValue(':offset', $offsetPacientes, PDO::PARAM_INT);
$stmtPac->execute();
$pacientes = $stmtPac->fetchAll(PDO::FETCH_ASSOC);

// 6. Carga de vías de contacto normalizadas (1FN)
$pacienteIds = array_column($pacientes, 'id');
$contactosPorPaciente = [];
if (!empty($pacienteIds)) {
    $inClause = implode(',', array_map('intval', $pacienteIds));
    $sqlContactos = "
        SELECT pc.*, tc.codigo AS tipo_contacto_codigo, tc.nombre AS tipo_nombre,
               pc.nombre_contacto_emergencia AS titular
        FROM paciente_contactos pc
        LEFT JOIN tipos_contacto tc ON pc.tipo_contacto_id = tc.id
        WHERE pc.paciente_id IN ({$inClause})
        ORDER BY pc.es_principal DESC, pc.id ASC
    ";
    $stmtC = $pdo->query($sqlContactos);
    while ($row = $stmtC->fetch(PDO::FETCH_ASSOC)) {
        $contactosPorPaciente[$row['paciente_id']][] = $row;
    }
}

// 7. Carga de paciente para edición si aplica
$pacienteEdicion = null;
$contactosEdicion = [];
if (!empty($_GET['editar_id']) || !empty($_GET['editar'])) {
    $edId = (int) (!empty($_GET['editar_id']) ? $_GET['editar_id'] : $_GET['editar']);
    $stmtEd = $pdo->prepare("SELECT * FROM pacientes WHERE id = :id");
    $stmtEd->execute([':id' => $edId]);
    $pacienteEdicion = $stmtEd->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($pacienteEdicion) {
        $stmtCEd = $pdo->prepare("SELECT * FROM paciente_contactos WHERE paciente_id = :id ORDER BY es_principal DESC, id ASC");
        $stmtCEd->execute([':id' => $edId]);
        $contactosEdicion = $stmtCEd->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!-- Cuadro de Mando del Padrón (3 Tarjetas KPIs Institucionales) -->
                    <div class="row g-2 mb-3">
                        <div class="col-sm-6 col-md-4">
                            <div class="card bg-white border shadow-sm p-3 d-flex flex-row align-items-center gap-3">
                                <div class="bg-primary-subtle text-primary p-2.5 rounded-3 d-flex align-items-center justify-content-center"
                                    style="width: 48px; height: 48px;">
                                    <i class="bi bi-people fs-4"></i>
                                </div>
                                <div>
                                    <span class="text-muted small d-block fw-semibold text-uppercase"
                                        style="font-size: 0.72rem; letter-spacing: 0.04em;">Total Pacientes</span>
                                    <div class="fs-4 fw-bold text-dark font-monospace">
                                        <?= number_format($kpiTotalPacientes) ?></div>
                                    <span class="text-muted small" style="font-size: 0.70rem;">Padrón municipal
                                        registrado</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <div class="card bg-white border shadow-sm p-3 d-flex flex-row align-items-center gap-3">
                                <div class="bg-success-subtle text-success p-2.5 rounded-3 d-flex align-items-center justify-content-center"
                                    style="width: 48px; height: 48px;">
                                    <i class="bi bi-telephone-forward fs-4 text-success"></i>
                                </div>
                                <div>
                                    <span class="text-muted small d-block fw-semibold text-uppercase"
                                        style="font-size: 0.72rem; letter-spacing: 0.04em;">Con Vías de Contacto</span>
                                    <div class="fs-4 fw-bold text-dark font-monospace">
                                        <?= number_format($kpiConContactos) ?></div>
                                    <span class="text-muted small" style="font-size: 0.70rem;">Con canales registrados</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="card bg-white border shadow-sm p-3 d-flex flex-row align-items-center gap-3">
                                <div class="bg-info-subtle text-info-emphasis p-2.5 rounded-3 d-flex align-items-center justify-content-center"
                                    style="width: 48px; height: 48px;">
                                    <i class="bi bi-person-plus fs-4 text-info"></i>
                                </div>
                                <div>
                                    <span class="text-muted small d-block fw-semibold text-uppercase"
                                        style="font-size: 0.72rem; letter-spacing: 0.04em;">Registrados este Mes</span>
                                    <div class="fs-4 fw-bold text-dark font-monospace"><?= number_format($kpiNuevosMes) ?>
                                    </div>
                                    <span class="text-muted small" style="font-size: 0.70rem;">Altas asistenciales
                                        <?= date('m/Y') ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Modal de Registro / Edición de Paciente -->
                    <div class="modal fade" id="modalPaciente" tabindex="-1" aria-labelledby="modalPacienteLabel"
                        aria-hidden="true">
                        <div class="modal-dialog modal-lg modal-dialog-centered">
                            <div class="modal-content border-0 shadow">
                                <form action="procesar.php" method="POST" id="form-paciente" novalidate>
                                    <div class="modal-header bg-light py-2 px-3 border-bottom">
                                        <h6 class="modal-title fw-bold" id="modalPacienteLabel">
                                            <i
                                                class="bi <?= $pacienteEdicion ? 'bi-pencil-square text-warning' : 'bi-person-plus-fill text-primary' ?> me-1"></i>
                                            <?= $pacienteEdicion ? 'Editar Paciente #' . (int) $pacienteEdicion['id'] : 'Registrar Nuevo Paciente' ?>
                                        </h6>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"
                                            aria-label="Cerrar"></button>
                                    </div>
                                    <div class="modal-body p-3">
                                        <input type="hidden" name="csrf_token"
                                            value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="accion" value="guardar_paciente">
                                        <input type="hidden" name="id"
                                            value="<?= $pacienteEdicion ? (int) $pacienteEdicion['id'] : '' ?>">

                                        <div class="row g-2 mb-2">
                                            <div class="col-4">
                                                <label for="paciente_tipo_doc"
                                                    class="form-label small fw-semibold mb-1">Tipo</label>
                                                <select class="form-select form-select-sm" id="paciente_tipo_doc"
                                                    name="tipo_documento_id" required>
                                                    <?php foreach ($catalogoTiposDoc as $td): ?>
                                                        <?php $sel = ($pacienteEdicion && (int) $pacienteEdicion['tipo_documento_id'] === (int) $td['id']) ? 'selected' : (!$pacienteEdicion && $td['codigo'] === 'V' ? 'selected' : ''); ?>
                                                        <option value="<?= (int) $td['id'] ?>" <?= $sel ?>>
                                                            <?= htmlspecialchars($td['codigo']) ?> -
                                                            <?= htmlspecialchars($td['nombre']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-8">
                                                <label for="paciente_cedula" class="form-label small fw-semibold mb-1">
                                                    Cédula / Documento <span class="text-danger">*</span>
                                                </label>
                                                <input type="text" class="form-control form-control-sm font-monospace"
                                                    id="paciente_cedula" name="cedula"
                                                    placeholder="Solo dígitos (ej. 14285714)" pattern="^[0-9]{5,12}$"
                                                    maxlength="12" required
                                                    value="<?= $pacienteEdicion ? htmlspecialchars($pacienteEdicion['cedula']) : '' ?>"
                                                    title="Cédula numérica entre 5 y 12 dígitos">
                                            </div>
                                        </div>

                                        <div class="mb-2">
                                            <label for="paciente_nombre" class="form-label small fw-semibold mb-1">
                                                Nombre y Apellido Completo <span class="text-danger">*</span>
                                            </label>
                                            <input type="text" class="form-control form-control-sm" id="paciente_nombre"
                                                name="nombre_completo" placeholder="Ej: Carmen Elena Rivas Parra" required
                                                maxlength="120"
                                                value="<?= $pacienteEdicion ? htmlspecialchars($pacienteEdicion['nombre_completo']) : '' ?>">
                                        </div>

                                        <div class="mb-2">
                                            <label for="paciente_fecha_nac" class="form-label small fw-semibold mb-1">Fecha
                                                de Nacimiento</label>
                                            <input type="date" class="form-control form-control-sm" id="paciente_fecha_nac"
                                                name="fecha_nacimiento" max="<?= date('Y-m-d') ?>"
                                                value="<?= $pacienteEdicion ? htmlspecialchars($pacienteEdicion['fecha_nacimiento'] ?? '') : '' ?>">
                                        </div>

                                        <div class="mb-3">
                                            <label for="paciente_direccion"
                                                class="form-label small fw-semibold mb-1">Dirección de Residencia</label>
                                            <textarea class="form-control form-control-sm" id="paciente_direccion"
                                                name="direccion_residencia" rows="2"
                                                placeholder="Sector, calle, casa o punto de referencia"
                                                maxlength="255"><?= $pacienteEdicion ? htmlspecialchars($pacienteEdicion['direccion_residencia'] ?? '') : '' ?></textarea>
                                        </div>

                                        <!-- Vías de Contacto Dinámicas -->
                                        <div class="p-2.5 mb-2 bg-light rounded border">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <span class="fw-semibold small text-dark">Vías de Contacto</span>
                                                <span class="text-muted small" style="font-size: 0.72rem;">Teléfonos,
                                                    mensajería y contactos de emergencia</span>
                                            </div>

                                            <!-- Encabezado de columnas de contacto -->
                                            <div class="row g-1 mb-1 text-muted text-uppercase"
                                                style="font-size: 0.65rem; font-weight: 600; letter-spacing: 0.3px;">
                                                <div class="col-4">Tipo de Vía</div>
                                                <div class="col-4">Número / Correo</div>
                                                <div class="col">Detalle / Titular</div>
                                                <div class="col-auto" style="width: 32px;"></div>
                                            </div>

                                            <div id="contenedor-contactos-paciente" class="d-flex flex-column gap-1">
                                                <?php if (!empty($contactosEdicion)): ?>
                                                    <?php foreach ($contactosEdicion as $idx => $contacto): ?>
                                                        <div class="row g-1 align-items-center fila-contacto mb-1">
                                                            <div class="col-4">
                                                                <select name="contactos_tipo[]" class="form-select form-select-sm"
                                                                    required style="font-size: 0.78rem;">
                                                                    <?php foreach ($catalogoTiposContacto as $tc): ?>
                                                                        <option value="<?= (int) $tc['id'] ?>" <?= ((int) $contacto['tipo_contacto_id'] === (int) $tc['id']) ? 'selected' : '' ?>>
                                                                            <?= htmlspecialchars($tc['nombre']) ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <div class="col-4">
                                                                <input type="text" name="contactos_valor[]"
                                                                    class="form-control form-control-sm font-monospace"
                                                                    value="<?= htmlspecialchars($contacto['valor'] ?? '') ?>"
                                                                    placeholder="Ej: 0412-1234567" required maxlength="100"
                                                                    style="font-size: 0.8rem;">
                                                            </div>
                                                            <div class="col">
                                                                <input type="text" name="contactos_detalle[]"
                                                                    class="form-control form-control-sm"
                                                                    value="<?= htmlspecialchars($contacto['nombre_contacto_emergencia'] ?? ($contacto['titular'] ?? '')) ?>"
                                                                    placeholder="Ej: Personal, Hija" maxlength="100"
                                                                    style="font-size: 0.78rem;">
                                                            </div>
                                                            <div class="col-auto text-end" style="width: 32px;">
                                                                <button type="button"
                                                                    class="btn btn-sm btn-outline-danger btn-quitar-fila p-0 d-flex align-items-center justify-content-center"
                                                                    title="Quitar vía" style="width: 28px; height: 28px;">
                                                                    <i class="bi bi-x-lg" style="font-size: 0.72rem;"></i>
                                                                </button>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <!-- Fila inicial por defecto (Teléfono personal) -->
                                                    <div class="row g-1 align-items-center fila-contacto mb-1">
                                                        <div class="col-4">
                                                            <select name="contactos_tipo[]" class="form-select form-select-sm"
                                                                required style="font-size: 0.78rem;">
                                                                <?php foreach ($catalogoTiposContacto as $tc): ?>
                                                                    <option value="<?= (int) $tc['id'] ?>"
                                                                        <?= ($tc['codigo'] === 'TEL_MOVIL' || strpos($tc['nombre'], 'Celular') !== false) ? 'selected' : '' ?>>
                                                                        <?= htmlspecialchars($tc['nombre']) ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="col-4">
                                                            <input type="text" name="contactos_valor[]"
                                                                class="form-control form-control-sm font-monospace"
                                                                placeholder="Ej: 0412-1234567" required maxlength="100"
                                                                style="font-size: 0.8rem;">
                                                        </div>
                                                        <div class="col">
                                                            <input type="text" name="contactos_detalle[]"
                                                                class="form-control form-control-sm" value="Personal"
                                                                placeholder="Ej: Personal, Familiar" maxlength="100"
                                                                style="font-size: 0.78rem;">
                                                        </div>
                                                        <div class="col-auto text-end" style="width: 32px;">
                                                            <button type="button"
                                                                class="btn btn-sm btn-outline-danger btn-quitar-fila p-0 d-flex align-items-center justify-content-center"
                                                                title="Quitar vía" style="width: 28px; height: 28px;">
                                                                <i class="bi bi-x-lg" style="font-size: 0.72rem;"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <div class="mt-2">
                                                <button type="button" id="btn-agregar-via-contacto"
                                                    class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1.5 py-1 px-2.5"
                                                    style="font-size: 0.76rem;">
                                                    <i class="bi bi-plus-circle"></i> Agregar otra vía de contacto
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer bg-light py-2 px-3 border-top d-flex justify-content-between">
                                        <?php if ($pacienteEdicion): ?>
                                            <a href="index.php?tab=pacientes" class="btn btn-outline-secondary btn-sm">Cancelar
                                                edición</a>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                                data-bs-dismiss="modal">Cerrar</button>
                                        <?php endif; ?>
                                        <button type="submit" class="btn btn-primary btn-sm px-3 fw-semibold">
                                            <i
                                                class="bi <?= $pacienteEdicion ? 'bi-check-circle-fill' : 'bi-person-plus-fill' ?> me-1"></i>
                                            <?= $pacienteEdicion ? 'Actualizar Expediente' : 'Registrar Paciente' ?>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <!-- Columna: Búsqueda y Padrón de Pacientes (100% de ancho) -->
                        <div class="col-12">
                            <!-- Barra de Búsqueda Rápida y Filtros -->
                            <div class="card bg-white border shadow-sm p-3 mb-3">
                                <form method="GET" action="index.php" class="row g-2 align-items-center">
                                    <input type="hidden" name="tab" value="pacientes">
                                    <div class="col-md-5">
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text bg-white text-muted"><i
                                                    class="bi bi-search"></i></span>
                                            <input type="search" name="q_paciente" class="form-control"
                                                placeholder="Buscar por cédula, nombre o teléfono..."
                                                value="<?= htmlspecialchars($busquedaPaciente) ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <select name="tipo_doc" class="form-select form-select-sm">
                                            <option value="todos">Todos los tipos de documento</option>
                                            <?php foreach ($catalogoTiposDoc as $td): ?>
                                                <option value="<?= (int) $td['id'] ?>"
                                                    <?= ((string) $filtroTipoDoc === (string) $td['id'] || $filtroTipoDoc === $td['codigo']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($td['codigo']) ?> -
                                                    <?= htmlspecialchars($td['nombre']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3 d-flex gap-1">
                                        <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                                            <i class="bi bi-funnel"></i> Filtrar
                                        </button>
                                        <?php if ($busquedaPaciente !== '' || ($filtroTipoDoc !== 'todos' && $filtroTipoDoc !== '')): ?>
                                            <a href="index.php?tab=pacientes" class="btn btn-outline-secondary btn-sm"
                                                title="Limpiar Filtros">
                                                <i class="bi bi-x-circle"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>

                            <!-- Tabla Institucional de Pacientes -->
                            <div class="card-sicm shadow-sm">
                                <div
                                    class="card-header-sicm d-flex flex-wrap justify-content-between align-items-center gap-2">
                                    <span class="fw-bold">
                                        <i class="bi bi-person-lines-fill me-1"></i> Padrón de Pacientes Registrados
                                    </span>
                                    <div class="d-flex align-items-center gap-2">
                                        <span
                                            class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace"
                                            style="font-size: 0.74rem;">
                                            <?= number_format($totalPacientesFiltrados) ?> pacientes encontrados
                                        </span>
                                        <?php if (tienePermiso('PACIENTES_GESTIONAR')): ?>
                                            <button type="button"
                                                class="btn btn-primary btn-sm px-3 d-inline-flex align-items-center gap-1.5 shadow-sm"
                                                data-bs-toggle="modal" data-bs-target="#modalPaciente">
                                                <i class="bi bi-person-plus-fill me-1"></i>Registrar Paciente
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
                                                <th style="width: 130px;">Documento</th>
                                                <th>Nombre y Apellido</th>
                                                <th>Vías de Contacto</th>
                                                <th>Dirección</th>
                                                <th style="width: 120px;" class="text-center">Récipes</th>
                                                <th style="width: 130px;" class="text-end">Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($pacientes)): ?>
                                                <tr>
                                                    <td colspan="7" class="text-center py-4 text-muted">
                                                        <i class="bi bi-inbox fs-3 d-block text-secondary mb-1"></i>
                                                        <span>No se encontraron pacientes que coincidan con los criterios de
                                                            búsqueda.</span>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($pacientes as $p): ?>
                                                    <?php
                                                    $edadStr = 'Fecha nac. no registrada';
                                                    if (!empty($p['fecha_nacimiento'])) {
                                                        try {
                                                            $fn = new DateTime($p['fecha_nacimiento']);
                                                            $hoy = new DateTime('today');
                                                            if ($fn <= $hoy) {
                                                                $edad = $hoy->diff($fn)->y;
                                                                $edadStr = "{$edad} años • Nac. " . htmlspecialchars($p['fecha_nacimiento']);
                                                            }
                                                        } catch (Exception $e) {
                                                            $edadStr = htmlspecialchars($p['fecha_nacimiento']);
                                                        }
                                                    }
                                                    $conts = $contactosPorPaciente[$p['id']] ?? [];
                                                    ?>
                                                    <tr>
                                                        <td class="text-muted font-monospace"><?= (int) $p['id'] ?></td>
                                                        <td>
                                                            <span
                                                                class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace py-1 px-1.5"
                                                                style="font-size: 0.76rem;">
                                                                <i
                                                                    class="bi bi-person-vcard me-1"></i><?= htmlspecialchars($p['tipo_doc_codigo']) ?>-<?= htmlspecialchars($p['cedula']) ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div class="fw-bold text-dark">
                                                                <?= htmlspecialchars($p['nombre_completo']) ?></div>
                                                            <div class="text-muted" style="font-size: 0.73rem;">
                                                                <i class="bi bi-calendar3 me-1"></i><?= $edadStr ?>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <?php if (empty($conts)): ?>
                                                                <span class="text-muted fst-italic" style="font-size: 0.74rem;">Sin vías
                                                                    registradas</span>
                                                            <?php else: ?>
                                                                <div class="d-flex flex-column gap-1">
                                                                    <?php foreach ($conts as $c): ?>
                                                                        <?php
                                                                        $isEmg = ($c['tipo_contacto_codigo'] === 'CONTACTO_EMERGENCIA' || ((int) $c['es_principal'] === 0 && !empty($c['nombre_contacto_emergencia']) && $c['nombre_contacto_emergencia'] !== 'Paciente Directo'));
                                                                        $isWp = ($c['tipo_contacto_codigo'] === 'WHATSAPP');
                                                                        $badgeCls = (int) $c['es_principal'] === 1 ? 'bg-primary-subtle text-primary border-primary-subtle' : ($isEmg ? 'bg-danger-subtle text-danger border-danger-subtle' : 'bg-light text-secondary border');
                                                                        $icon = $isEmg ? 'bi-person-heart text-danger' : ($isWp ? 'bi-whatsapp text-success' : 'bi-telephone text-primary');
                                                                        ?>
                                                                        <div class="badge <?= $badgeCls ?> text-start fw-normal d-inline-flex align-items-center gap-1 py-0.5 px-1.5 text-wrap"
                                                                            style="font-size: 0.72rem; max-width: 210px;">
                                                                            <i class="bi <?= $icon ?>"></i>
                                                                            <span
                                                                                class="font-monospace fw-semibold"><?= htmlspecialchars($c['valor']) ?></span>
                                                                            <?php if (!empty($c['nombre_contacto_emergencia']) && $c['nombre_contacto_emergencia'] !== 'Paciente Directo'): ?>
                                                                                <span class="text-muted ms-0.5"
                                                                                    style="font-size: 0.68rem;">(<?= htmlspecialchars($c['nombre_contacto_emergencia']) ?>)</span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <span class="text-secondary"
                                                                style="font-size: 0.75rem; line-height: 1.25; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;"
                                                                title="<?= htmlspecialchars($p['direccion_residencia'] ?? '') ?>">
                                                                <?= htmlspecialchars($p['direccion_residencia'] ?: 'No especificada') ?>
                                                            </span>
                                                        </td>
                                                        <td class="text-center">
                                                            <div class="d-flex flex-column align-items-center gap-1">
                                                                <span
                                                                    class="badge bg-secondary-subtle text-secondary border border-secondary-subtle"
                                                                    style="font-size: 0.70rem;">
                                                                    <i
                                                                        class="bi bi-file-earmark-medical me-0.5"></i><?= (int) ($p['total_recipes'] ?? 0) ?>
                                                                    Récipes
                                                                </span>
                                                                <?php if (tienePermiso('RECIPES_GESTIONAR')): ?>
                                                                    <a href="index.php?tab=recipes&paciente_id=<?= (int) $p['id'] ?>"
                                                                        class="btn btn-outline-primary btn-sm py-0 px-1.5 text-decoration-none"
                                                                        style="font-size: 0.72rem;"
                                                                        title="Prescribir o consultar récipe">
                                                                        <i class="bi bi-plus-circle me-1"></i>+ Récipe
                                                                    </a>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                        <td class="text-end">
                                                            <div class="d-flex justify-content-end gap-1">
                                                                <?php if (tienePermiso('PACIENTES_GESTIONAR')): ?>
                                                                    <a href="index.php?tab=pacientes&editar_id=<?= (int) $p['id'] ?><?= $busquedaPaciente !== '' ? '&q_paciente=' . urlencode($busquedaPaciente) : '' ?><?= $filtroTipoDoc !== 'todos' ? '&tipo_doc=' . urlencode($filtroTipoDoc) : '' ?><?= $paginaActual > 1 ? '&p_paciente=' . $paginaActual : '' ?>"
                                                                        class="btn btn-sm btn-outline-primary py-0 px-2"
                                                                        style="font-size: 0.74rem;" title="Editar Expediente">
                                                                        <i class="bi bi-pencil-square"></i> Editar
                                                                    </a>
                                                                <?php endif; ?>
                                                                <?php if (tienePermiso('AUDIT_VER')): ?>
                                                                    <a href="index.php?tab=auditoria&subtab=deltas&tabla=pacientes&registro_id=<?= (int) $p['id'] ?>"
                                                                        class="btn btn-sm btn-outline-info py-0 px-2 text-decoration-none"
                                                                        style="font-size: 0.74rem;" title="Auditoría Delta Forense">
                                                                        <i class="bi bi-clock-history"></i> Historial
                                                                    </a>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Paginación Institucional -->
                                <?php if ($totalPaginas > 1): ?>
                                    <?php $pagQuery = "&q_paciente=" . urlencode($busquedaPaciente) . "&tipo_doc=" . urlencode($filtroTipoDoc); ?>
                                    <div
                                        class="card-footer bg-white border-top d-flex justify-content-between align-items-center py-2 px-3">
                                        <span class="text-muted small" style="font-size: 0.74rem;">
                                            Página <?= $paginaActual ?> de <?= $totalPaginas ?>
                                            (<?= number_format($totalPacientesFiltrados) ?> registros)
                                        </span>
                                        <nav aria-label="Navegación del padrón">
                                            <ul class="pagination pagination-sm mb-0">
                                                <li class="page-item <?= ($paginaActual <= 1) ? 'disabled' : '' ?>">
                                                    <a class="page-link"
                                                        href="index.php?tab=pacientes<?= $pagQuery ?>&p_paciente=<?= $paginaActual - 1 ?>">
                                                        <i class="bi bi-chevron-left"></i>
                                                    </a>
                                                </li>
                                                <?php for ($pg = 1; $pg <= $totalPaginas; $pg++): ?>
                                                    <?php if ($pg === 1 || $pg === $totalPaginas || ($pg >= $paginaActual - 2 && $pg <= $paginaActual + 2)): ?>
                                                        <li class="page-item <?= ($pg === $paginaActual) ? 'active' : '' ?>">
                                                            <a class="page-link"
                                                                href="index.php?tab=pacientes<?= $pagQuery ?>&p_paciente=<?= $pg ?>"><?= $pg ?></a>
                                                        </li>
                                                    <?php elseif ($pg === $paginaActual - 3 || $pg === $paginaActual + 3): ?>
                                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                                    <?php endif; ?>
                                                <?php endfor; ?>
                                                <li class="page-item <?= ($paginaActual >= $totalPaginas) ? 'disabled' : '' ?>">
                                                    <a class="page-link"
                                                        href="index.php?tab=pacientes<?= $pagQuery ?>&p_paciente=<?= $paginaActual + 1 ?>">
                                                        <i class="bi bi-chevron-right"></i>
                                                    </a>
                                                </li>
                                            </ul>
                                        </nav>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

<!-- JavaScript para Vías de Contacto Dinámicas y Validación de Paciente -->
<script src="public/js/contactos_repeater.js"></script>
<?php if (!empty($pacienteEdicion)): ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const mPac = document.getElementById('modalPaciente');
            if (mPac) new bootstrap.Modal(mPac).show();
        });
    </script>
<?php endif; ?>
