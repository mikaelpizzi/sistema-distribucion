<div class="card bg-light border mb-3">
                        <div class="card-body p-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <strong class="text-dark fs-6">Configuración y Catálogos Maestros</strong>
                                </div>
                                <p class="text-muted small mb-0">
                                    Gestión y parametrización de catálogos maestros del sistema. Permite incorporar y
                                    mantener las
                                    clasificaciones de ambulatorios y el equipamiento operativo de la red de salud
                                    municipal.
                                </p>
                            </div>
                            <a href="index.php?tab=centros"
                                class="btn btn-outline-primary btn-sm py-1 px-3 text-nowrap">Volver a
                                Ambulatorios</a>
                        </div>
                    </div>

                    <div class="row g-3">
                        <!-- ========================================================== -->
                        <!-- ROLES OPERATIVOS Y MATRIZ DE PERMISOS RBAC (PARAMETRIZABLE) -->
                        <!-- ========================================================== -->
                        <div class="col-12 mb-4">
                            <div class="card-sicm">
                                <div
                                    class="card-header-sicm d-flex flex-wrap justify-content-between align-items-center gap-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="fw-bold">Roles Operativos y Matriz de Permisos RBAC</span>
                                        <span class="code-tag">roles</span>
                                        <span class="code-tag">rol_permisos</span>
                                    </div>
                                    <button class="btn btn-primary btn-sm py-1 px-3 fw-semibold" type="button"
                                        data-bs-toggle="collapse" data-bs-target="#collapseNuevoRol" aria-expanded="false"
                                        aria-controls="collapseNuevoRol">
                                        <i class="bi bi-shield-plus me-1"></i>+ Registrar Nuevo Rol Personalizado
                                    </button>
                                </div>
                                <div class="px-3 pt-2 pb-1 text-muted small" style="font-size: 0.75rem;">
                                    Control de Acceso Basado en Roles (RBAC) normalizado en Tercera Forma Normal (3FN).
                                    Parametrización en caliente de perfiles institucionales y asignación granular sobre los
                                    24 permisos atómicos de la red asistencial.
                                </div>

                                <!-- Formulario Colapsable: Registrar Nuevo Rol -->
                                <div class="collapse p-3 bg-light border-top border-bottom" id="collapseNuevoRol">
                                    <form action="procesar.php" method="POST" id="form-crear-rol">
                                        <input type="hidden" name="csrf_token"
                                            value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="accion" value="crear_rol">

                                        <div class="card border mb-3">
                                            <div class="card-header bg-white py-2 px-3 fw-semibold text-dark small">
                                                <i class="bi bi-shield-lock-fill text-primary me-1"></i>Definición de Perfil
                                                Institucional
                                            </div>
                                            <div class="card-body p-3">
                                                <div class="row g-2">
                                                    <div class="col-md-5">
                                                        <label class="form-label small fw-semibold mb-1">Nombre Técnico del
                                                            Rol <span class="text-danger">*</span></label>
                                                        <div class="input-group input-group-sm">
                                                            <span
                                                                class="input-group-text font-monospace bg-light">rol_</span>
                                                            <input type="text" name="nombre"
                                                                class="form-control form-control-sm font-monospace"
                                                                placeholder="coordinador_epidemiologico" required
                                                                maxlength="45" pattern="^[a-zA-Z0-9_]+$">
                                                        </div>
                                                        <div class="form-text" style="font-size: 0.70rem;">Se almacena con
                                                            prefijo <code>rol_</code> en minúsculas y sin caracteres
                                                            especiales.</div>
                                                    </div>
                                                    <div class="col-md-7">
                                                        <label class="form-label small fw-semibold mb-1">Descripción y
                                                            Propósito Institucional <span
                                                                class="text-danger">*</span></label>
                                                        <input type="text" name="descripcion"
                                                            class="form-control form-control-sm"
                                                            placeholder="Ej: Coordinación epidemiológica y dispensación de tratamientos crónicos"
                                                            required maxlength="255">
                                                        <div class="form-text" style="font-size: 0.70rem;">Resumen del
                                                            alcance asistencial del perfil en los ambulatorios.</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Matriz de Permisos Checkboxes -->
                                        <div class="card border mb-3">
                                            <div
                                                class="card-header bg-white py-2 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                                                <div class="small fw-bold text-dark">
                                                    <i class="bi bi-grid-3x3-gap-fill text-warning me-1"></i>Asignación de
                                                    Permisos Atómicos por Módulo (24 Permisos en 3FN)
                                                </div>
                                                <div class="d-flex gap-2">
                                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2"
                                                        style="font-size: 0.70rem;"
                                                        onclick="toggleCheckboxes('form-crear-rol', true)">Marcar
                                                        Todos</button>
                                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2"
                                                        style="font-size: 0.70rem;"
                                                        onclick="toggleCheckboxes('form-crear-rol', false)">Desmarcar
                                                        Todos</button>
                                                </div>
                                            </div>
                                            <div class="card-body p-3">
                                                <div class="row g-3">
                                                    <?php foreach ($permisosPorModulo as $moduloNom => $perms): ?>
                                                        <div class="col-md-6 col-xl-4">
                                                            <div class="border rounded p-2 h-100 bg-light">
                                                                <div
                                                                    class="fw-bold text-primary small border-bottom pb-1 mb-2 d-flex align-items-center gap-1">
                                                                    <i class="bi bi-folder2-open"></i>
                                                                    <?= htmlspecialchars($moduloNom) ?>
                                                                </div>
                                                                <?php foreach ($perms as $p): ?>
                                                                    <div class="form-check mb-1">
                                                                        <input class="form-check-input" type="checkbox"
                                                                            name="permisos[]" value="<?= (int) $p['id'] ?>"
                                                                            id="perm_new_<?= (int) $p['id'] ?>">
                                                                        <label class="form-check-label small"
                                                                            for="perm_new_<?= (int) $p['id'] ?>"
                                                                            title="<?= htmlspecialchars($p['descripcion'] ?? '') ?>">
                                                                            <span
                                                                                class="fw-semibold text-dark"><?= htmlspecialchars($p['nombre']) ?></span>
                                                                            <br><span class="code-tag"
                                                                                style="font-size: 0.65rem;"><?= htmlspecialchars($p['codigo']) ?></span>
                                                                        </label>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="d-flex justify-content-end gap-2">
                                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#collapseNuevoRol">Cancelar</button>
                                            <button type="submit" class="btn btn-primary btn-sm px-3 fw-semibold">
                                                <i class="bi bi-check2-circle me-1"></i>Guardar Rol y Asignar Permisos
                                            </button>
                                        </div>
                                    </form>
                                </div>

                                <!-- Tabla de Roles Operativos -->
                                <div class="table-responsive">
                                    <table class="table table-hover table-sm table-sicm mb-0">
                                        <thead>
                                            <tr>
                                                <th style="width: 45px;">ID</th>
                                                <th style="width: 170px;">Nombre del Rol</th>
                                                <th style="width: 140px;">Tipo</th>
                                                <th>Descripción Institucional</th>
                                                <th style="width: 110px;" class="text-center">Operadores</th>
                                                <th style="width: 120px;" class="text-center">Permisos</th>
                                                <th style="width: 150px;" class="text-end pe-3">Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($todosRoles as $r): ?>
                                                <tr>
                                                    <td class="text-muted fw-bold">#<?= (int) $r['id'] ?></td>
                                                    <td><span class="code-tag"><?= htmlspecialchars($r['nombre']) ?></span></td>
                                                    <td>
                                                        <?php if ((int) $r['es_personalizado'] === 0): ?>
                                                            <span
                                                                class="badge bg-primary-subtle text-primary border border-primary-subtle">[
                                                                Base del Sistema ]</span>
                                                        <?php else: ?>
                                                            <span
                                                                class="badge bg-success-subtle text-success border border-success-subtle">[
                                                                Personalizado ]</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="small text-muted">
                                                        <?= htmlspecialchars($r['descripcion'] ?? 'Sin descripción') ?></td>
                                                    <td class="text-center">
                                                        <?php if ((int) $r['total_usuarios'] > 0): ?>
                                                            <span class="badge bg-light text-primary border"
                                                                title="Operadores activos vinculados">
                                                                <?= (int) $r['total_usuarios'] ?>
                                                                <?= (int) $r['total_usuarios'] === 1 ? 'operador' : 'operadores' ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted small" style="font-size: 0.72rem;">0
                                                                operadores</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-light text-dark border font-monospace">
                                                            <?= (int) $r['total_permisos'] ?> / <?= count($permisosCatalogo) ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-end pe-3">
                                                        <div class="d-flex justify-content-end align-items-center gap-1">
                                                            <button type="button"
                                                                class="btn btn-outline-primary btn-sm py-0 px-2"
                                                                style="font-size: 0.72rem;" data-bs-toggle="modal"
                                                                data-bs-target="#modalPermisosRol<?= (int) $r['id'] ?>"
                                                                title="Modificar Matriz de Permisos">
                                                                <i class="bi bi-gear-fill me-1"></i>Permisos
                                                            </button>
                                                            <?php if ((int) $r['es_personalizado'] === 1 && (int) $r['total_usuarios'] === 0): ?>
                                                                <form method="POST" action="procesar.php" class="d-inline"
                                                                    onsubmit="return confirm('¿Confirma la eliminación definitiva del rol personalizado <?= htmlspecialchars($r['nombre']) ?>?');">
                                                                    <input type="hidden" name="csrf_token"
                                                                        value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                                    <input type="hidden" name="accion" value="eliminar_rol">
                                                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                                                    <button type="submit"
                                                                        class="btn btn-outline-danger btn-sm py-0 px-2"
                                                                        style="font-size: 0.72rem;"
                                                                        title="Eliminar Rol sin operadores">
                                                                        <i class="bi bi-trash"></i> Eliminar
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Modales de Permisos para cada Rol -->
                        <?php foreach ($todosRoles as $r): ?>
                            <div class="modal fade" id="modalPermisosRol<?= (int) $r['id'] ?>" tabindex="-1"
                                aria-labelledby="modalRolLabel<?= (int) $r['id'] ?>" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                    <form action="procesar.php" method="POST" id="form-edit-rol-<?= (int) $r['id'] ?>" class="modal-content border-0 shadow">
                                        <input type="hidden" name="csrf_token"
                                            value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="accion" value="actualizar_rol_permisos">
                                        <input type="hidden" name="rol_id" value="<?= (int) $r['id'] ?>">

                                        <div class="modal-header bg-light py-2 px-3 border-bottom">
                                            <h6 class="modal-title fw-bold" id="modalRolLabel<?= (int) $r['id'] ?>">
                                                <i class="bi bi-shield-lock text-primary me-1"></i>
                                                Matriz de Permisos: <span
                                                    class="font-monospace text-dark"><?= htmlspecialchars($r['nombre']) ?></span>
                                                <?php if ((int) $r['es_personalizado'] === 0): ?>
                                                    <span
                                                        class="badge bg-primary-subtle text-primary border border-primary-subtle ms-1"
                                                        style="font-size: 0.70rem;">Base del Sistema</span>
                                                <?php else: ?>
                                                    <span
                                                        class="badge bg-success-subtle text-success border border-success-subtle ms-1"
                                                        style="font-size: 0.70rem;">Personalizado</span>
                                                <?php endif; ?>
                                            </h6>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                                aria-label="Cerrar"></button>
                                        </div>

                                        <div class="modal-body p-3" style="max-height: calc(85vh - 120px); overflow-y: auto;">
                                                <div class="mb-3">
                                                    <label class="form-label small fw-semibold mb-1">Descripción y Alcance
                                                        Institucional</label>
                                                    <input type="text" name="descripcion" class="form-control form-control-sm"
                                                        value="<?= htmlspecialchars($r['descripcion'] ?? '') ?>" required
                                                        maxlength="255">
                                                </div>

                                                <?php if ((int) $r['id'] === 1): ?>
                                                    <div class="alert alert-info py-2 px-3 small mb-3">
                                                        <i class="bi bi-info-circle me-1"></i>
                                                        El rol <strong>rol_admin</strong> es el Administrador Central de Seguridad y
                                                        posee por diseño institucional todos los permisos del sistema.
                                                    </div>
                                                <?php endif; ?>

                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <span class="small fw-bold text-dark">
                                                        <i class="bi bi-grid-3x3-gap-fill text-muted me-1"></i>Permisos
                                                        Asignados (RBAC en 3FN)
                                                    </span>
                                                    <div class="d-flex gap-2">
                                                        <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2"
                                                            style="font-size: 0.70rem;"
                                                            onclick="toggleCheckboxes('form-edit-rol-<?= (int) $r['id'] ?>', true)">Marcar
                                                            Todos</button>
                                                        <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2"
                                                            style="font-size: 0.70rem;"
                                                            onclick="toggleCheckboxes('form-edit-rol-<?= (int) $r['id'] ?>', false)">Desmarcar
                                                            Todos</button>
                                                    </div>
                                                </div>

                                                <div class="row g-2">
                                                    <?php foreach ($permisosPorModulo as $moduloNom => $perms): ?>
                                                        <div class="col-md-6">
                                                            <div class="border rounded p-2 h-100 bg-light">
                                                                <div
                                                                    class="fw-bold text-primary small border-bottom pb-1 mb-2 d-flex align-items-center gap-1">
                                                                    <i class="bi bi-folder2-open"></i>
                                                                    <?= htmlspecialchars($moduloNom) ?>
                                                                </div>
                                                                <?php foreach ($perms as $p): ?>
                                                                    <?php $checked = isset($matrizRolPermisos[(int) $r['id']][(int) $p['id']]); ?>
                                                                    <div class="form-check mb-1">
                                                                        <input class="form-check-input" type="checkbox"
                                                                            name="permisos[]" value="<?= (int) $p['id'] ?>"
                                                                            id="perm_<?= (int) $r['id'] ?>_<?= (int) $p['id'] ?>"
                                                                            <?= $checked ? 'checked' : '' ?>>
                                                                        <label class="form-check-label small"
                                                                            for="perm_<?= (int) $r['id'] ?>_<?= (int) $p['id'] ?>"
                                                                            title="<?= htmlspecialchars($p['descripcion'] ?? '') ?>">
                                                                            <span
                                                                                class="fw-semibold text-dark"><?= htmlspecialchars($p['nombre']) ?></span>
                                                                            <br><span class="code-tag"
                                                                                style="font-size: 0.65rem;"><?= htmlspecialchars($p['codigo']) ?></span>
                                                                        </label>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>

                                            <div
                                                class="modal-footer bg-light py-2 px-3 border-top d-flex justify-content-between">
                                                <button type="button" class="btn btn-outline-secondary btn-sm"
                                                    data-bs-dismiss="modal">Cancelar</button>
                                                <button type="submit" class="btn btn-primary btn-sm px-3 fw-semibold">
                                                    <i class="bi bi-check2-circle me-1"></i>Actualizar Permisos
                                                </button>
                                            </div>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <!-- Catálogo 1: Tipos de Ambulatorio -->
                        <div class="col-12 mb-4">
                            <div class="card-sicm">
                                <div class="card-header-sicm d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="fw-bold">Tipos de Centro de Salud</span>
                                        <span class="code-tag ms-2">tipos_centro_salud</span>
                                    </div>
                                </div>
                                <div class="px-3 pt-2 pb-1 text-muted small" style="font-size: 0.75rem;">
                                    Define las clasificaciones y niveles de atención disponibles para los ambulatorios de la
                                    red.
                                </div>
                                <div class="p-3 bg-light border-top border-bottom">
                                    <form action="procesar.php" method="POST" class="row g-2 align-items-end">
                                        <input type="hidden" name="csrf_token"
                                            value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="accion" value="crear_tipo_centro">
                                        <div class="col-md-4">
                                            <label class="form-label small fw-semibold mb-1">Código</label>
                                            <input type="text" name="codigo"
                                                class="form-control form-control-sm text-uppercase font-monospace"
                                                placeholder="Ej: HOSP_DIA" required maxlength="30">
                                        </div>
                                        <div class="col-md-5">
                                            <label class="form-label small fw-semibold mb-1">Nombre del Tipo</label>
                                            <input type="text" name="nombre" class="form-control form-control-sm"
                                                placeholder="Ej: Hospital de Día" required maxlength="80">
                                        </div>
                                        <div class="col-md-3">
                                            <button type="submit" class="btn btn-primary btn-sm w-100 fw-semibold">+
                                                Agregar</button>
                                        </div>
                                    </form>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover table-sm table-sicm mb-0">
                                        <thead>
                                            <tr>
                                                <th style="width: 45px;">ID</th>
                                                <th style="width: 100px;">Código</th>
                                                <th>Tipo de Ambulatorio</th>
                                                <th style="width: 110px;">Centros Vinculados</th>
                                                <th style="width: 80px;">Estado</th>
                                                <th style="width: 85px;" class="text-end pe-3">Acción</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($tiposCentro as $t): ?>
                                                <tr>
                                                    <td class="text-muted fw-bold">#<?= (int) $t['id'] ?></td>
                                                    <td><span class="code-tag"><?= htmlspecialchars($t['codigo']) ?></span></td>
                                                    <td class="fw-semibold text-dark"><?= htmlspecialchars($t['nombre']) ?></td>
                                                    <td>
                                                        <?php if ((int) $t['total_centros'] > 0): ?>
                                                            <span class="badge bg-light text-primary border"
                                                                title="Ambulatorios que tienen este tipo asignado">
                                                                <?= (int) $t['total_centros'] ?>
                                                                <?= (int) $t['total_centros'] === 1 ? 'ambulatorio' : 'ambulatorios' ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted small" style="font-size: 0.72rem;">0
                                                                centros</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ((int) $t['activo'] === 1): ?>
                                                            <span class="badge badge-status-active">Activo</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-status-inactive">Inactivo</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end pe-3">
                                                        <form method="POST" action="procesar.php" class="d-inline"
                                                            onsubmit="return confirm('¿Confirma cambiar el estado de este tipo de centro?');">
                                                            <input type="hidden" name="csrf_token"
                                                                value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="accion" value="toggle_tipo_centro">
                                                            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                                            <input type="hidden" name="estado"
                                                                value="<?= (int) $t['activo'] === 1 ? '0' : '1' ?>">
                                                            <button type="submit"
                                                                class="btn btn-outline-secondary btn-sm py-0 px-2"
                                                                style="font-size: 0.72rem;">
                                                                <?= (int) $t['activo'] === 1 ? 'Desactivar' : 'Activar' ?>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Catálogo 2: Equipamiento Médico N:M -->
                        <div class="col-12 mb-4">
                            <div class="card-sicm">
                                <div class="card-header-sicm d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="fw-bold">Equipamiento y Capacidades</span>
                                        <span class="code-tag ms-2">recursos_operativos</span>
                                    </div>
                                </div>
                                <div class="px-3 pt-2 pb-1 text-muted small" style="font-size: 0.75rem;">
                                    Registra las capacidades e instalaciones técnicas asignables a cada centro de salud
                                    (cadena de
                                    frío, energía de emergencia, ambulancias).
                                </div>
                                <div class="p-3 bg-light border-top border-bottom">
                                    <form action="procesar.php" method="POST" class="row g-2 align-items-end">
                                        <input type="hidden" name="csrf_token"
                                            value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="accion" value="crear_recurso">
                                        <div class="col-md-4">
                                            <label class="form-label small fw-semibold mb-1">Código</label>
                                            <input type="text" name="codigo"
                                                class="form-control form-control-sm text-uppercase font-monospace"
                                                placeholder="Ej: PANEL_SOLAR" required maxlength="30">
                                        </div>
                                        <div class="col-md-5">
                                            <label class="form-label small fw-semibold mb-1">Nombre del Recurso</label>
                                            <input type="text" name="nombre" class="form-control form-control-sm"
                                                placeholder="Ej: Respaldo Solar 15kVA" required maxlength="80">
                                        </div>
                                        <div class="col-md-3">
                                            <button type="submit" class="btn btn-primary btn-sm w-100 fw-semibold">+
                                                Agregar</button>
                                        </div>
                                    </form>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover table-sm table-sicm mb-0">
                                        <thead>
                                            <tr>
                                                <th style="width: 45px;">ID</th>
                                                <th style="width: 110px;">Código</th>
                                                <th>Equipamiento</th>
                                                <th style="width: 110px;">Centros Vinculados</th>
                                                <th style="width: 80px;">Estado</th>
                                                <th style="width: 85px;" class="text-end pe-3">Acción</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($todosRecursos as $r): ?>
                                                <tr>
                                                    <td class="text-muted fw-bold">#<?= (int) $r['id'] ?></td>
                                                    <td><span class="code-tag"><?= htmlspecialchars($r['codigo']) ?></span></td>
                                                    <td class="fw-semibold text-dark"><?= htmlspecialchars($r['nombre']) ?></td>
                                                    <td>
                                                        <?php if ((int) $r['total_centros'] > 0): ?>
                                                            <span class="badge bg-light text-primary border"
                                                                title="Ambulatorios con este recurso">
                                                                <?= (int) $r['total_centros'] ?>
                                                                <?= (int) $r['total_centros'] === 1 ? 'ambulatorio' : 'ambulatorios' ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted small" style="font-size: 0.72rem;">0
                                                                centros</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ((int) $r['activo'] === 1): ?>
                                                            <span class="badge badge-status-active">Activo</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-status-inactive">Inactivo</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end pe-3">
                                                        <form method="POST" action="procesar.php" class="d-inline"
                                                            onsubmit="return confirm('¿Confirma cambiar el estado de este equipamiento/recurso?');">
                                                            <input type="hidden" name="csrf_token"
                                                                value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="accion" value="toggle_recurso">
                                                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                                            <input type="hidden" name="estado"
                                                                value="<?= (int) $r['activo'] === 1 ? '0' : '1' ?>">
                                                            <button type="submit"
                                                                class="btn btn-outline-secondary btn-sm py-0 px-2"
                                                                style="font-size: 0.72rem;">
                                                                <?= (int) $r['activo'] === 1 ? 'Desactivar' : 'Activar' ?>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
<script>
    // Utilidad global para marcar/desmarcar todos los checkboxes de una matriz RBAC
    function toggleCheckboxes(formId, checkState) {
        const form = document.getElementById(formId);
        if (!form) return;
        const cbs = form.querySelectorAll('input[type="checkbox"][name="permisos[]"]');
        cbs.forEach(cb => { cb.checked = checkState; });
    }
</script>
