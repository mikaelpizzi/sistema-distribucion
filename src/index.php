<?php
// src/index.php
// SICM | Sistema Integrado de Control de Medicamentos - Red Municipal de Salud
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/auth.php';
require_once __DIR__ . '/helpers/auditoria.php';

$pdo = getDB();
$usuario = requerirAutenticacion($pdo);
$esAdmin = ((int)$usuario['rol_id'] === 1);
$centroUsuarioId = $usuario['centro_salud_id'];

$tabActiva    = $_GET['tab'] ?? 'centros';
$mensajeExito = $_GET['msg'] ?? null;
$mensajeError = $_GET['error'] ?? null;

// Protección RBAC: El Farmaceuta solo tiene acceso a la pestaña de su ambulatorio
if (!$esAdmin && in_array($tabActiva, ['maestras', 'usuarios', 'auditoria'], true)) {
    header('Location: index.php?tab=centros');
    exit;
}

// ============================================================================
// CARGA CONDICIONAL DE DATOS SEGÚN LA PESTAÑA ACTIVA
// ============================================================================

if ($tabActiva === 'centros') {
    $filtroEstado = $_GET['estado'] ?? 'activos';
    $busqueda     = trim($_GET['q'] ?? '');
    $condiciones  = [];
    $params       = [];

    // Política de Lectura: El farmaceuta solo ve su ambulatorio asignado
    if (!$esAdmin) {
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
    $stmtRA = $pdo->query("SELECT cr.centro_salud_id, ro.nombre
                           FROM centro_recursos cr
                           INNER JOIN recursos_operativos ro ON ro.id = cr.recurso_id
                           WHERE cr.operativo = 1 ORDER BY ro.id ASC");
    $recursosPorCentro = [];
    while ($r = $stmtRA->fetch()) {
        $recursosPorCentro[(int)$r['centro_salud_id']][] = $r['nombre'];
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

    // Historial de cambios por ambulatorio
    $logsPorCentro = [];
    if (!empty($centros)) {
        $ids = implode(',', array_map('intval', array_column($centros, 'id')));
        $stmtCtx = $pdo->query("SELECT log_id, fecha_hora, operador, rol_operador, accion, registro_id, detalles_json, ip_origen, user_agent
                                FROM vw_logs_auditoria
                                WHERE tabla_afectada = 'centros_salud' AND registro_id IN ({$ids})
                                ORDER BY log_id DESC");
        while ($l = $stmtCtx->fetch()) {
            $logsPorCentro[(int)$l['registro_id']][] = $l;
        }
    }

} elseif ($tabActiva === 'maestras') {
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

} elseif ($tabActiva === 'usuarios') {
    if (!$esAdmin) {
        header('Location: index.php?tab=centros');
        exit;
    }

    $filtroEstadoU = $_GET['estado_u'] ?? 'activos';
    $busquedaU     = trim($_GET['q_u'] ?? '');
    $condU         = [];
    $paramsU       = [];

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
    $rolesCatalogo = $pdo->query("SELECT id, nombre FROM roles ORDER BY id ASC")->fetchAll();
    $centrosDisponibles = $pdo->query("SELECT id, codigo_sicm, nombre FROM centros_salud WHERE activo = 1 ORDER BY nombre ASC")->fetchAll();

    // Modo Edición
    $usuarioEdicion = null;
    $editarUId = filter_input(INPUT_GET, 'editar_u', FILTER_VALIDATE_INT);
    if ($editarUId) {
        $stmtUEd = $pdo->prepare("SELECT id, nombre, email, rol_id, centro_salud_id, activo FROM usuarios WHERE id = :id");
        $stmtUEd->execute([':id' => $editarUId]);
        $usuarioEdicion = $stmtUEd->fetch();
    }

} elseif ($tabActiva === 'auditoria') {
    $filtroAccion = $_GET['accion_filtro'] ?? 'todas';
    $filtroTabla  = $_GET['tabla_filtro'] ?? 'todas';
    $busquedaLog  = trim($_GET['q_log'] ?? '');
    $condLogs     = [];
    $paramsLogs   = [];

    if ($filtroAccion !== 'todas' && $filtroAccion !== '') {
        $condLogs[] = 'l.accion = :acc';
        $paramsLogs[':acc'] = $filtroAccion;
    }
    if ($filtroTabla !== 'todas' && $filtroTabla !== '') {
        $condLogs[] = 'l.tabla_afectada = :tbl';
        $paramsLogs[':tbl'] = $filtroTabla;
    }
    if ($busquedaLog !== '') {
        $condLogs[] = '(l.accion LIKE :ql1 OR l.tabla_afectada LIKE :ql2 OR l.ip_origen LIKE :ql3 OR l.operador LIKE :ql4 OR CAST(l.registro_id AS CHAR) = :ql5 OR l.detalles_json LIKE :ql6)';
        $paramsLogs[':ql1'] = '%' . $busquedaLog . '%';
        $paramsLogs[':ql2'] = '%' . $busquedaLog . '%';
        $paramsLogs[':ql3'] = '%' . $busquedaLog . '%';
        $paramsLogs[':ql4'] = '%' . $busquedaLog . '%';
        $paramsLogs[':ql5'] = $busquedaLog;
        $paramsLogs[':ql6'] = '%' . $busquedaLog . '%';
    }

    $whereLogs = !empty($condLogs) ? 'WHERE ' . implode(' AND ', $condLogs) : '';

    // Paginación en Servidor (Protección de Memoria y B+ Tree)
    $paginaActual = max(1, (int)($_GET['p'] ?? 1));
    $porPagina    = 25;

    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM vw_logs_auditoria l {$whereLogs}");
    $stmtCount->execute($paramsLogs);
    $totalFiltrados = (int)$stmtCount->fetchColumn();
    $totalPaginas   = max(1, (int)ceil($totalFiltrados / $porPagina));

    if ($paginaActual > $totalPaginas) {
        $paginaActual = $totalPaginas;
    }
    $offset = ($paginaActual - 1) * $porPagina;

    $stmtLogs = $pdo->prepare("SELECT l.log_id, l.fecha_hora, l.operador, l.rol_operador, l.email_operador,
                                      l.accion, l.tabla_afectada, l.registro_id, l.ip_origen, l.user_agent, l.detalles_json
                               FROM vw_logs_auditoria l
                               {$whereLogs}
                               ORDER BY l.log_id DESC LIMIT :lim OFFSET :off");
    foreach ($paramsLogs as $k => $v) {
        $stmtLogs->bindValue($k, $v);
    }
    $stmtLogs->bindValue(':lim', $porPagina, PDO::PARAM_INT);
    $stmtLogs->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmtLogs->execute();
    $logs = $stmtLogs->fetchAll();

    $accionesUnicas = $pdo->query("SELECT DISTINCT accion FROM logs_auditoria ORDER BY accion ASC")->fetchAll(PDO::FETCH_COLUMN);
    $tablasUnicas   = $pdo->query("SELECT DISTINCT tabla_afectada FROM logs_auditoria ORDER BY tabla_afectada ASC")->fetchAll(PDO::FETCH_COLUMN);
    $totalLogsCount = $pdo->query("SELECT COUNT(*) FROM logs_auditoria")->fetchColumn();

    // Diccionarios de nombres para resolución inmediata en UI (incluso para logs antiguos)
    $nombresCentros  = $pdo->query("SELECT id, nombre, codigo_sicm FROM centros_salud")->fetchAll(PDO::FETCH_UNIQUE);
    $nombresRecursos = $pdo->query("SELECT id, nombre, codigo FROM recursos_operativos")->fetchAll(PDO::FETCH_UNIQUE);
    $nombresTipos    = $pdo->query("SELECT id, nombre, codigo FROM tipos_centro_salud")->fetchAll(PDO::FETCH_UNIQUE);

    $kpisLogs = $pdo->query("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN accion LIKE 'CREAR%' OR accion LIKE '%REGISTRAR%' THEN 1 ELSE 0 END) AS total_creaciones,
            SUM(CASE WHEN accion LIKE 'EDITAR%' OR accion LIKE 'CONMUTAR%' THEN 1 ELSE 0 END) AS total_ediciones,
            SUM(CASE WHEN accion LIKE 'DESACTIVAR%' THEN 1 ELSE 0 END) AS total_bajas
        FROM logs_auditoria
    ")->fetch();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema Integrado de Control de Medicamentos (SICM)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="public/css/app.css" rel="stylesheet">
</head>
<body>

    <!-- Encabezado Institucional Responsivo -->
    <header class="navbar navbar-expand-lg navbar-dark header-sicm">
        <div class="container-fluid px-2 px-md-3">
            <a class="navbar-brand brand-title d-flex align-items-center text-white text-decoration-none me-3" href="index.php">
                <span class="d-none d-sm-inline">Sistema Integrado de Control de Medicamentos (SICM)</span>
                <span class="d-inline d-sm-none fw-bold">SICM &bull; Salud Municipal</span>
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSicm" aria-controls="navbarSicm" aria-expanded="false" aria-label="Navegación">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarSicm">
                <ul class="navbar-nav nav-sicm me-auto my-2 my-lg-0 gap-1">
                    <li class="nav-item">
                        <a href="index.php?tab=centros" class="nav-link-sicm <?= $tabActiva === 'centros' ? 'active' : '' ?>">
                            <?= $esAdmin ? 'Ambulatorios' : 'Mi Ambulatorio' ?>
                        </a>
                    </li>
                    <?php if ($esAdmin): ?>
                        <li class="nav-item">
                            <a href="index.php?tab=maestras" class="nav-link-sicm <?= $tabActiva === 'maestras' ? 'active' : '' ?>">
                                Configuración
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="index.php?tab=usuarios" class="nav-link-sicm <?= $tabActiva === 'usuarios' ? 'active' : '' ?>">
                                Operadores
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="index.php?tab=auditoria" class="nav-link-sicm <?= $tabActiva === 'auditoria' ? 'active' : '' ?>">
                                Auditoría
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>

                <div class="header-user-block d-flex flex-column flex-lg-row align-items-stretch align-items-lg-center gap-2 pt-2 pt-lg-0 mt-2 mt-lg-0 border-top border-secondary-subtle">
                    <?php if ($esAdmin): ?>
                        <a href="procesar.php?accion=descargar_respaldo" class="btn btn-outline-light btn-sm py-1 px-2.5 text-uppercase text-center" style="font-size: 0.72rem; font-weight: 600; letter-spacing: 0.04em;" title="Descargar snapshot caliente comprimido (.sql.gz)">
                            CREAR RESPALDO
                        </a>
                    <?php endif; ?>
                    <div class="small d-flex align-items-center text-white py-1 py-lg-0 text-truncate" style="font-size: 0.8rem; max-width: 100%;">
                        <?php if ($esAdmin): ?>
                            <span class="text-white fw-medium"><?= htmlspecialchars($usuario['nombre']) ?></span>
                            <span class="text-white-50 ms-1.5" style="font-size: 0.74rem;">(<?= htmlspecialchars($usuario['email']) ?>)</span>
                        <?php else: ?>
                            <span class="text-white fw-medium"><?= htmlspecialchars($usuario['nombre']) ?></span>
                            <span class="text-white-50 ms-1.5" style="font-size: 0.74rem;">(<?= htmlspecialchars($usuario['email']) ?> &bull; <?= htmlspecialchars($usuario['centro_nombre'] ?? 'Farmacia') ?>)</span>
                        <?php endif; ?>
                    </div>
                    <a href="logout.php" class="btn btn-outline-danger btn-sm py-1 px-2.5 text-center" style="font-size: 0.75rem;" title="Cerrar sesión de forma segura">
                        Salir
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="container-fluid px-3 py-3">

        <!-- Notificaciones de Éxito / Error -->
        <?php if ($mensajeExito): ?>
            <div class="alert alert-success alert-dismissible fade show py-2 mb-3 shadow-sm" role="alert">
                <?= htmlspecialchars($mensajeExito) ?>
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($mensajeError): ?>
            <div class="alert alert-danger alert-dismissible fade show py-2 mb-3 shadow-sm" role="alert">
                <?= htmlspecialchars($mensajeError) ?>
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div id="alerta-js" class="d-none mb-3"></div>

        <!-- ==================================================================== -->
        <!-- PESTAÑA 1: AMBULATORIOS                                              -->
        <!-- ==================================================================== -->
        <?php if ($tabActiva === 'centros'): ?>
            <div class="row g-3">
                <!-- Formulario (Admin) o Ficha Jurisdiccional (Farmaceuta) -->
                <div class="col-xl-4 col-lg-5">
                    <?php if ($esAdmin): ?>
                        <div class="card-sicm">
                            <div class="card-header-sicm d-flex justify-content-between align-items-center">
                                <span><?= $centroEdicion ? 'Editar Ambulatorio #' . (int)$centroEdicion['id'] : 'Nuevo Ambulatorio' ?></span>
                                <?php if ($centroEdicion): ?>
                                    <a href="index.php?tab=centros" class="small text-decoration-none text-muted">Cancelar edición</a>
                                <?php endif; ?>
                            </div>
                            <div class="p-3">
                                <form id="form-centro" action="procesar.php" method="POST" novalidate>
                                    <input type="hidden" name="accion" value="guardar_centro">
                                    <input type="hidden" name="id" value="<?= $centroEdicion ? (int)$centroEdicion['id'] : '' ?>">

                                    <div class="mb-2">
                                        <label for="codigo_sicm" class="form-label small fw-semibold mb-1">Código Sanitario SICM</label>
                                        <input type="text" class="form-control form-control-sm font-monospace text-uppercase"
                                            id="codigo_sicm" name="codigo_sicm" placeholder="Ej: AMB-MER-004" required maxlength="30"
                                            value="<?= $centroEdicion ? htmlspecialchars($centroEdicion['codigo_sicm']) : '' ?>">
                                    </div>

                                    <div class="mb-2">
                                        <label for="nombre" class="form-label small fw-semibold mb-1">Nombre del Ambulatorio</label>
                                        <input type="text" class="form-control form-control-sm" id="nombre" name="nombre"
                                            placeholder="Ej: Ambulatorio Urbano Los Curos" required maxlength="120"
                                            value="<?= $centroEdicion ? htmlspecialchars($centroEdicion['nombre']) : '' ?>">
                                    </div>

                                    <div class="mb-2">
                                        <label for="tipo_centro_id" class="form-label small fw-semibold mb-1">Clasificación Asistencial</label>
                                        <select class="form-select form-select-sm" id="tipo_centro_id" name="tipo_centro_id" required>
                                            <option value="">-- Seleccionar --</option>
                                            <?php foreach ($tiposCentro as $t): ?>
                                                <?php $sel = ($centroEdicion && (int)$centroEdicion['tipo_centro_id'] === (int)$t['id']) ? 'selected' : ''; ?>
                                                <option value="<?= (int)$t['id'] ?>" <?= $sel ?>><?= htmlspecialchars($t['nombre']) ?> (<?= htmlspecialchars($t['codigo']) ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="row g-2 mb-2">
                                        <div class="col-md-6">
                                            <label for="medico_director" class="form-label small fw-semibold mb-1">Director Médico</label>
                                            <input type="text" class="form-control form-control-sm" id="medico_director" name="medico_director"
                                                placeholder="Ej: Dra. Carmen Gil" maxlength="120"
                                                value="<?= $centroEdicion ? htmlspecialchars($centroEdicion['medico_director'] ?? '') : '' ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="horario_atencion" class="form-label small fw-semibold mb-1">Horario</label>
                                            <input type="text" class="form-control form-control-sm" id="horario_atencion" name="horario_atencion"
                                                placeholder="Ej: 24 Horas" maxlength="100"
                                                value="<?= $centroEdicion ? htmlspecialchars($centroEdicion['horario_atencion'] ?? '') : '' ?>">
                                        </div>
                                    </div>

                                    <div class="mb-2">
                                        <label for="direccion" class="form-label small fw-semibold mb-1">Dirección</label>
                                        <textarea class="form-control form-control-sm" id="direccion" name="direccion" rows="2"
                                            placeholder="Sector, calle o avenida" required maxlength="255"><?= $centroEdicion ? htmlspecialchars($centroEdicion['direccion']) : '' ?></textarea>
                                    </div>

                                    <div class="mb-3">
                                        <label for="telefono_principal" class="form-label small fw-semibold mb-1">Teléfono</label>
                                        <input type="text" class="form-control form-control-sm" id="telefono_principal" name="telefono_principal"
                                            placeholder="Ej: 0274-2521100" maxlength="30"
                                            value="<?= $centroEdicion ? htmlspecialchars($centroEdicion['telefono_principal'] ?? '') : '' ?>">
                                    </div>

                                    <!-- Equipamiento médico (N:M) -->
                                    <div class="mb-3 p-2 bg-light border rounded">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <span class="small fw-semibold text-dark">Equipamiento y Capacidades</span>
                                            <a href="index.php?tab=maestras" class="small text-decoration-none" style="font-size: 0.70rem;">Configurar</a>
                                        </div>
                                        <div class="row g-1 mt-1">
                                            <?php foreach ($catalogoRecursosActivos as $rec): ?>
                                                <?php $chk = in_array((int)$rec['id'], $recursosEdicionIds, true) ? 'checked' : ''; ?>
                                                <div class="col-12">
                                                    <div class="form-check form-check-sm mb-1">
                                                        <input class="form-check-input" type="checkbox" name="recursos[]"
                                                            value="<?= (int)$rec['id'] ?>" id="rec_<?= (int)$rec['id'] ?>" <?= $chk ?>>
                                                        <label class="form-check-label small" for="rec_<?= (int)$rec['id'] ?>">
                                                            <strong><?= htmlspecialchars($rec['nombre']) ?></strong>
                                                            <span class="d-block text-muted" style="font-size: 0.70rem;"><?= htmlspecialchars($rec['descripcion'] ?? '') ?></span>
                                                        </label>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary btn-sm w-100 fw-semibold">
                                            <?= $centroEdicion ? 'Actualizar Ambulatorio' : 'Guardar Ambulatorio' ?>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Ficha de Jurisdicción para Farmaceuta -->
                        <div class="card-sicm">
                            <div class="card-header-sicm d-flex justify-content-between align-items-center">
                                <span>Jurisdicción Asignada</span>
                                <span class="badge bg-success-subtle text-success border border-success-subtle font-monospace" style="font-size:0.68rem;">Rol Farmaceuta</span>
                            </div>
                            <div class="p-3">
                                <div class="mb-2">
                                    <span class="text-muted small">Ambulatorio Asignado:</span>
                                    <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($usuario['centro_nombre']) ?></div>
                                </div>
                                <div class="mb-2">
                                    <span class="text-muted small">Funcionario Responsable:</span>
                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($usuario['nombre']) ?></div>
                                    <div class="text-muted small font-monospace"><?= htmlspecialchars($usuario['email']) ?></div>
                                </div>
                                <div class="p-2 bg-light border rounded small text-secondary mt-3" style="font-size: 0.72rem;">
                                    <strong>Permisos Habilitados:</strong>
                                    <ul class="mb-0 ps-3 mt-1">
                                        <li>Consulta de lotes e inventario de su sede.</li>
                                        <li>Recepción de insumos por caducidad (FEFO).</li>
                                        <li>Dispensación controlada a pacientes.</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Tabla de Ambulatorios -->
                <div class="col-xl-8 col-lg-7">
                    <div class="card-sicm">
                        <div class="card-header-sicm d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <span>Ambulatorios de la Red</span>
                                <span class="badge bg-primary rounded-pill"><?= count($centros) ?></span>
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
                                    <input type="hidden" name="estado" value="<?= htmlspecialchars($filtroEstado) ?>">
                                    <input type="text" name="q" class="form-control form-control-sm" style="width: 140px;"
                                        placeholder="Buscar..." value="<?= htmlspecialchars($busqueda) ?>">
                                    <?php if ($busqueda !== ''): ?>
                                        <a href="index.php?tab=centros&estado=<?= htmlspecialchars($filtroEstado) ?>" class="btn btn-outline-secondary btn-sm ms-1">✕</a>
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
                                            <td colspan="7" class="text-center text-muted py-4">No hay ambulatorios registrados.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($centros as $c): ?>
                                            <?php
                                            $recs = $recursosPorCentro[(int)$c['id']] ?? [];
                                            $logsFila = $logsPorCentro[(int)$c['id']] ?? [];
                                            ?>
                                            <tr>
                                                <td class="text-muted fw-bold">#<?= (int)$c['id'] ?></td>
                                                <td><span class="code-tag"><?= htmlspecialchars($c['codigo_sicm']) ?></span></td>
                                                <td>
                                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($c['nombre']) ?></div>
                                                    <div class="text-muted small" style="font-size: 0.74rem;">
                                                        <?= htmlspecialchars($c['direccion']) ?>
                                                        <?= !empty($c['medico_director']) ? ' &bull; ' . htmlspecialchars($c['medico_director']) : '' ?>
                                                    </div>
                                                </td>
                                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($c['tipo_nombre']) ?></span></td>
                                                <td>
                                                    <?php if (empty($recs)): ?>
                                                        <span class="text-muted fst-italic small" style="font-size: 0.70rem;">Sin equipamiento</span>
                                                    <?php else: ?>
                                                        <div class="d-flex flex-wrap gap-1">
                                                            <?php foreach ($recs as $nom): ?>
                                                                <span class="badge badge-resource"><?= htmlspecialchars($nom) ?></span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ((int)$c['activo'] === 1): ?>
                                                        <span class="badge badge-status-active">Operativo</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-status-inactive">Inactivo</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end pe-3">
                                                    <div class="d-flex justify-content-end align-items-center gap-1">
                                                        <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 btn-historial"
                                                            data-nombre="<?= htmlspecialchars($c['nombre']) ?>"
                                                            data-codigo="<?= htmlspecialchars($c['codigo_sicm']) ?>"
                                                            data-logs="<?= htmlspecialchars(json_encode($logsFila, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
                                                            style="font-size: 0.74rem;">
                                                            Historial (<?= count($logsFila) ?>)
                                                        </button>
                                                        <?php if ($esAdmin): ?>
                                                            <a href="index.php?tab=centros&editar=<?= (int)$c['id'] ?>&estado=<?= htmlspecialchars($filtroEstado) ?>"
                                                                class="btn btn-outline-primary btn-sm py-0 px-2" style="font-size: 0.74rem;">Editar</a>
                                                            <?php if ((int)$c['activo'] === 1): ?>
                                                                <a href="procesar.php?accion=cambiar_estado_centro&id=<?= (int)$c['id'] ?>&estado=0"
                                                                    class="btn btn-link text-danger p-0 ms-1 text-decoration-none"
                                                                    onclick="return confirm('¿Desactivar ambulatorio?')" title="Desactivar">✕</a>
                                                            <?php else: ?>
                                                                <a href="procesar.php?accion=cambiar_estado_centro&id=<?= (int)$c['id'] ?>&estado=1"
                                                                    class="btn btn-outline-success btn-sm py-0 px-1 ms-1" style="font-size: 0.74rem;"
                                                                    onclick="return confirm('¿Reactivar ambulatorio?')" title="Reactivar">Reactivar</a>
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
        <?php endif; ?>

        <!-- ==================================================================== -->
        <!-- PESTAÑA 2: CONFIGURACIÓN DE AMBULATORIOS (CATÁLOGOS MAESTROS 3FN)    -->
        <!-- ==================================================================== -->
        <?php if ($tabActiva === 'maestras'): ?>
            <div class="card bg-light border mb-3">
                <div class="card-body p-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <strong class="text-dark fs-6">Configuración y Catálogos Maestros</strong>
                        </div>
                        <p class="text-muted small mb-0">
                            Gestión y parametrización de catálogos maestros del sistema. Permite incorporar y mantener las clasificaciones de ambulatorios y el equipamiento operativo de la red de salud municipal.
                        </p>
                    </div>
                    <a href="index.php?tab=centros" class="btn btn-outline-primary btn-sm py-1 px-3 text-nowrap">Volver a Ambulatorios</a>
                </div>
            </div>

            <div class="row g-3">
                <!-- Catálogo 1: Tipos de Ambulatorio -->
                <div class="col-xl-6 col-lg-6">
                    <div class="card-sicm">
                        <div class="card-header-sicm d-flex justify-content-between align-items-center">
                            <div>
                                <span class="fw-bold">Tipos de Centro de Salud</span>
                                <span class="code-tag ms-2">tipos_centro_salud</span>
                            </div>
                        </div>
                        <div class="px-3 pt-2 pb-1 text-muted small" style="font-size: 0.75rem;">
                            Define las clasificaciones y niveles de atención disponibles para los ambulatorios de la red.
                        </div>
                        <div class="p-3 bg-light border-top border-bottom">
                            <form action="procesar.php" method="POST" class="row g-2 align-items-end">
                                <input type="hidden" name="accion" value="crear_tipo_centro">
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold mb-1">Código</label>
                                    <input type="text" name="codigo" class="form-control form-control-sm text-uppercase font-monospace" placeholder="Ej: HOSP_DIA" required maxlength="30">
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label small fw-semibold mb-1">Nombre del Tipo</label>
                                    <input type="text" name="nombre" class="form-control form-control-sm" placeholder="Ej: Hospital de Día" required maxlength="80">
                                </div>
                                <div class="col-md-3">
                                    <button type="submit" class="btn btn-primary btn-sm w-100 fw-semibold">+ Agregar</button>
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
                                            <td class="text-muted fw-bold">#<?= (int)$t['id'] ?></td>
                                            <td><span class="code-tag"><?= htmlspecialchars($t['codigo']) ?></span></td>
                                            <td class="fw-semibold text-dark"><?= htmlspecialchars($t['nombre']) ?></td>
                                            <td>
                                                <?php if ((int)$t['total_centros'] > 0): ?>
                                                    <span class="badge bg-light text-primary border" title="Ambulatorios que tienen este tipo asignado">
                                                        <?= (int)$t['total_centros'] ?> <?= (int)$t['total_centros'] === 1 ? 'ambulatorio' : 'ambulatorios' ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted small" style="font-size: 0.72rem;">0 centros</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ((int)$t['activo'] === 1): ?>
                                                    <span class="badge badge-status-active">Activo</span>
                                                <?php else: ?>
                                                    <span class="badge badge-status-inactive">Inactivo</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end pe-3">
                                                <a href="procesar.php?accion=toggle_tipo_centro&id=<?= (int)$t['id'] ?>&estado=<?= (int)$t['activo'] === 1 ? '0' : '1' ?>"
                                                    class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size: 0.72rem;">
                                                    <?= (int)$t['activo'] === 1 ? 'Desactivar' : 'Activar' ?>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Catálogo 2: Equipamiento Médico N:M -->
                <div class="col-xl-6 col-lg-6">
                    <div class="card-sicm">
                        <div class="card-header-sicm d-flex justify-content-between align-items-center">
                            <div>
                                <span class="fw-bold">Equipamiento y Capacidades</span>
                                <span class="code-tag ms-2">recursos_operativos</span>
                            </div>
                        </div>
                        <div class="px-3 pt-2 pb-1 text-muted small" style="font-size: 0.75rem;">
                            Registra las capacidades e instalaciones técnicas asignables a cada centro de salud (cadena de frío, energía de emergencia, ambulancias).
                        </div>
                        <div class="p-3 bg-light border-top border-bottom">
                            <form action="procesar.php" method="POST" class="row g-2 align-items-end">
                                <input type="hidden" name="accion" value="crear_recurso">
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold mb-1">Código</label>
                                    <input type="text" name="codigo" class="form-control form-control-sm text-uppercase font-monospace" placeholder="Ej: PANEL_SOLAR" required maxlength="30">
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label small fw-semibold mb-1">Nombre del Recurso</label>
                                    <input type="text" name="nombre" class="form-control form-control-sm" placeholder="Ej: Respaldo Solar 15kVA" required maxlength="80">
                                </div>
                                <div class="col-md-3">
                                    <button type="submit" class="btn btn-primary btn-sm w-100 fw-semibold">+ Agregar</button>
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
                                            <td class="text-muted fw-bold">#<?= (int)$r['id'] ?></td>
                                            <td><span class="code-tag"><?= htmlspecialchars($r['codigo']) ?></span></td>
                                            <td class="fw-semibold text-dark"><?= htmlspecialchars($r['nombre']) ?></td>
                                            <td>
                                                <?php if ((int)$r['total_centros'] > 0): ?>
                                                    <span class="badge bg-light text-primary border" title="Ambulatorios con este recurso">
                                                        <?= (int)$r['total_centros'] ?> <?= (int)$r['total_centros'] === 1 ? 'ambulatorio' : 'ambulatorios' ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted small" style="font-size: 0.72rem;">0 centros</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ((int)$r['activo'] === 1): ?>
                                                    <span class="badge badge-status-active">Activo</span>
                                                <?php else: ?>
                                                    <span class="badge badge-status-inactive">Inactivo</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end pe-3">
                                                <a href="procesar.php?accion=toggle_recurso&id=<?= (int)$r['id'] ?>&estado=<?= (int)$r['activo'] === 1 ? '0' : '1' ?>"
                                                    class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size: 0.72rem;">
                                                    <?= (int)$r['activo'] === 1 ? 'Desactivar' : 'Activar' ?>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- ==================================================================== -->
        <!-- PESTAÑA: OPERADORES Y SEGURIDAD (ADMINISTRADOR CENTRAL)             -->
        <!-- ==================================================================== -->
        <?php if ($tabActiva === 'usuarios' && $esAdmin): ?>
            <div class="row g-3">
                <!-- Formulario Operador -->
                <div class="col-xl-4 col-lg-5">
                    <div class="card-sicm">
                        <div class="card-header-sicm d-flex justify-content-between align-items-center">
                            <span><?= $usuarioEdicion ? 'Editar Operador #' . (int)$usuarioEdicion['id'] : 'Nuevo Operador Sanitario' ?></span>
                            <?php if ($usuarioEdicion): ?>
                                <a href="index.php?tab=usuarios" class="small text-decoration-none text-muted">Cancelar edición</a>
                            <?php endif; ?>
                        </div>
                        <div class="p-3">
                            <form id="form-usuario" action="procesar.php" method="POST" novalidate>
                                <input type="hidden" name="accion" value="guardar_usuario">
                                <input type="hidden" id="u_id" name="id" value="<?= $usuarioEdicion ? (int)$usuarioEdicion['id'] : '' ?>">

                                <div class="mb-2">
                                    <label for="u_nombre" class="form-label small fw-semibold mb-1">Nombre Completo del Funcionario</label>
                                    <input type="text" class="form-control form-control-sm" id="u_nombre" name="nombre"
                                        placeholder="Ej: Lcda. María Rivas" required maxlength="80"
                                        value="<?= $usuarioEdicion ? htmlspecialchars($usuarioEdicion['nombre']) : '' ?>">
                                </div>

                                <div class="mb-2">
                                    <label for="u_email" class="form-label small fw-semibold mb-1">Correo Institucional Oficial</label>
                                    <input type="email" class="form-control form-control-sm" id="u_email" name="email"
                                        placeholder="ej: mrivas@salud.prueba.ve" required maxlength="120"
                                        value="<?= $usuarioEdicion ? htmlspecialchars($usuarioEdicion['email']) : '' ?>">
                                </div>

                                <div class="mb-2">
                                    <label for="u_rol_id" class="form-label small fw-semibold mb-1">Rol Operativo</label>
                                    <select class="form-select form-select-sm" id="u_rol_id" name="rol_id" required>
                                        <option value="">-- Seleccionar Rol --</option>
                                        <?php foreach ($rolesCatalogo as $ro): ?>
                                            <?php $selR = ($usuarioEdicion && (int)$usuarioEdicion['rol_id'] === (int)$ro['id']) ? 'selected' : ''; ?>
                                            <option value="<?= (int)$ro['id'] ?>" <?= $selR ?>>
                                                <?= (int)$ro['id'] === 1 ? 'Administrador Central (Acceso Global)' : 'Farmaceuta (Dispensación Sectorial)' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-2" id="wrap_centro_salud">
                                    <label for="u_centro_salud_id" class="form-label small fw-semibold mb-1">
                                        Ambulatorio de Adscripción <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select form-select-sm" id="u_centro_salud_id" name="centro_salud_id">
                                        <option value="">-- Seleccionar Ambulatorio --</option>
                                        <?php foreach ($centrosDisponibles as $cs): ?>
                                            <?php $selC = ($usuarioEdicion && (int)$usuarioEdicion['centro_salud_id'] === (int)$cs['id']) ? 'selected' : ''; ?>
                                            <option value="<?= (int)$cs['id'] ?>" <?= $selC ?>>
                                                <?= htmlspecialchars($cs['nombre']) ?> (<?= htmlspecialchars($cs['codigo_sicm']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="text-muted small mt-1" style="font-size: 0.70rem;">
                                        Delimita la visibilidad estricta de inventario y dispensación del farmaceuta.
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="u_password" class="form-label small fw-semibold mb-1">
                                        Contraseña <?= $usuarioEdicion ? '<span class="text-muted fw-normal">(Dejar en blanco para no cambiar)</span>' : '<span class="text-danger">*</span>' ?>
                                    </label>
                                    <input type="password" class="form-control form-control-sm" id="u_password" name="password"
                                        placeholder="<?= $usuarioEdicion ? 'Sin cambios' : 'Mín. 8 caracteres (A-Z, a-z, 0-9, !@#)' ?>" 
                                        maxlength="72"
                                        <?= $usuarioEdicion ? '' : 'required minlength="8"' ?>>
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

                <!-- Tabla Operadores -->
                <div class="col-xl-8 col-lg-7">
                    <div class="card-sicm">
                        <div class="card-header-sicm d-flex flex-wrap justify-content-between align-items-center gap-2">
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
                                    <input type="hidden" name="estado_u" value="<?= htmlspecialchars($filtroEstadoU) ?>">
                                    <input type="text" name="q_u" class="form-control form-control-sm" style="width: 140px;"
                                        placeholder="Buscar..." value="<?= htmlspecialchars($busquedaU) ?>">
                                    <?php if ($busquedaU !== ''): ?>
                                        <a href="index.php?tab=usuarios&estado_u=<?= htmlspecialchars($filtroEstadoU) ?>" class="btn btn-outline-secondary btn-sm ms-1">✕</a>
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
                                            <td colspan="6" class="text-center text-muted py-4">No hay operadores registrados con ese criterio.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($listaUsuarios as $u): ?>
                                            <tr>
                                                <td class="text-muted fw-bold">#<?= (int)$u['id'] ?></td>
                                                <td>
                                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($u['nombre']) ?></div>
                                                    <div class="text-muted small font-monospace" style="font-size: 0.72rem;"><?= htmlspecialchars($u['email']) ?></div>
                                                </td>
                                                <td>
                                                    <?php if ((int)$u['rol_id'] === 1): ?>
                                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Admin Central</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success-subtle text-success border border-success-subtle">Farmaceuta</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ((int)$u['rol_id'] === 1 || empty($u['centro_salud_id'])): ?>
                                                        <span class="badge bg-light text-secondary border">Red Municipal Completa</span>
                                                    <?php else: ?>
                                                        <div class="fw-semibold text-dark" style="font-size: 0.80rem;"><?= htmlspecialchars($u['centro_nombre']) ?></div>
                                                        <span class="code-tag" style="font-size: 0.68rem;"><?= htmlspecialchars($u['centro_codigo']) ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ((int)$u['activo'] === 1): ?>
                                                        <span class="badge badge-status-active">Activo</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-status-inactive">Inactivo</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end pe-3">
                                                    <div class="d-flex justify-content-end align-items-center gap-1">
                                                        <a href="index.php?tab=usuarios&editar_u=<?= (int)$u['id'] ?>&estado_u=<?= htmlspecialchars($filtroEstadoU) ?>"
                                                            class="btn btn-outline-primary btn-sm py-0 px-2" style="font-size: 0.74rem;">Editar</a>
                                                        <?php if ((int)$u['id'] !== (int)$usuario['id']): ?>
                                                            <?php if ((int)$u['activo'] === 1): ?>
                                                                <a href="procesar.php?accion=cambiar_estado_usuario&id=<?= (int)$u['id'] ?>&estado=0"
                                                                    class="btn btn-link text-danger p-0 ms-1 text-decoration-none"
                                                                    onclick="return confirm('¿Desactivar operador?')" title="Desactivar">✕</a>
                                                            <?php else: ?>
                                                                <a href="procesar.php?accion=cambiar_estado_usuario&id=<?= (int)$u['id'] ?>&estado=1"
                                                                    class="btn btn-outline-success btn-sm py-0 px-1 ms-1" style="font-size: 0.74rem;"
                                                                    onclick="return confirm('¿Reactivar operador?')" title="Reactivar">Reactivar</a>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <span class="text-muted small ms-1" style="font-size: 0.70rem;" title="Tu sesión actual">(Tú)</span>
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

        <!-- ==================================================================== -->
        <!-- PESTAÑA 3: AUDITORÍA FORENSE (CENTRALIZADA, INMUTABLE, MULTITABLA)    -->
        <!-- ==================================================================== -->
        <?php if ($tabActiva === 'auditoria'): ?>
            <!-- Cuadro de Mando de Auditoría (KPIs Forenses) -->
            <div class="row g-2 mb-3">
                <div class="col-6 col-md-3">
                    <div class="card-sicm p-2 text-center">
                        <span class="text-muted small" style="font-size: 0.72rem;">Total Registros</span>
                        <div class="fs-5 fw-bold text-dark font-monospace"><?= number_format((int)$kpisLogs['total']) ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card-sicm p-2 text-center border-start border-success border-3">
                        <span class="text-success small fw-semibold" style="font-size: 0.72rem;">Altas / Creaciones</span>
                        <div class="fs-5 fw-bold text-success font-monospace"><?= number_format((int)$kpisLogs['total_creaciones']) ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card-sicm p-2 text-center border-start border-primary border-3">
                        <span class="text-primary small fw-semibold" style="font-size: 0.72rem;">Modificaciones (Deltas)</span>
                        <div class="fs-5 fw-bold text-primary font-monospace"><?= number_format((int)$kpisLogs['total_ediciones']) ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card-sicm p-2 text-center border-start border-danger border-3">
                        <span class="text-danger small fw-semibold" style="font-size: 0.72rem;">Bajas (Soft Delete)</span>
                        <div class="fs-5 fw-bold text-danger font-monospace"><?= number_format((int)$kpisLogs['total_bajas']) ?></div>
                    </div>
                </div>
            </div>

            <!-- Panel de Continuidad Operativa y Respaldos -->
            <div class="card-sicm mb-3 p-3 bg-light border">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                    <div>
                        <span class="fw-bold text-dark fs-6">Copias de Seguridad y Resguardo</span>
                        <div class="text-muted small">Generación y descarga de respaldos de la red asistencial municipal para continuidad del servicio.</div>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="procesar.php?accion=descargar_respaldo" class="btn btn-primary btn-sm px-3 shadow-sm fw-medium">
                            CREAR RESPALDO AHORA
                        </a>
                    </div>
                </div>
                <div class="row g-2 pt-2 border-top small text-secondary">
                    <div class="col-md-4">
                        <strong class="d-block text-dark mb-1">Disponibilidad Continua</strong>
                        Generación transaccional sin interrumpir la atención médica ni bloquear consultas en los ambulatorios.
                    </div>
                    <div class="col-md-4">
                        <strong class="d-block text-dark mb-1">Copia Integral Comprimida</strong>
                        Descarga inmediata de toda la información histórica, configuraciones y registros de la red de salud.
                    </div>
                    <div class="col-md-4">
                        <strong class="d-block text-dark mb-1">Trazabilidad de Resguardo</strong>
                        Cada descarga queda registrada con fecha, hora y funcionario responsable en la bitácora institucional.
                    </div>
                </div>
            </div>

            <!-- Barra de Filtros Específicos para Auditoría -->
            <div class="card-sicm mb-3 p-3">
                <form method="GET" action="index.php" class="row g-2 align-items-center m-0">
                    <input type="hidden" name="tab" value="auditoria">
                    <div class="col-auto">
                        <span class="small fw-semibold text-secondary">Filtros Forenses:</span>
                    </div>
                    <div class="col-sm-3 col-md-auto">
                        <select name="tabla_filtro" class="form-select form-select-sm">
                            <option value="todas">-- Todas las tablas --</option>
                            <?php foreach ($tablasUnicas as $tbl): ?>
                                <option value="<?= htmlspecialchars($tbl) ?>" <?= $filtroTabla === $tbl ? 'selected' : '' ?>>
                                    Tabla: <?= htmlspecialchars($tbl) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-3 col-md-auto">
                        <select name="accion_filtro" class="form-select form-select-sm">
                            <option value="todas">-- Todas las acciones --</option>
                            <?php foreach ($accionesUnicas as $acc): ?>
                                <option value="<?= htmlspecialchars($acc) ?>" <?= $filtroAccion === $acc ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($acc) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-4 col-md">
                        <input type="text" name="q_log" class="form-control form-control-sm"
                            placeholder="Buscar por IP, operador, ambulatorio, ID..." value="<?= htmlspecialchars($busquedaLog) ?>">
                    </div>
                    <div class="col-auto d-flex gap-1">
                        <button type="submit" class="btn btn-primary btn-sm px-3">Filtrar</button>
                        <?php if ($filtroAccion !== 'todas' || $filtroTabla !== 'todas' || $busquedaLog !== ''): ?>
                            <a href="index.php?tab=auditoria" class="btn btn-outline-secondary btn-sm" title="Restablecer filtros">✕ Limpiar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="card-sicm">
                <div class="card-header-sicm d-flex justify-content-between align-items-center">
                    <div>
                        <span>Bitácora de Trazabilidad</span>
                        <span class="text-muted small fw-normal ms-2">(Mostrando <?= count($logs) ?> de <?= number_format($totalFiltrados) ?> eventos)</span>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-sm table-sicm mb-0">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th style="width: 135px;">Fecha y hora</th>
                                <th>Usuario / Rol</th>
                                <th style="width: 140px;">Acción</th>
                                <th>Entidad / Objeto Afectado</th>
                                <th style="width: 105px;">IP Origen</th>
                                <th>Cliente / Navegador</th>
                                <th style="width: 85px;" class="text-end pe-3">Cambio</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">No hay eventos que coincidan con los filtros.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $l): ?>
                                    <?php
                                    $badge = 'bg-secondary';
                                    if (str_contains($l['accion'], 'CREAR') || str_contains($l['accion'], 'REGISTRAR')) $badge = 'bg-success';
                                    elseif (str_contains($l['accion'], 'EDITAR') || str_contains($l['accion'], 'CONMUTAR')) $badge = 'bg-primary';
                                    elseif (str_contains($l['accion'], 'DESACTIVAR')) $badge = 'bg-danger';
                                    elseif (str_contains($l['accion'], 'REACTIVAR')) $badge = 'bg-warning text-dark';

                                    $detPreview = [];
                                    try { $detPreview = json_decode($l['detalles_json'] ?? '{}', true); } catch (Exception $e) {}

                                    $regId = (int)($l['registro_id'] ?? 0);
                                    $nombreEntidad = $detPreview['nombre'] ?? null;
                                    $codigoEntidad = $detPreview['codigo_sicm'] ?? $detPreview['codigo'] ?? null;

                                    if (!$nombreEntidad) {
                                        if ($l['tabla_afectada'] === 'centros_salud' && isset($nombresCentros[$regId])) {
                                            $nombreEntidad = $nombresCentros[$regId]['nombre'];
                                            $codigoEntidad = $nombresCentros[$regId]['codigo_sicm'];
                                        } elseif ($l['tabla_afectada'] === 'recursos_operativos' && isset($nombresRecursos[$regId])) {
                                            $nombreEntidad = $nombresRecursos[$regId]['nombre'];
                                            $codigoEntidad = $nombresRecursos[$regId]['codigo'];
                                        } elseif ($l['tabla_afectada'] === 'tipos_centro_salud' && isset($nombresTipos[$regId])) {
                                            $nombreEntidad = $nombresTipos[$regId]['nombre'];
                                            $codigoEntidad = $nombresTipos[$regId]['codigo'];
                                        }
                                    }

                                    if (!$nombreEntidad) {
                                        $nombreEntidad = $detPreview['evento'] ?? 'Registro #' . $regId;
                                    }
                                    ?>
                                    <tr>
                                        <td class="code-tag">#<?= (int)$l['log_id'] ?></td>
                                        <td class="small font-monospace"><?= htmlspecialchars($l['fecha_hora']) ?></td>
                                        <td>
                                            <div class="fw-semibold text-dark"><?= htmlspecialchars($l['operador']) ?></div>
                                            <div class="text-muted" style="font-size: 0.68rem;"><?= htmlspecialchars($l['rol_operador']) ?></div>
                                        </td>
                                        <td><span class="badge <?= $badge ?>" style="font-size: 0.70rem;"><?= htmlspecialchars($l['accion']) ?></span></td>
                                        <td>
                                            <div class="d-flex flex-column">
                                                <span class="fw-bold text-dark text-truncate" style="max-width: 230px; font-size: 0.80rem;" title="<?= htmlspecialchars($nombreEntidad) ?>">
                                                    <?= htmlspecialchars($nombreEntidad) ?>
                                                </span>
                                                <div class="mt-1 d-flex align-items-center gap-1">
                                                    <span class="code-tag" style="font-size: 0.68rem;">
                                                        <?= htmlspecialchars($l['tabla_afectada']) ?> #<?= $regId ?>
                                                    </span>
                                                    <?php if ($codigoEntidad): ?>
                                                        <span class="badge bg-light text-secondary border font-monospace" style="font-size: 0.65rem;">
                                                            <?= htmlspecialchars($codigoEntidad) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td><span class="code-tag"><?= htmlspecialchars($l['ip_origen']) ?></span></td>
                                        <td>
                                            <?php
                                            $uaRaw = $l['user_agent'] ?? 'CLI / Local';
                                            $uaResumen = resumirUserAgent($uaRaw);
                                            ?>
                                            <div class="d-flex flex-column" title="<?= htmlspecialchars($uaRaw) ?>">
                                                <span class="fw-semibold text-dark" style="font-size: 0.76rem;">
                                                    <?= htmlspecialchars($uaResumen) ?>
                                                </span>
                                                <span class="text-muted text-truncate font-monospace" style="max-width: 220px; font-size: 0.68rem;" title="<?= htmlspecialchars($uaRaw) ?>">
                                                    <?= htmlspecialchars($uaRaw) ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td class="text-end pe-3">
                                            <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2 btn-ver-json"
                                                data-id="<?= (int)$l['log_id'] ?>"
                                                data-accion="<?= htmlspecialchars($l['accion']) ?>"
                                                data-tabla="<?= htmlspecialchars($l['tabla_afectada']) ?>"
                                                data-registro="<?= htmlspecialchars((string)($l['registro_id'] ?? '')) ?>"
                                                data-operador="<?= htmlspecialchars($l['operador']) ?>"
                                                data-rol="<?= htmlspecialchars($l['rol_operador']) ?>"
                                                data-fecha="<?= htmlspecialchars($l['fecha_hora']) ?>"
                                                data-ip="<?= htmlspecialchars($l['ip_origen']) ?>"
                                                data-ua="<?= htmlspecialchars($l['user_agent'] ?? 'CLI / Local') ?>"
                                                data-json="<?= htmlspecialchars($l['detalles_json'] ?? '{}', ENT_QUOTES, 'UTF-8') ?>"
                                                style="font-size: 0.72rem;">Ver</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalPaginas > 1): ?>
                    <div class="card-footer bg-light d-flex flex-wrap justify-content-between align-items-center py-2 px-3 border-top">
                        <span class="small text-muted" style="font-size: 0.78rem;">
                            Mostrando <strong><?= count($logs) ?></strong> de <strong><?= number_format($totalFiltrados) ?></strong> eventos (Página <?= $paginaActual ?> de <?= $totalPaginas ?>)
                        </span>
                        <div class="btn-group btn-group-sm">
                            <?php
                            $queryParams = "&tabla_filtro=" . urlencode($filtroTabla) . "&accion_filtro=" . urlencode($filtroAccion) . "&q_log=" . urlencode($busquedaLog);
                            ?>
                            <?php if ($paginaActual > 1): ?>
                                <a href="index.php?tab=auditoria<?= $queryParams ?>&p=<?= $paginaActual - 1 ?>"
                                   class="btn btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;">« Anterior</a>
                            <?php else: ?>
                                <button class="btn btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;" disabled>« Anterior</button>
                            <?php endif; ?>

                            <button class="btn btn-primary py-0 px-2 disabled" style="font-size: 0.75rem;"><?= $paginaActual ?> / <?= $totalPaginas ?></button>

                            <?php if ($paginaActual < $totalPaginas): ?>
                                <a href="index.php?tab=auditoria<?= $queryParams ?>&p=<?= $paginaActual + 1 ?>"
                                   class="btn btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;">Siguiente »</a>
                            <?php else: ?>
                                <button class="btn btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;" disabled>Siguiente »</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>

    <!-- MODAL 1: HISTORIAL DE CAMBIOS DE UN AMBULATORIO -->
    <div class="modal fade" id="modalHistorial" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-dark text-white py-2 px-3">
                    <h6 class="modal-title font-monospace m-0">Historial: <span id="m-codigo" class="text-info"></span></h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3">
                    <div class="mb-2 pb-2 border-bottom">
                        <span class="text-muted small">Ambulatorio:</span> <strong id="m-nombre" class="text-dark"></strong>
                    </div>
                    <div id="m-vacio" class="text-center text-muted py-3 d-none">Sin cambios registrados.</div>
                    <div id="m-tabla-wrap" class="table-responsive">
                        <table class="table table-bordered table-sm table-sicm bg-white mb-0">
                            <thead>
                                <tr>
                                    <th style="width: 45px;">#</th>
                                    <th style="width: 130px;">Fecha y hora</th>
                                    <th>Usuario</th>
                                    <th style="width: 130px;">Acción</th>
                                    <th style="width: 100px;">IP</th>
                                    <th>Cambios realizados</th>
                                </tr>
                            </thead>
                            <tbody id="m-tbody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer py-2 px-3 bg-light">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL 2: AUDITORÍA FORENSE Y DELTA DE CAMBIOS -->
    <div class="modal fade" id="modalJson" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-dark text-white py-2 px-3">
                    <h6 class="modal-title font-monospace m-0">
                        Auditoría Forense &bull; Evento #<span id="mj-id" class="text-info"></span>
                    </h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3">
                    <!-- Metadatos de Auditoría (Actor, Contexto, Acción) -->
                    <div class="bg-light p-2 rounded border mb-3 small">
                        <div class="row g-2">
                            <div class="col-sm-6">
                                <span class="text-muted">Operador:</span> <strong id="mj-operador"></strong> (<span id="mj-rol" class="text-muted"></span>)
                            </div>
                            <div class="col-sm-6">
                                <span class="text-muted">Fecha y Hora:</span> <span id="mj-fecha" class="font-monospace fw-semibold"></span>
                            </div>
                            <div class="col-sm-6">
                                <span class="text-muted">Acción:</span> <span id="mj-badge-accion" class="badge bg-primary"></span>
                                <span class="text-muted ms-2">Tabla:</span> <code id="mj-tabla"></code> <span id="mj-registro" class="text-muted"></span>
                            </div>
                            <div class="col-sm-6">
                                <span class="text-muted">Dirección IP:</span> <code id="mj-ip" class="font-monospace fw-semibold"></code>
                            </div>
                            <div class="col-12 mt-2 pt-2 border-top">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-muted fw-semibold" style="font-size: 0.72rem;">Agente de Usuario / Cliente (Navegador):</span>
                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" id="btn-copy-ua" style="font-size: 0.68rem;" title="Copiar User-Agent completo">
                                        Copiar
                                    </button>
                                </div>
                                <div id="mj-ua" class="p-2 bg-white border rounded font-monospace small text-break user-select-all" style="font-size: 0.74rem; max-height: 80px; overflow-y: auto; color: #1e293b; line-height: 1.4;"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Descripción del Evento -->
                    <div id="mj-evento-desc" class="alert alert-info py-2 px-3 small mb-3 d-none"></div>

                    <!-- Tabla de Diferencias (Visual Diff) -->
                    <div id="mj-diff-wrap" class="d-none mb-3">
                        <div class="fw-semibold small text-dark mb-1">Diferencias Registradas (Delta de Auditoría):</div>
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm mb-0 small">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 25%;">Campo Modificado</th>
                                        <th style="width: 37.5%;" class="text-danger bg-danger-subtle">Valor Anterior</th>
                                        <th style="width: 37.5%;" class="text-success bg-success-subtle">Valor Posterior</th>
                                    </tr>
                                </thead>
                                <tbody id="mj-diff-tbody"></tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Tabla de Datos Iniciales (Creación u otros) -->
                    <div id="mj-datos-wrap" class="d-none mb-3">
                        <div class="fw-semibold small text-dark mb-1">Valores del Registro:</div>
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm mb-0 small">
                                <tbody id="mj-datos-tbody"></tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Acordeón para Inspeccionar JSON Crudo -->
                    <details class="mt-2">
                        <summary class="text-muted small cursor-pointer user-select-none" style="cursor: pointer;">
                            &#9654; Inspeccionar Payload JSON Crudo
                        </summary>
                        <pre id="mj-contenido" class="json-viewer mt-2 p-2 bg-dark text-light rounded small font-monospace" style="max-height: 220px; overflow-y: auto;"></pre>
                    </details>
                </div>
                <div class="modal-footer py-2 px-3 bg-light">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="public/js/validaciones.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Modal de Historial de Fila
            const modalH = document.getElementById('modalHistorial');
            if (modalH) {
                const bsH = new bootstrap.Modal(modalH);
                document.querySelectorAll('.btn-historial').forEach(btn => {
                    btn.addEventListener('click', () => {
                        document.getElementById('m-codigo').textContent = btn.getAttribute('data-codigo');
                        document.getElementById('m-nombre').textContent = btn.getAttribute('data-nombre');
                        const tbody = document.getElementById('m-tbody');
                        tbody.innerHTML = '';
                        let logs = [];
                        try { logs = JSON.parse(btn.getAttribute('data-logs') || '[]'); } catch (e) {}

                        if (logs.length === 0) {
                            document.getElementById('m-vacio').classList.remove('d-none');
                            document.getElementById('m-tabla-wrap').classList.add('d-none');
                        } else {
                            document.getElementById('m-vacio').classList.add('d-none');
                            document.getElementById('m-tabla-wrap').classList.remove('d-none');
                            logs.forEach(l => {
                                let badge = 'bg-secondary';
                                if (l.accion === 'CREAR_CENTRO') badge = 'bg-success';
                                else if (l.accion === 'EDITAR_CENTRO') badge = 'bg-primary';
                                else if (l.accion === 'DESACTIVAR_CENTRO') badge = 'bg-danger';
                                else if (l.accion === 'REACTIVAR_CENTRO') badge = 'bg-warning text-dark';

                                let det = {};
                                try { det = JSON.parse(l.detalles_json || '{}'); } catch (e) {}
                                let info = det.evento ? `<div class="fw-semibold text-primary small">${det.evento}</div>` : '';
                                if (det.campos_alterados) info += `<div class="text-muted small">Campos modificados: <code>${det.campos_alterados.join(', ')}</code></div>`;

                                const tr = document.createElement('tr');
                                tr.innerHTML = `
                                    <td class="code-tag">#${l.log_id}</td>
                                    <td class="small font-monospace">${l.fecha_hora}</td>
                                    <td class="small"><strong>${l.operador}</strong></td>
                                    <td><span class="badge ${badge}" style="font-size:0.70rem;">${l.accion}</span></td>
                                    <td class="font-monospace small">${l.ip_origen}</td>
                                    <td>${info || '<span class="text-muted small">Registro inicial</span>'}</td>
                                `;
                                tbody.appendChild(tr);
                            });
                        }
                        bsH.show();
                    });
                });
            }

            // Modal de Auditoría Forense (Diff Visual estilo CloudTrail/Stripe)
            const modalJ = document.getElementById('modalJson');
            if (modalJ) {
                const bsJ = new bootstrap.Modal(modalJ);
                document.querySelectorAll('.btn-ver-json').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const id = btn.getAttribute('data-id');
                        const accion = btn.getAttribute('data-accion') || '';
                        const tabla = btn.getAttribute('data-tabla') || '';
                        const registro = btn.getAttribute('data-registro');
                        const operador = btn.getAttribute('data-operador') || '';
                        const rol = btn.getAttribute('data-rol') || '';
                        const fecha = btn.getAttribute('data-fecha') || '';
                        const ip = btn.getAttribute('data-ip') || '';
                        const ua = btn.getAttribute('data-ua') || '';
                        const raw = btn.getAttribute('data-json') || '{}';

                        document.getElementById('mj-id').textContent = id;
                        document.getElementById('mj-operador').textContent = operador;
                        document.getElementById('mj-rol').textContent = rol;
                        document.getElementById('mj-fecha').textContent = fecha;
                        document.getElementById('mj-tabla').textContent = tabla;
                        document.getElementById('mj-registro').textContent = registro ? `(ID #${registro})` : '';
                        document.getElementById('mj-ip').textContent = ip;
                        const uaElem = document.getElementById('mj-ua');
                        uaElem.textContent = ua || 'CLI / Local';
                        const copyUaBtn = document.getElementById('btn-copy-ua');
                        if (copyUaBtn) {
                            copyUaBtn.onclick = () => {
                                navigator.clipboard.writeText(uaElem.textContent);
                                const orig = copyUaBtn.textContent;
                                copyUaBtn.textContent = '¡Copiado!';
                                setTimeout(() => { copyUaBtn.textContent = orig; }, 1500);
                            };
                        }

                        const badge = document.getElementById('mj-badge-accion');
                        badge.textContent = accion;
                        badge.className = 'badge ' + (
                            accion.includes('CREAR') ? 'bg-success' :
                            accion.includes('EDITAR') ? 'bg-primary' :
                            accion.includes('DESACTIVAR') ? 'bg-danger' :
                            accion.includes('REACTIVAR') ? 'bg-warning text-dark' : 'bg-secondary'
                        );

                        let parsed = {};
                        try { parsed = JSON.parse(raw); } catch (e) { parsed = { raw: raw }; }

                        // JSON crudo
                        document.getElementById('mj-contenido').textContent = JSON.stringify(parsed, null, 2);

                        // Descripción del Evento
                        const descEl = document.getElementById('mj-evento-desc');
                        if (parsed.evento) {
                            descEl.textContent = parsed.evento;
                            descEl.classList.remove('d-none');
                        } else {
                            descEl.classList.add('d-none');
                        }

                        // Diff Table o Valores
                        const diffWrap = document.getElementById('mj-diff-wrap');
                        const diffTbody = document.getElementById('mj-diff-tbody');
                        const datosWrap = document.getElementById('mj-datos-wrap');
                        const datosTbody = document.getElementById('mj-datos-tbody');
                        diffTbody.innerHTML = '';
                        datosTbody.innerHTML = '';

                        if (parsed.delta && Object.keys(parsed.delta).length > 0) {
                            diffWrap.classList.remove('d-none');
                            datosWrap.classList.add('d-none');
                            for (const [campo, val] of Object.entries(parsed.delta)) {
                                const tr = document.createElement('tr');
                                const ant = typeof val.antes === 'object' ? JSON.stringify(val.antes) : (val.antes ?? '<em>(Vacío)</em>');
                                const des = typeof val.despues === 'object' ? JSON.stringify(val.despues) : (val.despues ?? '<em>(Vacío)</em>');
                                tr.innerHTML = `
                                    <td class="fw-semibold text-secondary"><code>${campo}</code></td>
                                    <td class="bg-danger-subtle font-monospace">${ant}</td>
                                    <td class="bg-success-subtle font-monospace">${des}</td>
                                `;
                                diffTbody.appendChild(tr);
                            }
                        } else {
                            diffWrap.classList.add('d-none');
                            const keys = Object.keys(parsed).filter(k => k !== 'evento' && k !== 'campos_alterados' && k !== 'raw');
                            if (keys.length > 0) {
                                datosWrap.classList.remove('d-none');
                                keys.forEach(k => {
                                    const tr = document.createElement('tr');
                                    const val = typeof parsed[k] === 'object' ? JSON.stringify(parsed[k]) : parsed[k];
                                    tr.innerHTML = `
                                        <th style="width: 30%;" class="table-light"><code>${k}</code></th>
                                        <td class="font-monospace">${val ?? '<em>(null)</em>'}</td>
                                    `;
                                    datosTbody.appendChild(tr);
                                });
                            } else {
                                datosWrap.classList.add('d-none');
                            }
                        }

                        bsJ.show();
                    });
                });
            }
        });
    </script>
</body>
</html>