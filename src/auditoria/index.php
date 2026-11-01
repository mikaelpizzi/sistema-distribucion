<?php
// src/auditoria/index.php
// Consola Global de Auditoría Forense y Peritaje Informático (Misión 2 - Exclusivo rol_admin)
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

// ----------------------------------------------------------------------------
// FILTROS Y CONSULTA USANDO LA VISTA DESACOPLADA (vw_logs_auditoria)
// ----------------------------------------------------------------------------
$filtroAccion = $_GET['accion_filtro'] ?? 'todas';
$busqueda = trim($_GET['q'] ?? '');

$condiciones = [];
$params = [];

if ($filtroAccion !== 'todas' && $filtroAccion !== '') {
    $condiciones[] = 'l.accion = :acc';
    $params[':acc'] = $filtroAccion;
}

if ($busqueda !== '') {
    $condiciones[] = '(l.accion LIKE :q1 OR l.tabla_afectada LIKE :q2 OR l.ip_origen LIKE :q3 OR l.operador LIKE :q4 OR CAST(l.registro_id AS CHAR) = :q5)';
    $params[':q1'] = '%' . $busqueda . '%';
    $params[':q2'] = '%' . $busqueda . '%';
    $params[':q3'] = '%' . $busqueda . '%';
    $params[':q4'] = '%' . $busqueda . '%';
    $params[':q5'] = $busqueda;
}

$whereSql = !empty($condiciones) ? 'WHERE ' . implode(' AND ', $condiciones) : '';

// Consulta a la vista SQL desacoplada (3FN)
$sql = "SELECT l.log_id, l.fecha_hora, l.usuario_id, l.operador, l.rol_operador, l.email_operador,
               l.accion, l.tabla_afectada, l.registro_id, l.ip_origen, l.detalles_json
        FROM vw_logs_auditoria l
        {$whereSql}
        ORDER BY l.log_id DESC
        LIMIT 50";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Acciones únicas registradas para el desplegable
$acciones = $pdo->query("SELECT DISTINCT accion FROM logs_auditoria ORDER BY accion ASC")->fetchAll(PDO::FETCH_COLUMN);

// Métrica global
$totalLogs = $pdo->query("SELECT COUNT(*) FROM logs_auditoria")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SICM | Consola Forense de Auditoría</title>
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

        .table > :not(caption) > * > * {
            padding: 0.48rem 0.65rem;
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
    </style>
</head>

<body>

    <!-- Header Técnico de Navegación Unificada -->
    <header class="header-slim text-white py-2 px-3 d-flex justify-content-between align-items-center shadow-sm">
        <div class="d-flex align-items-center gap-3">
            <span class="fw-bold tracking-wide text-info fs-6">SICM-RED</span>
            <span class="text-white-50">|</span>
            <nav class="d-flex gap-1">
                <a href="../centros/index.php" class="nav-link-tech">🏢 Red de Centros</a>
                <a href="../maestras/index.php" class="nav-link-tech">⚙️ Tablas Maestras</a>
                <a href="index.php" class="nav-link-tech active">🛡️ Auditoría Forense</a>
            </nav>
        </div>
        <div class="text-white-50 font-monospace small" style="font-size: 0.76rem;">
            Vista: <span class="text-white">vw_logs_auditoria</span> | Principio: <span class="text-white">WORM (Inmutable)</span> | Total: <span class="badge bg-primary"><?= (int)$totalLogs ?> logs</span>
        </div>
    </header>

    <div class="container-fluid px-3 py-3">

        <!-- Banner Explicativo de Auditoría Forense y RBAC -->
        <div class="alert alert-dark border shadow-sm py-2 px-3 mb-3 d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2 small">
                <span class="fs-5">🛡️</span>
                <div>
                    <strong>Consola Central de Seguridad y Auditoría Forense:</strong> Esta pantalla está reservada exclusivamente para el <code>rol_admin</code> / Auditoría Municipal. Permite la investigación de eventos históricos, peritaje de IP, verificación de deltas JSON y trazabilidad inmutable de toda la red.
                </div>
            </div>
            <span class="badge bg-info text-dark font-monospace">Acceso Privilegiado</span>
        </div>

        <div class="card border">
            <!-- Barra de Filtros y Búsqueda -->
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-2 px-3 border-bottom">
                <div class="d-flex align-items-center gap-2">
                    <span class="fw-bold text-dark fs-6">Bitácora Global de Operaciones</span>
                    <span class="badge bg-secondary rounded-pill"><?= count($logs) ?> visualizados</span>
                </div>
                <form method="GET" action="index.php" class="d-flex align-items-center gap-2 m-0">
                    <select name="accion_filtro" class="form-select form-select-sm" style="width: 200px;" onchange="this.form.submit()">
                        <option value="todas">-- Todas las acciones --</option>
                        <?php foreach ($acciones as $acc): ?>
                            <option value="<?= htmlspecialchars($acc) ?>" <?= $filtroAccion === $acc ? 'selected' : '' ?>>
                                <?= htmlspecialchars($acc) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="q" class="form-control form-control-sm" style="width: 220px;" 
                           placeholder="Buscar por operador, IP, tabla o ID..." value="<?= htmlspecialchars($busqueda) ?>">
                    <button type="submit" class="btn btn-primary btn-sm py-1 px-3">Filtrar</button>
                    <?php if ($filtroAccion !== 'todas' || $busqueda !== ''): ?>
                        <a href="index.php" class="btn btn-outline-secondary btn-sm py-1 px-2" title="Limpiar">✕</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Tabla de Logs -->
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm align-middle mb-0">
                        <thead class="table-light text-muted small text-uppercase" style="font-size: 0.73rem;">
                            <tr>
                                <th style="width: 65px;"># Log</th>
                                <th style="width: 150px;">Fecha / Hora</th>
                                <th>Operador Responsable</th>
                                <th style="width: 170px;">Acción Auditada</th>
                                <th style="width: 150px;">Entidad / ID Afectado</th>
                                <th style="width: 120px;">IP Origen</th>
                                <th style="width: 160px;" class="text-end pe-3">Peritaje</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        No se encontraron eventos de auditoría bajo los filtros seleccionados.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $l): ?>
                                    <?php 
                                        $badgeClass = match($l['accion']) {
                                            'CREAR_CENTRO', 'CREAR_TIPO_CENTRO', 'CREAR_RECURSO_OPERATIVO' => 'bg-success',
                                            'EDITAR_CENTRO' => 'bg-primary',
                                            'DESACTIVAR_CENTRO', 'DESACTIVAR_RECURSO_OPERATIVO' => 'bg-danger',
                                            'REACTIVAR_CENTRO', 'REACTIVAR_RECURSO_OPERATIVO' => 'bg-warning text-dark',
                                            'INICIALIZAR_SISTEMA' => 'bg-secondary',
                                            default => 'bg-dark'
                                        };
                                    ?>
                                    <tr>
                                        <td class="font-monospace text-muted fw-bold">#<?= (int)$l['log_id'] ?></td>
                                        <td class="small font-monospace"><?= htmlspecialchars($l['fecha_hora']) ?></td>
                                        <td>
                                            <div class="fw-semibold text-dark small"><?= htmlspecialchars($l['operador']) ?></div>
                                            <div class="text-muted" style="font-size: 0.70rem;">
                                                <span class="badge bg-light text-dark border"><?= htmlspecialchars($l['rol_operador']) ?></span>
                                                <?php if (!empty($l['email_operador'])): ?>
                                                    <span><?= htmlspecialchars($l['email_operador']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge <?= $badgeClass ?>" style="font-size: 0.72rem;">
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
                                        <td class="text-end pe-3">
                                            <button type="button" class="btn btn-outline-dark btn-sm py-0 px-2 btn-ver-json"
                                                    data-log-id="<?= (int)$l['log_id'] ?>"
                                                    data-log-accion="<?= htmlspecialchars($l['accion']) ?>"
                                                    data-log-fecha="<?= htmlspecialchars($l['fecha_hora']) ?>"
                                                    data-log-ip="<?= htmlspecialchars($l['ip_origen']) ?>"
                                                    data-log-json="<?= htmlspecialchars($l['detalles_json'] ?? '{}', ENT_QUOTES, 'UTF-8') ?>"
                                                    style="font-size: 0.74rem;">
                                                🔍 Ver Delta JSON
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
                <span><strong>Principio WORM:</strong> Ningún usuario, ni siquiera el administrador de base de datos, debe alterar o truncar los registros de auditoría.</span>
                <span><strong>Desacoplamiento:</strong> Alimentado por la vista relacional <code>vw_logs_auditoria</code>.</span>
            </div>
        </div>

    </div>

    <!-- MODAL DE PERITAJE FORENSE JSON -->
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

                    <ul class="nav nav-pills mb-2" id="pills-tab" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active py-1 px-3 small" id="tab-resumen-btn" data-bs-toggle="pill" data-bs-target="#tab-resumen" type="button" role="tab">Resumen Delta</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link py-1 px-3 small" id="tab-json-btn" data-bs-toggle="pill" data-bs-target="#tab-json" type="button" role="tab">Payload JSON Crudo</button>
                        </li>
                    </ul>

                    <div class="tab-content" id="pills-tabContent">
                        <div class="tab-pane fade show active" id="tab-resumen" role="tabpanel">
                            <div id="modal-delta-content" class="p-2 border rounded bg-light" style="max-height: 380px; overflow-y: auto;">
                                <!-- Llenado por JS -->
                            </div>
                        </div>
                        <div class="tab-pane fade" id="tab-json" role="tabpanel">
                            <pre id="modal-log-pre" class="json-preview mb-0"></pre>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-1 px-3 bg-light">
                    <span class="text-muted small me-auto">Registro firmado inmutablemente en MariaDB InnoDB</span>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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
