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
                                    <span class="text-muted small" style="font-size: 0.70rem;">Catálogo formal del
                                        vademécum</span>
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
                                        style="font-size: 0.72rem; letter-spacing: 0.04em;">Cadena de Frío (2°C -
                                        8°C)</span>
                                    <div class="fs-4 fw-bold text-dark font-monospace"><?= number_format($kpiCadenaFrio) ?>
                                    </div>
                                    <span class="text-muted small" style="font-size: 0.70rem;">Refrigeración clínica
                                        requerida</span>
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
                                    <div class="fs-4 fw-bold text-dark font-monospace">
                                        <?= number_format($kpiPatologiasCie) ?></div>
                                    <span class="text-muted small" style="font-size: 0.70rem;">Diagnósticos clínicos
                                        registrados</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Navegación de Subpestañas del Módulo -->
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                        <div class="nav nav-pills gap-1">
                            <a href="index.php?tab=medicamentos&subtab=vademecum"
                                class="nav-link btn-sm py-1.5 px-3 <?= $subtabMedicamentos === 'vademecum' ? 'active bg-primary fw-bold' : 'bg-white border text-secondary' ?>"
                                style="font-size: 0.82rem;">
                                <i class="bi bi-prescription2 me-1"></i> Vademécum de Medicamentos
                            </a>
                            <a href="index.php?tab=medicamentos&subtab=patologias"
                                class="nav-link btn-sm py-1.5 px-3 <?= $subtabMedicamentos === 'patologias' ? 'active bg-primary fw-bold' : 'bg-white border text-secondary' ?>"
                                style="font-size: 0.82rem;">
                                <i class="bi bi-heart-pulse me-1"></i> Catálogo de Patologías (CIE-10)
                            </a>
                            <a href="index.php?tab=medicamentos&subtab=formas"
                                class="nav-link btn-sm py-1.5 px-3 <?= $subtabMedicamentos === 'formas' ? 'active bg-primary fw-bold' : 'bg-white border text-secondary' ?>"
                                style="font-size: 0.82rem;">
                                <i class="bi bi-boxes me-1"></i> Formas Farmacéuticas
                            </a>
                        </div>
                    </div>

                    <!-- SUBPESTAÑA 1: VADEMÉCUM DE MEDICAMENTOS -->
                    <?php if ($subtabMedicamentos === 'vademecum'): ?>
                        <!-- Modal de Registro / Edición de Fármaco -->
                        <div class="modal fade" id="modalMedicamento" tabindex="-1" aria-labelledby="modalMedicamentoLabel"
                            aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-centered">
                                <div class="modal-content border-0 shadow">
                                    <form method="POST" action="procesar.php" novalidate id="form-medicamento">
                                        <div class="modal-header bg-light py-2 px-3 border-bottom">
                                            <h6 class="modal-title fw-bold" id="modalMedicamentoLabel">
                                                <i
                                                    class="bi <?= $medicamentoEdicion ? 'bi-pencil-square text-warning' : 'bi-capsule text-primary' ?> me-1"></i>
                                                <?= $medicamentoEdicion ? 'Editar Especificaciones de Fármaco #' . (int) $medicamentoEdicion['id'] : 'Registrar Nuevo Fármaco' ?>
                                            </h6>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                                aria-label="Cerrar"></button>
                                        </div>
                                        <div class="modal-body p-3">
                                            <input type="hidden" name="csrf_token"
                                                value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="accion"
                                                value="<?= $medicamentoEdicion ? 'editar_medicamento' : 'crear_medicamento' ?>">
                                            <?php if ($medicamentoEdicion): ?>
                                                <input type="hidden" name="id" value="<?= (int) $medicamentoEdicion['id'] ?>">
                                            <?php endif; ?>

                                            <div class="row g-2 mb-2">
                                                <div class="col-md-7">
                                                    <label class="form-label-sicm mb-1">Nombre Genérico (Principio Activo) <span
                                                            class="text-danger">*</span></label>
                                                    <input type="text" name="nombre_generico"
                                                        class="form-control form-control-sm" placeholder="Ej: Losartán Potásico"
                                                        required minlength="2" maxlength="120"
                                                        value="<?= htmlspecialchars($medicamentoEdicion['nombre_generico'] ?? '') ?>">
                                                    <div class="form-text" style="font-size: 0.70rem;">Denominación Común
                                                        Internacional (DCI).</div>
                                                </div>
                                                <div class="col-md-5">
                                                    <label class="form-label-sicm mb-1">Concentración / Dosificación <span
                                                            class="text-danger">*</span></label>
                                                    <input type="text" name="concentracion" class="form-control form-control-sm"
                                                        placeholder="Ej: 50mg, 100UI/ml, 850mg" required minlength="1"
                                                        maxlength="50"
                                                        value="<?= htmlspecialchars($medicamentoEdicion['concentracion'] ?? '') ?>">
                                                    <div class="form-text" style="font-size: 0.70rem;">Fuerza activa o dosis
                                                        unitaria.</div>
                                                </div>
                                            </div>

                                            <div class="row g-2 mb-2">
                                                <div class="col-md-6">
                                                    <label class="form-label-sicm mb-1">Forma Farmacéutica <span
                                                            class="text-danger">*</span></label>
                                                    <select name="forma_farmaceutica_id" class="form-select form-select-sm"
                                                        required>
                                                        <option value="">-- Seleccionar Presentación --</option>
                                                        <?php foreach ($formasFarmaceuticas as $forma): ?>
                                                            <option value="<?= $forma['id'] ?>" <?= ($medicamentoEdicion && (int) $medicamentoEdicion['forma_farmaceutica_id'] === (int) $forma['id']) ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($forma['nombre']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label-sicm mb-1">Stock Mínimo Sugerido (Umbral de
                                                        Alerta)</label>
                                                    <input type="number" name="stock_minimo_sugerido"
                                                        class="form-control form-control-sm" min="1" max="99999" required
                                                        value="<?= (int) ($medicamentoEdicion['stock_minimo_sugerido'] ?? 20) ?>">
                                                    <div class="form-text" style="font-size: 0.70rem;">Dispara avisos
                                                        automáticos de reposición en ambulatorios.</div>
                                                </div>
                                            </div>

                                            <div class="p-2.5 bg-light border rounded">
                                                <div class="form-check form-switch mb-0">
                                                    <input class="form-check-input" type="checkbox" role="switch"
                                                        id="switchCadenaFrio" name="requiere_cadena_frio" value="1"
                                                        <?= ($medicamentoEdicion && (int) $medicamentoEdicion['requiere_cadena_frio'] === 1) ? 'checked' : '' ?>>
                                                    <label class="form-check-label small fw-semibold text-dark"
                                                        for="switchCadenaFrio">
                                                        <i class="bi bi-snow2 text-info me-1"></i> Requiere Cadena de Frío
                                                        Estricta
                                                    </label>
                                                </div>
                                                <div class="text-muted mt-1" style="font-size: 0.69rem; line-height: 1.3;">
                                                    Refrigeración clínica continua entre <strong>2°C y 8°C</strong>. Su
                                                    dispensación exige ambulatorios con equipo de frío certificado.
                                                </div>
                                            </div>
                                        </div>
                                        <div class="modal-footer bg-light py-2 px-3 border-top d-flex justify-content-between">
                                            <?php if ($medicamentoEdicion): ?>
                                                <a href="index.php?tab=medicamentos&subtab=vademecum"
                                                    class="btn btn-outline-secondary btn-sm">Cancelar edición</a>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-outline-secondary btn-sm"
                                                    data-bs-dismiss="modal">Cerrar</button>
                                            <?php endif; ?>
                                            <button type="submit" class="btn btn-primary btn-sm px-3 fw-semibold">
                                                <i
                                                    class="bi <?= $medicamentoEdicion ? 'bi-check2-circle' : 'bi-plus-lg' ?> me-1"></i>
                                                <?= $medicamentoEdicion ? 'Guardar Cambios' : 'Registrar Medicamento' ?>
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <!-- Tabla de Medicamentos (100% de ancho) -->
                            <div class="col-12">
                                <div class="card-sicm">
                                    <div
                                        class="card-header-sicm d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
                                        <div class="d-flex align-items-center gap-2">
                                            <h6 class="m-0 fw-bold">
                                                <i class="bi bi-list-check text-primary me-1"></i>
                                                Vademécum Municipal (<?= count($medicamentos) ?> fármacos)
                                            </h6>
                                            <?php if (tienePermiso('MED_CREAR')): ?>
                                                <button type="button"
                                                    class="btn btn-primary btn-sm px-3 d-inline-flex align-items-center gap-1 shadow-sm ms-2"
                                                    data-bs-toggle="modal" data-bs-target="#modalMedicamento">
                                                    <i class="bi bi-plus-lg me-1"></i>Registrar Fármaco
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                        <form method="GET" action="index.php"
                                            class="d-flex flex-wrap align-items-center gap-1 m-0">
                                            <input type="hidden" name="tab" value="medicamentos">
                                            <input type="hidden" name="subtab" value="vademecum">
                                            <select name="estado_med" class="form-select form-select-sm"
                                                style="width: auto; font-size: 0.78rem;" onchange="this.form.submit()">
                                                <option value="todos" <?= ($filtroEstadoMed === 'todos') ? 'selected' : '' ?>>Todos
                                                    los Estados</option>
                                                <option value="activos" <?= ($filtroEstadoMed === 'activos') ? 'selected' : '' ?>>
                                                    Solo Operativos</option>
                                                <option value="descontinuados" <?= ($filtroEstadoMed === 'descontinuados') ? 'selected' : '' ?>>Solo Descontinuados</option>
                                            </select>
                                            <div class="input-group input-group-sm" style="width: 190px;">
                                                <input type="text" name="q_med" class="form-control"
                                                    placeholder="Buscar principio..."
                                                    value="<?= htmlspecialchars($busquedaMed) ?>">
                                                <button class="btn btn-outline-secondary" type="submit"><i
                                                        class="bi bi-search"></i></button>
                                            </div>
                                            <?php if ($busquedaMed !== '' || $filtroEstadoMed !== 'todos'): ?>
                                                <a href="index.php?tab=medicamentos&subtab=vademecum"
                                                    class="btn btn-outline-danger btn-sm py-1 px-2" title="Limpiar Filtros">
                                                    <i class="bi bi-x-circle"></i>
                                                </a>
                                            <?php endif; ?>
                                        </form>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0 font-sans"
                                            style="font-size: 0.82rem;">
                                            <thead class="table-light">
                                                <tr>
                                                    <th style="width: 45px;">#ID</th>
                                                    <th>Nombre Genérico</th>
                                                    <th>Concentración</th>
                                                    <th>Forma Farmacéutica</th>
                                                    <th>Cadena de Frío</th>
                                                    <th class="text-center" style="width: 85px;">Stock Mín.</th>
                                                    <th class="text-center" style="width: 95px;">Estado</th>
                                                    <th class="text-end" style="width: 190px;">Acciones</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($medicamentos)): ?>
                                                    <tr>
                                                        <td colspan="8" class="text-center text-muted py-4">
                                                            <i class="bi bi-inbox fs-4 d-block mb-1"></i>
                                                            No se encontraron medicamentos en el vademécum con los filtros
                                                            indicados.
                                                        </td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($medicamentos as $med): ?>
                                                        <tr
                                                            class="<?= ((int) $med['activo'] === 0) ? 'table-secondary opacity-75' : '' ?>">
                                                            <td class="font-monospace text-muted">#<?= $med['id'] ?></td>
                                                            <td>
                                                                <span
                                                                    class="fw-bold text-dark"><?= htmlspecialchars($med['nombre_generico']) ?></span>
                                                            </td>
                                                            <td>
                                                                <span class="badge bg-light text-secondary border font-monospace"
                                                                    style="font-size: 0.72rem;">
                                                                    <?= htmlspecialchars($med['concentracion']) ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <span
                                                                    class="text-secondary"><?= htmlspecialchars($med['forma_nombre']) ?></span>
                                                            </td>
                                                            <td>
                                                                <?php if ((int) $med['requiere_cadena_frio'] === 1): ?>
                                                                    <span class="badge bg-info text-dark" style="font-size: 0.72rem;">
                                                                        <i class="bi bi-snow2 me-1"></i>❄️ Refrigerado (2-8°C)
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-light text-secondary border"
                                                                        style="font-size: 0.72rem;">
                                                                        Ambiente
                                                                    </span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="text-center">
                                                                <span class="badge bg-light text-dark border font-monospace">
                                                                    <?= number_format((int) $med['stock_minimo_sugerido']) ?>
                                                                </span>
                                                            </td>
                                                            <td class="text-center">
                                                                <?php if ((int) $med['activo'] === 1): ?>
                                                                    <span
                                                                        class="badge bg-success-subtle text-success border border-success-subtle"
                                                                        style="font-size: 0.72rem;">
                                                                        Operativo
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span
                                                                        class="badge bg-secondary-subtle text-secondary border border-secondary-subtle"
                                                                        style="font-size: 0.72rem;">
                                                                        Descontinuado
                                                                    </span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="text-end">
                                                                <div class="d-inline-flex gap-1">
                                                                    <?php if (tienePermiso('AUDIT_VER')): ?>
                                                                        <a href="index.php?tab=auditoria&subtab=deltas&tabla=medicamentos&registro_id=<?= $med['id'] ?>"
                                                                            class="btn btn-outline-secondary btn-sm py-0.5 px-2"
                                                                            style="font-size: 0.75rem;"
                                                                            title="Ver Auditoría Delta celda por celda">
                                                                            <i class="bi bi-clock-history"></i> Historial
                                                                        </a>
                                                                    <?php endif; ?>
                                                                    <?php if (tienePermiso('MED_EDITAR')): ?>
                                                                        <a href="index.php?tab=medicamentos&subtab=vademecum&editar_med=<?= $med['id'] ?>"
                                                                            class="btn btn-outline-primary btn-sm py-0.5 px-2"
                                                                            style="font-size: 0.75rem;"
                                                                            title="Editar Especificaciones de Fármaco">
                                                                            <i class="bi bi-pencil-square"></i> Editar
                                                                        </a>
                                                                        <form method="POST" action="procesar.php" class="d-inline"
                                                                            onsubmit="return confirm('¿Confirma que desea <?= ((int) $med['activo'] === 1) ? 'descontinuar' : 'reactivar' ?> el medicamento <?= htmlspecialchars(addslashes($med['nombre_generico'])) ?>?');">
                                                                            <input type="hidden" name="csrf_token"
                                                                                value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                                            <input type="hidden" name="accion"
                                                                                value="cambiar_estado_medicamento">
                                                                            <input type="hidden" name="id" value="<?= $med['id'] ?>">
                                                                            <button type="submit"
                                                                                class="btn btn-sm py-0.5 px-2 <?= ((int) $med['activo'] === 1) ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                                                                style="font-size: 0.75rem;"
                                                                                title="<?= ((int) $med['activo'] === 1) ? 'Descontinuar fármaco' : 'Reactivar fármaco' ?>">
                                                                                <?= ((int) $med['activo'] === 1) ? 'Desactivar' : 'Reactivar' ?>
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
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- SUBPESTAÑA 2: CATÁLOGO DE PATOLOGÍAS (CIE-10) -->
                    <?php if ($subtabMedicamentos === 'patologias'): ?>
                        <div class="row g-3">
                            <!-- Formulario de Registro de Patología -->
                            <div class="col-xl-4 col-lg-5">
                                <div class="card-sicm">
                                    <div class="card-header-sicm">
                                        <h6 class="m-0 fw-bold">
                                            <i class="bi bi-plus-circle text-primary me-1"></i>
                                            Registrar Patología (CIE-10)
                                        </h6>
                                    </div>
                                    <div class="card-body p-3">
                                        <form method="POST" action="procesar.php" novalidate id="form-patologia">
                                            <input type="hidden" name="csrf_token"
                                                value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="accion" value="crear_patologia">

                                            <div class="mb-3">
                                                <label class="form-label-sicm">Código CIE-10 (Opcional)</label>
                                                <input type="text" name="codigo_cie10"
                                                    class="form-control form-control-sm font-monospace text-uppercase"
                                                    placeholder="Ej: I10, E11, J45" maxlength="10">
                                                <div class="form-text" style="font-size: 0.70rem;">Clasificación Internacional
                                                    de Enfermedades (CIE-10).</div>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label-sicm">Diagnóstico / Denominación <span
                                                        class="text-danger">*</span></label>
                                                <input type="text" name="nombre" class="form-control form-control-sm"
                                                    placeholder="Ej: Hipertensión Arterial Primaria" required minlength="3"
                                                    maxlength="120">
                                            </div>

                                            <div class="mb-3">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="cronica" value="1"
                                                        id="checkCronica" checked>
                                                    <label class="form-check-label small fw-semibold text-dark"
                                                        for="checkCronica">
                                                        Enfermedad Crónica No Transmisible
                                                    </label>
                                                </div>
                                                <div class="text-muted" style="font-size: 0.70rem;">
                                                    Indica patología elegible para récipe de suministro continuo mensual.
                                                </div>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label-sicm">Descripción o Criterios Clínicos</label>
                                                <textarea name="descripcion" class="form-control form-control-sm" rows="3"
                                                    placeholder="Detalles sobre criterios diagnósticos o estadio evolutivo..."
                                                    maxlength="255"></textarea>
                                            </div>

                                            <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
                                                <span class="text-muted small" style="font-size: 0.70rem;"><span
                                                        class="text-danger">*</span> Campo requerido</span>
                                                <button type="submit" class="btn btn-primary btn-sm px-3 fw-semibold">
                                                    <i class="bi bi-plus-lg me-1"></i> Registrar Patología
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- Tabla de Patologías -->
                            <div class="col-xl-8 col-lg-7">
                                <div class="card-sicm">
                                    <div class="card-header-sicm d-flex justify-content-between align-items-center py-2">
                                        <h6 class="m-0 fw-bold">
                                            <i class="bi bi-heart-pulse text-primary me-1"></i>
                                            Catálogo Clínico Parametrizable (<?= count($patologias) ?> patologías)
                                        </h6>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0" style="font-size: 0.82rem;">
                                            <thead class="table-light">
                                                <tr>
                                                    <th style="width: 45px;">#ID</th>
                                                    <th style="width: 95px;">CIE-10</th>
                                                    <th>Diagnóstico / Denominación</th>
                                                    <th class="text-center" style="width: 110px;">Clasificación</th>
                                                    <th class="text-center" style="width: 90px;">Estado</th>
                                                    <th class="text-end" style="width: 110px;">Acciones</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($patologias)): ?>
                                                    <tr>
                                                        <td colspan="6" class="text-center text-muted py-4">
                                                            No hay patologías registradas en el catálogo clínico.
                                                        </td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($patologias as $pat): ?>
                                                        <tr
                                                            class="<?= ((int) $pat['activo'] === 0) ? 'table-secondary opacity-75' : '' ?>">
                                                            <td class="font-monospace text-muted">#<?= $pat['id'] ?></td>
                                                            <td>
                                                                <?php if (!empty($pat['codigo_cie10'])): ?>
                                                                    <span class="badge bg-light text-dark border font-monospace">
                                                                        <?= htmlspecialchars($pat['codigo_cie10']) ?>
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span class="text-muted small"><em>(Sin CIE)</em></span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <div class="fw-bold text-dark"><?= htmlspecialchars($pat['nombre']) ?>
                                                                </div>
                                                                <?php if (!empty($pat['descripcion'])): ?>
                                                                    <div class="text-muted small"
                                                                        style="font-size: 0.72rem; line-height: 1.2;">
                                                                        <?= htmlspecialchars($pat['descripcion']) ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="text-center">
                                                                <?php if ((int) $pat['cronica'] === 1): ?>
                                                                    <span
                                                                        class="badge bg-primary-subtle text-primary border border-primary-subtle"
                                                                        style="font-size: 0.72rem;">
                                                                        Crónica
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span
                                                                        class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"
                                                                        style="font-size: 0.72rem;">
                                                                        Aguda
                                                                    </span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="text-center">
                                                                <?php if ((int) $pat['activo'] === 1): ?>
                                                                    <span
                                                                        class="badge bg-success-subtle text-success border border-success-subtle"
                                                                        style="font-size: 0.72rem;">
                                                                        Activo
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span
                                                                        class="badge bg-secondary-subtle text-secondary border border-secondary-subtle"
                                                                        style="font-size: 0.72rem;">
                                                                        Inactivo
                                                                    </span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="text-end">
                                                                <form method="POST" action="procesar.php" class="d-inline"
                                                                    onsubmit="return confirm('¿Confirma que desea <?= ((int) $pat['activo'] === 1) ? 'desactivar' : 'reactivar' ?> la patología <?= htmlspecialchars(addslashes($pat['nombre'])) ?>?');">
                                                                    <input type="hidden" name="csrf_token"
                                                                        value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                                    <input type="hidden" name="accion" value="cambiar_estado_patologia">
                                                                    <input type="hidden" name="id" value="<?= $pat['id'] ?>">
                                                                    <button type="submit"
                                                                        class="btn btn-sm py-0.5 px-2 <?= ((int) $pat['activo'] === 1) ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                                                        style="font-size: 0.75rem;">
                                                                        <?= ((int) $pat['activo'] === 1) ? 'Desactivar' : 'Reactivar' ?>
                                                                    </button>
                                                                </form>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- SUBPESTAÑA 3: FORMAS FARMACÉUTICAS -->
                    <?php if ($subtabMedicamentos === 'formas'): ?>
                        <div class="row g-3">
                            <!-- Formulario de Registro de Forma Farmacéutica -->
                            <div class="col-xl-4 col-lg-5">
                                <div class="card-sicm">
                                    <div class="card-header-sicm">
                                        <h6 class="m-0 fw-bold">
                                            <i class="bi bi-plus-circle text-primary me-1"></i>
                                            Nueva Forma Farmacéutica
                                        </h6>
                                    </div>
                                    <div class="card-body p-3">
                                        <form method="POST" action="procesar.php" novalidate id="form-forma">
                                            <input type="hidden" name="csrf_token"
                                                value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="accion" value="crear_forma_farmaceutica">

                                            <div class="mb-3">
                                                <label class="form-label-sicm">Denominación de Presentación <span
                                                        class="text-danger">*</span></label>
                                                <input type="text" name="nombre" class="form-control form-control-sm"
                                                    placeholder="Ej: Tableta, Cápsula, Jarabe, Ampolla..." required
                                                    minlength="2" maxlength="60">
                                                <div class="form-text" style="font-size: 0.70rem;">
                                                    Vía o formato físico de administración del principio activo.
                                                </div>
                                            </div>

                                            <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
                                                <span class="text-muted small" style="font-size: 0.70rem;"><span
                                                        class="text-danger">*</span> Campo requerido</span>
                                                <button type="submit" class="btn btn-primary btn-sm px-3 fw-semibold">
                                                    <i class="bi bi-plus-lg me-1"></i> Registrar Forma
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- Tabla de Formas Farmacéuticas -->
                            <div class="col-xl-8 col-lg-7">
                                <div class="card-sicm">
                                    <div class="card-header-sicm d-flex justify-content-between align-items-center py-2">
                                        <h6 class="m-0 fw-bold">
                                            <i class="bi bi-boxes text-primary me-1"></i>
                                            Formas de Presentación Farmacéutica (<?= count($formasFarmaceuticas) ?>)
                                        </h6>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0" style="font-size: 0.82rem;">
                                            <thead class="table-light">
                                                <tr>
                                                    <th style="width: 50px;">#ID</th>
                                                    <th>Forma Farmacéutica / Presentación</th>
                                                    <th class="text-center" style="width: 140px;">Fármacos Vinculados</th>
                                                    <th class="text-center" style="width: 120px;">Condición</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($formasFarmaceuticas)): ?>
                                                    <tr>
                                                        <td colspan="4" class="text-center text-muted py-4">
                                                            No se han registrado formas farmacéuticas.
                                                        </td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($formasFarmaceuticas as $f): ?>
                                                        <tr>
                                                            <td class="font-monospace text-muted">#<?= $f['id'] ?></td>
                                                            <td class="fw-bold text-dark">
                                                                <i class="bi bi-capsule text-primary me-1"></i>
                                                                <?= htmlspecialchars($f['nombre']) ?>
                                                            </td>
                                                            <td class="text-center">
                                                                <span class="badge bg-light text-dark border font-monospace">
                                                                    <?= number_format((int) $f['total_medicamentos']) ?> fármaco(s)
                                                                </span>
                                                            </td>
                                                            <td class="text-center">
                                                                <?php if ((int) $f['total_medicamentos'] > 0): ?>
                                                                    <span
                                                                        class="badge bg-success-subtle text-success border border-success-subtle"
                                                                        style="font-size: 0.72rem;">
                                                                        En Uso Activo
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span
                                                                        class="badge bg-secondary-subtle text-secondary border border-secondary-subtle"
                                                                        style="font-size: 0.72rem;">
                                                                        Disponible
                                                                    </span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
<?php if (!empty($medicamentoEdicion)): ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const mMed = document.getElementById('modalMedicamento');
            if (mMed) new bootstrap.Modal(mMed).show();
        });
    </script>
<?php endif; ?>
