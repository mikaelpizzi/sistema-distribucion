<?php
// src/views/tabs/tab_auditoria.php
// SICM | Módulo Autosuficiente: Consola de Auditoría Forense
declare(strict_types=1);

$moduloNombre = 'Consola de Auditoría Forense';
$seccionNombre = 'Seguridad y Control';

$subtabActiva = $_GET['subtab'] ?? 'eventos';
if (!in_array($subtabActiva, ['eventos', 'deltas'], true)) {
    $subtabActiva = 'eventos';
}

// 1. Métricas Forenses Superiores (KPIs Reales y Cuantificables)
$totalLogsCount = 0;
$totalAlertasSeguridad = 0;
$totalDeltasCampos = 0;

try {
    $totalLogsCount = (int) $pdo->query("SELECT COUNT(*) FROM logs_auditoria")->fetchColumn();
} catch (Throwable $e) {
    $totalLogsCount = 0;
}

try {
    $totalAlertasSeguridad = (int) $pdo->query("
        SELECT COUNT(*) FROM logs_auditoria
        WHERE accion IN ('INTENTO_LOGIN_FALLIDO', 'INTENTO_DOBLE_RETIRO', 'VIOLACION_REGLA_TRIGGER', 'ERROR_GENERAL_BD', 'ACCESO_DENEGADO')
           OR ip_origen = 'CONSOLA_DIRECTA'
    ")->fetchColumn();
} catch (Throwable $e) {
    $totalAlertasSeguridad = 0;
}

try {
    $totalDeltasCampos = (int) $pdo->query("SELECT COUNT(*) FROM logs_cambios_campos")->fetchColumn();
} catch (Throwable $e) {
    $totalDeltasCampos = 0;
}

// 2. Filtros Dinámicos y Autodescubribles
$tablasUnicas = [];
try {
    $stmtTablas = $pdo->query("
        SELECT DISTINCT tabla_afectada AS tabla FROM logs_auditoria WHERE tabla_afectada IS NOT NULL AND tabla_afectada != ''
        UNION
        SELECT DISTINCT tabla_nombre AS tabla FROM logs_cambios_campos WHERE tabla_nombre IS NOT NULL AND tabla_nombre != ''
        ORDER BY tabla ASC
    ");
    $tablasUnicas = $stmtTablas->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $tablasUnicas = [];
}

$accionesUnicas = [];
try {
    $accionesUnicas = $pdo->query("SELECT DISTINCT accion FROM logs_auditoria ORDER BY accion ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $accionesUnicas = [];
}

$camposModificadosUnicos = [];
try {
    $camposModificadosUnicos = $pdo->query("SELECT DISTINCT campo_modificado FROM logs_cambios_campos ORDER BY campo_modificado ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $camposModificadosUnicos = [];
}

// Diccionarios para resolución de nombres de entidades
$nombresCentros = [];
$nombresRecursos = [];
$nombresTipos = [];
$nombresUsuarios = [];
$nombresMedicamentos = [];
$nombresPacientes = [];
$nombresPatologias = [];
$nombresLotes = [];
try {
    $nombresCentros = $pdo->query("SELECT id, nombre, codigo_sicm FROM centros_salud")->fetchAll(PDO::FETCH_UNIQUE);
    $nombresRecursos = $pdo->query("SELECT id, nombre, codigo FROM recursos_operativos")->fetchAll(PDO::FETCH_UNIQUE);
    $nombresTipos = $pdo->query("SELECT id, nombre, codigo FROM tipos_centro_salud")->fetchAll(PDO::FETCH_UNIQUE);
    $nombresUsuarios = $pdo->query("SELECT id, nombre, email FROM usuarios")->fetchAll(PDO::FETCH_UNIQUE);
    $nombresMedicamentos = $pdo->query("SELECT id, nombre_generico AS nombre, concentracion FROM medicamentos")->fetchAll(PDO::FETCH_UNIQUE);
    $nombresPacientes = $pdo->query("SELECT id, nombre_completo AS nombre, cedula FROM pacientes")->fetchAll(PDO::FETCH_UNIQUE);
    $nombresPatologias = $pdo->query("SELECT id, nombre, codigo_cie10 FROM patologias")->fetchAll(PDO::FETCH_UNIQUE);
    $nombresLotes = $pdo->query("SELECT l.id, l.numero_lote AS nombre, m.nombre_generico AS medicamento FROM lotes_inventario l JOIN medicamentos m ON l.medicamento_id = m.id")->fetchAll(PDO::FETCH_UNIQUE);
} catch (Throwable $e) {
    error_log('[AUDITORIA DICCIONARIOS ERROR]: ' . $e->getMessage());
}

$diccionariosEntidades = [
    'centros' => $nombresCentros,
    'recursos' => $nombresRecursos,
    'tipos' => $nombresTipos,
    'usuarios' => $nombresUsuarios,
    'medicamentos' => $nombresMedicamentos,
    'pacientes' => $nombresPacientes,
    'patologias' => $nombresPatologias,
    'lotes' => $nombresLotes,
];

// Carga de usuarios registrados activos para el selector de auditoría
$usuariosListaAuditoria = [];
try {
    $usuariosListaAuditoria = $pdo->query("
        SELECT u.id, u.nombre, u.email, r.nombre AS rol_nombre 
        FROM usuarios u 
        JOIN roles r ON r.id = u.rol_id 
        WHERE u.activo = 1 
        ORDER BY u.nombre ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $usuariosListaAuditoria = [];
}

// Parámetros compartidos de filtrado
$filtroTabla = $_GET['tabla_filtro'] ?? ($_GET['tabla'] ?? 'todas');
$filtroUsuario = trim((string) ($_GET['usuario_filtro'] ?? ''));
if ($filtroUsuario === '') {
    $filtroUsuario = trim((string) ($_GET['q_operador'] ?? 'todos'));
}
if ($filtroUsuario === '') {
    $filtroUsuario = 'todos';
}
$filtroOperador = $filtroUsuario;
$filtroRegistroId = trim((string) ($_GET['registro_id'] ?? ''));
$filtroFechaDesde = trim($_GET['fecha_desde'] ?? '');
$filtroFechaHasta = trim($_GET['fecha_hasta'] ?? '');
$busquedaLog = trim($_GET['q_log'] ?? '');
$filtroCampo = trim($_GET['campo_filtro'] ?? 'todos');
if ($filtroCampo === '') {
    $filtroCampo = 'todos';
}

// Desplegable unificado de Severidad / Tipo
$filtroTipo = $_GET['tipo_evento'] ?? '';
if ($filtroTipo === '') {
    if (!empty($_GET['solo_alertas'])) {
        $filtroTipo = 'alertas';
    } elseif (!empty($_GET['solo_consola'])) {
        $filtroTipo = 'cli';
    } else {
        $filtroTipo = 'todos';
    }
}

$paginaActual = max(1, (int) ($_GET['p'] ?? 1));
$porPagina = 25;

$filtroAccion = $_GET['accion_filtro'] ?? 'todas';

$hayFiltrosActivos = (
    ($subtabActiva === 'eventos' && $filtroAccion !== 'todas' && $filtroAccion !== '') ||
    ($subtabActiva === 'deltas' && $filtroCampo !== 'todos' && $filtroCampo !== '') ||
    ($filtroTabla !== 'todas' && $filtroTabla !== '') ||
    ($filtroUsuario !== 'todos' && $filtroUsuario !== '') ||
    ($filtroRegistroId !== '') ||
    ($filtroFechaDesde !== '') ||
    ($filtroFechaHasta !== '') ||
    ($subtabActiva === 'eventos' && $filtroTipo !== 'todos' && $filtroTipo !== '') ||
    ($busquedaLog !== '')
);

if ($subtabActiva === 'eventos') {
    $condLogs = [];
    $paramsLogs = [];

    if ($filtroAccion !== 'todas' && $filtroAccion !== '') {
        $condLogs[] = 'l.accion = :acc';
        $paramsLogs[':acc'] = $filtroAccion;
    }
    if ($filtroTabla !== 'todas' && $filtroTabla !== '') {
        $condLogs[] = 'l.tabla_afectada = :tbl';
        $paramsLogs[':tbl'] = $filtroTabla;
    }
    if ($filtroUsuario !== 'todos' && $filtroUsuario !== '') {
        if ($filtroUsuario === 'anonimo') {
            $condLogs[] = 'l.usuario_id IS NULL';
        } elseif ($filtroUsuario === 'consola') {
            $condLogs[] = "l.ip_origen = 'CONSOLA_DIRECTA'";
        } elseif (is_numeric($filtroUsuario)) {
            $condLogs[] = 'l.usuario_id = :uid_filtro';
            $paramsLogs[':uid_filtro'] = (int) $filtroUsuario;
        } else {
            $opTexto = mb_strtolower(trim((string) preg_replace('/[\s_-]+/', ' ', $filtroUsuario)), 'UTF-8');
            $esAnonimoSemantico = in_array($opTexto, ['anonimo', 'anónimo', 'no autenticado', 'sin usuario', 'sin sesion', 'sin sesión'], true);
            if ($esAnonimoSemantico) {
                $condLogs[] = 'l.usuario_id IS NULL';
            } elseif ($opTexto === 'consola') {
                $condLogs[] = "l.ip_origen = 'CONSOLA_DIRECTA'";
            } else {
                $condLogs[] = '(l.operador LIKE :q_op1 OR l.email_operador LIKE :q_op2)';
                $paramsLogs[':q_op1'] = '%' . $filtroUsuario . '%';
                $paramsLogs[':q_op2'] = '%' . $filtroUsuario . '%';
            }
        }
    }
    if ($filtroRegistroId !== '' && is_numeric($filtroRegistroId)) {
        $condLogs[] = 'l.registro_id = :reg_id';
        $paramsLogs[':reg_id'] = (int) $filtroRegistroId;
    }
    if ($filtroFechaDesde !== '') {
        $condLogs[] = 'l.fecha_hora >= :f_desde';
        $paramsLogs[':f_desde'] = $filtroFechaDesde . ' 00:00:00';
    }
    if ($filtroFechaHasta !== '') {
        $condLogs[] = 'l.fecha_hora <= :f_hasta';
        $paramsLogs[':f_hasta'] = $filtroFechaHasta . ' 23:59:59';
    }
    if ($filtroTipo === 'alertas') {
        $condLogs[] = "(l.accion IN ('INTENTO_LOGIN_FALLIDO', 'INTENTO_DOBLE_RETIRO', 'VIOLACION_REGLA_TRIGGER', 'ERROR_GENERAL_BD', 'ACCESO_DENEGADO') OR l.ip_origen = 'CONSOLA_DIRECTA' OR l.accion LIKE '%FALLID%' OR l.accion LIKE '%TRIGGER%' OR l.accion LIKE '%VIOLACION%')";
    } elseif ($filtroTipo === 'cli') {
        $condLogs[] = "l.ip_origen = 'CONSOLA_DIRECTA'";
    } elseif ($filtroTipo === 'info') {
        $condLogs[] = "(l.accion NOT IN ('INTENTO_LOGIN_FALLIDO', 'INTENTO_DOBLE_RETIRO', 'VIOLACION_REGLA_TRIGGER', 'ERROR_GENERAL_BD', 'ACCESO_DENEGADO') AND l.ip_origen != 'CONSOLA_DIRECTA' AND l.accion NOT LIKE '%FALLID%' AND l.accion NOT LIKE '%TRIGGER%' AND l.accion NOT LIKE '%VIOLACION%')";
    } elseif ($filtroTipo === 'anonimo') {
        $condLogs[] = 'l.usuario_id IS NULL';
    }
    if ($busquedaLog !== '') {
        $condOr = [
            'l.accion LIKE :ql1',
            'l.tabla_afectada LIKE :ql2',
            'l.ip_origen LIKE :ql3',
            'l.operador LIKE :ql4',
            'l.email_operador LIKE :ql5',
            'l.detalles_json LIKE :ql6'
        ];
        $paramsLogs[':ql1'] = '%' . $busquedaLog . '%';
        $paramsLogs[':ql2'] = '%' . $busquedaLog . '%';
        $paramsLogs[':ql3'] = '%' . $busquedaLog . '%';
        $paramsLogs[':ql4'] = '%' . $busquedaLog . '%';
        $paramsLogs[':ql5'] = '%' . $busquedaLog . '%';
        $paramsLogs[':ql6'] = '%' . $busquedaLog . '%';

        if (is_numeric($busquedaLog)) {
            $condOr[] = 'l.registro_id = :ql_num';
            $paramsLogs[':ql_num'] = (int) $busquedaLog;
        } else {
            $condOr[] = 'CAST(l.registro_id AS CHAR) = :ql_str';
            $paramsLogs[':ql_str'] = $busquedaLog;
        }
        $condLogs[] = '(' . implode(' OR ', $condOr) . ')';
    }

    $whereLogs = !empty($condLogs) ? 'WHERE ' . implode(' AND ', $condLogs) : '';

    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM vw_logs_auditoria l {$whereLogs}");
    $stmtCount->execute($paramsLogs);
    $totalFiltrados = (int) $stmtCount->fetchColumn();
    $totalPaginas = max(1, (int) ceil($totalFiltrados / $porPagina));

    if ($paginaActual > $totalPaginas) {
        $paginaActual = $totalPaginas;
    }
    $offset = ($paginaActual - 1) * $porPagina;

    $stmtLogs = $pdo->prepare("
        SELECT l.log_id, l.fecha_hora, l.usuario_id, l.operador, l.rol_operador, l.email_operador,
               l.accion, l.tabla_afectada, l.registro_id, l.ip_origen, l.user_agent, l.detalles_json
        FROM vw_logs_auditoria l
        {$whereLogs}
        ORDER BY l.log_id DESC
        LIMIT :lim OFFSET :off
    ");
    foreach ($paramsLogs as $k => $v) {
        $stmtLogs->bindValue($k, $v);
    }
    $stmtLogs->bindValue(':lim', $porPagina, PDO::PARAM_INT);
    $stmtLogs->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmtLogs->execute();
    $logs = $stmtLogs->fetchAll();
    $maxLogId = (int) ($pdo->query("SELECT IFNULL(MAX(id), 0) FROM logs_auditoria")->fetchColumn() ?: 0);

} else {
    // Subtab Deltas: Historial de Modificaciones
    $condDeltas = [];
    $paramsDeltas = [];

    if ($filtroTabla !== 'todas' && $filtroTabla !== '') {
        $condDeltas[] = 'lcc.tabla_nombre = :tbl';
        $paramsDeltas[':tbl'] = $filtroTabla;
    }
    if ($filtroCampo !== 'todos' && $filtroCampo !== '') {
        $condDeltas[] = 'lcc.campo_modificado = :campo_modificado';
        $paramsDeltas[':campo_modificado'] = $filtroCampo;
    }
    if ($filtroRegistroId !== '' && is_numeric($filtroRegistroId)) {
        $condDeltas[] = 'lcc.registro_id = :reg_id';
        $paramsDeltas[':reg_id'] = (int) $filtroRegistroId;
    }
    if ($filtroUsuario !== 'todos' && $filtroUsuario !== '') {
        if ($filtroUsuario === 'anonimo') {
            $condDeltas[] = 'lcc.usuario_app_id IS NULL';
        } elseif ($filtroUsuario === 'consola') {
            $condDeltas[] = "lcc.ip_origen = 'CONSOLA_DIRECTA'";
        } elseif (is_numeric($filtroUsuario)) {
            $condDeltas[] = 'lcc.usuario_app_id = :uid_filtro';
            $paramsDeltas[':uid_filtro'] = (int) $filtroUsuario;
        } else {
            $opTexto = mb_strtolower(trim((string) preg_replace('/[\s_-]+/', ' ', $filtroUsuario)), 'UTF-8');
            $esAnonimoSemantico = in_array($opTexto, ['anonimo', 'anónimo', 'no autenticado', 'sin usuario', 'sin sesion', 'sin sesión'], true);
            if ($esAnonimoSemantico) {
                $condDeltas[] = 'lcc.usuario_app_id IS NULL';
            } elseif ($opTexto === 'consola') {
                $condDeltas[] = "lcc.ip_origen = 'CONSOLA_DIRECTA'";
            } else {
                $condDeltas[] = '(u.nombre LIKE :q_op1 OR u.email LIKE :q_op2 OR lcc.db_user LIKE :q_op3)';
                $paramsDeltas[':q_op1'] = '%' . $filtroUsuario . '%';
                $paramsDeltas[':q_op2'] = '%' . $filtroUsuario . '%';
                $paramsDeltas[':q_op3'] = '%' . $filtroUsuario . '%';
            }
        }
    }
    if ($filtroFechaDesde !== '') {
        $condDeltas[] = 'lcc.created_at >= :f_desde';
        $paramsDeltas[':f_desde'] = $filtroFechaDesde . ' 00:00:00';
    }
    if ($filtroFechaHasta !== '') {
        $condDeltas[] = 'lcc.created_at <= :f_hasta';
        $paramsDeltas[':f_hasta'] = $filtroFechaHasta . ' 23:59:59';
    }
    if ($filtroTipo === 'alertas') {
        $condDeltas[] = "(lcc.ip_origen = 'CONSOLA_DIRECTA' OR lcc.usuario_app_id IS NULL)";
    } elseif ($filtroTipo === 'cli') {
        $condDeltas[] = "lcc.ip_origen = 'CONSOLA_DIRECTA'";
    } elseif ($filtroTipo === 'info') {
        $condDeltas[] = "(lcc.ip_origen != 'CONSOLA_DIRECTA' AND lcc.usuario_app_id IS NOT NULL)";
    } elseif ($filtroTipo === 'anonimo') {
        $condDeltas[] = 'lcc.usuario_app_id IS NULL';
    }
    if ($busquedaLog !== '') {
        $condOrDelta = [
            'lcc.tabla_nombre LIKE :qd1',
            'lcc.campo_modificado LIKE :qd2',
            'lcc.valor_anterior LIKE :qd3',
            'lcc.valor_nuevo LIKE :qd4',
            'lcc.db_user LIKE :qd5',
            'lcc.ip_origen LIKE :qd6',
            'u.nombre LIKE :qd7',
            'u.email LIKE :qd8'
        ];
        $paramsDeltas[':qd1'] = '%' . $busquedaLog . '%';
        $paramsDeltas[':qd2'] = '%' . $busquedaLog . '%';
        $paramsDeltas[':qd3'] = '%' . $busquedaLog . '%';
        $paramsDeltas[':qd4'] = '%' . $busquedaLog . '%';
        $paramsDeltas[':qd5'] = '%' . $busquedaLog . '%';
        $paramsDeltas[':qd6'] = '%' . $busquedaLog . '%';
        $paramsDeltas[':qd7'] = '%' . $busquedaLog . '%';
        $paramsDeltas[':qd8'] = '%' . $busquedaLog . '%';

        if (is_numeric($busquedaLog)) {
            $condOrDelta[] = 'lcc.registro_id = :qd_num';
            $paramsDeltas[':qd_num'] = (int) $busquedaLog;
        } else {
            $condOrDelta[] = 'CAST(lcc.registro_id AS CHAR) = :qd_str';
            $paramsDeltas[':qd_str'] = $busquedaLog;
        }
        $condDeltas[] = '(' . implode(' OR ', $condOrDelta) . ')';
    }

    $whereDeltas = !empty($condDeltas) ? 'WHERE ' . implode(' AND ', $condDeltas) : '';

    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM logs_cambios_campos lcc LEFT JOIN usuarios u ON u.id = lcc.usuario_app_id {$whereDeltas}");
    $stmtCount->execute($paramsDeltas);
    $totalFiltrados = (int) $stmtCount->fetchColumn();
    $totalPaginas = max(1, (int) ceil($totalFiltrados / $porPagina));

    if ($paginaActual > $totalPaginas) {
        $paginaActual = $totalPaginas;
    }
    $offset = ($paginaActual - 1) * $porPagina;

    $stmtDeltas = $pdo->prepare("
        SELECT lcc.id, lcc.tabla_nombre, lcc.registro_id, lcc.campo_modificado,
               lcc.valor_anterior, lcc.valor_nuevo, lcc.usuario_app_id,
               lcc.db_user, lcc.connection_id, lcc.ip_origen, lcc.created_at,
               u.nombre AS operador_app, u.email AS email_app, r.nombre AS rol_app
        FROM logs_cambios_campos lcc
        LEFT JOIN usuarios u ON u.id = lcc.usuario_app_id
        LEFT JOIN roles r ON r.id = u.rol_id
        {$whereDeltas}
        ORDER BY lcc.id DESC
        LIMIT :lim OFFSET :off
    ");
    foreach ($paramsDeltas as $k => $v) {
        $stmtDeltas->bindValue($k, $v);
    }
    $stmtDeltas->bindValue(':lim', $porPagina, PDO::PARAM_INT);
    $stmtDeltas->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmtDeltas->execute();
    $deltas = $stmtDeltas->fetchAll();
    $maxDeltaId = (int) ($pdo->query("SELECT IFNULL(MAX(id), 0) FROM logs_cambios_campos")->fetchColumn() ?: 0);
}
?>
<!-- Cuadro de Mando de Auditoría (KPIs Institucionales) -->
<div class="row g-2 mb-3">
    <div class="col-12 col-md-4">
        <div class="card-sicm p-2.5 text-center shadow-sm">
            <span class="text-muted small d-block fw-semibold" style="font-size: 0.75rem;">Total de
                Eventos</span>
            <div class="fs-4 fw-bold text-dark font-monospace"><?= number_format($totalLogsCount) ?>
            </div>
            <span class="text-muted small" style="font-size: 0.70rem;">Bitácora general del
                sistema</span>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card-sicm p-2.5 text-center border-start border-danger border-4 shadow-sm">
            <span class="text-danger small d-block fw-bold" style="font-size: 0.75rem;">Alertas del
                Sistema</span>
            <div class="fs-4 fw-bold text-danger font-monospace">
                <?= number_format($totalAlertasSeguridad) ?>
            </div>
            <span class="text-muted small" style="font-size: 0.70rem;">Incidentes e intentos
                fallidos</span>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card-sicm p-2.5 text-center border-start border-primary border-4 shadow-sm">
            <span class="text-primary small d-block fw-bold" style="font-size: 0.75rem;">Modificaciones
                de
                Datos</span>
            <div class="fs-4 fw-bold text-primary font-monospace">
                <?= number_format($totalDeltasCampos) ?></div>
            <span class="text-muted small" style="font-size: 0.70rem;">Cambios registrados por
                campo</span>
        </div>
    </div>
</div>

<!-- Navegación por Subpestañas y Controles de Telemetría -->
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <ul class="nav nav-pills gap-1">
        <li class="nav-item">
            <a href="index.php?tab=auditoria&subtab=eventos<?= $filtroTabla !== 'todas' ? '&tabla=' . urlencode($filtroTabla) : '' ?><?= $filtroRegistroId !== '' ? '&registro_id=' . urlencode($filtroRegistroId) : '' ?>"
                class="nav-link btn-sm py-1.5 px-3 <?= $subtabActiva === 'eventos' ? 'active bg-primary fw-bold' : 'bg-white border text-secondary' ?>"
                style="font-size: 0.82rem;">
                <i class="bi bi-journal-text me-1"></i> Bitácora de Eventos
                <span
                    class="badge bg-light text-dark ms-1 font-monospace"><?= number_format($totalLogsCount) ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a href="index.php?tab=auditoria&subtab=deltas<?= $filtroTabla !== 'todas' ? '&tabla=' . urlencode($filtroTabla) : '' ?><?= $filtroRegistroId !== '' ? '&registro_id=' . urlencode($filtroRegistroId) : '' ?>"
                class="nav-link btn-sm py-1.5 px-3 <?= $subtabActiva === 'deltas' ? 'active bg-primary fw-bold' : 'bg-white border text-secondary' ?>"
                style="font-size: 0.82rem;">
                <i class="bi bi-clock-history me-1"></i> Historial de Modificaciones
                <span
                    class="badge bg-light text-dark ms-1 font-monospace"><?= number_format($totalDeltasCampos) ?></span>
            </a>
        </li>
    </ul>

    <div class="d-flex align-items-center gap-2">
        <!-- Botón de Actualización Bajo Demanda -->
        <button type="button" id="btn-actualizar-auditoria"
            class="btn btn-outline-primary btn-sm d-flex align-items-center gap-1.5 shadow-sm fw-semibold"
            style="font-size: 0.75rem;" title="Refrescar la vista con los datos más recientes">
            <i class="bi bi-arrow-clockwise" id="icon-actualizar"></i>
            <span id="text-actualizar">Actualizar</span>
            <span id="badge-novedades" class="badge bg-danger rounded-pill d-none ms-1"
                style="font-size: 0.65rem;"></span>
        </button>

        <!-- Exportación con Nombre Claro y Contextual -->
        <?php
        $exportQuery = "tab=auditoria&subtab=" . urlencode($subtabActiva)
            . "&tabla_filtro=" . urlencode($filtroTabla)
            . "&usuario_filtro=" . urlencode($filtroUsuario)
            . "&tipo_evento=" . urlencode($filtroTipo)
            . "&campo_filtro=" . urlencode($filtroCampo)
            . "&registro_id=" . urlencode($filtroRegistroId)
            . "&fecha_desde=" . urlencode($filtroFechaDesde)
            . "&fecha_hasta=" . urlencode($filtroFechaHasta)
            . "&q_log=" . urlencode($busquedaLog);
        if ($subtabActiva === 'eventos') {
            $exportQuery .= "&accion_filtro=" . urlencode($filtroAccion);
        }
        ?>
        <?php if (tienePermiso('AUDIT_EXPORTAR')): ?>
            <?php if ($subtabActiva === 'eventos'): ?>
                <a href="procesar.php?accion=exportar_acta_pericial&<?= $exportQuery ?>"
                    class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1 shadow-sm"
                    style="font-size: 0.75rem;" title="Descargar bitácora de eventos filtrados en formato CSV">
                    <i class="bi bi-file-earmark-arrow-down"></i> Exportar Eventos (CSV)
                </a>
            <?php else: ?>
                <a href="procesar.php?accion=exportar_acta_pericial&<?= $exportQuery ?>"
                    class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1 shadow-sm"
                    style="font-size: 0.75rem;"
                    title="Descargar historial de modificaciones filtradas en formato CSV">
                    <i class="bi bi-file-earmark-arrow-down"></i> Exportar Modificaciones (CSV)
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Barra de Filtros en Dos Niveles (Grid Limpio Responsivo) -->
<div class="card-sicm mb-3 p-3 bg-white shadow-sm border">
    <form method="GET" action="index.php" id="form-filtros-auditoria">
        <input type="hidden" name="tab" value="auditoria">
        <input type="hidden" name="subtab" value="<?= htmlspecialchars($subtabActiva) ?>">
        <?php if ($filtroRegistroId !== ''): ?>
            <input type="hidden" name="registro_id" value="<?= htmlspecialchars($filtroRegistroId) ?>">
        <?php endif; ?>
        <?php if ($subtabActiva === 'eventos' && isset($filtroAccion) && $filtroAccion !== 'todas'): ?>
            <input type="hidden" name="accion_filtro" value="<?= htmlspecialchars($filtroAccion) ?>">
        <?php endif; ?>

        <!-- Fila 1 (Búsqueda Universal y Atajos Rápidos) -->
        <div class="row g-2 align-items-center mb-3">
            <!-- Columna izquierda amplia: Input group con icono de lupa y campo de búsqueda amplia -->
            <div class="col-lg-7 col-md-12">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white border-end-0 text-muted">
                        <i class="bi bi-search"></i>
                    </span>
                    <input type="text" name="q_log" class="form-control border-start-0 ps-0"
                        placeholder="Buscar por texto, usuario, correo, IP o detalle..."
                        value="<?= htmlspecialchars($busquedaLog) ?>"
                        title="Buscar por texto, usuario, correo, IP o detalle...">
                </div>
            </div>

            <!-- Columna central/atajos: Atajos rápidos con botones pequeños -->
            <div class="col-lg-3 col-md-6">
                <div class="d-flex align-items-center gap-2 flex-nowrap">
                    <button type="button"
                        class="btn btn-outline-secondary btn-sm py-1 px-2 text-nowrap shadow-xs"
                        id="btn-shortcut-hoy" title="Establecer rango de fechas a hoy">
                        📅 Hoy
                    </button>
                    <button type="button"
                        class="btn btn-outline-warning text-dark btn-sm py-1 px-2 text-nowrap shadow-xs"
                        id="btn-shortcut-incidentes" title="Ver únicamente alertas e incidentes">
                        ⚠️ Solo Incidentes
                    </button>
                </div>
            </div>

            <!-- Columna derecha: Botones de acción -->
            <div class="col-lg-2 col-md-6 text-end">
                <div class="d-flex align-items-center justify-content-md-end gap-2">
                    <button type="submit"
                        class="btn btn-primary btn-sm px-3 shadow-xs flex-grow-1 flex-md-grow-0">
                        <i class="bi bi-funnel-fill me-1"></i>Filtrar
                    </button>
                    <a href="index.php?tab=auditoria&subtab=<?= htmlspecialchars($subtabActiva) ?>"
                        class="btn btn-outline-secondary btn-sm px-2 text-nowrap shadow-xs"
                        title="Restablecer todos los filtros">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Restablecer
                    </a>
                </div>
            </div>
        </div>

        <!-- Fila 2 (Filtros Clasificados en 4 Columnas con Labels Claros) -->
        <div class="row g-2 align-items-end pt-3 border-top mt-1">
            <!-- Col 1: Entidad / Tabla -->
            <div class="col-md-3 col-sm-6">
                <label class="form-label text-muted small fw-semibold mb-1 d-block"
                    for="select-tabla-filtro">
                    Entidad / Tabla
                </label>
                <select name="tabla_filtro" id="select-tabla-filtro" class="form-select form-select-sm"
                    title="Filtrar por tabla o entidad">
                    <option value="todas">-- Todas las tablas --</option>
                    <?php foreach ($tablasUnicas as $tbl): ?>
                        <option value="<?= htmlspecialchars($tbl) ?>" <?= $filtroTabla === $tbl ? 'selected' : '' ?>>
                            <?= htmlspecialchars($tbl) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($subtabActiva === 'deltas'): ?>
                <!-- Col 2: Campo Modificado -->
                <div class="col-md-3 col-sm-6">
                    <label class="form-label text-muted small fw-semibold mb-1 d-block"
                        for="select-campo-filtro">
                        Campo Modificado
                    </label>
                    <select name="campo_filtro" id="select-campo-filtro" class="form-select form-select-sm"
                        title="Filtrar por nombre de campo modificado">
                        <option value="todos" <?= $filtroCampo === 'todos' ? 'selected' : '' ?>>-- Todos los
                            campos --</option>
                        <?php foreach ($camposModificadosUnicos as $cmp): ?>
                            <option value="<?= htmlspecialchars($cmp) ?>" <?= $filtroCampo === $cmp ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cmp) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <!-- Col 2: Tipo de Evento / Severidad -->
                <div class="col-md-3 col-sm-6">
                    <label class="form-label text-muted small fw-semibold mb-1 d-block"
                        for="select-tipo-evento">
                        Tipo de Evento / Severidad
                    </label>
                    <select name="tipo_evento" id="select-tipo-evento" class="form-select form-select-sm"
                        title="Filtrar por severidad o tipo de evento">
                        <option value="todos" <?= $filtroTipo === 'todos' ? 'selected' : '' ?>>Todos los
                            eventos
                        </option>
                        <option value="alertas" <?= $filtroTipo === 'alertas' ? 'selected' : '' ?>>Solo alertas
                            e
                            incidentes</option>
                        <option value="anonimo" <?= $filtroTipo === 'anonimo' ? 'selected' : '' ?>>Sin
                            autenticar /
                            Intentos anónimos</option>
                        <option value="cli" <?= $filtroTipo === 'cli' ? 'selected' : '' ?>>Accesos directos por
                            terminal</option>
                        <option value="info" <?= $filtroTipo === 'info' ? 'selected' : '' ?>>Operaciones
                            habituales
                        </option>
                    </select>
                </div>
            <?php endif; ?>

            <!-- Col 3: Operador / Usuario -->
            <div class="col-md-3 col-sm-6">
                <label class="form-label text-muted small fw-semibold mb-1 d-block"
                    for="select-usuario-filtro">
                    Operador / Actor
                </label>
                <select name="usuario_filtro" id="select-usuario-filtro"
                    class="form-select form-select-sm" title="Filtrar por operador o actor del sistema">
                    <option value="todos" <?= $filtroUsuario === 'todos' ? 'selected' : '' ?>>-- Todos los
                        actores
                        --</option>
                    <optgroup label="Seguridad y Motor">
                        <option value="anonimo" <?= $filtroUsuario === 'anonimo' ? 'selected' : '' ?>>[!]
                            Intentos
                            Anónimos / Sin Sesión</option>
                        <option value="consola" <?= $filtroUsuario === 'consola' ? 'selected' : '' ?>>[⚙]
                            Consola
                            Directa / Tareas CLI</option>
                    </optgroup>
                    <optgroup label="Funcionarios Registrados">
                        <?php foreach ($usuariosListaAuditoria as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= (string) $filtroUsuario === (string) $u['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['nombre']) ?>
                                (<?= htmlspecialchars($u['rol_nombre'] ?? $u['email']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
            </div>

            <!-- Col 4: Rango de Fechas -->
            <div class="col-md-3 col-sm-6">
                <label class="form-label text-muted small fw-semibold mb-1 d-block">
                    Rango de Fechas
                </label>
                <div class="input-group input-group-sm">
                    <input type="date" name="fecha_desde" class="form-control"
                        value="<?= htmlspecialchars($filtroFechaDesde) ?>" title="Fecha Desde"
                        placeholder="Desde">
                    <span class="input-group-text bg-light text-muted px-1.5"
                        style="font-size: 0.75rem;">a</span>
                    <input type="date" name="fecha_hasta" class="form-control"
                        value="<?= htmlspecialchars($filtroFechaHasta) ?>" title="Fecha Hasta"
                        placeholder="Hasta">
                </div>
            </div>
        </div>
    </form>
</div>

<!-- Etiquetas de Filtros Activos (Active Filter Chips) -->
<?php
$armarUrlSinFiltro = function (array $paramsARemover) use ($subtabActiva): string {
    $parametros = $_GET;
    foreach ($paramsARemover as $p) {
        unset($parametros[$p]);
    }
    unset($parametros['p']);
    $parametros['tab'] = 'auditoria';
    $parametros['subtab'] = $subtabActiva;
    return 'index.php?' . http_build_query($parametros);
};

$filtrosActivosChips = [];
if ($busquedaLog !== '') {
    $filtrosActivosChips[] = [
        'nombre' => 'Búsqueda',
        'valor' => $busquedaLog,
        'url' => $armarUrlSinFiltro(['q_log', 'q_delta']),
    ];
}
if ($filtroTabla !== 'todas' && $filtroTabla !== '') {
    $filtrosActivosChips[] = [
        'nombre' => 'Tabla',
        'valor' => $filtroTabla,
        'url' => $armarUrlSinFiltro(['tabla_filtro', 'tabla']),
    ];
}
if ($filtroTipo !== 'todos' && $filtroTipo !== '') {
    $tipoLabel = match ($filtroTipo) {
        'alertas' => 'Solo alertas e incidentes',
        'anonimo' => 'Sin autenticar / Anónimo',
        'cli' => 'Accesos directos por terminal',
        'info' => 'Operaciones habituales',
        default => $filtroTipo
    };
    $filtrosActivosChips[] = [
        'nombre' => 'Tipo',
        'valor' => $tipoLabel,
        'url' => $armarUrlSinFiltro(['tipo_evento', 'solo_alertas', 'solo_consola']),
    ];
}
if ($filtroUsuario !== 'todos' && $filtroUsuario !== '') {
    $chipNombre = 'Operador';
    if ($filtroUsuario === 'anonimo') {
        $chipNombre = 'Actor';
        $actorLabel = 'Sin sesión / Anónimo';
    } elseif ($filtroUsuario === 'consola') {
        $chipNombre = 'Actor';
        $actorLabel = 'Consola Directa / CLI';
    } elseif (is_numeric($filtroUsuario)) {
        $chipNombre = 'Operador';
        $uid = (int) $filtroUsuario;
        $nombreEncontrado = $nombresUsuarios[$uid]['nombre'] ?? null;
        if (!$nombreEncontrado) {
            foreach ($usuariosListaAuditoria as $u) {
                if ((int) $u['id'] === $uid) {
                    $nombreEncontrado = $u['nombre'];
                    break;
                }
            }
        }
        $actorLabel = $nombreEncontrado ?: ('Usuario #' . $uid);
    } else {
        $chipNombre = 'Operador';
        $actorLabel = $filtroUsuario;
    }
    $filtrosActivosChips[] = [
        'nombre' => $chipNombre,
        'valor' => $actorLabel,
        'url' => $armarUrlSinFiltro(['usuario_filtro', 'q_operador']),
    ];
}
if ($filtroFechaDesde !== '' && $filtroFechaHasta !== '') {
    $filtrosActivosChips[] = [
        'nombre' => 'Fechas',
        'valor' => $filtroFechaDesde . ' a ' . $filtroFechaHasta,
        'url' => $armarUrlSinFiltro(['fecha_desde', 'fecha_hasta']),
    ];
} elseif ($filtroFechaDesde !== '') {
    $filtrosActivosChips[] = [
        'nombre' => 'Desde',
        'valor' => $filtroFechaDesde,
        'url' => $armarUrlSinFiltro(['fecha_desde']),
    ];
} elseif ($filtroFechaHasta !== '') {
    $filtrosActivosChips[] = [
        'nombre' => 'Hasta',
        'valor' => $filtroFechaHasta,
        'url' => $armarUrlSinFiltro(['fecha_hasta']),
    ];
}
if ($filtroRegistroId !== '') {
    $filtrosActivosChips[] = [
        'nombre' => 'Registro',
        'valor' => '#' . $filtroRegistroId,
        'url' => $armarUrlSinFiltro(['registro_id']),
    ];
}
if ($subtabActiva === 'eventos' && isset($filtroAccion) && $filtroAccion !== 'todas' && $filtroAccion !== '') {
    $filtrosActivosChips[] = [
        'nombre' => 'Acción',
        'valor' => $filtroAccion,
        'url' => $armarUrlSinFiltro(['accion_filtro']),
    ];
}
?>

<?php if (!empty($filtrosActivosChips)): ?>
    <div
        class="d-flex flex-wrap align-items-center gap-1.5 mb-3 p-2 px-3 bg-white border rounded shadow-xs">
        <span class="text-muted small fw-semibold me-1">
            <i class="bi bi-funnel text-primary me-1"></i>Filtros activos:
        </span>
        <?php foreach ($filtrosActivosChips as $chip): ?>
            <span
                class="badge bg-light text-dark border d-inline-flex align-items-center gap-1 py-1.5 px-2 fw-normal"
                style="font-size: 0.78rem;">
                <span class="text-secondary"><?= htmlspecialchars($chip['nombre']) ?>:</span>
                <strong><?= htmlspecialchars($chip['valor']) ?></strong>
                <a href="<?= htmlspecialchars($chip['url']) ?>"
                    class="text-danger fw-bold text-decoration-none ms-1 px-1 rounded hover-bg"
                    title="Quitar este filtro" style="font-size: 0.9rem; line-height: 1;">&times;</a>
            </span>
        <?php endforeach; ?>
        <a href="index.php?tab=auditoria&subtab=<?= htmlspecialchars($subtabActiva) ?>"
            class="text-muted small text-decoration-underline ms-2">
            (Limpiar todos)
        </a>
    </div>
<?php endif; ?>

<!-- Carga Dinámica de la Subpestaña de Auditoría -->
<?php
$subtabFile = __DIR__ . "/auditoria/subtab_{$subtabActiva}.php";
if (file_exists($subtabFile)) {
    require $subtabFile;
} else {
    echo '<div class="alert alert-danger py-2 px-3 small">Subpestaña de auditoría no encontrada.</div>';
}
?>

<script src="public/js/auditoria_stream.js?v=<?= filemtime(dirname(__DIR__, 2) . '/public/js/auditoria_stream.js') ?>"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        // Atajos Rápidos de Filtros de Auditoría
        const btnHoy = document.getElementById('btn-shortcut-hoy');
        if (btnHoy) {
            btnHoy.addEventListener('click', () => {
                const form = document.getElementById('form-filtros-auditoria');
                if (!form) return;
                const hoy = '<?= date('Y-m-d') ?>';
                const fDesde = form.querySelector('input[name="fecha_desde"]');
                const fHasta = form.querySelector('input[name="fecha_hasta"]');
                if (fDesde) fDesde.value = hoy;
                if (fHasta) fHasta.value = hoy;
                form.submit();
            });
        }

        const btnIncidentes = document.getElementById('btn-shortcut-incidentes');
        if (btnIncidentes) {
            btnIncidentes.addEventListener('click', () => {
                const form = document.getElementById('form-filtros-auditoria');
                if (!form) return;
                const selectTipo = form.querySelector('select[name="tipo_evento"]');
                if (selectTipo) selectTipo.value = 'alertas';
                form.submit();
            });
        }
    });
</script>
