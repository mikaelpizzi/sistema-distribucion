<?php
// src/centros/index.php
// Vista y Controlador de interfaz en PHP puro para la Red de Ambulatorios Municipales y Auditoría Forense
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

// ----------------------------------------------------------------------------
// 1. FILTROS Y CONSULTA DE CENTROS DE SALUD
// ----------------------------------------------------------------------------
$filtroEstado = $_GET['estado'] ?? 'activos';
$busquedaCentro = trim($_GET['q'] ?? '');

$condicionesCentros = [];
$paramsCentros = [];

if ($filtroEstado === 'inactivos') {
    $condicionesCentros[] = 'c.activo = 0';
} elseif ($filtroEstado === 'todos') {
    // Sin restricción de estado
} else {
    $condicionesCentros[] = 'c.activo = 1';
}

if ($busquedaCentro !== '') {
    $condicionesCentros[] = '(c.nombre LIKE :q1 OR c.codigo_sicm LIKE :q2 OR c.direccion LIKE :q3 OR c.medico_director LIKE :q4)';
    $paramsCentros[':q1'] = '%' . $busquedaCentro . '%';
    $paramsCentros[':q2'] = '%' . $busquedaCentro . '%';
    $paramsCentros[':q3'] = '%' . $busquedaCentro . '%';
    $paramsCentros[':q4'] = '%' . $busquedaCentro . '%';
}

$whereCentros = !empty($condicionesCentros) ? 'WHERE ' . implode(' AND ', $condicionesCentros) : '';

$sqlCentros = "SELECT c.id, c.codigo_sicm, c.nombre, c.tipo_centro_id, 
                      t.nombre AS tipo_nombre, t.codigo AS tipo_codigo,
                      c.medico_director, c.horario_atencion,
                      c.direccion, c.telefono_principal, c.activo, c.created_at
               FROM centros_salud c
               INNER JOIN tipos_centro_salud t ON t.id = c.tipo_centro_id
               {$whereCentros}
               ORDER BY c.id DESC";

$stmtCentros = $pdo->prepare($sqlCentros);
$stmtCentros->execute($paramsCentros);
$centros = $stmtCentros->fetchAll();

// ----------------------------------------------------------------------------
// 2. RECURSOS OPERATIVOS POR CENTRO (Relación N:M)
// ----------------------------------------------------------------------------
// Obtenemos los recursos asignados a cada centro de forma relacional y óptima
$sqlRecursosAsignados = "SELECT cr.centro_salud_id, ro.id AS recurso_id, ro.codigo, ro.nombre, cr.detalle_capacidad
                         FROM centro_recursos cr
                         INNER JOIN recursos_operativos ro ON ro.id = cr.recurso_id
                         WHERE cr.operativo = 1
                         ORDER BY ro.id ASC";
$stmtRA = $pdo->query($sqlRecursosAsignados);
$recursosPorCentro = [];
while ($row = $stmtRA->fetch()) {
    $recursosPorCentro[(int)$row['centro_salud_id']][] = $row;
}

// ----------------------------------------------------------------------------
// 3. CATÁLOGOS MAESTROS (3FN Parametrizables - Misión 1)
// ----------------------------------------------------------------------------
$tiposCentro = $pdo->query("SELECT id, codigo, nombre FROM tipos_centro_salud ORDER BY id ASC")->fetchAll();
$catalogoRecursos = $pdo->query("SELECT id, codigo, nombre, descripcion FROM recursos_operativos WHERE activo = 1 ORDER BY id ASC")->fetchAll();

// ----------------------------------------------------------------------------
// 4. MODO EDICIÓN
// ----------------------------------------------------------------------------
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

// ----------------------------------------------------------------------------
// 5. BITÁCORA FORENSE DE AUDITORÍA (Misión 2 - logs_auditoria)
// ----------------------------------------------------------------------------
$filtroAccionLog = $_GET['log_accion'] ?? 'todas';
$busquedaLog = trim($_GET['log_q'] ?? '');

$condicionesLogs = [];
$paramsLogs = [];

if ($filtroAccionLog !== 'todas' && $filtroAccionLog !== '') {
    $condicionesLogs[] = 'l.accion = :accion_filtro';
    $paramsLogs[':accion_filtro'] = $filtroAccionLog;
}

if ($busquedaLog !== '') {
    $condicionesLogs[] = '(l.accion LIKE :lq1 OR l.tabla_afectada LIKE :lq2 OR l.ip_origen LIKE :lq3 OR u.nombre LIKE :lq4 OR CAST(l.registro_id AS CHAR) = :lq5)';
    $paramsLogs[':lq1'] = '%' . $busquedaLog . '%';
    $paramsLogs[':lq2'] = '%' . $busquedaLog . '%';
    $paramsLogs[':lq3'] = '%' . $busquedaLog . '%';
    $paramsLogs[':lq4'] = '%' . $busquedaLog . '%';
    $paramsLogs[':lq5'] = $busquedaLog;
}

$whereLogs = !empty($condicionesLogs) ? 'WHERE ' . implode(' AND ', $condicionesLogs) : '';

$sqlLogs = "SELECT l.id, l.usuario_id, u.nombre AS usuario_nombre, r.nombre AS rol_nombre,
                   l.accion, l.tabla_afectada, l.registro_id, l.detalles_json, l.ip_origen, l.fecha_hora
            FROM logs_auditoria l
            LEFT JOIN usuarios u ON u.id = l.usuario_id
            LEFT JOIN roles r ON r.id = u.rol_id
            {$whereLogs}
            ORDER BY l.id DESC
            LIMIT 25";

$stmtLogs = $pdo->prepare($sqlLogs);
$stmtLogs->execute($paramsLogs);
$logs = $stmtLogs->fetchAll();

// Acciones únicas disponibles para el selector de filtro
$accionesDisponibles = $pdo->query("SELECT DISTINCT accion FROM logs_auditoria ORDER BY accion ASC")->fetchAll(PDO::FETCH_COLUMN);

// Mapeo visual de iconos para recursos
function getRecursoIcon(string $codigo): string {
    return match ($codigo) {
        'CADENA_FRIO'   => '❄️',
        'PLANTA_ELEC'   => '⚡',
        'AMBULANCIA'    => '🚑',
        'ESTERILIZACION'=> '🧪',
        'FARMACIA_24H'  => '🕒',
        'BANCO_SANGRE'  => '🩸',
        default         => '🔹'
    };
}

$mensajeExito = $_GET['msg'] ?? null;
$mensajeError = $_GET['error'] ?? null;
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SICM | Red Asistencial y Auditoría Forense</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f3f4f6;
            font-size: 0.86rem;
            color: #1f2937;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }

        .header-slim {
            background-color: #0f172a;
            border-bottom: 2px solid #3b82f6;
        }

        .card {
            border-radius: 6px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.07);
        }

        .sicm-code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            background-color: #e2e8f0;
            color: #0f172a;
            font-weight: 600;
            font-size: 0.77rem;
            letter-spacing: 0.4px;
        }

        .table > :not(caption) > * > * {
            padding: 0.48rem 0.65rem;
        }

        .badge-resource {
            font-size: 0.72rem;
            font-weight: 500;
            background-color: #e0f2fe;
            color: #0369a1;
            border: 1px solid #bae6fd;
        }

        .json-preview {
            background-color: #1e293b;
            color: #e2e8f0;
            font-family: ui-monospace, Menlo, Monaco, Consolas, monospace;
            font-size: 0.8rem;
            max-height: 380px;
            overflow-y: auto;
            border-radius: 5px;
            padding: 1rem;
        }

        .nav-status .btn.active {
            background-color: #1e293b;
            color: #fff;
            border-color: #1e293b;
        }
    </style>
</head>

<body>

    <!-- Header Técnico de Alta Densidad -->
    <header class="header-slim text-white py-2 px-3 d-flex justify-content-between align-items-center shadow-sm">
        <div class="d-flex align-items-center gap-3">
            <span class="fw-bold tracking-wide text-info fs-6">SICM-RED</span>
            <span class="text-white-50">|</span>
            <span class="text-light fw-medium">Sistema Municipal de Salud</span>
            <span class="badge bg-primary text-uppercase" style="font-size: 0.68rem;">Misión 1: Tablas Maestras N:M</span>
            <span class="badge bg-success text-uppercase" style="font-size: 0.68rem;">Misión 2: Auditoría Forense</span>
        </div>
        <div class="text-white-50 font-monospace small" style="font-size: 0.76rem;">
            DB: <span class="text-white">control_medicamentos_red</span> | Motor: <span class="text-white">InnoDB 16KB</span> | Normalización: <span class="text-white">3FN Estricta</span>
        </div>
    </header>

    <div class="container-fluid px-3 py-3">

        <!-- Mensajes de Feedback de Transacciones de Base de Datos -->
        <?php if ($mensajeExito): ?>
            <div class="alert alert-success alert-dismissible fade show py-2 shadow-sm mb-3" role="alert">
                <strong>Transacción Exitosa:</strong> <?= htmlspecialchars($mensajeExito) ?>
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($mensajeError): ?>
            <div class="alert alert-danger alert-dismissible fade show py-2 shadow-sm mb-3" role="alert">
                <strong>Fallo de Integridad:</strong> <?= htmlspecialchars($mensajeError) ?>
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Alerta Dinámica de Validación JS -->
        <div id="alerta-js" class="d-none mb-3"></div>

        <!-- ================================================================ -->
        <!-- SECCIÓN PRINCIPAL: GESTIÓN DE AMBULATORIOS Y CAPACIDADES N:M    -->
        <!-- ================================================================ -->
        <div class="row g-3">
            
            <!-- FORMULARIO DE REGISTRO / EDICIÓN -->
            <div class="col-xl-4 col-lg-4">
                <div class="card border">
                    <div class="card-header bg-white text-dark fw-bold border-bottom py-2 px-3 d-flex justify-content-between align-items-center">
                        <span><?= $centroEdicion ? '✏️ Modificar Ambulatorio #' . (int)$centroEdicion['id'] : '➕ Registrar Nuevo Ambulatorio' ?></span>
                        <span class="badge bg-light text-secondary border">3FN + N:M</span>
                    </div>
                    <div class="card-body p-3">
                        <form id="form-centro" action="procesar.php" method="POST" novalidate>
                            <input type="hidden" name="accion" value="guardar">
                            <input type="hidden" name="id" value="<?= $centroEdicion ? (int)$centroEdicion['id'] : '' ?>">

                            <!-- Código Sanitario SICM -->
                            <div class="mb-2">
                                <label for="codigo_sicm" class="form-label small fw-semibold mb-1">Código Sanitario SICM (Único)</label>
                                <input type="text" class="form-control form-control-sm font-monospace text-uppercase" id="codigo_sicm" name="codigo_sicm"
                                    placeholder="Ej: AMB-MER-004" required maxlength="30"
                                    value="<?= $centroEdicion ? htmlspecialchars($centroEdicion['codigo_sicm']) : '' ?>">
                            </div>

                            <!-- Nombre Oficial -->
                            <div class="mb-2">
                                <label for="nombre" class="form-label small fw-semibold mb-1">Nombre Oficial del Centro</label>
                                <input type="text" class="form-control form-control-sm" id="nombre" name="nombre"
                                    placeholder="Ej: Ambulatorio Urbano Los Curos" required maxlength="120"
                                    value="<?= $centroEdicion ? htmlspecialchars($centroEdicion['nombre']) : '' ?>">
                            </div>

                            <!-- Clasificación Sanitaria (Tabla Maestra 1) -->
                            <div class="mb-2">
                                <label for="tipo_centro_id" class="form-label small fw-semibold mb-1">Clasificación Sanitaria (Tabla Maestra)</label>
                                <select class="form-select form-select-sm" id="tipo_centro_id" name="tipo_centro_id" required>
                                    <option value="">-- Seleccionar Tipo --</option>
                                    <?php foreach ($tiposCentro as $t): ?>
                                        <?php 
                                            $sel = ($centroEdicion && (int)$centroEdicion['tipo_centro_id'] === (int)$t['id']) ? 'selected' : '';
                                        ?>
                                        <option value="<?= (int)$t['id'] ?>" <?= $sel ?>>
                                            <?= htmlspecialchars($t['nombre']) ?> (<?= htmlspecialchars($t['codigo']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Médico Director & Horario de Atención -->
                            <div class="row g-2 mb-2">
                                <div class="col-md-6">
                                    <label for="medico_director" class="form-label small fw-semibold mb-1">Médico Director / MPPS</label>
                                    <input type="text" class="form-control form-control-sm" id="medico_director" name="medico_director"
                                        placeholder="Ej: Dra. Carmen Gil" maxlength="120"
                                        value="<?= $centroEdicion ? htmlspecialchars($centroEdicion['medico_director'] ?? '') : '' ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="horario_atencion" class="form-label small fw-semibold mb-1">Horario Operativo</label>
                                    <input type="text" class="form-control form-control-sm" id="horario_atencion" name="horario_atencion"
                                        placeholder="Ej: 24 Horas / 7:00-19:00" maxlength="100"
                                        value="<?= $centroEdicion ? htmlspecialchars($centroEdicion['horario_atencion'] ?? '') : '' ?>">
                                </div>
                            </div>

                            <!-- Dirección Física -->
                            <div class="mb-2">
                                <label for="direccion" class="form-label small fw-semibold mb-1">Dirección Física / Sector</label>
                                <textarea class="form-control form-control-sm" id="direccion" name="direccion" rows="2"
                                    placeholder="Sector, calle o avenida donde opera el centro" required maxlength="255"><?= $centroEdicion ? htmlspecialchars($centroEdicion['direccion']) : '' ?></textarea>
                            </div>

                            <!-- Teléfono -->
                            <div class="mb-3">
                                <label for="telefono_principal" class="form-label small fw-semibold mb-1">Teléfono de Emergencia / Contacto</label>
                                <input type="text" class="form-control form-control-sm" id="telefono_principal" name="telefono_principal"
                                    placeholder="Ej: 0274-2521100" maxlength="30"
                                    value="<?= $centroEdicion ? htmlspecialchars($centroEdicion['telefono_principal'] ?? '') : '' ?>">
                            </div>

                            <!-- CAPACIDADES OPERATIVAS (Catálogo Maestro N:M) -->
                            <div class="mb-3 p-2 bg-light border rounded">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="small fw-bold text-dark">⚙️ Capacidades y Recursos (N:M)</span>
                                    <span class="badge bg-secondary" style="font-size: 0.65rem;">Catálogo Dinámico</span>
                                </div>
                                <div class="text-muted small mb-2" style="font-size: 0.74rem;">
                                    Generado dinámicamente desde <code>recursos_operativos</code> sin tocar código PHP.
                                </div>
                                
                                <div class="row g-1">
                                    <?php foreach ($catalogoRecursos as $rec): ?>
                                        <?php 
                                            $checked = in_array((int)$rec['id'], $recursosEdicionIds, true) ? 'checked' : '';
                                        ?>
                                        <div class="col-12">
                                            <div class="form-check form-check-sm mb-1">
                                                <input class="form-check-input" type="checkbox" name="recursos[]" 
                                                       value="<?= (int)$rec['id'] ?>" id="rec_<?= (int)$rec['id'] ?>" <?= $checked ?>>
                                                <label class="form-check-label small" for="rec_<?= (int)$rec['id'] ?>">
                                                    <?= getRecursoIcon($rec['codigo']) ?> <strong><?= htmlspecialchars($rec['nombre']) ?></strong>
                                                    <span class="d-block text-muted" style="font-size: 0.70rem; line-height: 1.1;">
                                                        <?= htmlspecialchars($rec['descripcion'] ?? '') ?>
                                                    </span>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Botones de Acción -->
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary btn-sm w-100 fw-semibold">
                                    <?= $centroEdicion ? 'Actualizar Ficha de Centro' : 'Registrar Ambulatorio' ?>
                                </button>
                                <?php if ($centroEdicion): ?>
                                    <a href="index.php" class="btn btn-outline-secondary btn-sm w-50">Cancelar</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- TABLA DE CENTROS DE SALUD -->
            <div class="col-xl-8 col-lg-8">
                <div class="card border">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center py-2 px-3 border-bottom">
                        <div class="d-flex align-items-center gap-2">
                            <span class="fw-bold text-dark fs-6">Red de Ambulatorios Municipales</span>
                            <span class="badge bg-primary rounded-pill"><?= count($centros) ?> centros</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <!-- Conmutador de Filtro de Estado -->
                            <div class="btn-group btn-group-sm nav-status" role="group">
                                <a href="index.php?estado=activos<?= $busquedaCentro !== '' ? '&q=' . urlencode($busquedaCentro) : '' ?>" 
                                   class="btn btn-sm btn-outline-secondary <?= $filtroEstado === 'activos' ? 'active' : '' ?>">
                                    Operativos
                                </a>
                                <a href="index.php?estado=inactivos<?= $busquedaCentro !== '' ? '&q=' . urlencode($busquedaCentro) : '' ?>" 
                                   class="btn btn-sm btn-outline-secondary <?= $filtroEstado === 'inactivos' ? 'active' : '' ?>">
                                    Desactivados (Soft Delete)
                                </a>
                                <a href="index.php?estado=todos<?= $busquedaCentro !== '' ? '&q=' . urlencode($busquedaCentro) : '' ?>" 
                                   class="btn btn-sm btn-outline-secondary <?= $filtroEstado === 'todos' ? 'active' : '' ?>">
                                    Todos
                                </a>
                            </div>

                            <!-- Buscador en Servidor -->
                            <form method="GET" action="index.php" class="d-flex m-0">
                                <input type="hidden" name="estado" value="<?= htmlspecialchars($filtroEstado) ?>">
                                <input type="text" name="q" class="form-control form-control-sm" style="width: 180px;"
                                    placeholder="Buscar..." value="<?= htmlspecialchars($busquedaCentro) ?>">
                                <?php if ($busquedaCentro !== ''): ?>
                                    <a href="index.php?estado=<?= htmlspecialchars($filtroEstado) ?>" class="btn btn-outline-secondary btn-sm ms-1" title="Limpiar búsqueda">✕</a>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                    
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover table-sm align-middle mb-0">
                                <thead class="table-light text-muted small text-uppercase" style="font-size: 0.73rem;">
                                    <tr>
                                        <th style="width: 45px;">ID</th>
                                        <th style="width: 125px;">Código SICM</th>
                                        <th>Centro y Responsable Sanitario</th>
                                        <th style="width: 140px;">Clasificación</th>
                                        <th>Capacidades Operativas (N:M)</th>
                                        <th style="width: 90px;">Estado</th>
                                        <th style="width: 130px;" class="text-end pe-3">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($centros)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-4">
                                                No se encontraron centros de salud bajo el criterio seleccionado.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($centros as $c): ?>
                                            <?php 
                                                $recCentro = $recursosPorCentro[(int)$c['id']] ?? [];
                                            ?>
                                            <tr>
                                                <td class="text-muted fw-bold">#<?= (int)$c['id'] ?></td>
                                                <td>
                                                    <span class="sicm-code border px-2 py-0 rounded"><?= htmlspecialchars($c['codigo_sicm']) ?></span>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($c['nombre']) ?></div>
                                                    <div class="text-muted small" style="font-size: 0.74rem;">
                                                        <?php if (!empty($c['medico_director'])): ?>
                                                            <span>👨‍⚕️ <?= htmlspecialchars($c['medico_director']) ?></span> | 
                                                        <?php endif; ?>
                                                        <span>📍 <?= htmlspecialchars($c['direccion']) ?></span>
                                                        <?php if (!empty($c['horario_atencion'])): ?>
                                                            | <span>🕒 <?= htmlspecialchars($c['horario_atencion']) ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($c['tipo_nombre']) ?></span>
                                                </td>
                                                <td>
                                                    <?php if (empty($recCentro)): ?>
                                                        <span class="text-muted fst-italic small" style="font-size: 0.72rem;">Sin equipamiento registrado</span>
                                                    <?php else: ?>
                                                        <div class="d-flex flex-wrap gap-1">
                                                            <?php foreach ($recCentro as $r): ?>
                                                                <span class="badge badge-resource rounded-pill" title="<?= htmlspecialchars($r['detalle_capacidad'] ?? $r['nombre']) ?>">
                                                                    <?= getRecursoIcon($r['codigo']) ?> <?= htmlspecialchars($r['nombre']) ?>
                                                                </span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ((int)$c['activo'] === 1): ?>
                                                        <span class="badge bg-success">Operativo</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Inactivo (Soft)</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end pe-3">
                                                    <div class="d-flex justify-content-end align-items-center gap-1">
                                                        <a href="index.php?editar=<?= (int)$c['id'] ?>&estado=<?= htmlspecialchars($filtroEstado) ?>" 
                                                           class="btn btn-outline-primary btn-sm py-0 px-2" style="font-size: 0.78rem;">Editar</a>
                                                        
                                                        <?php if ((int)$c['activo'] === 1): ?>
                                                            <a href="procesar.php?accion=cambiar_estado&id=<?= (int)$c['id'] ?>&estado=0" 
                                                               class="btn btn-link text-danger p-0 small text-decoration-none ms-1"
                                                               onclick="return confirm('¿Desactivar ambulatorio mediante Soft Delete?')"
                                                               title="Desactivar">Desactivar</a>
                                                        <?php else: ?>
                                                            <a href="procesar.php?accion=cambiar_estado&id=<?= (int)$c['id'] ?>&estado=1" 
                                                               class="btn btn-outline-success btn-sm py-0 px-2 ms-1" style="font-size: 0.78rem;"
                                                               onclick="return confirm('¿Reactivar centro en la red municipal?')"
                                                               title="Reactivar">Reactivar</a>
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
                    <div class="card-footer bg-light py-2 px-3 text-muted small d-flex justify-content-between align-items-center border-top">
                        <span><strong>Arquitectura Senior:</strong> Normalización 3FN con tablas maestras dinámicas (<code>tipos_centro_salud</code>, <code>recursos_operativos</code>, <code>centro_recursos</code>).</span>
                        <span><strong>Integridad:</strong> Cero borrado físico en centros operativos.</span>
                    </div>
                </div>
            </div>

        </div>

        <!-- ================================================================ -->
        <!-- SECCIÓN INFERIOR: BITÁCORA FORENSE DE AUDITORÍA (MISIÓN 2)       -->
        <!-- ================================================================ -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card border border-dark-subtle shadow-sm">
                    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center py-2 px-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="fs-6 fw-bold">🛡️ Misión 2: Bitácora Forense de Auditoría en Tiempo Real (<code>logs_auditoria</code>)</span>
                            <span class="badge bg-info text-dark rounded-pill"><?= count($logs) ?> registros recientes</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <!-- Filtro de Acción -->
                            <form method="GET" action="index.php" class="d-flex align-items-center gap-1 m-0">
                                <input type="hidden" name="estado" value="<?= htmlspecialchars($filtroEstado) ?>">
                                <select name="log_accion" class="form-select form-select-sm" style="width: 170px;" onchange="this.form.submit()">
                                    <option value="todas">-- Todas las acciones --</option>
                                    <?php foreach ($accionesDisponibles as $acc): ?>
                                        <option value="<?= htmlspecialchars($acc) ?>" <?= $filtroAccionLog === $acc ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($acc) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="log_q" class="form-control form-control-sm" style="width: 150px;" 
                                       placeholder="Buscar en logs..." value="<?= htmlspecialchars($busquedaLog) ?>">
                                <button type="submit" class="btn btn-outline-light btn-sm py-0 px-2">Filtrar</button>
                                <?php if ($filtroAccionLog !== 'todas' || $busquedaLog !== ''): ?>
                                    <a href="index.php?estado=<?= htmlspecialchars($filtroEstado) ?>" class="btn btn-outline-secondary btn-sm py-0 px-2 text-white" title="Limpiar filtro">✕</a>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>

                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover table-sm align-middle mb-0">
                                <thead class="table-secondary text-dark small text-uppercase" style="font-size: 0.72rem;">
                                    <tr>
                                        <th style="width: 60px;"># Log</th>
                                        <th style="width: 145px;">Fecha / Hora</th>
                                        <th style="width: 180px;">Operador Responsable</th>
                                        <th style="width: 160px;">Acción Auditada</th>
                                        <th style="width: 140px;">Tabla / ID Afectado</th>
                                        <th style="width: 110px;">IP Origen</th>
                                        <th>Snapshot Forense (JSON Estructurado)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($logs)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-4">
                                                No se registraron eventos de auditoría bajo el criterio de búsqueda.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($logs as $l): ?>
                                            <?php 
                                                // Determinación de badge para la acción
                                                $accionBadge = match($l['accion']) {
                                                    'CREAR_CENTRO'      => 'bg-success',
                                                    'EDITAR_CENTRO'     => 'bg-primary',
                                                    'DESACTIVAR_CENTRO' => 'bg-danger',
                                                    'REACTIVAR_CENTRO'  => 'bg-warning text-dark',
                                                    'INICIALIZAR_SISTEMA' => 'bg-secondary',
                                                    default             => 'bg-dark'
                                                };
                                            ?>
                                            <tr>
                                                <td class="font-monospace text-muted fw-bold">#<?= (int)$l['id'] ?></td>
                                                <td class="small font-monospace"><?= htmlspecialchars($l['fecha_hora']) ?></td>
                                                <td>
                                                    <div class="fw-semibold text-dark small"><?= htmlspecialchars($l['usuario_nombre'] ?? 'Sistema Central') ?></div>
                                                    <span class="badge bg-light text-muted border" style="font-size: 0.65rem;">
                                                        <?= htmlspecialchars($l['rol_nombre'] ?? 'Trazabilidad Base') ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge <?= $accionBadge ?>" style="font-size: 0.72rem;">
                                                        <?= htmlspecialchars($l['accion']) ?>
                                                    </span>
                                                </td>
                                                <td class="small">
                                                    <code><?= htmlspecialchars($l['tabla_afectada']) ?></code> 
                                                    <span class="badge bg-dark-subtle text-dark">#<?= (int)$l['registro_id'] ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-light text-dark font-monospace border"><?= htmlspecialchars($l['ip_origen']) ?></span>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-outline-dark btn-sm py-0 px-2 btn-ver-json"
                                                            data-log-id="<?= (int)$l['id'] ?>"
                                                            data-log-accion="<?= htmlspecialchars($l['accion']) ?>"
                                                            data-log-fecha="<?= htmlspecialchars($l['fecha_hora']) ?>"
                                                            data-log-ip="<?= htmlspecialchars($l['ip_origen']) ?>"
                                                            data-log-json="<?= htmlspecialchars($l['detalles_json'] ?? '{}', ENT_QUOTES, 'UTF-8') ?>"
                                                            style="font-size: 0.74rem;">
                                                        🔍 Inspeccionar Delta / JSON
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer bg-light py-2 px-3 text-muted small d-flex justify-content-between align-items-center border-top">
                        <span><strong>Principio Forense:</strong> Los logs de auditoría son inmutables (WORM: <em>Write Once, Read Many</em>). No admiten borrado ni edición bajo ningún rol.</span>
                        <span><strong>JSON Nativo:</strong> Almacena deltas comparativos de estado (antes y después) para peritaje informático.</span>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- MODAL DE INSPECCIÓN FORENSE JSON -->
    <div class="modal fade" id="modalAuditoria" tabindex="-1" aria-labelledby="modalAuditoriaLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-dark text-white py-2 px-3">
                    <h6 class="modal-title font-monospace" id="modalAuditoriaLabel">
                        🔍 Peritaje Forense de Log <span id="modal-log-id" class="text-info">#</span>
                    </h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-3">
                    <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                        <div>
                            <span class="text-muted small">Acción:</span> <strong id="modal-log-accion" class="badge bg-primary"></strong>
                        </div>
                        <div>
                            <span class="text-muted small">Fecha / Hora:</span> <code id="modal-log-fecha" class="text-dark"></code>
                        </div>
                        <div>
                            <span class="text-muted small">IP Origen:</span> <code id="modal-log-ip" class="text-dark"></code>
                        </div>
                    </div>

                    <!-- Pestañas de Vista: Vista Amigable / JSON Crudo -->
                    <ul class="nav nav-pills mb-2" id="pills-tab" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active py-1 px-3 small" id="tab-resumen-btn" data-bs-toggle="pill" data-bs-target="#tab-resumen" type="button" role="tab">Resumen Delta</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link py-1 px-3 small" id="tab-json-btn" data-bs-toggle="pill" data-bs-target="#tab-json" type="button" role="tab">Payload JSON Crudo</button>
                        </li>
                    </ul>

                    <div class="tab-content" id="pills-tabContent">
                        <!-- Pestaña 1: Resumen Delta -->
                        <div class="tab-pane fade show active" id="tab-resumen" role="tabpanel">
                            <div id="modal-delta-content" class="p-2 border rounded bg-light" style="max-height: 380px; overflow-y: auto;">
                                <!-- Se llena dinámicamente con JS -->
                            </div>
                        </div>

                        <!-- Pestaña 2: JSON Crudo -->
                        <div class="tab-pane fade" id="tab-json" role="tabpanel">
                            <pre id="modal-log-pre" class="json-preview mb-0"></pre>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-1 px-3 bg-light">
                    <span class="text-muted small me-auto">Registro firmado inmutablemente en MariaDB 11.4 InnoDB</span>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Nativo en Cliente para Validación (Sin jQuery, Sin frameworks) -->
    <script src="../public/js/validaciones.js"></script>
    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Script para el Visor Forense de Logs JSON -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const modalEl = document.getElementById('modalAuditoria');
            const bsModal = new bootstrap.Modal(modalEl);

            const modalLogId = document.getElementById('modal-log-id');
            const modalLogAccion = document.getElementById('modal-log-accion');
            const modalLogFecha = document.getElementById('modal-log-fecha');
            const modalLogIp = document.getElementById('modal-log-ip');
            const modalLogPre = document.getElementById('modal-log-pre');
            const modalDeltaContent = document.getElementById('modal-delta-content');

            document.querySelectorAll('.btn-ver-json').forEach(btn => {
                btn.addEventListener('click', () => {
                    const id = btn.getAttribute('data-log-id');
                    const accion = btn.getAttribute('data-log-accion');
                    const fecha = btn.getAttribute('data-log-fecha');
                    const ip = btn.getAttribute('data-log-ip');
                    const rawJson = btn.getAttribute('data-log-json');

                    modalLogId.textContent = '#' + id;
                    modalLogAccion.textContent = accion;
                    modalLogFecha.textContent = fecha;
                    modalLogIp.textContent = ip;

                    let parsed = {};
                    try {
                        parsed = JSON.parse(rawJson);
                        modalLogPre.textContent = JSON.stringify(parsed, null, 2);
                    } catch (e) {
                        modalLogPre.textContent = rawJson;
                    }

                    // Renderizar Resumen Delta Inteligente
                    let deltaHtml = '';
                    if (parsed.evento) {
                        deltaHtml += `<div class="mb-2"><strong>Descripción del Evento:</strong> <span class="text-primary">${parsed.evento}</span></div>`;
                    }

                    if (parsed.delta_especifico && Object.keys(parsed.delta_especifico).length > 0) {
                        deltaHtml += `<div class="table-responsive"><table class="table table-bordered table-sm bg-white mb-2">
                            <thead class="table-light"><tr><th>Campo Alterado</th><th>Valor Previo</th><th>Nuevo Valor</th></tr></thead><tbody>`;
                        for (const [campo, vals] of Object.entries(parsed.delta_especifico)) {
                            deltaHtml += `<tr>
                                <td><code>${campo}</code></td>
                                <td class="text-danger">${JSON.stringify(vals.antes)}</td>
                                <td class="text-success">${JSON.stringify(vals.despues)}</td>
                            </tr>`;
                        }
                        deltaHtml += `</tbody></table></div>`;
                    } else if (parsed.datos_creados) {
                        deltaHtml += `<div class="table-responsive"><table class="table table-bordered table-sm bg-white mb-2">
                            <thead class="table-light"><tr><th>Campo Inicial</th><th>Valor Registrado</th></tr></thead><tbody>`;
                        for (const [campo, val] of Object.entries(parsed.datos_creados)) {
                            deltaHtml += `<tr><td><code>${campo}</code></td><td>${JSON.stringify(val)}</td></tr>`;
                        }
                        deltaHtml += `</tbody></table></div>`;
                    } else if (parsed.estado_anterior !== undefined && parsed.nuevo_estado !== undefined) {
                        deltaHtml += `<div class="alert alert-secondary py-2 small mb-0">
                            <strong>Cambio de Estado:</strong> Pasó de <code>activo = ${parsed.estado_anterior}</code> a <code>activo = ${parsed.nuevo_estado}</code>.
                        </div>`;
                    } else {
                        deltaHtml += `<pre class="mb-0 bg-white p-2 border rounded font-monospace small">${JSON.stringify(parsed, null, 2)}</pre>`;
                    }

                    modalDeltaContent.innerHTML = deltaHtml;
                    bsModal.show();
                });
            });
        });
    </script>
</body>

</html>
