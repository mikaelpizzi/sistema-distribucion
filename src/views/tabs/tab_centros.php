<?php
// src/views/tabs/tab_centros.php
// SICM | Módulo Autosuficiente: Red de Ambulatorios
declare(strict_types=1);

$moduloNombre = $puedeVerTodaLaRed ? 'Red de Ambulatorios' : 'Mi Ambulatorio';
$seccionNombre = 'Vademécum y Red';

$filtroEstado = $_GET['estado'] ?? 'activos';
$busqueda = trim($_GET['q'] ?? '');
$condiciones = [];
$params = [];

// Política de Lectura: Jurisdicción asistencial central o ambulatorio asignado
if (!$puedeVerTodaLaRed) {
    $condiciones[] = 'c.id = :mi_centro_id';
    $params[':mi_centro_id'] = $centroUsuarioId;
} else {
    if ($filtroEstado === 'inactivos') {
        $condiciones[] = 'c.activo = 0';
    } elseif ($filtroEstado !== 'todos') {
        $condiciones[] = 'c.activo = 1';
    }
}

if ($busqueda !== '') {
    $condiciones[] = '(c.nombre LIKE :q1 OR c.codigo_sicm LIKE :q2 OR c.direccion LIKE :q3 OR c.medico_director LIKE :q4)';
    $params[':q1'] = '%' . $busqueda . '%';
    $params[':q2'] = '%' . $busqueda . '%';
    $params[':q3'] = '%' . $busqueda . '%';
    $params[':q4'] = '%' . $busqueda . '%';
}

$whereSql = !empty($condiciones) ? 'WHERE ' . implode(' AND ', $condiciones) : '';
$stmtCentros = $pdo->prepare("SELECT c.id, c.codigo_sicm, c.nombre, c.tipo_centro_id, c.tipo_nombre, c.tipo_codigo,
                                     c.medico_director, c.horario_atencion, c.direccion, c.telefono_principal, c.activo
                              FROM vw_centros_salud c {$whereSql} ORDER BY c.id DESC");
$stmtCentros->execute($params);
$centros = $stmtCentros->fetchAll();

// Recursos asignados por centro (N:M)
$recursosPorCentro = [];
if (!empty($centros)) {
    $centroIds = array_map(fn($c) => (int) $c['id'], $centros);
    $placeholders = implode(',', array_fill(0, count($centroIds), '?'));
    $stmtRA = $pdo->prepare("SELECT cr.centro_salud_id, ro.nombre
                           FROM centro_recursos cr
                           INNER JOIN recursos_operativos ro ON ro.id = cr.recurso_id
                           WHERE cr.operativo = 1 AND cr.centro_salud_id IN ({$placeholders})
                           ORDER BY ro.id ASC");
    $stmtRA->execute($centroIds);
    while ($r = $stmtRA->fetch()) {
        $recursosPorCentro[(int) $r['centro_salud_id']][] = $r['nombre'];
    }
}

// Catálogos para el formulario
$tiposCentro = $pdo->query("SELECT id, codigo, nombre FROM tipos_centro_salud WHERE activo = 1 ORDER BY id ASC")->fetchAll();
$catalogoRecursosActivos = $pdo->query("SELECT id, nombre, descripcion FROM recursos_operativos WHERE activo = 1 ORDER BY id ASC")->fetchAll();

// Modo Edición
$centroEdicion = null;
$recursosEdicionIds = [];
$editarId = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT);
if ($editarId) {
    $stmtEd = $pdo->prepare("SELECT * FROM centros_salud WHERE id = :id");
    $stmtEd->execute([':id' => $editarId]);
    $centroEdicion = $stmtEd->fetch();
    if ($centroEdicion) {
        $stmtRecEd = $pdo->prepare("SELECT recurso_id FROM centro_recursos WHERE centro_salud_id = :id");
        $stmtRecEd->execute([':id' => $editarId]);
        $recursosEdicionIds = array_map('intval', $stmtRecEd->fetchAll(PDO::FETCH_COLUMN));
    }
}
?>
<?php if (!$puedeVerTodaLaRed): ?>
                        <!-- Ficha de Jurisdicción para Farmaceuta -->
                        <div class="card-sicm mb-3">
                            <div class="card-header-sicm d-flex justify-content-between align-items-center">
                                <span class="fw-bold"><i class="bi bi-geo-alt-fill text-primary me-1"></i> Jurisdicción
                                    Asignada</span>
                                <span class="badge bg-success-subtle text-success border border-success-subtle font-monospace"
                                    style="font-size:0.68rem;">Rol Farmaceuta</span>
                            </div>
                            <div class="p-3">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <span class="text-muted small">Ambulatorio Asignado:</span>
                                        <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($usuario['centro_nombre']) ?>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <span class="text-muted small">Funcionario Responsable:</span>
                                        <div class="fw-semibold text-dark"><?= htmlspecialchars($usuario['nombre']) ?></div>
                                        <div class="text-muted small font-monospace"><?= htmlspecialchars($usuario['email']) ?>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="p-2 bg-light border rounded small text-secondary"
                                            style="font-size: 0.72rem;">
                                            <strong>Permisos Habilitados:</strong>
                                            <ul class="mb-0 ps-3 mt-1">
                                                <li>Consulta de lotes e inventario de su sede.</li>
                                                <li>Recepción de insumos por caducidad (FEFO).</li>
                                                <li>Dispensación controlada a pacientes.</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (tienePermiso('CENTROS_CREAR') || tienePermiso('CENTROS_EDITAR')): ?>
                        <!-- Modal de Registro / Edición de Ambulatorio -->
                        <div class="modal fade" id="modalCentro" tabindex="-1" aria-labelledby="modalCentroLabel"
                            aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-centered">
                                <div class="modal-content border-0 shadow">
                                    <form id="form-centro" action="procesar.php" method="POST" novalidate>
                                        <div class="modal-header bg-light py-2 px-3 border-bottom">
                                            <h6 class="modal-title fw-bold" id="modalCentroLabel">
                                                <i
                                                    class="bi <?= $centroEdicion ? 'bi-pencil-square text-warning' : 'bi-hospital text-primary' ?> me-1"></i>
                                                <?= $centroEdicion ? 'Editar Ambulatorio #' . (int) $centroEdicion['id'] : 'Registrar Nuevo Ambulatorio' ?>
                                            </h6>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                                aria-label="Cerrar"></button>
                                        </div>
                                        <div class="modal-body p-3">
                                            <input type="hidden" name="csrf_token"
                                                value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="accion" value="guardar_centro">
                                            <input type="hidden" name="id"
                                                value="<?= $centroEdicion ? (int) $centroEdicion['id'] : '' ?>">

                                            <div class="row g-2 mb-2">
                                                <div class="col-md-4">
                                                    <label for="codigo_sicm" class="form-label small fw-semibold mb-1">Código
                                                        Sanitario SICM <span class="text-danger">*</span></label>
                                                    <input type="text"
                                                        class="form-control form-control-sm font-monospace text-uppercase"
                                                        id="codigo_sicm" name="codigo_sicm" placeholder="Ej: AMB-MER-004"
                                                        required maxlength="30"
                                                        value="<?= $centroEdicion ? htmlspecialchars($centroEdicion['codigo_sicm']) : '' ?>">
                                                </div>
                                                <div class="col-md-8">
                                                    <label for="nombre" class="form-label small fw-semibold mb-1">Nombre del
                                                        Ambulatorio <span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control form-control-sm" id="nombre"
                                                        name="nombre" placeholder="Ej: Ambulatorio Urbano Los Curos" required
                                                        maxlength="120"
                                                        value="<?= $centroEdicion ? htmlspecialchars($centroEdicion['nombre']) : '' ?>">
                                                </div>
                                            </div>

                                            <div class="row g-2 mb-2">
                                                <div class="col-md-6">
                                                    <label for="tipo_centro_id"
                                                        class="form-label small fw-semibold mb-1">Clasificación Asistencial
                                                        <span class="text-danger">*</span></label>
                                                    <select class="form-select form-select-sm" id="tipo_centro_id"
                                                        name="tipo_centro_id" required>
                                                        <option value="">-- Seleccionar --</option>
                                                        <?php foreach ($tiposCentro as $t): ?>
                                                            <?php $sel = ($centroEdicion && (int) $centroEdicion['tipo_centro_id'] === (int) $t['id']) ? 'selected' : ''; ?>
                                                            <option value="<?= (int) $t['id'] ?>" <?= $sel ?>>
                                                                <?= htmlspecialchars($t['nombre']) ?>
                                                                (<?= htmlspecialchars($t['codigo']) ?>)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-6">
                                                    <label for="telefono_principal"
                                                        class="form-label small fw-semibold mb-1">Teléfono Institucional</label>
                                                    <input type="text" class="form-control form-control-sm"
                                                        id="telefono_principal" name="telefono_principal"
                                                        placeholder="Ej: 0274-2521100" maxlength="30"
                                                        value="<?= $centroEdicion ? htmlspecialchars($centroEdicion['telefono_principal'] ?? '') : '' ?>">
                                                </div>
                                            </div>

                                            <div class="row g-2 mb-2">
                                                <div class="col-md-6">
                                                    <label for="medico_director"
                                                        class="form-label small fw-semibold mb-1">Director Médico /
                                                        Responsable</label>
                                                    <input type="text" class="form-control form-control-sm" id="medico_director"
                                                        name="medico_director" placeholder="Ej: Dra. Carmen Gil" maxlength="120"
                                                        value="<?= $centroEdicion ? htmlspecialchars($centroEdicion['medico_director'] ?? '') : '' ?>">
                                                </div>
                                                <div class="col-md-6">
                                                    <label for="horario_atencion"
                                                        class="form-label small fw-semibold mb-1">Horario de Atención</label>
                                                    <input type="text" class="form-control form-control-sm"
                                                        id="horario_atencion" name="horario_atencion" placeholder="Ej: 24 Horas"
                                                        maxlength="100"
                                                        value="<?= $centroEdicion ? htmlspecialchars($centroEdicion['horario_atencion'] ?? '') : '' ?>">
                                                </div>
                                            </div>

                                            <div class="mb-3">
                                                <label for="direccion" class="form-label small fw-semibold mb-1">Dirección <span
                                                        class="text-danger">*</span></label>
                                                <textarea class="form-control form-control-sm" id="direccion" name="direccion"
                                                    rows="2" placeholder="Sector, calle o avenida" required
                                                    maxlength="255"><?= $centroEdicion ? htmlspecialchars($centroEdicion['direccion']) : '' ?></textarea>
                                            </div>

                                            <!-- Equipamiento médico (N:M) -->
                                            <div class="p-2.5 bg-light border rounded">
                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                    <span class="small fw-semibold text-dark">Equipamiento y Capacidades
                                                        Clínicas</span>
                                                    <a href="index.php?tab=maestras" class="small text-decoration-none"
                                                        style="font-size: 0.70rem;">Configurar Catálogo</a>
                                                </div>
                                                <div class="row g-2 mt-1">
                                                    <?php foreach ($catalogoRecursosActivos as $rec): ?>
                                                        <?php $chk = in_array((int) $rec['id'], $recursosEdicionIds, true) ? 'checked' : ''; ?>
                                                        <div class="col-md-6">
                                                            <div class="form-check form-check-sm mb-1">
                                                                <input class="form-check-input" type="checkbox" name="recursos[]"
                                                                    value="<?= (int) $rec['id'] ?>" id="rec_<?= (int) $rec['id'] ?>"
                                                                    <?= $chk ?>>
                                                                <label class="form-check-label small"
                                                                    for="rec_<?= (int) $rec['id'] ?>">
                                                                    <strong><?= htmlspecialchars($rec['nombre']) ?></strong>
                                                                    <span class="d-block text-muted"
                                                                        style="font-size: 0.70rem;"><?= htmlspecialchars($rec['descripcion'] ?? '') ?></span>
                                                                </label>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="modal-footer bg-light py-2 px-3 border-top d-flex justify-content-between">
                                            <?php if ($centroEdicion): ?>
                                                <a href="index.php?tab=centros" class="btn btn-outline-secondary btn-sm">Cancelar
                                                    edición</a>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-outline-secondary btn-sm"
                                                    data-bs-dismiss="modal">Cerrar</button>
                                            <?php endif; ?>
                                            <button type="submit" class="btn btn-primary btn-sm px-3 fw-semibold">
                                                <i
                                                    class="bi <?= $centroEdicion ? 'bi-check2-circle' : 'bi-plus-lg' ?> me-1"></i>
                                                <?= $centroEdicion ? 'Actualizar Ambulatorio' : 'Guardar Ambulatorio' ?>
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="row g-3">
                        <!-- Tabla de Ambulatorios (Ocupa 100% del ancho) -->
                        <div class="col-12">
                            <div class="card-sicm">
                                <div
                                    class="card-header-sicm d-flex flex-wrap justify-content-between align-items-center gap-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="fw-bold"><i class="bi bi-hospital me-1"></i> Ambulatorios de la
                                            Red</span>
                                        <span class="badge bg-primary rounded-pill"><?= count($centros) ?></span>
                                        <?php if (tienePermiso('CENTROS_CREAR')): ?>
                                            <button type="button"
                                                class="btn btn-primary btn-sm px-3 d-inline-flex align-items-center gap-1.5 shadow-sm ms-2"
                                                data-bs-toggle="modal" data-bs-target="#modalCentro">
                                                <i class="bi bi-plus-lg me-1"></i>Nuevo Ambulatorio
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="btn-group btn-group-sm btn-filter-group" role="group">
                                            <a href="index.php?tab=centros&estado=activos<?= $busqueda !== '' ? '&q=' . urlencode($busqueda) : '' ?>"
                                                class="btn btn-outline-secondary <?= $filtroEstado === 'activos' ? 'active' : '' ?>">Operativos</a>
                                            <a href="index.php?tab=centros&estado=inactivos<?= $busqueda !== '' ? '&q=' . urlencode($busqueda) : '' ?>"
                                                class="btn btn-outline-secondary <?= $filtroEstado === 'inactivos' ? 'active' : '' ?>">Inactivos</a>
                                            <a href="index.php?tab=centros&estado=todos<?= $busqueda !== '' ? '&q=' . urlencode($busqueda) : '' ?>"
                                                class="btn btn-outline-secondary <?= $filtroEstado === 'todos' ? 'active' : '' ?>">Todos</a>
                                        </div>

                                        <form method="GET" action="index.php" class="d-flex m-0">
                                            <input type="hidden" name="tab" value="centros">
                                            <input type="hidden" name="estado"
                                                value="<?= htmlspecialchars($filtroEstado) ?>">
                                            <input type="text" name="q" class="form-control form-control-sm"
                                                style="width: 140px;" placeholder="Buscar..."
                                                value="<?= htmlspecialchars($busqueda) ?>">
                                            <?php if ($busqueda !== ''): ?>
                                                <a href="index.php?tab=centros&estado=<?= htmlspecialchars($filtroEstado) ?>"
                                                    class="btn btn-outline-secondary btn-sm ms-1">✕</a>
                                            <?php endif; ?>
                                        </form>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-hover table-sm table-sicm mb-0">
                                        <thead>
                                            <tr>
                                                <th style="width: 45px;">ID</th>
                                                <th style="width: 110px;">Código</th>
                                                <th>Ambulatorio</th>
                                                <th style="width: 130px;">Clasificación</th>
                                                <th>Equipamiento</th>
                                                <th style="width: 80px;">Estado</th>
                                                <th style="width: 160px;" class="text-end pe-3">Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($centros)): ?>
                                                <tr>
                                                    <td colspan="7" class="text-center text-muted py-4">No hay ambulatorios
                                                        registrados.
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($centros as $c): ?>
                                                    <?php
                                                    $recs = $recursosPorCentro[(int) $c['id']] ?? [];
                                                    ?>
                                                    <tr>
                                                        <td class="text-muted fw-bold">#<?= (int) $c['id'] ?></td>
                                                        <td><span class="code-tag"><?= htmlspecialchars($c['codigo_sicm']) ?></span>
                                                        </td>
                                                        <td>
                                                            <div class="fw-semibold text-dark"><?= htmlspecialchars($c['nombre']) ?>
                                                            </div>
                                                            <div class="text-muted small" style="font-size: 0.74rem;">
                                                                <?= htmlspecialchars($c['direccion']) ?>
                                                                <?= !empty($c['medico_director']) ? ' &bull; ' . htmlspecialchars($c['medico_director']) : '' ?>
                                                            </div>
                                                        </td>
                                                        <td><span
                                                                class="badge bg-light text-dark border"><?= htmlspecialchars($c['tipo_nombre']) ?></span>
                                                        </td>
                                                        <td>
                                                            <?php if (empty($recs)): ?>
                                                                <span class="text-muted fst-italic small"
                                                                    style="font-size: 0.70rem;">Sin
                                                                    equipamiento</span>
                                                            <?php else: ?>
                                                                <div class="d-flex flex-wrap gap-1">
                                                                    <?php foreach ($recs as $nom): ?>
                                                                        <span
                                                                            class="badge badge-resource"><?= htmlspecialchars($nom) ?></span>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ((int) $c['activo'] === 1): ?>
                                                                <span class="badge badge-status-active">Operativo</span>
                                                            <?php else: ?>
                                                                <span class="badge badge-status-inactive">Inactivo</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-end pe-3">
                                                            <div class="d-flex justify-content-end align-items-center gap-1">
                                                                <?php if (tienePermiso('AUDIT_VER')): ?>
                                                                    <a href="index.php?tab=auditoria&subtab=deltas&tabla=centros_salud&registro_id=<?= (int) $c['id'] ?>"
                                                                        class="btn btn-outline-info btn-sm py-0 px-2 text-decoration-none"
                                                                        style="font-size: 0.74rem;"
                                                                        title="Ver trazabilidad forense delta celda por celda">
                                                                        <i class="bi bi-clock-history me-1"></i>Historial
                                                                    </a>
                                                                <?php endif; ?>
                                                                <?php if (tienePermiso('CENTROS_EDITAR')): ?>
                                                                    <a href="index.php?tab=centros&editar=<?= (int) $c['id'] ?>&estado=<?= htmlspecialchars($filtroEstado) ?>"
                                                                        class="btn btn-outline-primary btn-sm py-0 px-2"
                                                                        style="font-size: 0.74rem;">Editar</a>
                                                                <?php endif; ?>
                                                                <?php if (tienePermiso('CENTROS_ESTADO')): ?>
                                                                    <?php if ((int) $c['activo'] === 1): ?>
                                                                        <form method="POST" action="procesar.php" class="d-inline"
                                                                            onsubmit="return confirm('¿Desactivar ambulatorio?');">
                                                                            <input type="hidden" name="csrf_token"
                                                                                value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                                            <input type="hidden" name="accion"
                                                                                value="cambiar_estado_centro">
                                                                            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                                                            <input type="hidden" name="estado" value="0">
                                                                            <button type="submit"
                                                                                class="btn btn-link text-danger p-0 ms-1 text-decoration-none"
                                                                                title="Desactivar"
                                                                                style="font-size: 0.85rem; line-height: 1;">✕</button>
                                                                        </form>
                                                                    <?php else: ?>
                                                                        <form method="POST" action="procesar.php" class="d-inline"
                                                                            onsubmit="return confirm('¿Reactivar ambulatorio?');">
                                                                            <input type="hidden" name="csrf_token"
                                                                                value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                                            <input type="hidden" name="accion"
                                                                                value="cambiar_estado_centro">
                                                                            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                                                            <input type="hidden" name="estado" value="1">
                                                                            <button type="submit"
                                                                                class="btn btn-outline-success btn-sm py-0 px-1 ms-1"
                                                                                style="font-size: 0.74rem;"
                                                                                title="Reactivar">Reactivar</button>
                                                                        </form>
                                                                    <?php endif; ?>
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
<?php if (!empty($centroEdicion)): ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const mCentro = document.getElementById('modalCentro');
            if (mCentro) new bootstrap.Modal(mCentro).show();
        });
    </script>
<?php endif; ?>
