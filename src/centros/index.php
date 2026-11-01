<?php
// src/centros/index.php
// Vista y Controlador Operativo para la Red de Ambulatorios Municipales (Usa vw_centros_salud)
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

// ----------------------------------------------------------------------------
// 1. FILTROS Y CONSULTA USANDO LA VISTA OPERATIVA (vw_centros_salud)
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

// Consulta directa a la VISTA SQL (3FN)
$sqlCentros = "SELECT c.id, c.codigo_sicm, c.nombre, c.tipo_centro_id, 
                      c.tipo_nombre, c.tipo_codigo,
                      c.medico_director, c.horario_atencion,
                      c.direccion, c.telefono_principal, c.activo, c.created_at, c.updated_at
               FROM vw_centros_salud c
               {$whereCentros}
               ORDER BY c.id DESC";

$stmtCentros = $pdo->prepare($sqlCentros);
$stmtCentros->execute($paramsCentros);
$centros = $stmtCentros->fetchAll();

// ----------------------------------------------------------------------------
// 2. RECURSOS OPERATIVOS POR CENTRO (Relación N:M)
// ----------------------------------------------------------------------------
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
// 3. CATÁLOGOS MAESTROS PARA POBLAR SELECTS Y CHECKBOXES DINÁMICOS
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
// 5. HISTORIAL CONTEXTUAL DE LOGS (Solo para centros visibles en pantalla)
// ----------------------------------------------------------------------------
$logsPorCentro = [];
if (!empty($centros)) {
    $ids = array_column($centros, 'id');
    $inClause = implode(',', array_map('intval', $ids));
    $sqlContextLogs = "SELECT log_id, fecha_hora, operador, rol_operador, accion, registro_id, detalles_json, ip_origen
                       FROM vw_logs_auditoria
                       WHERE tabla_afectada = 'centros_salud' AND registro_id IN ({$inClause})
                       ORDER BY log_id DESC";
    $stmtCtx = $pdo->query($sqlContextLogs);
    while ($l = $stmtCtx->fetch()) {
        $logsPorCentro[(int)$l['registro_id']][] = $l;
    }
}

// Iconografía para recursos operativos
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
    <title>SICM | Red de Ambulatorios y Centros de Salud</title>
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

        .nav-link-tech {
            color: #94a3b8;
            font-size: 0.82rem;
            text-decoration: none;
            padding: 0.25rem 0.65rem;
            border-radius: 4px;
        }

        .nav-link-tech:hover {
            color: #ffffff;
            background-color: #1e293b;
        }

        .nav-link-tech.active {
            color: #38bdf8;
            background-color: #1e293b;
            font-weight: 600;
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
            max-height: 350px;
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

    <!-- Header Técnico de Navegación Unificada -->
    <header class="header-slim text-white py-2 px-3 d-flex justify-content-between align-items-center shadow-sm">
        <div class="d-flex align-items-center gap-3">
            <span class="fw-bold tracking-wide text-info fs-6">SICM-RED</span>
            <span class="text-white-50">|</span>
            <nav class="d-flex gap-1">
                <a href="index.php" class="nav-link-tech active">🏢 Red de Centros</a>
                <a href="../maestras/index.php" class="nav-link-tech">⚙️ Tablas Maestras</a>
                <a href="../auditoria/index.php" class="nav-link-tech">🛡️ Auditoría Forense</a>
            </nav>
        </div>
        <div class="text-white-50 font-monospace small" style="font-size: 0.76rem;">
            Vista: <span class="text-white">vw_centros_salud</span> | Arquitectura: <span class="text-white">3FN Relacional N:M</span>
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

        <div class="row g-3">
            
            <!-- FORMULARIO DE REGISTRO / EDICIÓN -->
            <div class="col-xl-4 col-lg-4">
                <div class="card border">
                    <div class="card-header bg-white text-dark fw-bold border-bottom py-2 px-3 d-flex justify-content-between align-items-center">
                        <span><?= $centroEdicion ? '✏️ Modificar Ambulatorio #' . (int)$centroEdicion['id'] : '➕ Registrar Nuevo Ambulatorio' ?></span>
                        <a href="../maestras/index.php" class="badge bg-light text-primary text-decoration-none border" title="Administrar clasificaciones y recursos">⚙️ Configurar Maestras</a>
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
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label for="tipo_centro_id" class="form-label small fw-semibold mb-0">Clasificación Sanitaria (Tabla Maestra)</label>
                                    <a href="../maestras/index.php?tab=tipos" class="small text-decoration-none" style="font-size: 0.72rem;">+ Nueva</a>
                                </div>
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
                                    <a href="../maestras/index.php?tab=recursos" class="badge bg-secondary text-decoration-none" style="font-size: 0.65rem;">+ Gestionar</a>
                                </div>
                                <div class="text-muted small mb-2" style="font-size: 0.72rem;">
                                    Catálogo dinámico desde <code>recursos_operativos</code> adaptable a cualquier empresa.
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
                            <span class="fw-bold text-dark fs-6">Red Asistencial Municipal</span>
                            <span class="badge bg-primary rounded-pill"><?= count($centros) ?> centros</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <div class="btn-group btn-group-sm nav-status" role="group">
                                <a href="index.php?estado=activos<?= $busquedaCentro !== '' ? '&q=' . urlencode($busquedaCentro) : '' ?>" 
                                   class="btn btn-sm btn-outline-secondary <?= $filtroEstado === 'activos' ? 'active' : '' ?>">
                                    Operativos
                                </a>
                                <a href="index.php?estado=inactivos<?= $busquedaCentro !== '' ? '&q=' . urlencode($busquedaCentro) : '' ?>" 
                                   class="btn btn-sm btn-outline-secondary <?= $filtroEstado === 'inactivos' ? 'active' : '' ?>">
                                    Desactivados
                                </a>
                                <a href="index.php?estado=todos<?= $busquedaCentro !== '' ? '&q=' . urlencode($busquedaCentro) : '' ?>" 
                                   class="btn btn-sm btn-outline-secondary <?= $filtroEstado === 'todos' ? 'active' : '' ?>">
                                    Todos
                                </a>
                            </div>

                            <form method="GET" action="index.php" class="d-flex m-0">
                                <input type="hidden" name="estado" value="<?= htmlspecialchars($filtroEstado) ?>">
                                <input type="text" name="q" class="form-control form-control-sm" style="width: 170px;"
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
                                        <th style="width: 120px;">Código SICM</th>
                                        <th>Centro y Responsable Sanitario</th>
                                        <th style="width: 140px;">Clasificación</th>
                                        <th>Capacidades Operativas (N:M)</th>
                                        <th style="width: 85px;">Estado</th>
                                        <th style="width: 155px;" class="text-end pe-3">Acciones</th>
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
                                                $logsEsteCentro = $logsPorCentro[(int)$c['id']] ?? [];
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
                                                        <span class="badge bg-secondary">Inactivo</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end pe-3">
                                                    <div class="d-flex justify-content-end align-items-center gap-1">
                                                        <!-- BOTÓN CONTEXTUAL PRO: HISTORIAL POR FILA (Misión 2 desacoplada) -->
                                                        <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 btn-historial-centro"
                                                                data-centro-id="<?= (int)$c['id'] ?>"
                                                                data-centro-nombre="<?= htmlspecialchars($c['nombre']) ?>"
                                                                data-centro-codigo="<?= htmlspecialchars($c['codigo_sicm']) ?>"
                                                                data-centro-logs="<?= htmlspecialchars(json_encode($logsEsteCentro, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
                                                                title="Ver trazabilidad de este registro"
                                                                style="font-size: 0.74rem;">
                                                            🕒 Historial (<?= count($logsEsteCentro) ?>)
                                                        </button>

                                                        <a href="index.php?editar=<?= (int)$c['id'] ?>&estado=<?= htmlspecialchars($filtroEstado) ?>" 
                                                           class="btn btn-outline-primary btn-sm py-0 px-2" style="font-size: 0.74rem;">Editar</a>
                                                        
                                                        <?php if ((int)$c['activo'] === 1): ?>
                                                            <a href="procesar.php?accion=cambiar_estado&id=<?= (int)$c['id'] ?>&estado=0" 
                                                               class="btn btn-link text-danger p-0 small text-decoration-none ms-1"
                                                               onclick="return confirm('¿Desactivar ambulatorio mediante Soft Delete?')"
                                                               title="Desactivar">✕</a>
                                                        <?php else: ?>
                                                            <a href="procesar.php?accion=cambiar_estado&id=<?= (int)$c['id'] ?>&estado=1" 
                                                               class="btn btn-outline-success btn-sm py-0 px-1 ms-1" style="font-size: 0.74rem;"
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
                        <span><strong>Consulta Desacoplada:</strong> Pantalla alimentada por <code>vw_centros_salud</code>. Cero contaminación con logs globales.</span>
                        <a href="../auditoria/index.php" class="text-decoration-none fw-semibold">🛡️ Ir a Consola Forense Global &rarr;</a>
                    </div>
                </div>
            </div>

        </div>

    </div>

    <!-- MODAL CONTEXTUAL: HISTORIAL FORENSE POR CENTRO (Misión 2 Desacoplada) -->
    <div class="modal fade" id="modalHistorialCentro" tabindex="-1" aria-labelledby="modalHistorialLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-dark text-white py-2 px-3">
                    <h6 class="modal-title font-monospace" id="modalHistorialLabel">
                        🕒 Historial Quirúrgico de Auditoría: <span id="modal-hist-codigo" class="text-info"></span>
                    </h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-3">
                    <div class="mb-2 pb-2 border-bottom">
                        <span class="text-muted small">Ambulatorio:</span> <strong id="modal-hist-nombre" class="text-dark"></strong>
                    </div>

                    <div id="modal-hist-vacio" class="text-center text-muted py-3 d-none">
                        No hay eventos registrados aún para este ambulatorio.
                    </div>

                    <div id="modal-hist-lista" class="table-responsive">
                        <table class="table table-bordered table-hover table-sm bg-white mb-0">
                            <thead class="table-light small text-uppercase" style="font-size: 0.72rem;">
                                <tr>
                                    <th style="width: 55px;"># Log</th>
                                    <th style="width: 145px;">Fecha / Hora</th>
                                    <th>Operador</th>
                                    <th style="width: 150px;">Acción</th>
                                    <th style="width: 110px;">IP Origen</th>
                                    <th>Detalles / Delta</th>
                                </tr>
                            </thead>
                            <tbody id="modal-hist-tbody">
                                <!-- Se llena dinámicamente con JS -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer py-1 px-3 bg-light d-flex justify-content-between">
                    <a href="../auditoria/index.php" class="small text-decoration-none">Ver auditoría completa de toda la red municipal &rarr;</a>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Nativo -->
    <script src="../public/js/validaciones.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Script del Modal Contextual de Historial por Fila -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const modalEl = document.getElementById('modalHistorialCentro');
            const bsModal = new bootstrap.Modal(modalEl);

            const modalCodigo = document.getElementById('modal-hist-codigo');
            const modalNombre = document.getElementById('modal-hist-nombre');
            const tbody = document.getElementById('modal-hist-tbody');
            const vacioMsg = document.getElementById('modal-hist-vacio');
            const listaTabla = document.getElementById('modal-hist-lista');

            document.querySelectorAll('.btn-historial-centro').forEach(btn => {
                btn.addEventListener('click', () => {
                    const nombre = btn.getAttribute('data-centro-nombre');
                    const codigo = btn.getAttribute('data-centro-codigo');
                    const rawLogs = btn.getAttribute('data-centro-logs');

                    modalCodigo.textContent = codigo;
                    modalNombre.textContent = nombre;
                    tbody.innerHTML = '';

                    let logs = [];
                    try {
                        logs = JSON.parse(rawLogs);
                    } catch (e) {
                        logs = [];
                    }

                    if (!logs || logs.length === 0) {
                        vacioMsg.classList.remove('d-none');
                        listaTabla.classList.add('d-none');
                    } else {
                        vacioMsg.classList.add('d-none');
                        listaTabla.classList.remove('d-none');

                        logs.forEach(l => {
                            let badgeAccion = 'bg-dark';
                            if (l.accion === 'CREAR_CENTRO') badgeAccion = 'bg-success';
                            else if (l.accion === 'EDITAR_CENTRO') badgeAccion = 'bg-primary';
                            else if (l.accion === 'DESACTIVAR_CENTRO') badgeAccion = 'bg-danger';
                            else if (l.accion === 'REACTIVAR_CENTRO') badgeAccion = 'bg-warning text-dark';

                            let detalles = {};
                            try {
                                detalles = JSON.parse(l.detalles_json);
                            } catch (e) {
                                detalles = {};
                            }

                            let infoHtml = '';
                            if (detalles.evento) {
                                infoHtml += `<div class="fw-semibold text-primary small">${detalles.evento}</div>`;
                            }
                            if (detalles.campos_alterados && detalles.campos_alterados.length > 0) {
                                infoHtml += `<div class="text-muted small">Campos alterados: <code>${detalles.campos_alterados.join(', ')}</code></div>`;
                            }

                            const tr = document.createElement('tr');
                            tr.innerHTML = `
                                <td class="font-monospace fw-bold text-muted">#${l.log_id}</td>
                                <td class="small font-monospace">${l.fecha_hora}</td>
                                <td class="small">
                                    <strong>${l.operador}</strong>
                                    <span class="badge bg-light text-muted border d-block" style="font-size: 0.65rem;">${l.rol_operador}</span>
                                </td>
                                <td><span class="badge ${badgeAccion}" style="font-size: 0.70rem;">${l.accion}</span></td>
                                <td class="font-monospace small">${l.ip_origen}</td>
                                <td>${infoHtml || '<span class="text-muted small">Registro estándar</span>'}</td>
                            `;
                            tbody.appendChild(tr);
                        });
                    }

                    bsModal.show();
                });
            });
        });
    </script>
</body>

</html>
