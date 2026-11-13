<?php
// src/views/tabs/tab_maestras.php
// SICM | Módulo Autosuficiente: Catálogos Maestros y Matriz RBAC
declare(strict_types=1);

$moduloNombre = 'Catálogos Maestros';
$seccionNombre = 'Configuración';

// Catálogos con contador de integridad (cuántos ambulatorios dependen de cada fila)
$tiposCentro = $pdo->query("
    SELECT t.id, t.codigo, t.nombre, t.descripcion, t.activo, COUNT(cs.id) AS total_centros
    FROM tipos_centro_salud t
    LEFT JOIN centros_salud cs ON cs.tipo_centro_id = t.id
    GROUP BY t.id, t.codigo, t.nombre, t.descripcion, t.activo
    ORDER BY t.id ASC
")->fetchAll();

$todosRecursos = $pdo->query("
    SELECT r.id, r.codigo, r.nombre, r.descripcion, r.activo, COUNT(cr.centro_salud_id) AS total_centros
    FROM recursos_operativos r
    LEFT JOIN centro_recursos cr ON cr.recurso_id = r.id
    GROUP BY r.id, r.codigo, r.nombre, r.descripcion, r.activo
    ORDER BY r.id ASC
")->fetchAll();

// Catálogo 3: Tipos de Documento de Identidad Oficiales (Configurables)
$tiposDocumento = $pdo->query("
    SELECT td.id, td.codigo, td.nombre, td.activo, COUNT(p.id) AS total_pacientes
    FROM tipos_documento td
    LEFT JOIN pacientes p ON p.tipo_documento_id = td.id
    GROUP BY td.id, td.codigo, td.nombre, td.activo
    ORDER BY td.id ASC
")->fetchAll();

// Catálogo 4: Atributos Farmacológicos Normalizados (3FN - N:M)
$atributosFarmacologicos = $pdo->query("
    SELECT af.id, af.codigo, af.nombre, af.descripcion, af.icono, af.color_badge, af.activo, COUNT(ma.medicamento_id) AS total_medicamentos
    FROM atributos_farmacologicos af
    LEFT JOIN medicamento_atributos ma ON ma.atributo_id = af.id
    GROUP BY af.id, af.codigo, af.nombre, af.descripcion, af.icono, af.color_badge, af.activo
    ORDER BY af.id ASC
")->fetchAll();

// Catálogo 5: Clasificaciones de Patologías (3FN)
$clasificacionesPatologia = $pdo->query("
    SELECT cp.id, cp.codigo, cp.nombre, cp.descripcion, cp.es_recurrente_mensual, cp.activo, COUNT(p.id) AS total_patologias
    FROM clasificaciones_patologia cp
    LEFT JOIN patologias p ON p.clasificacion_id = cp.id
    GROUP BY cp.id, cp.codigo, cp.nombre, cp.descripcion, cp.es_recurrente_mensual, cp.activo
    ORDER BY cp.id ASC
")->fetchAll();


// Gestión Dinámica de Roles RBAC y Matriz de Permisos en 3FN
$todosRoles = $pdo->query("
    SELECT r.id, r.nombre, r.es_personalizado, r.descripcion, 
           COUNT(DISTINCT u.id) AS total_usuarios, 
           COUNT(DISTINCT rp.permiso_id) AS total_permisos 
    FROM roles r 
    LEFT JOIN usuarios u ON u.rol_id = r.id 
    LEFT JOIN rol_permisos rp ON rp.rol_id = r.id 
    GROUP BY r.id, r.nombre, r.es_personalizado, r.descripcion 
    ORDER BY r.id ASC
")->fetchAll();

$permisosCatalogo = $pdo->query("
    SELECT p.id, p.codigo, p.nombre, p.descripcion, m.id AS modulo_id, m.nombre AS modulo_nombre 
    FROM permisos p 
    JOIN modulos_sistema m ON p.modulo_id = m.id 
    ORDER BY m.id ASC, p.id ASC
")->fetchAll();

$rolPermisosRaw = $pdo->query("SELECT rol_id, permiso_id FROM rol_permisos")->fetchAll();
$matrizRolPermisos = [];
foreach ($rolPermisosRaw as $rp) {
    $matrizRolPermisos[(int) $rp['rol_id']][(int) $rp['permiso_id']] = true;
}

$permisosPorModulo = [];
foreach ($permisosCatalogo as $p) {
    $permisosPorModulo[$p['modulo_nombre']][] = $p;
}
?>
<div class="card bg-light border mb-3">
                        <div class="card-body p-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <strong class="text-dark fs-6">Configuración y Catálogos Maestros</strong>
                                </div>
                                <p class="text-muted small mb-0">
                                    Gestión y parametrización de catálogos maestros del sistema. Permite incorporar y
                                    mantener las clasificaciones de ambulatorios y el equipamiento operativo de la red de salud municipal.
                                </p>
                            </div>
                            <a href="index.php?tab=centros"
                                class="btn btn-outline-primary btn-sm py-1 px-3 text-nowrap">Volver a Ambulatorios</a>
                        </div>
                    </div>

                    <!-- Estilos para Navegación Rápida y Scroll Offset -->
                    <style>
                        .sec-catalogo {
                            scroll-margin-top: 125px;
                        }
                        #nav-catalogos-maestros .nav-link {
                            font-size: 0.78rem;
                            color: #495057;
                            background-color: #f8f9fa;
                            border: 1px solid #e2e8f0;
                            border-radius: 6px;
                            white-space: nowrap;
                            transition: all 0.15s ease-in-out;
                        }
                        #nav-catalogos-maestros .nav-link:hover {
                            background-color: #e2e8f0;
                            color: #0d6efd;
                            border-color: #cbd5e1;
                        }
                        #nav-catalogos-maestros .nav-link.active {
                            background-color: #0d6efd;
                            color: #ffffff;
                            border-color: #0d6efd;
                            font-weight: 600;
                        }
                    </style>

                    <!-- Barra de Navegación Rápida por Catálogos -->
                    <div class="card border mb-3 sticky-top shadow-sm" style="top: 54px; z-index: 1000; background-color: rgba(255, 255, 255, 0.98); backdrop-filter: blur(6px);">
                        <div class="card-body py-2 px-3">
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <span class="small fw-semibold text-secondary text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                                    <i class="bi bi-compass me-1"></i>Navegación Rápida:
                                </span>
                                <ul class="nav nav-pills gap-1 flex-nowrap overflow-x-auto" id="nav-catalogos-maestros">
                                    <li class="nav-item">
                                        <a class="nav-link py-1 px-2.5 small fw-medium active" href="#sec-roles">
                                            <i class="bi bi-shield-lock me-1"></i>Roles y Permisos
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link py-1 px-2.5 small fw-medium" href="#sec-tipos-centro">
                                            <i class="bi bi-hospital me-1"></i>Tipos de Centro
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link py-1 px-2.5 small fw-medium" href="#sec-equipamiento">
                                            <i class="bi bi-tools me-1"></i>Equipamiento
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link py-1 px-2.5 small fw-medium" href="#sec-documentos">
                                            <i class="bi bi-card-heading me-1"></i>Documentos de Identidad
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link py-1 px-2.5 small fw-medium" href="#sec-atributos">
                                            <i class="bi bi-tags me-1"></i>Atributos Farmacológicos
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link py-1 px-2.5 small fw-medium" href="#sec-patologias">
                                            <i class="bi bi-clipboard2-pulse me-1"></i>Clasificaciones de Patologías
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <!-- ========================================================== -->
                        <!-- ROLES OPERATIVOS Y MATRIZ DE PERMISOS RBAC (PARAMETRIZABLE) -->
                        <!-- ========================================================== -->
                        <div class="col-12 mb-4 sec-catalogo" id="sec-roles">
                            <div class="card-sicm">
                                <div
                                    class="card-header-sicm d-flex flex-wrap justify-content-between align-items-center gap-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="fw-bold">Roles Operativos y Matriz de Permisos</span>
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle small"><?= count($todosRoles) ?> roles definidos</span>
                                    </div>
                                    <button class="btn btn-primary btn-sm py-1 px-3 fw-semibold" type="button"
                                        data-bs-toggle="collapse" data-bs-target="#collapseNuevoRol" aria-expanded="false"
                                        aria-controls="collapseNuevoRol">
                                        <i class="bi bi-shield-plus me-1"></i>+ Registrar Nuevo Rol Personalizado
                                    </button>
                                </div>
                                <div class="px-3 pt-2 pb-1 text-muted small" style="font-size: 0.75rem;">
                                    Administración de perfiles institucionales y asignación de permisos para los operadores de la red de salud municipal.
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
                                                    Permisos por Módulo (<?= count($permisosCatalogo) ?> Permisos Disponibles)
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
                                                        <i class="bi bi-grid-3x3-gap-fill text-muted me-1"></i>Permisos Asignados al Perfil
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
                        <div class="col-12 mb-4 sec-catalogo" id="sec-tipos-centro">
                            <div class="card-sicm">
                                <div class="card-header-sicm d-flex justify-content-between align-items-center">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="fw-bold">Tipos de Centro de Salud</span>
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle small"><?= count($tiposCentro) ?> tipos</span>
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
                        <div class="col-12 mb-4 sec-catalogo" id="sec-equipamiento">
                            <div class="card-sicm">
                                <div class="card-header-sicm d-flex justify-content-between align-items-center">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="fw-bold">Equipamiento y Capacidades</span>
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle small"><?= count($todosRecursos) ?> capacidades</span>
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

                        <!-- Catálogo 3: Tipos de Documento de Identidad Oficiales -->
                        <div class="col-12 mb-4 sec-catalogo" id="sec-documentos">
                            <div class="card-sicm">
                                <div class="card-header-sicm d-flex justify-content-between align-items-center">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="fw-bold">Tipos de Documento de Identidad</span>
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle small"><?= count($tiposDocumento) ?> tipos</span>
                                    </div>
                                </div>
                                <div class="px-3 pt-2 pb-1 text-muted small" style="font-size: 0.75rem;">
                                    Catálogo institucional configurable para los documentos de identidad admitidos en el padrón de pacientes (V, E, P, DNI, RUT, Cédula Extranjera).
                                </div>
                                <div class="p-3 bg-light border-top border-bottom">
                                    <form action="procesar.php" method="POST" class="row g-2 align-items-end">
                                        <input type="hidden" name="csrf_token"
                                            value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="accion" value="crear_tipo_documento">
                                        <div class="col-md-3">
                                            <label class="form-label small fw-semibold mb-1">Código Oficial</label>
                                            <input type="text" name="codigo"
                                                class="form-control form-control-sm text-uppercase font-monospace"
                                                placeholder="Ej: DNI, RUT" required maxlength="10">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold mb-1">Denominación del Documento</label>
                                            <input type="text" name="nombre" class="form-control form-control-sm"
                                                placeholder="Ej: Documento Nacional de Identidad" required maxlength="40">
                                        </div>
                                        <div class="col-md-3">
                                            <button type="submit" class="btn btn-primary btn-sm w-100 fw-semibold">
                                                + Registrar Tipo
                                            </button>
                                        </div>
                                    </form>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover table-sm table-sicm mb-0">
                                        <thead>
                                            <tr>
                                                <th style="width: 45px;">ID</th>
                                                <th style="width: 90px;">Código</th>
                                                <th>Denominación Oficial</th>
                                                <th style="width: 140px;">Pacientes Registrados</th>
                                                <th style="width: 80px;">Estado</th>
                                                <th style="width: 140px;" class="text-end pe-3">Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($tiposDocumento as $td): ?>
                                                <tr>
                                                    <td class="text-muted fw-bold">#<?= (int) $td['id'] ?></td>
                                                    <td><span class="badge bg-light text-dark border font-monospace"><?= htmlspecialchars($td['codigo']) ?></span></td>
                                                    <td class="fw-semibold text-dark"><?= htmlspecialchars($td['nombre']) ?></td>
                                                    <td>
                                                        <?php if ((int) $td['total_pacientes'] > 0): ?>
                                                            <span class="badge bg-light text-primary border"
                                                                title="Beneficiarios empadronados con este tipo de documento">
                                                                <?= (int) $td['total_pacientes'] ?> <?= (int) $td['total_pacientes'] === 1 ? 'paciente' : 'pacientes' ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted small" style="font-size: 0.72rem;">0 pacientes</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ((int) $td['activo'] === 1): ?>
                                                            <span class="badge badge-status-active">Activo</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-status-inactive">Inactivo</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end pe-3">
                                                        <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2 me-1"
                                                            style="font-size: 0.72rem;" data-bs-toggle="modal"
                                                            data-bs-target="#modalEditDoc<?= (int) $td['id'] ?>">
                                                            <i class="bi bi-pencil me-1"></i>Editar
                                                        </button>
                                                        <form method="POST" action="procesar.php" class="d-inline"
                                                            onsubmit="return confirm('¿Confirma cambiar el estado de este tipo de documento?');">
                                                            <input type="hidden" name="csrf_token"
                                                                value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="accion" value="toggle_tipo_documento">
                                                            <input type="hidden" name="id" value="<?= (int) $td['id'] ?>">
                                                            <input type="hidden" name="estado"
                                                                value="<?= (int) $td['activo'] === 1 ? '0' : '1' ?>">
                                                            <button type="submit"
                                                                class="btn btn-outline-secondary btn-sm py-0 px-2"
                                                                style="font-size: 0.72rem;">
                                                                <?= (int) $td['activo'] === 1 ? 'Desactivar' : 'Activar' ?>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>

                                                <!-- Modal Edición Tipo Documento -->
                                                <div class="modal fade" id="modalEditDoc<?= (int) $td['id'] ?>" tabindex="-1"
                                                    aria-hidden="true">
                                                    <div class="modal-dialog modal-dialog-centered">
                                                        <div class="modal-content border-0 shadow">
                                                            <form action="procesar.php" method="POST">
                                                                <input type="hidden" name="csrf_token"
                                                                    value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                                <input type="hidden" name="accion" value="editar_tipo_documento">
                                                                <input type="hidden" name="id" value="<?= (int) $td['id'] ?>">
                                                                <div class="modal-header bg-light py-2 px-3 border-bottom">
                                                                    <h6 class="modal-title fw-bold">Editar Tipo de Documento #<?= (int) $td['id'] ?></h6>
                                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                </div>
                                                                <div class="modal-body p-3">
                                                                    <div class="mb-3">
                                                                        <label class="form-label small fw-semibold mb-1">Código Oficial *</label>
                                                                        <input type="text" name="codigo" class="form-control form-control-sm text-uppercase font-monospace"
                                                                            value="<?= htmlspecialchars($td['codigo']) ?>" required maxlength="10">
                                                                    </div>
                                                                    <div class="mb-2">
                                                                        <label class="form-label small fw-semibold mb-1">Denominación *</label>
                                                                        <input type="text" name="nombre" class="form-control form-control-sm"
                                                                            value="<?= htmlspecialchars($td['nombre']) ?>" required maxlength="40">
                                                                    </div>
                                                                </div>
                                                                <div class="modal-footer bg-light py-2 px-3 border-top d-flex justify-content-between">
                                                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                                                                    <button type="submit" class="btn btn-primary btn-sm px-3 fw-semibold">Guardar Cambios</button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Catálogo 4: Atributos Farmacológicos -->
                        <div class="col-12 mb-4 sec-catalogo" id="sec-atributos">
                            <div class="card-sicm">
                                <div class="card-header-sicm d-flex justify-content-between align-items-center">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="fw-bold">Atributos Farmacológicos</span>
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle small"><?= count($atributosFarmacologicos) ?> atributos</span>
                                    </div>
                                </div>
                                <div class="px-3 pt-2 pb-1 text-muted small" style="font-size: 0.75rem;">
                                    Catálogo de condiciones técnicas de almacenamiento, custodia legal y manejo clínico de medicamentos en relación N:M.
                                </div>
                                <div class="p-3 bg-light border-top border-bottom">
                                    <form action="procesar.php" method="POST" class="row g-2 align-items-end">
                                        <input type="hidden" name="csrf_token"
                                            value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="accion" value="crear_atributo_farmacologico">
                                        <div class="col-md-3">
                                            <label class="form-label small fw-semibold mb-1">Código Único</label>
                                            <input type="text" name="codigo"
                                                class="form-control form-control-sm text-uppercase font-monospace"
                                                placeholder="Ej: FOTOSENSIBLE" required maxlength="30">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small fw-semibold mb-1">Denominación del Atributo</label>
                                            <input type="text" name="nombre" class="form-control form-control-sm"
                                                placeholder="Ej: Proteger de la Luz Solar" required maxlength="80">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label small fw-semibold mb-1">Color / Distintivo</label>
                                            <select name="color_badge" class="form-select form-select-sm">
                                                <option value="primary">Azul (Institucional)</option>
                                                <option value="info">Celeste (Cadena de Frío)</option>
                                                <option value="warning">Ámbar (Precaución)</option>
                                                <option value="danger">Rojo (Control Estricto)</option>
                                                <option value="dark">Oscuro (Reserva)</option>
                                                <option value="secondary">Gris (Genérico)</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <button type="submit" class="btn btn-primary btn-sm w-100 fw-semibold">
                                                + Agregar
                                            </button>
                                        </div>
                                        <div class="col-12 mt-2">
                                            <label class="form-label small fw-semibold mb-1">Descripción / Norma Técnica</label>
                                            <input type="text" name="descripcion" class="form-control form-control-sm"
                                                placeholder="Ej: Almacenar en envase opaco protegido de fuentes de radiación solar directa" maxlength="255">
                                        </div>
                                    </form>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover table-sm table-sicm mb-0">
                                        <thead>
                                            <tr>
                                                <th style="width: 45px;">ID</th>
                                                <th style="width: 130px;">Código</th>
                                                <th>Atributo Clínico</th>
                                                <th style="width: 140px;">Distintivo Visual</th>
                                                <th style="width: 130px;">Fármacos Vinculados</th>
                                                <th style="width: 80px;">Estado</th>
                                                <th style="width: 85px;" class="text-end pe-3">Acción</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($atributosFarmacologicos as $af): ?>
                                                <tr>
                                                    <td class="text-muted fw-bold">#<?= (int) $af['id'] ?></td>
                                                    <td><span class="code-tag"><?= htmlspecialchars($af['codigo']) ?></span></td>
                                                    <td>
                                                        <div class="fw-semibold text-dark"><?= htmlspecialchars($af['nombre']) ?></div>
                                                        <?php if (!empty($af['descripcion'])): ?>
                                                            <div class="text-muted small" style="font-size: 0.70rem;"><?= htmlspecialchars($af['descripcion']) ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?= htmlspecialchars($af['color_badge']) ?>-subtle text-<?= htmlspecialchars($af['color_badge']) ?> border border-<?= htmlspecialchars($af['color_badge']) ?>-subtle">
                                                            <i class="bi <?= htmlspecialchars($af['icono']) ?> me-1"></i><?= htmlspecialchars($af['nombre']) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if ((int) $af['total_medicamentos'] > 0): ?>
                                                            <span class="badge bg-light text-primary border"
                                                                title="Medicamentos con este atributo asignado">
                                                                <?= (int) $af['total_medicamentos'] ?> <?= (int) $af['total_medicamentos'] === 1 ? 'fármaco' : 'fármacos' ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted small" style="font-size: 0.72rem;">0 fármacos</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ((int) $af['activo'] === 1): ?>
                                                            <span class="badge badge-status-active">Activo</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-status-inactive">Inactivo</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end pe-3">
                                                        <form method="POST" action="procesar.php" class="d-inline"
                                                            onsubmit="return confirm('¿Confirma cambiar el estado de este atributo farmacológico?');">
                                                            <input type="hidden" name="csrf_token"
                                                                value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="accion" value="toggle_atributo_farmacologico">
                                                            <input type="hidden" name="id" value="<?= (int) $af['id'] ?>">
                                                            <input type="hidden" name="estado"
                                                                value="<?= (int) $af['activo'] === 1 ? '0' : '1' ?>">
                                                            <button type="submit"
                                                                class="btn btn-outline-secondary btn-sm py-0 px-2"
                                                                style="font-size: 0.72rem;">
                                                                <?= (int) $af['activo'] === 1 ? 'Desactivar' : 'Activar' ?>
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

                        <!-- Catálogo 5: Clasificaciones de Patologías -->
                        <div class="col-12 mb-4 sec-catalogo" id="sec-patologias">
                            <div class="card-sicm">
                                <div class="card-header-sicm d-flex justify-content-between align-items-center">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="fw-bold">Clasificaciones de Patologías</span>
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle small"><?= count($clasificacionesPatologia) ?> clasificaciones</span>
                                    </div>
                                </div>
                                <div class="px-3 pt-2 pb-1 text-muted small" style="font-size: 0.75rem;">
                                    Categorización clínica de diagnósticos nosológicos y criterio de elegibilidad para dotaciones recurrentes de récipe médico.
                                </div>
                                <div class="p-3 bg-light border-top border-bottom">
                                    <form action="procesar.php" method="POST" class="row g-2 align-items-end">
                                        <input type="hidden" name="csrf_token"
                                            value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="accion" value="crear_clasificacion_patologia">
                                        <div class="col-md-3">
                                            <label class="form-label small fw-semibold mb-1">Código Único</label>
                                            <input type="text" name="codigo"
                                                class="form-control form-control-sm text-uppercase font-monospace"
                                                placeholder="Ej: EPIDEMIOLOGICA" required maxlength="30">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small fw-semibold mb-1">Denominación</label>
                                            <input type="text" name="nombre" class="form-control form-control-sm"
                                                placeholder="Ej: Vigilancia Epidemiológica" required maxlength="80">
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-check pt-2">
                                                <input class="form-check-input" type="checkbox" name="es_recurrente_mensual" value="1" id="checkRecurrente">
                                                <label class="form-check-label small fw-semibold text-dark" for="checkRecurrente">
                                                    Dotación Mensual Continua
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <button type="submit" class="btn btn-primary btn-sm w-100 fw-semibold">
                                                + Agregar
                                            </button>
                                        </div>
                                        <div class="col-12 mt-2">
                                            <label class="form-label small fw-semibold mb-1">Descripción Clínica</label>
                                            <input type="text" name="descripcion" class="form-control form-control-sm"
                                                placeholder="Ej: Patología sujeta a cerco epidemiológico y seguimiento estricto de casos" maxlength="255">
                                        </div>
                                    </form>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover table-sm table-sicm mb-0">
                                        <thead>
                                            <tr>
                                                <th style="width: 45px;">ID</th>
                                                <th style="width: 120px;">Código</th>
                                                <th>Clasificación Diagnóstica</th>
                                                <th style="width: 130px;">Dotación Continua</th>
                                                <th style="width: 130px;">Patologías Asociadas</th>
                                                <th style="width: 80px;">Estado</th>
                                                <th style="width: 85px;" class="text-end pe-3">Acción</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($clasificacionesPatologia as $cp): ?>
                                                <tr>
                                                    <td class="text-muted fw-bold">#<?= (int) $cp['id'] ?></td>
                                                    <td><span class="code-tag"><?= htmlspecialchars($cp['codigo']) ?></span></td>
                                                    <td>
                                                        <div class="fw-semibold text-dark"><?= htmlspecialchars($cp['nombre']) ?></div>
                                                        <?php if (!empty($cp['descripcion'])): ?>
                                                            <div class="text-muted small" style="font-size: 0.70rem;"><?= htmlspecialchars($cp['descripcion']) ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ((int) $cp['es_recurrente_mensual'] === 1): ?>
                                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                                                <i class="bi bi-arrow-repeat me-1"></i>Recurrente
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                                                                Tratamiento Puntual
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ((int) $cp['total_patologias'] > 0): ?>
                                                            <span class="badge bg-light text-primary border"
                                                                title="Patologías con esta clasificación">
                                                                <?= (int) $cp['total_patologias'] ?> <?= (int) $cp['total_patologias'] === 1 ? 'patología' : 'patologías' ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted small" style="font-size: 0.72rem;">0 patologías</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ((int) $cp['activo'] === 1): ?>
                                                            <span class="badge badge-status-active">Activo</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-status-inactive">Inactivo</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end pe-3">
                                                        <form method="POST" action="procesar.php" class="d-inline"
                                                            onsubmit="return confirm('¿Confirma cambiar el estado de esta clasificación?');">
                                                            <input type="hidden" name="csrf_token"
                                                                value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="accion" value="toggle_clasificacion_patologia">
                                                            <input type="hidden" name="id" value="<?= (int) $cp['id'] ?>">
                                                            <input type="hidden" name="estado"
                                                                value="<?= (int) $cp['activo'] === 1 ? '0' : '1' ?>">
                                                            <button type="submit"
                                                                class="btn btn-outline-secondary btn-sm py-0 px-2"
                                                                style="font-size: 0.72rem;">
                                                                <?= (int) $cp['activo'] === 1 ? 'Desactivar' : 'Activar' ?>
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
<script src="public/js/permisos_matrix.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const navLinks = document.querySelectorAll('#nav-catalogos-maestros .nav-link');
    navLinks.forEach(link => {
        link.addEventListener('click', function () {
            navLinks.forEach(l => l.classList.remove('active'));
            this.classList.add('active');
        });
    });

    // Sincronizar pill activo al hacer scroll
    const secciones = document.querySelectorAll('.sec-catalogo');
    if ('IntersectionObserver' in window && secciones.length > 0) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const id = entry.target.getAttribute('id');
                    navLinks.forEach(link => {
                        if (link.getAttribute('href') === '#' + id) {
                            navLinks.forEach(l => l.classList.remove('active'));
                            link.classList.add('active');
                        }
                    });
                }
            });
        }, {
            rootMargin: '-100px 0px -70% 0px',
            threshold: 0
        });

        secciones.forEach(sec => observer.observe(sec));
    }
});
</script>
