<?php
// src/views/tabs/tab_usuarios.php
// SICM | Módulo Autosuficiente: Operadores y Roles
declare(strict_types=1);

if (!tienePermiso('USR_VER')) {
    header('Location: index.php');
    exit;
}

$moduloNombre = 'Operadores y Roles';
$seccionNombre = 'Seguridad y Control';

$filtroEstadoU = $_GET['estado_u'] ?? 'activos';
$busquedaU = trim($_GET['q_u'] ?? '');
$condU = [];
$paramsU = [];

if ($filtroEstadoU === 'inactivos') {
    $condU[] = 'u.activo = 0';
} elseif ($filtroEstadoU !== 'todos') {
    $condU[] = 'u.activo = 1';
}

if ($busquedaU !== '') {
    $condU[] = '(u.nombre LIKE :qu1 OR u.email LIKE :qu2 OR cs.nombre LIKE :qu3)';
    $paramsU[':qu1'] = '%' . $busquedaU . '%';
    $paramsU[':qu2'] = '%' . $busquedaU . '%';
    $paramsU[':qu3'] = '%' . $busquedaU . '%';
}

$whereU = !empty($condU) ? 'WHERE ' . implode(' AND ', $condU) : '';

$stmtUsers = $pdo->prepare("
    SELECT u.id, u.nombre, u.email, u.rol_id, r.nombre AS rol_nombre, 
           u.centro_salud_id, cs.nombre AS centro_nombre, cs.codigo_sicm AS centro_codigo, 
           u.activo, u.created_at, u.updated_at
    FROM usuarios u
    INNER JOIN roles r ON r.id = u.rol_id
    LEFT JOIN centros_salud cs ON cs.id = u.centro_salud_id
    {$whereU}
    ORDER BY u.id ASC
");
$stmtUsers->execute($paramsU);
$listaUsuarios = $stmtUsers->fetchAll();

// Catálogos para el formulario de usuarios
$rolesCatalogo = $pdo->query("SELECT id, nombre, descripcion, es_personalizado FROM roles ORDER BY id ASC")->fetchAll();
$centrosDisponibles = $pdo->query("SELECT id, codigo_sicm, nombre FROM centros_salud WHERE activo = 1 ORDER BY nombre ASC")->fetchAll();

// Modo Edición
$usuarioEdicion = null;
$editarUId = filter_input(INPUT_GET, 'editar_u', FILTER_VALIDATE_INT);
if ($editarUId) {
    $stmtUEd = $pdo->prepare("SELECT id, nombre, email, rol_id, centro_salud_id, activo FROM usuarios WHERE id = :id");
    $stmtUEd->execute([':id' => $editarUId]);
    $usuarioEdicion = $stmtUEd->fetch();
}
?>
<div class="row g-3">
                        <?php if (tienePermiso('USR_GESTIONAR')): ?>
                            <!-- Formulario Operador -->
                            <div class="col-xl-4 col-lg-5">
                                <div class="card-sicm">
                                    <div class="card-header-sicm d-flex justify-content-between align-items-center">
                                        <span><?= $usuarioEdicion ? 'Editar Operador #' . (int) $usuarioEdicion['id'] : 'Nuevo Operador Sanitario' ?></span>
                                        <?php if ($usuarioEdicion): ?>
                                            <a href="index.php?tab=usuarios" class="small text-decoration-none text-muted">Cancelar
                                                edición</a>
                                        <?php endif; ?>
                                    </div>
                                    <div class="p-3">
                                        <form id="form-usuario" action="procesar.php" method="POST" novalidate>
                                            <input type="hidden" name="csrf_token"
                                                value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="accion" value="guardar_usuario">
                                            <input type="hidden" id="u_id" name="id"
                                                value="<?= $usuarioEdicion ? (int) $usuarioEdicion['id'] : '' ?>">

                                            <div class="mb-2">
                                                <label for="u_nombre" class="form-label small fw-semibold mb-1">Nombre Completo
                                                    del
                                                    Funcionario</label>
                                                <input type="text" class="form-control form-control-sm" id="u_nombre"
                                                    name="nombre" placeholder="Ej: Lcda. María Rivas" required maxlength="80"
                                                    value="<?= $usuarioEdicion ? htmlspecialchars($usuarioEdicion['nombre']) : '' ?>">
                                            </div>

                                            <div class="mb-2">
                                                <label for="u_email" class="form-label small fw-semibold mb-1">Correo
                                                    Institucional
                                                    Oficial</label>
                                                <input type="email" class="form-control form-control-sm" id="u_email"
                                                    name="email" placeholder="ej: mrivas@salud.prueba.ve" required
                                                    maxlength="120"
                                                    value="<?= $usuarioEdicion ? htmlspecialchars($usuarioEdicion['email']) : '' ?>">
                                            </div>

                                            <div class="mb-2">
                                                <label for="u_rol_id" class="form-label small fw-semibold mb-1">Rol
                                                    Operativo</label>
                                                <select class="form-select form-select-sm" id="u_rol_id" name="rol_id" required>
                                                    <option value="">-- Seleccionar Rol --</option>
                                                    <?php foreach ($rolesCatalogo as $ro): ?>
                                                        <?php $selR = ($usuarioEdicion && (int) $usuarioEdicion['rol_id'] === (int) $ro['id']) ? 'selected' : ''; ?>
                                                        <option value="<?= (int) $ro['id'] ?>" <?= $selR ?>>
                                                            <?php
                                                            if ((int) $ro['id'] === 1) {
                                                                echo 'Administrador Central (Acceso Global)';
                                                            } elseif ((int) $ro['id'] === 2) {
                                                                echo 'Farmaceuta (Dispensación Sectorial)';
                                                            } elseif ((int) $ro['id'] === 3) {
                                                                echo 'Auditor Sanitario (Control Forense)';
                                                            } else {
                                                                echo htmlspecialchars($ro['nombre']) . (!empty($ro['descripcion']) ? ' - ' . htmlspecialchars($ro['descripcion']) : ' [Personalizado]');
                                                            }
                                                            ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div class="mb-2" id="wrap_centro_salud">
                                                <label for="u_centro_salud_id" class="form-label small fw-semibold mb-1">
                                                    Ambulatorio de Adscripción <span class="text-danger">*</span>
                                                </label>
                                                <select class="form-select form-select-sm" id="u_centro_salud_id"
                                                    name="centro_salud_id">
                                                    <option value="">-- Seleccionar Ambulatorio --</option>
                                                    <?php foreach ($centrosDisponibles as $cs): ?>
                                                        <?php $selC = ($usuarioEdicion && (int) $usuarioEdicion['centro_salud_id'] === (int) $cs['id']) ? 'selected' : ''; ?>
                                                        <option value="<?= (int) $cs['id'] ?>" <?= $selC ?>>
                                                            <?= htmlspecialchars($cs['nombre']) ?>
                                                            (<?= htmlspecialchars($cs['codigo_sicm']) ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="text-muted small mt-1" style="font-size: 0.70rem;">
                                                    Delimita la visibilidad estricta de inventario y dispensación del
                                                    farmaceuta.
                                                </div>
                                            </div>

                                            <div class="mb-3">
                                                <label for="u_password" class="form-label small fw-semibold mb-1">
                                                    Contraseña
                                                    <?= $usuarioEdicion ? '<span class="text-muted fw-normal">(Dejar en blanco para no cambiar)</span>' : '<span class="text-danger">*</span>' ?>
                                                </label>
                                                <input type="password" class="form-control form-control-sm" id="u_password"
                                                    name="password"
                                                    placeholder="<?= $usuarioEdicion ? 'Sin cambios' : 'Mín. 8 caracteres (A-Z, a-z, 0-9, !@#)' ?>"
                                                    maxlength="72" <?= $usuarioEdicion ? '' : 'required minlength="8"' ?>>
                                            </div>

                                            <div class="d-flex gap-2">
                                                <button type="submit" class="btn btn-primary btn-sm w-100 fw-semibold">
                                                    <?= $usuarioEdicion ? 'Actualizar Operador' : 'Guardar Operador' ?>
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Tabla Operadores -->
                        <div class="<?= tienePermiso('USR_GESTIONAR') ? 'col-xl-8 col-lg-7' : 'col-12' ?>">
                            <div class="card-sicm">
                                <div
                                    class="card-header-sicm d-flex flex-wrap justify-content-between align-items-center gap-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <span>Padrón de Operadores y Seguridad</span>
                                        <span class="badge bg-primary rounded-pill"><?= count($listaUsuarios) ?></span>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="btn-group btn-group-sm btn-filter-group" role="group">
                                            <a href="index.php?tab=usuarios&estado_u=activos<?= $busquedaU !== '' ? '&q_u=' . urlencode($busquedaU) : '' ?>"
                                                class="btn btn-outline-secondary <?= $filtroEstadoU === 'activos' ? 'active' : '' ?>">Activos</a>
                                            <a href="index.php?tab=usuarios&estado_u=inactivos<?= $busquedaU !== '' ? '&q_u=' . urlencode($busquedaU) : '' ?>"
                                                class="btn btn-outline-secondary <?= $filtroEstadoU === 'inactivos' ? 'active' : '' ?>">Inactivos</a>
                                            <a href="index.php?tab=usuarios&estado_u=todos<?= $busquedaU !== '' ? '&q_u=' . urlencode($busquedaU) : '' ?>"
                                                class="btn btn-outline-secondary <?= $filtroEstadoU === 'todos' ? 'active' : '' ?>">Todos</a>
                                        </div>

                                        <form method="GET" action="index.php" class="d-flex m-0">
                                            <input type="hidden" name="tab" value="usuarios">
                                            <input type="hidden" name="estado_u"
                                                value="<?= htmlspecialchars($filtroEstadoU) ?>">
                                            <input type="text" name="q_u" class="form-control form-control-sm"
                                                style="width: 140px;" placeholder="Buscar..."
                                                value="<?= htmlspecialchars($busquedaU) ?>">
                                            <?php if ($busquedaU !== ''): ?>
                                                <a href="index.php?tab=usuarios&estado_u=<?= htmlspecialchars($filtroEstadoU) ?>"
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
                                                <th>Funcionario / Email</th>
                                                <th style="width: 130px;">Rol</th>
                                                <th>Jurisdicción Sanitaria</th>
                                                <th style="width: 80px;">Estado</th>
                                                <th style="width: 130px;" class="text-end pe-3">Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($listaUsuarios)): ?>
                                                <tr>
                                                    <td colspan="6" class="text-center text-muted py-4">No hay operadores
                                                        registrados
                                                        con ese criterio.</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($listaUsuarios as $u): ?>
                                                    <tr>
                                                        <td class="text-muted fw-bold">#<?= (int) $u['id'] ?></td>
                                                        <td>
                                                            <div class="fw-semibold text-dark"><?= htmlspecialchars($u['nombre']) ?>
                                                            </div>
                                                            <div class="text-muted small font-monospace"
                                                                style="font-size: 0.72rem;">
                                                                <?= htmlspecialchars($u['email']) ?>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            $badgeRolClass = match ((int) $u['rol_id']) {
                                                                1 => 'bg-primary-subtle text-primary border border-primary-subtle',
                                                                2 => 'bg-success-subtle text-success border border-success-subtle',
                                                                3 => 'bg-info-subtle text-info border border-info-subtle',
                                                                default => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
                                                            };
                                                            ?>
                                                            <span class="badge <?= $badgeRolClass ?>">
                                                                <?= htmlspecialchars($u['rol_nombre']) ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php if (empty($u['centro_salud_id'])): ?>
                                                                <span class="badge bg-light text-secondary border">Red Municipal
                                                                    Completa</span>
                                                            <?php else: ?>
                                                                <div class="fw-semibold text-dark" style="font-size: 0.80rem;">
                                                                    <?= htmlspecialchars($u['centro_nombre'] ?? 'Ambulatorio') ?>
                                                                </div>
                                                                <?php if (!empty($u['centro_codigo'])): ?>
                                                                    <span class="code-tag"
                                                                        style="font-size: 0.68rem;"><?= htmlspecialchars($u['centro_codigo']) ?></span>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ((int) $u['activo'] === 1): ?>
                                                                <span class="badge badge-status-active">Activo</span>
                                                            <?php else: ?>
                                                                <span class="badge badge-status-inactive">Inactivo</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-end pe-3">
                                                            <div class="d-flex justify-content-end align-items-center gap-1">
                                                                <?php if (tienePermiso('AUDIT_VER')): ?>
                                                                    <a href="index.php?tab=auditoria&subtab=deltas&tabla=usuarios&registro_id=<?= (int) $u['id'] ?>"
                                                                        class="btn btn-outline-info btn-sm py-0 px-2 text-decoration-none"
                                                                        style="font-size: 0.74rem;" title="Auditoría Delta">
                                                                        <i class="bi bi-clock-history me-1"></i>Historial
                                                                    </a>
                                                                <?php endif; ?>
                                                                <?php if (tienePermiso('USR_GESTIONAR')): ?>
                                                                    <a href="index.php?tab=usuarios&editar_u=<?= (int) $u['id'] ?>&estado_u=<?= htmlspecialchars($filtroEstadoU) ?>"
                                                                        class="btn btn-outline-primary btn-sm py-0 px-2"
                                                                        style="font-size: 0.74rem;">Editar</a>
                                                                    <?php if ((int) $u['id'] !== (int) $usuario['id']): ?>
                                                                        <?php if ((int) $u['activo'] === 1): ?>
                                                                            <form method="POST" action="procesar.php" class="d-inline"
                                                                                onsubmit="return confirm('¿Desactivar operador?');">
                                                                                <input type="hidden" name="csrf_token"
                                                                                    value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                                                <input type="hidden" name="accion"
                                                                                    value="cambiar_estado_usuario">
                                                                                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                                                                <input type="hidden" name="estado" value="0">
                                                                                <button type="submit"
                                                                                    class="btn btn-link text-danger p-0 ms-1 text-decoration-none"
                                                                                    title="Desactivar"
                                                                                    style="font-size: 0.85rem; line-height: 1;">✕</button>
                                                                            </form>
                                                                        <?php else: ?>
                                                                            <form method="POST" action="procesar.php" class="d-inline"
                                                                                onsubmit="return confirm('¿Reactivar operador?');">
                                                                                <input type="hidden" name="csrf_token"
                                                                                    value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                                                <input type="hidden" name="accion"
                                                                                    value="cambiar_estado_usuario">
                                                                                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                                                                <input type="hidden" name="estado" value="1">
                                                                                <button type="submit"
                                                                                    class="btn btn-outline-success btn-sm py-0 px-1 ms-1"
                                                                                    style="font-size: 0.74rem;"
                                                                                    title="Reactivar">Reactivar</button>
                                                                            </form>
                                                                        <?php endif; ?>
                                                                    <?php else: ?>
                                                                        <span class="text-muted small ms-1" style="font-size: 0.70rem;"
                                                                            title="Tu sesión actual">(Tú)</span>
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
