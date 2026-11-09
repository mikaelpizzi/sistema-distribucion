<?php
// src/index.php
// SICM | Sistema Integrado de Control de Medicamentos - Red Municipal de Salud
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/auth.php';
require_once __DIR__ . '/helpers/auditoria.php';
require_once __DIR__ . '/helpers/configuracion.php';

$pdo = getDB();
$usuario = requerirAutenticacion($pdo);
establecerContextoAuditoriaSQL($pdo, (int)$usuario['id']);
$esAdmin = ((int) $usuario['rol_id'] === 1);
$centroUsuarioId = $usuario['centro_salud_id'];

// Configuración Institucional Centralizada
$configInst = obtenerConfiguracionInstitucional($pdo);

$tabActiva = $_GET['tab'] ?? 'centros';
$mensajeExito = $_GET['msg'] ?? ($_SESSION['mensaje_exito'] ?? null);
$mensajeError = $_GET['error'] ?? ($_SESSION['mensaje_error'] ?? null);
unset($_SESSION['mensaje_exito'], $_SESSION['mensaje_error']);

// Protección RBAC: El Farmaceuta solo tiene acceso a las pestañas asistenciales de su ambulatorio
$tabsAdmin = ['maestras', 'usuarios', 'auditoria', 'configuracion'];
if (!$esAdmin && in_array($tabActiva, $tabsAdmin, true)) {
    header('Location: index.php?tab=centros');
    exit;
}

$tabsValidas = ['centros', 'medicamentos', 'maestras', 'configuracion', 'usuarios', 'auditoria', 'pacientes', 'recipes', 'despachos'];
$tabDesconocida = !in_array($tabActiva, $tabsValidas, true);

// ============================================================================
// CARGA CONDICIONAL DE DATOS SEGÚN LA PESTAÑA ACTIVA
// ============================================================================

if ($tabActiva === 'centros') {
    $filtroEstado = $_GET['estado'] ?? 'activos';
    $busqueda = trim($_GET['q'] ?? '');
    $condiciones = [];
    $params = [];

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
    $recursosPorCentro = [];
    if (!empty($centros)) {
        $centroIds = array_map(fn($c) => (int)$c['id'], $centros);
        $inCentros = implode(',', $centroIds);
        $stmtRA = $pdo->query("SELECT cr.centro_salud_id, ro.nombre
                               FROM centro_recursos cr
                               INNER JOIN recursos_operativos ro ON ro.id = cr.recurso_id
                               WHERE cr.operativo = 1 AND cr.centro_salud_id IN ({$inCentros})
                               ORDER BY ro.id ASC");
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


} elseif ($tabActiva === 'medicamentos') {
    $subtabMedicamentos = $_GET['subtab'] ?? 'vademecum';
    if (!in_array($subtabMedicamentos, ['vademecum', 'patologias', 'formas'], true)) {
        $subtabMedicamentos = 'vademecum';
    }

    // 1. KPIs Rápidos Institucionales
    $kpiMedicamentosActivos = (int) $pdo->query("SELECT COUNT(*) FROM medicamentos WHERE activo = 1")->fetchColumn();
    $kpiCadenaFrio = (int) $pdo->query("SELECT COUNT(*) FROM medicamentos WHERE activo = 1 AND requiere_cadena_frio = 1")->fetchColumn();
    $kpiPatologiasCie = (int) $pdo->query("SELECT COUNT(*) FROM patologias WHERE activo = 1")->fetchColumn();

    // 2. Catálogo de Formas Farmacéuticas con conteo de medicamentos vinculados
    $formasFarmaceuticas = $pdo->query("
        SELECT ff.id, ff.nombre, COUNT(m.id) AS total_medicamentos
        FROM formas_farmaceuticas ff
        LEFT JOIN medicamentos m ON m.forma_farmaceutica_id = ff.id
        GROUP BY ff.id, ff.nombre
        ORDER BY ff.nombre ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 3. Vademécum de Medicamentos
    $filtroEstadoMed = $_GET['estado_med'] ?? 'todos';
    $busquedaMed = trim($_GET['q_med'] ?? '');
    $condMed = [];
    $paramsMed = [];

    if ($filtroEstadoMed === 'activos') {
        $condMed[] = 'm.activo = 1';
    } elseif ($filtroEstadoMed === 'descontinuados') {
        $condMed[] = 'm.activo = 0';
    }

    if ($busquedaMed !== '') {
        $condMed[] = '(m.nombre_generico LIKE :qm1 OR m.concentracion LIKE :qm2 OR ff.nombre LIKE :qm3)';
        $paramsMed[':qm1'] = '%' . $busquedaMed . '%';
        $paramsMed[':qm2'] = '%' . $busquedaMed . '%';
        $paramsMed[':qm3'] = '%' . $busquedaMed . '%';
    }

    $whereMed = !empty($condMed) ? 'WHERE ' . implode(' AND ', $condMed) : '';
    $sqlMed = "SELECT m.*, ff.nombre AS forma_nombre
               FROM medicamentos m
               LEFT JOIN formas_farmaceuticas ff ON m.forma_farmaceutica_id = ff.id
               {$whereMed}
               ORDER BY m.nombre_generico ASC";
    $stmtMed = $pdo->prepare($sqlMed);
    $stmtMed->execute($paramsMed);
    $medicamentos = $stmtMed->fetchAll(PDO::FETCH_ASSOC);

    // 4. Catálogo CIE-10 de Patologías
    $filtroEstadoPat = $_GET['estado_pat'] ?? 'todos';
    $busquedaPat = trim($_GET['q_pat'] ?? '');
    $condPat = [];
    $paramsPat = [];

    if ($filtroEstadoPat === 'activos') {
        $condPat[] = 'p.activo = 1';
    } elseif ($filtroEstadoPat === 'inactivos') {
        $condPat[] = 'p.activo = 0';
    }

    if ($busquedaPat !== '') {
        $condPat[] = '(p.codigo_cie10 LIKE :qp1 OR p.nombre LIKE :qp2 OR p.descripcion LIKE :qp3)';
        $paramsPat[':qp1'] = '%' . $busquedaPat . '%';
        $paramsPat[':qp2'] = '%' . $busquedaPat . '%';
        $paramsPat[':qp3'] = '%' . $busquedaPat . '%';
    }

    $wherePat = !empty($condPat) ? 'WHERE ' . implode(' AND ', $condPat) : '';
    $sqlPat = "SELECT p.* FROM patologias p {$wherePat} ORDER BY p.codigo_cie10 ASC";
    $stmtPat = $pdo->prepare($sqlPat);
    $stmtPat->execute($paramsPat);
    $patologias = $stmtPat->fetchAll(PDO::FETCH_ASSOC);

    // Edición si aplica
    $medicamentoEdicion = null;
    $editarMId = filter_input(INPUT_GET, 'editar_m', FILTER_VALIDATE_INT)
        ?: filter_input(INPUT_GET, 'editar_med', FILTER_VALIDATE_INT)
        ?: filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT);
    if ($editarMId) {
        $stmtMEd = $pdo->prepare("SELECT * FROM medicamentos WHERE id = :id");
        $stmtMEd->execute([':id' => $editarMId]);
        $medicamentoEdicion = $stmtMEd->fetch(PDO::FETCH_ASSOC);
    }

} elseif ($tabActiva === 'auditoria') {
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
    try {
        $nombresCentros = $pdo->query("SELECT id, nombre, codigo_sicm FROM centros_salud")->fetchAll(PDO::FETCH_UNIQUE);
        $nombresRecursos = $pdo->query("SELECT id, nombre, codigo FROM recursos_operativos")->fetchAll(PDO::FETCH_UNIQUE);
        $nombresTipos = $pdo->query("SELECT id, nombre, codigo FROM tipos_centro_salud")->fetchAll(PDO::FETCH_UNIQUE);
        $nombresUsuarios = $pdo->query("SELECT id, nombre, email FROM usuarios")->fetchAll(PDO::FETCH_UNIQUE);
        $nombresMedicamentos = $pdo->query("SELECT id, nombre_generico AS nombre, concentracion FROM medicamentos")->fetchAll(PDO::FETCH_UNIQUE);
        $nombresPacientes = $pdo->query("SELECT id, nombre_completo AS nombre, cedula FROM pacientes")->fetchAll(PDO::FETCH_UNIQUE);
        $nombresPatologias = $pdo->query("SELECT id, nombre, codigo_cie10 FROM patologias")->fetchAll(PDO::FETCH_UNIQUE);
    } catch (Throwable $e) {
        error_log('[AUDITORIA DICCIONARIOS ERROR]: ' . $e->getMessage());
    }

    $diccionariosEntidades = [
        'centros'      => $nombresCentros,
        'recursos'     => $nombresRecursos,
        'tipos'        => $nombresTipos,
        'usuarios'     => $nombresUsuarios,
        'medicamentos' => $nombresMedicamentos,
        'pacientes'    => $nombresPacientes,
        'patologias'   => $nombresPatologias,
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
} elseif ($tabActiva === 'configuracion') {
    if (!$esAdmin) {
        header('Location: index.php?tab=centros');
        exit;
    }

} elseif ($tabActiva === 'pacientes') {
    // 1. Catálogos Normalizados de Tipos de Documento y Vías de Contacto (Cero Hardcoding)
    $catalogoTiposDoc = $pdo->query("SELECT * FROM tipos_documento ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $catalogoTiposContacto = $pdo->query("SELECT * FROM tipos_contacto ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    // 2. Métricas del Padrón Municipal (KPIs Institucionales)
    $kpiTotalPacientes = (int) ($pdo->query("SELECT COUNT(*) FROM pacientes")->fetchColumn() ?: 0);
    $kpiConContactos   = (int) ($pdo->query("SELECT COUNT(DISTINCT paciente_id) FROM paciente_contactos")->fetchColumn() ?: 0);
    $kpiNuevosMes      = (int) ($pdo->query("SELECT COUNT(*) FROM pacientes WHERE DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')")->fetchColumn() ?: 0);

    // 3. Parámetros de Búsqueda y Filtros
    $busquedaPaciente = trim($_GET['q_paciente'] ?? '');
    $filtroTipoDoc    = trim($_GET['tipo_doc'] ?? 'todos');

    $condPacientes = [];
    $paramsPacientes = [];

    if ($busquedaPaciente !== '') {
        $condPacientes[] = "(p.cedula LIKE :q OR p.nombre_completo LIKE :q OR EXISTS (SELECT 1 FROM paciente_contactos pc_sub WHERE pc_sub.paciente_id = p.id AND pc_sub.valor LIKE :q))";
        $paramsPacientes[':q'] = '%' . $busquedaPaciente . '%';
    }

    if ($filtroTipoDoc !== 'todos' && $filtroTipoDoc !== '') {
        if (ctype_digit($filtroTipoDoc)) {
            $condPacientes[] = "p.tipo_documento_id = :tipo_doc_id";
            $paramsPacientes[':tipo_doc_id'] = (int) $filtroTipoDoc;
        } else {
            $condPacientes[] = "td.codigo = :tipo_doc_cod";
            $paramsPacientes[':tipo_doc_cod'] = strtoupper($filtroTipoDoc);
        }
    }

    $wherePacientesSql = !empty($condPacientes) ? 'WHERE ' . implode(' AND ', $condPacientes) : '';

    // 4. Paginación de Pacientes
    $stmtCountPac = $pdo->prepare("SELECT COUNT(*) FROM pacientes p LEFT JOIN tipos_documento td ON p.tipo_documento_id = td.id {$wherePacientesSql}");
    $stmtCountPac->execute($paramsPacientes);
    $totalRegistrosPacientes = (int) $stmtCountPac->fetchColumn();
    $totalPacientesFiltrados = $totalRegistrosPacientes;

    $limitePorPagina = 20;
    $paginaActual = max(1, (int) ($_GET['p_paciente'] ?? 1));
    $totalPaginas = max(1, (int) ceil($totalRegistrosPacientes / $limitePorPagina));
    if ($paginaActual > $totalPaginas) {
        $paginaActual = $totalPaginas;
    }
    $offsetPacientes = ($paginaActual - 1) * $limitePorPagina;

    // 5. Consulta de Pacientes
    $sqlPacientes = "
        SELECT p.id, p.tipo_documento_id, td.codigo AS tipo_doc_codigo, td.nombre AS tipo_doc_nombre,
               p.cedula, p.nombre_completo, p.fecha_nacimiento, p.direccion_residencia, p.created_at,
               (SELECT COUNT(*) FROM tratamientos_autorizados ta WHERE ta.paciente_id = p.id) AS total_recipes
        FROM pacientes p
        LEFT JOIN tipos_documento td ON p.tipo_documento_id = td.id
        {$wherePacientesSql}
        ORDER BY p.id DESC
        LIMIT :limite OFFSET :offset
    ";
    $stmtPac = $pdo->prepare($sqlPacientes);
    foreach ($paramsPacientes as $k => $v) {
        $stmtPac->bindValue($k, $v);
    }
    $stmtPac->bindValue(':limite', $limitePorPagina, PDO::PARAM_INT);
    $stmtPac->bindValue(':offset', $offsetPacientes, PDO::PARAM_INT);
    $stmtPac->execute();
    $pacientes = $stmtPac->fetchAll(PDO::FETCH_ASSOC);

    // 6. Carga de vías de contacto normalizadas (1FN)
    $pacienteIds = array_column($pacientes, 'id');
    $contactosPorPaciente = [];
    if (!empty($pacienteIds)) {
        $inClause = implode(',', array_map('intval', $pacienteIds));
        $sqlContactos = "
            SELECT pc.*, tc.codigo AS tipo_contacto_codigo, tc.nombre AS tipo_nombre,
                   pc.nombre_contacto_emergencia AS titular
            FROM paciente_contactos pc
            LEFT JOIN tipos_contacto tc ON pc.tipo_contacto_id = tc.id
            WHERE pc.paciente_id IN ({$inClause})
            ORDER BY pc.es_principal DESC, pc.id ASC
        ";
        $stmtC = $pdo->query($sqlContactos);
        while ($row = $stmtC->fetch(PDO::FETCH_ASSOC)) {
            $contactosPorPaciente[$row['paciente_id']][] = $row;
        }
    }

    // 7. Carga de paciente para edición si aplica
    $pacienteEdicion = null;
    $contactosEdicion = [];
    if (!empty($_GET['editar_id']) || !empty($_GET['editar'])) {
        $edId = (int) (!empty($_GET['editar_id']) ? $_GET['editar_id'] : $_GET['editar']);
        $stmtEd = $pdo->prepare("SELECT * FROM pacientes WHERE id = :id");
        $stmtEd->execute([':id' => $edId]);
        $pacienteEdicion = $stmtEd->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($pacienteEdicion) {
            $stmtCEd = $pdo->prepare("SELECT * FROM paciente_contactos WHERE paciente_id = :id ORDER BY es_principal DESC, id ASC");
            $stmtCEd->execute([':id' => $edId]);
            $contactosEdicion = $stmtCEd->fetchAll(PDO::FETCH_ASSOC);
        }
    }

} elseif ($tabActiva === 'recipes') {
    // 1. Catálogos Normalizados para Selectores Clínicos
    $catalogoPacientes = $pdo->query("
        SELECT p.id, p.cedula, td.codigo AS tipo_doc_codigo, p.nombre_completo
        FROM pacientes p
        LEFT JOIN tipos_documento td ON p.tipo_documento_id = td.id
        ORDER BY p.nombre_completo ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $catalogoMedicamentos = $pdo->query("
        SELECT m.id, m.nombre_generico, m.concentracion, ff.nombre AS forma_nombre
        FROM medicamentos m
        LEFT JOIN formas_farmaceuticas ff ON m.forma_farmaceutica_id = ff.id
        WHERE m.activo = 1
        ORDER BY m.nombre_generico ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $catalogoPatologias = $pdo->query("
        SELECT id, codigo_cie10, nombre
        FROM patologias
        WHERE activo = 1
        ORDER BY nombre ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 2. Métricas del Cuadro de Mando de Récipes (KPIs Institucionales)
    $kpiTotalRecipes    = (int) ($pdo->query("SELECT COUNT(*) FROM tratamientos_autorizados")->fetchColumn() ?: 0);
    $kpiRecipesVigentes = (int) ($pdo->query("SELECT COUNT(*) FROM tratamientos_autorizados WHERE activo = 1 AND fecha_vencimiento_recipe >= CURRENT_DATE")->fetchColumn() ?: 0);
    $kpiRecipesVencidos = (int) ($pdo->query("SELECT COUNT(*) FROM tratamientos_autorizados WHERE fecha_vencimiento_recipe < CURRENT_DATE OR activo = 0")->fetchColumn() ?: 0);

    // 3. Parámetros de Búsqueda y Filtros
    $busquedaRecipe        = trim($_GET['q_recipe'] ?? '');
    $filtroEstadoRecipe    = trim($_GET['filtro_estado'] ?? 'todos');
    $filtroPatologiaRecipe = trim($_GET['filtro_patologia'] ?? 'todas');
    $filtroPacienteId      = !empty($_GET['paciente_id']) ? (int) $_GET['paciente_id'] : null;

    $condRecipes = [];
    $paramsRecipes = [];

    if ($busquedaRecipe !== '') {
        $condRecipes[] = "(p.cedula LIKE :q1 OR p.nombre_completo LIKE :q2 OR m.nombre_generico LIKE :q3 OR t.medico_prescriptor LIKE :q4)";
        $paramsRecipes[':q1'] = '%' . $busquedaRecipe . '%';
        $paramsRecipes[':q2'] = '%' . $busquedaRecipe . '%';
        $paramsRecipes[':q3'] = '%' . $busquedaRecipe . '%';
        $paramsRecipes[':q4'] = '%' . $busquedaRecipe . '%';
    }

    if ($filtroEstadoRecipe === 'vigentes') {
        $condRecipes[] = "(t.activo = 1 AND t.fecha_vencimiento_recipe >= CURRENT_DATE)";
    } elseif ($filtroEstadoRecipe === 'vencidos') {
        $condRecipes[] = "(t.fecha_vencimiento_recipe < CURRENT_DATE OR t.activo = 0)";
    }

    if ($filtroPatologiaRecipe !== 'todas' && $filtroPatologiaRecipe !== '' && ctype_digit($filtroPatologiaRecipe)) {
        $condRecipes[] = "t.patologia_id = :patologia_id";
        $paramsRecipes[':patologia_id'] = (int) $filtroPatologiaRecipe;
    }

    if ($filtroPacienteId !== null && $filtroPacienteId > 0) {
        $condRecipes[] = "t.paciente_id = :paciente_id";
        $paramsRecipes[':paciente_id'] = $filtroPacienteId;
    }

    $whereRecipesSql = !empty($condRecipes) ? 'WHERE ' . implode(' AND ', $condRecipes) : '';

    // 4. Conteo y Paginación
    $stmtCountRec = $pdo->prepare("
        SELECT COUNT(*)
        FROM tratamientos_autorizados t
        JOIN pacientes p ON t.paciente_id = p.id
        LEFT JOIN tipos_documento td ON p.tipo_documento_id = td.id
        JOIN medicamentos m ON t.medicamento_id = m.id
        LEFT JOIN formas_farmaceuticas ff ON m.forma_farmaceutica_id = ff.id
        JOIN patologias pat ON t.patologia_id = pat.id
        {$whereRecipesSql}
    ");
    $stmtCountRec->execute($paramsRecipes);
    $totalRegistrosRecipes = (int) $stmtCountRec->fetchColumn();
    $totalRecipesFiltrados = $totalRegistrosRecipes;

    $limitePorPagina = 15;
    $paginaActual = max(1, (int) ($_GET['p_recipe'] ?? 1));
    $totalPaginas = max(1, (int) ceil($totalRegistrosRecipes / $limitePorPagina));
    if ($paginaActual > $totalPaginas) {
        $paginaActual = $totalPaginas;
    }
    $offsetRecipes = ($paginaActual - 1) * $limitePorPagina;

    // 5. Consulta Principal de Récipes
    $sqlRecipes = "
        SELECT t.id, t.paciente_id, t.medicamento_id, t.patologia_id, t.dosis_mensual,
               t.fecha_emision_recipe, t.fecha_vencimiento_recipe, t.medico_prescriptor,
               t.activo, t.created_at,
               (t.fecha_vencimiento_recipe < CURRENT_DATE) AS esta_vencido,
               p.cedula AS paciente_cedula, p.nombre_completo AS paciente_nombre,
               td.codigo AS tipo_doc_codigo,
               m.nombre_generico AS medicamento_nombre, m.concentracion AS medicamento_concentracion,
               ff.nombre AS forma_nombre,
               pat.codigo_cie10 AS patologia_cie10, pat.nombre AS patologia_nombre
        FROM tratamientos_autorizados t
        JOIN pacientes p ON t.paciente_id = p.id
        LEFT JOIN tipos_documento td ON p.tipo_documento_id = td.id
        JOIN medicamentos m ON t.medicamento_id = m.id
        LEFT JOIN formas_farmaceuticas ff ON m.forma_farmaceutica_id = ff.id
        JOIN patologias pat ON t.patologia_id = pat.id
        {$whereRecipesSql}
        ORDER BY t.id DESC
        LIMIT :limite OFFSET :offset
    ";
    $stmtRec = $pdo->prepare($sqlRecipes);
    foreach ($paramsRecipes as $k => $v) {
        $stmtRec->bindValue($k, $v);
    }
    $stmtRec->bindValue(':limite', $limitePorPagina, PDO::PARAM_INT);
    $stmtRec->bindValue(':offset', $offsetRecipes, PDO::PARAM_INT);
    $stmtRec->execute();
    $recipes = $stmtRec->fetchAll(PDO::FETCH_ASSOC);

    // 6. Carga de Récipe para Edición si aplica
    $recipeEdicion = null;
    if (!empty($_GET['editar_id']) || !empty($_GET['editar'])) {
        $edId = (int) (!empty($_GET['editar_id']) ? $_GET['editar_id'] : $_GET['editar']);
        $stmtEd = $pdo->prepare("SELECT * FROM tratamientos_autorizados WHERE id = :id");
        $stmtEd->execute([':id' => $edId]);
        $recipeEdicion = $stmtEd->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}


// Determinación del Módulo y Sección Activa para Breadcrumb Superior
$moduloNombre = 'Ambulatorios';
$seccionNombre = 'Vademécum y Red';
if ($tabDesconocida) {
    $moduloNombre = 'Módulo No Localizado';
    $seccionNombre = 'Navegación';
} elseif ($tabActiva === 'centros') {
    $moduloNombre = $esAdmin ? 'Red de Ambulatorios' : 'Mi Ambulatorio';
    $seccionNombre = 'Vademécum y Red';
} elseif ($tabActiva === 'medicamentos') {
    $moduloNombre = 'Catálogo de Fármacos';
    $seccionNombre = 'Vademécum y Red';
} elseif ($tabActiva === 'maestras') {
    $moduloNombre = 'Catálogos Maestros';
    $seccionNombre = 'Configuración';
} elseif ($tabActiva === 'configuracion') {
    $moduloNombre = 'Identidad Institucional';
    $seccionNombre = 'Configuración';
} elseif ($tabActiva === 'usuarios') {
    $moduloNombre = 'Operadores y Roles';
    $seccionNombre = 'Seguridad y Control';
} elseif ($tabActiva === 'auditoria') {
    $moduloNombre = 'Consola de Auditoría Forense';
    $seccionNombre = 'Seguridad y Control';
} elseif ($tabActiva === 'pacientes') {
    $moduloNombre = 'Padrón de Pacientes';
    $seccionNombre = 'Gestión Clínica';
} elseif ($tabActiva === 'recipes') {
    $moduloNombre = 'Récipes Médicos';
    $seccionNombre = 'Gestión Clínica';
} elseif ($tabActiva === 'despachos') {
    $moduloNombre = 'Dispensación FEFO';
    $seccionNombre = 'Gestión Clínica';
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($configInst['siglas_sistema']) ?> - <?= htmlspecialchars($configInst['nombre_sistema']) ?></title>
    <link rel="icon" type="image/svg+xml" href="public/img/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="public/css/app.css?v=<?= filemtime(__DIR__ . '/public/css/app.css') ?>" rel="stylesheet">
</head>

<body>

    <div class="sicm-layout" id="sicm-layout">
        <!-- BARRA LATERAL INSTITUCIONAL (SIDEBAR) -->
        <aside class="sicm-sidebar offcanvas-lg offcanvas-start" id="sidebarMenu" tabindex="-1" aria-labelledby="sidebarMenuLabel">
            <!-- Encabezado del Sidebar: Identidad Institucional -->
            <div class="sicm-sidebar-header d-flex align-items-center justify-content-between">
                <a href="index.php" class="sicm-sidebar-brand">
                    <?php if (!empty($configInst['logotipo_ruta'])): ?>
                        <img src="<?= htmlspecialchars($configInst['logotipo_ruta']) ?>" alt="Logo" class="sicm-sidebar-logo">
                    <?php else: ?>
                        <div class="d-inline-flex align-items-center justify-content-center rounded bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25" style="width: 36px; height: 36px; flex-shrink: 0;">
                            <i class="bi bi-shield-plus fs-5 text-primary"></i>
                        </div>
                    <?php endif; ?>
                    <div class="d-flex flex-column text-truncate" style="min-width: 0;">
                        <span class="brand-title text-truncate" style="font-size: 0.95rem; line-height: 1.25;"><?= htmlspecialchars($configInst['siglas_sistema']) ?></span>
                        <span class="text-muted text-truncate" style="font-size: 0.68rem; line-height: 1.25;" title="<?= htmlspecialchars($configInst['ente_rector']) ?>"><?= htmlspecialchars($configInst['ente_rector']) ?></span>
                    </div>
                </a>
                <button type="button" class="btn-close d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#sidebarMenu" aria-label="Cerrar"></button>
            </div>

            <!-- Enlaces agrupados semánticamente -->
            <div class="sicm-sidebar-nav">
                <!-- GESTIÓN CLÍNICA -->
                <div class="sidebar-section-title">
                    <i class="bi bi-heart-pulse"></i> Gestión Clínica
                </div>
                <a href="index.php?tab=pacientes" class="sidebar-nav-item <?= $tabActiva === 'pacientes' ? 'active' : '' ?>">
                    <span class="d-flex align-items-center gap-2 text-truncate">
                        <i class="bi bi-people nav-icon"></i>
                        <span class="text-truncate">Pacientes</span>
                    </span>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size: 0.65rem;">1FN</span>
                </a>
                <a href="index.php?tab=recipes" class="sidebar-nav-item <?= $tabActiva === 'recipes' ? 'active' : '' ?>">
                    <span class="d-flex align-items-center gap-2 text-truncate">
                        <i class="bi bi-file-earmark-medical nav-icon"></i>
                        <span class="text-truncate">Récipes Médicos</span>
                    </span>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size: 0.65rem;">Clínico</span>
                </a>
                <a href="index.php?tab=despachos" class="sidebar-nav-item <?= $tabActiva === 'despachos' ? 'active' : '' ?>">
                    <span class="d-flex align-items-center gap-2 text-truncate">
                        <i class="bi bi-box-seam nav-icon"></i>
                        <span class="text-truncate">Dispensación FEFO</span>
                    </span>
                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle" style="font-size: 0.6rem;">Sprint 3</span>
                </a>

                <!-- VADEMÉCUM Y RED -->
                <div class="sidebar-section-title mt-3">
                    <i class="bi bi-capsule"></i> Vademécum y Red
                </div>
                <a href="index.php?tab=medicamentos" class="sidebar-nav-item <?= $tabActiva === 'medicamentos' ? 'active' : '' ?>">
                    <span class="d-flex align-items-center gap-2 text-truncate">
                        <i class="bi bi-prescription2 nav-icon"></i>
                        <span class="text-truncate">Vademécum</span>
                    </span>
                </a>
                <a href="index.php?tab=centros" class="sidebar-nav-item <?= $tabActiva === 'centros' ? 'active' : '' ?>">
                    <span class="d-flex align-items-center gap-2 text-truncate">
                        <i class="bi bi-hospital nav-icon"></i>
                        <span class="text-truncate"><?= $esAdmin ? 'Red de Ambulatorios' : 'Mi Ambulatorio' ?></span>
                    </span>
                </a>

                <!-- ADMINISTRACIÓN Y CONTROL (Solo Administrador) -->
                <?php if ($esAdmin): ?>
                    <div class="sidebar-section-title mt-3">
                        <i class="bi bi-shield-check"></i> Administración Central
                    </div>
                    <a href="index.php?tab=maestras" class="sidebar-nav-item <?= $tabActiva === 'maestras' ? 'active' : '' ?>">
                        <span class="d-flex align-items-center gap-2 text-truncate">
                            <i class="bi bi-sliders nav-icon"></i>
                            <span class="text-truncate">Catálogos Maestros</span>
                        </span>
                    </a>
                    <a href="index.php?tab=configuracion" class="sidebar-nav-item <?= $tabActiva === 'configuracion' ? 'active' : '' ?>">
                        <span class="d-flex align-items-center gap-2 text-truncate">
                            <i class="bi bi-building-gear nav-icon"></i>
                            <span class="text-truncate">Identidad Institucional</span>
                        </span>
                    </a>
                    <a href="index.php?tab=usuarios" class="sidebar-nav-item <?= $tabActiva === 'usuarios' ? 'active' : '' ?>">
                        <span class="d-flex align-items-center gap-2 text-truncate">
                            <i class="bi bi-person-badge nav-icon"></i>
                            <span class="text-truncate">Operadores</span>
                        </span>
                    </a>
                    <a href="index.php?tab=auditoria" class="sidebar-nav-item <?= $tabActiva === 'auditoria' ? 'active' : '' ?>">
                        <span class="d-flex align-items-center gap-2 text-truncate">
                            <i class="bi bi-journal-text nav-icon"></i>
                            <span class="text-truncate">Auditoría Forense</span>
                        </span>
                    </a>
                <?php endif; ?>
            </div>

            <!-- Pie del Sidebar Institucional (Sticky Bottom) -->
            <div class="sicm-sidebar-footer">
                <div class="sidebar-user-card">
                    <div class="user-name text-truncate"><?= htmlspecialchars($usuario['nombre']) ?></div>
                    <div class="user-role text-truncate"><?= htmlspecialchars($usuario['rol_nombre']) ?></div>
                    <div class="user-center text-truncate">
                        <i class="bi bi-geo-alt-fill me-1"></i><?= htmlspecialchars($esAdmin ? 'Administración Central' : ($usuario['centro_nombre'] ?? 'Red Municipal')) ?>
                    </div>
                </div>

                <?php if ($esAdmin): ?>
                    <a href="procesar.php?accion=descargar_respaldo"
                        class="btn btn-outline-secondary btn-sm w-100 mb-2 d-flex align-items-center justify-content-center py-1.5"
                        style="font-size: 0.74rem; font-weight: 600;"
                        title="Descargar snapshot caliente comprimido (.sql.gz)">
                        <i class="bi bi-cloud-arrow-down me-2 text-primary"></i>Crear Respaldo
                    </a>
                <?php endif; ?>

                <a href="logout.php" class="btn btn-outline-danger btn-sm w-100 d-flex align-items-center justify-content-center py-1.5"
                    style="font-size: 0.76rem;" title="Cerrar sesión de forma segura">
                    <i class="bi bi-box-arrow-right me-2"></i>Cerrar Sesión
                </a>
            </div>
        </aside>

        <!-- ÁREA DE CONTENIDO PRINCIPAL -->
        <div class="sicm-main">
            <!-- BARRA SUPERIOR MINIMALISTA -->
            <header class="sicm-topbar">
                <div class="d-flex align-items-center">
                    <button class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1.5 px-2.5 py-1 me-2" id="btn-toggle-sidebar" type="button" title="Ocultar o mostrar menú lateral" aria-label="Alternar menú lateral">
                        <i class="bi bi-list fs-5" id="icon-toggle-sidebar"></i>
                        <span class="d-none d-md-inline small fw-semibold text-secondary">Menú</span>
                    </button>
                    <nav class="sicm-breadcrumb" aria-label="breadcrumb">
                        <span class="text-secondary"><?= htmlspecialchars($seccionNombre) ?></span>
                        <i class="bi bi-chevron-right text-muted" style="font-size: 0.65rem;"></i>
                        <span class="sicm-breadcrumb-active"><?= htmlspecialchars($moduloNombre) ?></span>
                    </nav>
                </div>
            </header>

            <!-- CONTENEDOR FLUIDO DE TRABAJO -->
            <main class="container-fluid py-3 px-3 px-md-4">

                <!-- Alertas de Flash Session -->
                <?php if ($mensajeExito): ?>
                    <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 py-2.5 px-3 mb-3 border shadow-sm" role="alert">
                        <i class="bi bi-check-circle-fill text-success fs-5 flex-shrink-0"></i>
                        <div class="small fw-medium"><?= htmlspecialchars((string) $mensajeExito, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
                    </div>
                <?php endif; ?>

                <?php if ($mensajeError): ?>
                    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 py-2.5 px-3 mb-3 border shadow-sm" role="alert">
                        <i class="bi bi-exclamation-octagon-fill text-danger fs-5 flex-shrink-0"></i>
                        <div class="small fw-medium"><?= htmlspecialchars((string) $mensajeError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
                    </div>
                <?php endif; ?>

        <?php if ($tabActiva === 'centros'): ?>
            <?php if (!$esAdmin): ?>
                <!-- Ficha de Jurisdicción para Farmaceuta -->
                <div class="card-sicm mb-3">
                    <div class="card-header-sicm d-flex justify-content-between align-items-center">
                        <span class="fw-bold"><i class="bi bi-geo-alt-fill text-primary me-1"></i> Jurisdicción Asignada</span>
                        <span class="badge bg-success-subtle text-success border border-success-subtle font-monospace"
                            style="font-size:0.68rem;">Rol Farmaceuta</span>
                    </div>
                    <div class="p-3">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <span class="text-muted small">Ambulatorio Asignado:</span>
                                <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($usuario['centro_nombre']) ?></div>
                            </div>
                            <div class="col-md-4">
                                <span class="text-muted small">Funcionario Responsable:</span>
                                <div class="fw-semibold text-dark"><?= htmlspecialchars($usuario['nombre']) ?></div>
                                <div class="text-muted small font-monospace"><?= htmlspecialchars($usuario['email']) ?></div>
                            </div>
                            <div class="col-md-4">
                                <div class="p-2 bg-light border rounded small text-secondary" style="font-size: 0.72rem;">
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

            <?php if ($esAdmin): ?>
                <!-- Modal de Registro / Edición de Ambulatorio -->
                <div class="modal fade" id="modalCentro" tabindex="-1" aria-labelledby="modalCentroLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-centered">
                        <div class="modal-content border-0 shadow">
                            <form id="form-centro" action="procesar.php" method="POST" novalidate>
                                <div class="modal-header bg-light py-2 px-3 border-bottom">
                                    <h6 class="modal-title fw-bold" id="modalCentroLabel">
                                        <i class="bi <?= $centroEdicion ? 'bi-pencil-square text-warning' : 'bi-hospital text-primary' ?> me-1"></i>
                                        <?= $centroEdicion ? 'Editar Ambulatorio #' . (int) $centroEdicion['id'] : 'Registrar Nuevo Ambulatorio' ?>
                                    </h6>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                                </div>
                                <div class="modal-body p-3">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="accion" value="guardar_centro">
                                    <input type="hidden" name="id"
                                        value="<?= $centroEdicion ? (int) $centroEdicion['id'] : '' ?>">

                                    <div class="row g-2 mb-2">
                                        <div class="col-md-4">
                                            <label for="codigo_sicm" class="form-label small fw-semibold mb-1">Código Sanitario SICM <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control form-control-sm font-monospace text-uppercase"
                                                id="codigo_sicm" name="codigo_sicm" placeholder="Ej: AMB-MER-004" required
                                                maxlength="30"
                                                value="<?= $centroEdicion ? htmlspecialchars($centroEdicion['codigo_sicm']) : '' ?>">
                                        </div>
                                        <div class="col-md-8">
                                            <label for="nombre" class="form-label small fw-semibold mb-1">Nombre del Ambulatorio <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control form-control-sm" id="nombre" name="nombre"
                                                placeholder="Ej: Ambulatorio Urbano Los Curos" required maxlength="120"
                                                value="<?= $centroEdicion ? htmlspecialchars($centroEdicion['nombre']) : '' ?>">
                                        </div>
                                    </div>

                                    <div class="row g-2 mb-2">
                                        <div class="col-md-6">
                                            <label for="tipo_centro_id" class="form-label small fw-semibold mb-1">Clasificación Asistencial <span class="text-danger">*</span></label>
                                            <select class="form-select form-select-sm" id="tipo_centro_id" name="tipo_centro_id" required>
                                                <option value="">-- Seleccionar --</option>
                                                <?php foreach ($tiposCentro as $t): ?>
                                                    <?php $sel = ($centroEdicion && (int) $centroEdicion['tipo_centro_id'] === (int) $t['id']) ? 'selected' : ''; ?>
                                                    <option value="<?= (int) $t['id'] ?>" <?= $sel ?>>
                                                        <?= htmlspecialchars($t['nombre']) ?> (<?= htmlspecialchars($t['codigo']) ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="telefono_principal" class="form-label small fw-semibold mb-1">Teléfono Institucional</label>
                                            <input type="text" class="form-control form-control-sm" id="telefono_principal"
                                                name="telefono_principal" placeholder="Ej: 0274-2521100" maxlength="30"
                                                value="<?= $centroEdicion ? htmlspecialchars($centroEdicion['telefono_principal'] ?? '') : '' ?>">
                                        </div>
                                    </div>

                                    <div class="row g-2 mb-2">
                                        <div class="col-md-6">
                                            <label for="medico_director" class="form-label small fw-semibold mb-1">Director Médico / Responsable</label>
                                            <input type="text" class="form-control form-control-sm" id="medico_director"
                                                name="medico_director" placeholder="Ej: Dra. Carmen Gil" maxlength="120"
                                                value="<?= $centroEdicion ? htmlspecialchars($centroEdicion['medico_director'] ?? '') : '' ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="horario_atencion" class="form-label small fw-semibold mb-1">Horario de Atención</label>
                                            <input type="text" class="form-control form-control-sm" id="horario_atencion"
                                                name="horario_atencion" placeholder="Ej: 24 Horas" maxlength="100"
                                                value="<?= $centroEdicion ? htmlspecialchars($centroEdicion['horario_atencion'] ?? '') : '' ?>">
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="direccion" class="form-label small fw-semibold mb-1">Dirección <span class="text-danger">*</span></label>
                                        <textarea class="form-control form-control-sm" id="direccion" name="direccion" rows="2"
                                            placeholder="Sector, calle o avenida" required
                                            maxlength="255"><?= $centroEdicion ? htmlspecialchars($centroEdicion['direccion']) : '' ?></textarea>
                                    </div>

                                    <!-- Equipamiento médico (N:M) -->
                                    <div class="p-2.5 bg-light border rounded">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <span class="small fw-semibold text-dark">Equipamiento y Capacidades Clínicas</span>
                                            <a href="index.php?tab=maestras" class="small text-decoration-none" style="font-size: 0.70rem;">Configurar Catálogo</a>
                                        </div>
                                        <div class="row g-2 mt-1">
                                            <?php foreach ($catalogoRecursosActivos as $rec): ?>
                                                <?php $chk = in_array((int) $rec['id'], $recursosEdicionIds, true) ? 'checked' : ''; ?>
                                                <div class="col-md-6">
                                                    <div class="form-check form-check-sm mb-1">
                                                        <input class="form-check-input" type="checkbox" name="recursos[]"
                                                            value="<?= (int) $rec['id'] ?>" id="rec_<?= (int) $rec['id'] ?>" <?= $chk ?>>
                                                        <label class="form-check-label small" for="rec_<?= (int) $rec['id'] ?>">
                                                            <strong><?= htmlspecialchars($rec['nombre']) ?></strong>
                                                            <span class="d-block text-muted" style="font-size: 0.70rem;"><?= htmlspecialchars($rec['descripcion'] ?? '') ?></span>
                                                        </label>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer bg-light py-2 px-3 border-top d-flex justify-content-between">
                                    <?php if ($centroEdicion): ?>
                                        <a href="index.php?tab=centros" class="btn btn-outline-secondary btn-sm">Cancelar edición</a>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
                                    <?php endif; ?>
                                    <button type="submit" class="btn btn-primary btn-sm px-3 fw-semibold">
                                        <i class="bi <?= $centroEdicion ? 'bi-check2-circle' : 'bi-plus-lg' ?> me-1"></i>
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
                        <div class="card-header-sicm d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <span class="fw-bold"><i class="bi bi-hospital me-1"></i> Ambulatorios de la Red</span>
                                <span class="badge bg-primary rounded-pill"><?= count($centros) ?></span>
                                <?php if ($esAdmin): ?>
                                    <button type="button" class="btn btn-primary btn-sm px-3 d-inline-flex align-items-center gap-1.5 shadow-sm ms-2"
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
                                    <input type="hidden" name="estado" value="<?= htmlspecialchars($filtroEstado) ?>">
                                    <input type="text" name="q" class="form-control form-control-sm" style="width: 140px;"
                                        placeholder="Buscar..." value="<?= htmlspecialchars($busqueda) ?>">
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
                                            <td colspan="7" class="text-center text-muted py-4">No hay ambulatorios registrados.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($centros as $c): ?>
                                            <?php
                                            $recs = $recursosPorCentro[(int) $c['id']] ?? [];
                                            ?>
                                            <tr>
                                                <td class="text-muted fw-bold">#<?= (int) $c['id'] ?></td>
                                                <td><span class="code-tag"><?= htmlspecialchars($c['codigo_sicm']) ?></span></td>
                                                <td>
                                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($c['nombre']) ?></div>
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
                                                        <span class="text-muted fst-italic small" style="font-size: 0.70rem;">Sin
                                                            equipamiento</span>
                                                    <?php else: ?>
                                                        <div class="d-flex flex-wrap gap-1">
                                                            <?php foreach ($recs as $nom): ?>
                                                                <span class="badge badge-resource"><?= htmlspecialchars($nom) ?></span>
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
                                                        <a href="index.php?tab=auditoria&subtab=deltas&tabla=centros_salud&registro_id=<?= (int) $c['id'] ?>"
                                                            class="btn btn-outline-info btn-sm py-0 px-2 text-decoration-none"
                                                            style="font-size: 0.74rem;"
                                                            title="Ver trazabilidad forense delta celda por celda">
                                                            <i class="bi bi-clock-history me-1"></i>Historial
                                                        </a>
                                                        <?php if ($esAdmin): ?>
                                                            <a href="index.php?tab=centros&editar=<?= (int) $c['id'] ?>&estado=<?= htmlspecialchars($filtroEstado) ?>"
                                                                class="btn btn-outline-primary btn-sm py-0 px-2"
                                                                style="font-size: 0.74rem;">Editar</a>
                                                            <?php if ((int) $c['activo'] === 1): ?>
                                                                <form method="POST" action="procesar.php" class="d-inline" onsubmit="return confirm('¿Desactivar ambulatorio?');">
                                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                                    <input type="hidden" name="accion" value="cambiar_estado_centro">
                                                                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                                                    <input type="hidden" name="estado" value="0">
                                                                    <button type="submit" class="btn btn-link text-danger p-0 ms-1 text-decoration-none" title="Desactivar" style="font-size: 0.85rem; line-height: 1;">✕</button>
                                                                </form>
                                                            <?php else: ?>
                                                                <form method="POST" action="procesar.php" class="d-inline" onsubmit="return confirm('¿Reactivar ambulatorio?');">
                                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                                    <input type="hidden" name="accion" value="cambiar_estado_centro">
                                                                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                                                    <input type="hidden" name="estado" value="1">
                                                                    <button type="submit" class="btn btn-outline-success btn-sm py-0 px-1 ms-1" style="font-size: 0.74rem;" title="Reactivar">Reactivar</button>
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
        <?php endif; ?>

        <!-- ==================================================================== -->
        <!-- PESTAÑA 2: CONFIGURACIÓN DE AMBULATORIOS (CATÁLOGOS MAESTROS 3FN)    -->
        <!-- ==================================================================== -->

<?php if ($tabActiva === 'medicamentos'): ?>

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
                            <div class="fs-4 fw-bold text-dark font-monospace"><?= number_format($kpiMedicamentosActivos) ?></div>
                            <span class="text-muted small" style="font-size: 0.70rem;">Catálogo formal del vademécum</span>
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
                                style="font-size: 0.72rem; letter-spacing: 0.04em;">Cadena de Frío (2°C - 8°C)</span>
                            <div class="fs-4 fw-bold text-dark font-monospace"><?= number_format($kpiCadenaFrio) ?></div>
                            <span class="text-muted small" style="font-size: 0.70rem;">Refrigeración clínica requerida</span>
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
                            <div class="fs-4 fw-bold text-dark font-monospace"><?= number_format($kpiPatologiasCie) ?></div>
                            <span class="text-muted small" style="font-size: 0.70rem;">Diagnósticos clínicos registrados</span>
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
                <div class="modal fade" id="modalMedicamento" tabindex="-1" aria-labelledby="modalMedicamentoLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-centered">
                        <div class="modal-content border-0 shadow">
                            <form method="POST" action="procesar.php" novalidate id="form-medicamento">
                                <div class="modal-header bg-light py-2 px-3 border-bottom">
                                    <h6 class="modal-title fw-bold" id="modalMedicamentoLabel">
                                        <i class="bi <?= $medicamentoEdicion ? 'bi-pencil-square text-warning' : 'bi-capsule text-primary' ?> me-1"></i>
                                        <?= $medicamentoEdicion ? 'Editar Especificaciones de Fármaco #' . (int) $medicamentoEdicion['id'] : 'Registrar Nuevo Fármaco' ?>
                                    </h6>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                                </div>
                                <div class="modal-body p-3">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="accion" value="<?= $medicamentoEdicion ? 'editar_medicamento' : 'crear_medicamento' ?>">
                                    <?php if ($medicamentoEdicion): ?>
                                        <input type="hidden" name="id" value="<?= (int) $medicamentoEdicion['id'] ?>">
                                    <?php endif; ?>

                                    <div class="row g-2 mb-2">
                                        <div class="col-md-7">
                                            <label class="form-label-sicm mb-1">Nombre Genérico (Principio Activo) <span class="text-danger">*</span></label>
                                            <input type="text" name="nombre_generico" class="form-control form-control-sm"
                                                placeholder="Ej: Losartán Potásico" required minlength="2" maxlength="120"
                                                value="<?= htmlspecialchars($medicamentoEdicion['nombre_generico'] ?? '') ?>">
                                            <div class="form-text" style="font-size: 0.70rem;">Denominación Común Internacional (DCI).</div>
                                        </div>
                                        <div class="col-md-5">
                                            <label class="form-label-sicm mb-1">Concentración / Dosificación <span class="text-danger">*</span></label>
                                            <input type="text" name="concentracion" class="form-control form-control-sm"
                                                placeholder="Ej: 50mg, 100UI/ml, 850mg" required minlength="1" maxlength="50"
                                                value="<?= htmlspecialchars($medicamentoEdicion['concentracion'] ?? '') ?>">
                                            <div class="form-text" style="font-size: 0.70rem;">Fuerza activa o dosis unitaria.</div>
                                        </div>
                                    </div>

                                    <div class="row g-2 mb-2">
                                        <div class="col-md-6">
                                            <label class="form-label-sicm mb-1">Forma Farmacéutica <span class="text-danger">*</span></label>
                                            <select name="forma_farmaceutica_id" class="form-select form-select-sm" required>
                                                <option value="">-- Seleccionar Presentación --</option>
                                                <?php foreach ($formasFarmaceuticas as $forma): ?>
                                                    <option value="<?= $forma['id'] ?>" <?= ($medicamentoEdicion && (int) $medicamentoEdicion['forma_farmaceutica_id'] === (int) $forma['id']) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($forma['nombre']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label-sicm mb-1">Stock Mínimo Sugerido (Umbral de Alerta)</label>
                                            <input type="number" name="stock_minimo_sugerido" class="form-control form-control-sm"
                                                min="1" max="99999" required
                                                value="<?= (int) ($medicamentoEdicion['stock_minimo_sugerido'] ?? 20) ?>">
                                            <div class="form-text" style="font-size: 0.70rem;">Dispara avisos automáticos de reposición en ambulatorios.</div>
                                        </div>
                                    </div>

                                    <div class="p-2.5 bg-light border rounded">
                                        <div class="form-check form-switch mb-0">
                                            <input class="form-check-input" type="checkbox" role="switch" id="switchCadenaFrio"
                                                name="requiere_cadena_frio" value="1"
                                                <?= ($medicamentoEdicion && (int) $medicamentoEdicion['requiere_cadena_frio'] === 1) ? 'checked' : '' ?>>
                                            <label class="form-check-label small fw-semibold text-dark" for="switchCadenaFrio">
                                                <i class="bi bi-snow2 text-info me-1"></i> Requiere Cadena de Frío Estricta
                                            </label>
                                        </div>
                                        <div class="text-muted mt-1" style="font-size: 0.69rem; line-height: 1.3;">
                                            Refrigeración clínica continua entre <strong>2°C y 8°C</strong>. Su dispensación exige ambulatorios con equipo de frío certificado.
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer bg-light py-2 px-3 border-top d-flex justify-content-between">
                                    <?php if ($medicamentoEdicion): ?>
                                        <a href="index.php?tab=medicamentos&subtab=vademecum" class="btn btn-outline-secondary btn-sm">Cancelar edición</a>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
                                    <?php endif; ?>
                                    <button type="submit" class="btn btn-primary btn-sm px-3 fw-semibold">
                                        <i class="bi <?= $medicamentoEdicion ? 'bi-check2-circle' : 'bi-plus-lg' ?> me-1"></i>
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
                            <div class="card-header-sicm d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
                                <div class="d-flex align-items-center gap-2">
                                    <h6 class="m-0 fw-bold">
                                        <i class="bi bi-list-check text-primary me-1"></i>
                                        Vademécum Municipal (<?= count($medicamentos) ?> fármacos)
                                    </h6>
                                    <button type="button" class="btn btn-primary btn-sm px-3 d-inline-flex align-items-center gap-1 shadow-sm ms-2"
                                        data-bs-toggle="modal" data-bs-target="#modalMedicamento">
                                        <i class="bi bi-plus-lg me-1"></i>Registrar Fármaco
                                    </button>
                                </div>
                                <form method="GET" action="index.php" class="d-flex flex-wrap align-items-center gap-1 m-0">
                                    <input type="hidden" name="tab" value="medicamentos">
                                    <input type="hidden" name="subtab" value="vademecum">
                                    <select name="estado_med" class="form-select form-select-sm" style="width: auto; font-size: 0.78rem;" onchange="this.form.submit()">
                                        <option value="todos" <?= ($filtroEstadoMed === 'todos') ? 'selected' : '' ?>>Todos los Estados</option>
                                        <option value="activos" <?= ($filtroEstadoMed === 'activos') ? 'selected' : '' ?>>Solo Operativos</option>
                                        <option value="descontinuados" <?= ($filtroEstadoMed === 'descontinuados') ? 'selected' : '' ?>>Solo Descontinuados</option>
                                    </select>
                                    <div class="input-group input-group-sm" style="width: 190px;">
                                        <input type="text" name="q_med" class="form-control" placeholder="Buscar principio..."
                                            value="<?= htmlspecialchars($busquedaMed) ?>">
                                        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
                                    </div>
                                    <?php if ($busquedaMed !== '' || $filtroEstadoMed !== 'todos'): ?>
                                        <a href="index.php?tab=medicamentos&subtab=vademecum" class="btn btn-outline-danger btn-sm py-1 px-2" title="Limpiar Filtros">
                                            <i class="bi bi-x-circle"></i>
                                        </a>
                                    <?php endif; ?>
                                </form>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0 font-sans" style="font-size: 0.82rem;">
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
                                                    No se encontraron medicamentos en el vademécum con los filtros indicados.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($medicamentos as $med): ?>
                                                <tr class="<?= ((int) $med['activo'] === 0) ? 'table-secondary opacity-75' : '' ?>">
                                                    <td class="font-monospace text-muted">#<?= $med['id'] ?></td>
                                                    <td>
                                                        <span class="fw-bold text-dark"><?= htmlspecialchars($med['nombre_generico']) ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-light text-secondary border font-monospace" style="font-size: 0.72rem;">
                                                            <?= htmlspecialchars($med['concentracion']) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="text-secondary"><?= htmlspecialchars($med['forma_nombre']) ?></span>
                                                    </td>
                                                    <td>
                                                        <?php if ((int) $med['requiere_cadena_frio'] === 1): ?>
                                                            <span class="badge bg-info text-dark" style="font-size: 0.72rem;">
                                                                <i class="bi bi-snow2 me-1"></i>❄️ Refrigerado (2-8°C)
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge bg-light text-secondary border" style="font-size: 0.72rem;">
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
                                                            <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.72rem;">
                                                                Operativo
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle" style="font-size: 0.72rem;">
                                                                Descontinuado
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <div class="d-inline-flex gap-1">
                                                            <?php if ($esAdmin): ?>
                                                                <a href="index.php?tab=auditoria&subtab=deltas&tabla=medicamentos&registro_id=<?= $med['id'] ?>"
                                                                    class="btn btn-outline-secondary btn-sm py-0.5 px-2"
                                                                    style="font-size: 0.75rem;" title="Ver Auditoría Delta celda por celda">
                                                                    <i class="bi bi-clock-history"></i> Historial
                                                                </a>
                                                            <?php endif; ?>
                                                            <a href="index.php?tab=medicamentos&subtab=vademecum&editar_med=<?= $med['id'] ?>"
                                                                class="btn btn-outline-primary btn-sm py-0.5 px-2"
                                                                style="font-size: 0.75rem;" title="Editar Especificaciones de Fármaco">
                                                                <i class="bi bi-pencil-square"></i> Editar
                                                            </a>
                                                            <form method="POST" action="procesar.php" class="d-inline"
                                                                onsubmit="return confirm('¿Confirma que desea <?= ((int) $med['activo'] === 1) ? 'descontinuar' : 'reactivar' ?> el medicamento <?= htmlspecialchars(addslashes($med['nombre_generico'])) ?>?');">
                                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                                <input type="hidden" name="accion" value="cambiar_estado_medicamento">
                                                                <input type="hidden" name="id" value="<?= $med['id'] ?>">
                                                                <button type="submit"
                                                                    class="btn btn-sm py-0.5 px-2 <?= ((int) $med['activo'] === 1) ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                                                    style="font-size: 0.75rem;"
                                                                    title="<?= ((int) $med['activo'] === 1) ? 'Descontinuar fármaco' : 'Reactivar fármaco' ?>">
                                                                    <?= ((int) $med['activo'] === 1) ? 'Desactivar' : 'Reactivar' ?>
                                                                </button>
                                                            </form>
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
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="accion" value="crear_patologia">

                                    <div class="mb-3">
                                        <label class="form-label-sicm">Código CIE-10 (Opcional)</label>
                                        <input type="text" name="codigo_cie10" class="form-control form-control-sm font-monospace text-uppercase"
                                            placeholder="Ej: I10, E11, J45" maxlength="10">
                                        <div class="form-text" style="font-size: 0.70rem;">Clasificación Internacional de Enfermedades (CIE-10).</div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label-sicm">Diagnóstico / Denominación <span class="text-danger">*</span></label>
                                        <input type="text" name="nombre" class="form-control form-control-sm"
                                            placeholder="Ej: Hipertensión Arterial Primaria" required minlength="3" maxlength="120">
                                    </div>

                                    <div class="mb-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="cronica" value="1" id="checkCronica" checked>
                                            <label class="form-check-label small fw-semibold text-dark" for="checkCronica">
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
                                            placeholder="Detalles sobre criterios diagnósticos o estadio evolutivo..." maxlength="255"></textarea>
                                    </div>

                                    <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
                                        <span class="text-muted small" style="font-size: 0.70rem;"><span class="text-danger">*</span> Campo requerido</span>
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
                                                <tr class="<?= ((int) $pat['activo'] === 0) ? 'table-secondary opacity-75' : '' ?>">
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
                                                        <div class="fw-bold text-dark"><?= htmlspecialchars($pat['nombre']) ?></div>
                                                        <?php if (!empty($pat['descripcion'])): ?>
                                                            <div class="text-muted small" style="font-size: 0.72rem; line-height: 1.2;">
                                                                <?= htmlspecialchars($pat['descripcion']) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php if ((int) $pat['cronica'] === 1): ?>
                                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size: 0.72rem;">
                                                                Crónica
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle" style="font-size: 0.72rem;">
                                                                Aguda
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php if ((int) $pat['activo'] === 1): ?>
                                                            <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.72rem;">
                                                                Activo
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle" style="font-size: 0.72rem;">
                                                                Inactivo
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <form method="POST" action="procesar.php" class="d-inline"
                                                            onsubmit="return confirm('¿Confirma que desea <?= ((int) $pat['activo'] === 1) ? 'desactivar' : 'reactivar' ?> la patología <?= htmlspecialchars(addslashes($pat['nombre'])) ?>?');">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
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
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="accion" value="crear_forma_farmaceutica">

                                    <div class="mb-3">
                                        <label class="form-label-sicm">Denominación de Presentación <span class="text-danger">*</span></label>
                                        <input type="text" name="nombre" class="form-control form-control-sm"
                                            placeholder="Ej: Tableta, Cápsula, Jarabe, Ampolla..." required minlength="2" maxlength="60">
                                        <div class="form-text" style="font-size: 0.70rem;">
                                            Vía o formato físico de administración del principio activo.
                                        </div>
                                    </div>

                                    <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
                                        <span class="text-muted small" style="font-size: 0.70rem;"><span class="text-danger">*</span> Campo requerido</span>
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
                                                            <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.72rem;">
                                                                En Uso Activo
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle" style="font-size: 0.72rem;">
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

        <?php endif; ?>

        <!-- ==================================================================== -->
        <!-- PESTAÑA 3: AUDITORÍA FORENSE Y TRAZABILIDAD TRANSACCIONAL (WORM)     -->
        <!-- ==================================================================== -->
        <?php if ($tabActiva === 'maestras'): ?>
            <div class="card bg-light border mb-3">
                <div class="card-body p-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <strong class="text-dark fs-6">Configuración y Catálogos Maestros</strong>
                        </div>
                        <p class="text-muted small mb-0">
                            Gestión y parametrización de catálogos maestros del sistema. Permite incorporar y mantener las
                            clasificaciones de ambulatorios y el equipamiento operativo de la red de salud municipal.
                        </p>
                    </div>
                    <a href="index.php?tab=centros" class="btn btn-outline-primary btn-sm py-1 px-3 text-nowrap">Volver a
                        Ambulatorios</a>
                </div>
            </div>

            <div class="row g-3">
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
                            Define las clasificaciones y niveles de atención disponibles para los ambulatorios de la red.
                        </div>
                        <div class="p-3 bg-light border-top border-bottom">
                            <form action="procesar.php" method="POST" class="row g-2 align-items-end">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
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
                                                    <span class="text-muted small" style="font-size: 0.72rem;">0 centros</span>
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
                                                <form method="POST" action="procesar.php" class="d-inline" onsubmit="return confirm('¿Confirma cambiar el estado de este tipo de centro?');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="accion" value="toggle_tipo_centro">
                                                    <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                                    <input type="hidden" name="estado" value="<?= (int) $t['activo'] === 1 ? '0' : '1' ?>">
                                                    <button type="submit" class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size: 0.72rem;">
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
                            Registra las capacidades e instalaciones técnicas asignables a cada centro de salud (cadena de
                            frío, energía de emergencia, ambulancias).
                        </div>
                        <div class="p-3 bg-light border-top border-bottom">
                            <form action="procesar.php" method="POST" class="row g-2 align-items-end">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
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
                                                    <span class="text-muted small" style="font-size: 0.72rem;">0 centros</span>
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
                                                <form method="POST" action="procesar.php" class="d-inline" onsubmit="return confirm('¿Confirma cambiar el estado de este equipamiento/recurso?');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="accion" value="toggle_recurso">
                                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                                    <input type="hidden" name="estado" value="<?= (int) $r['activo'] === 1 ? '0' : '1' ?>">
                                                    <button type="submit" class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size: 0.72rem;">
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
                            <span><?= $usuarioEdicion ? 'Editar Operador #' . (int) $usuarioEdicion['id'] : 'Nuevo Operador Sanitario' ?></span>
                            <?php if ($usuarioEdicion): ?>
                                <a href="index.php?tab=usuarios" class="small text-decoration-none text-muted">Cancelar
                                    edición</a>
                            <?php endif; ?>
                        </div>
                        <div class="p-3">
                            <form id="form-usuario" action="procesar.php" method="POST" novalidate>
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="accion" value="guardar_usuario">
                                <input type="hidden" id="u_id" name="id"
                                    value="<?= $usuarioEdicion ? (int) $usuarioEdicion['id'] : '' ?>">

                                <div class="mb-2">
                                    <label for="u_nombre" class="form-label small fw-semibold mb-1">Nombre Completo del
                                        Funcionario</label>
                                    <input type="text" class="form-control form-control-sm" id="u_nombre" name="nombre"
                                        placeholder="Ej: Lcda. María Rivas" required maxlength="80"
                                        value="<?= $usuarioEdicion ? htmlspecialchars($usuarioEdicion['nombre']) : '' ?>">
                                </div>

                                <div class="mb-2">
                                    <label for="u_email" class="form-label small fw-semibold mb-1">Correo Institucional
                                        Oficial</label>
                                    <input type="email" class="form-control form-control-sm" id="u_email" name="email"
                                        placeholder="ej: mrivas@salud.prueba.ve" required maxlength="120"
                                        value="<?= $usuarioEdicion ? htmlspecialchars($usuarioEdicion['email']) : '' ?>">
                                </div>

                                <div class="mb-2">
                                    <label for="u_rol_id" class="form-label small fw-semibold mb-1">Rol Operativo</label>
                                    <select class="form-select form-select-sm" id="u_rol_id" name="rol_id" required>
                                        <option value="">-- Seleccionar Rol --</option>
                                        <?php foreach ($rolesCatalogo as $ro): ?>
                                            <?php $selR = ($usuarioEdicion && (int) $usuarioEdicion['rol_id'] === (int) $ro['id']) ? 'selected' : ''; ?>
                                            <option value="<?= (int) $ro['id'] ?>" <?= $selR ?>>
                                                <?= (int) $ro['id'] === 1 ? 'Administrador Central (Acceso Global)' : 'Farmaceuta (Dispensación Sectorial)' ?>
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
                                        Delimita la visibilidad estricta de inventario y dispensación del farmaceuta.
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
                                            <td colspan="6" class="text-center text-muted py-4">No hay operadores registrados
                                                con ese criterio.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($listaUsuarios as $u): ?>
                                            <tr>
                                                <td class="text-muted fw-bold">#<?= (int) $u['id'] ?></td>
                                                <td>
                                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($u['nombre']) ?></div>
                                                    <div class="text-muted small font-monospace" style="font-size: 0.72rem;">
                                                        <?= htmlspecialchars($u['email']) ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ((int) $u['rol_id'] === 1): ?>
                                                        <span
                                                            class="badge bg-primary-subtle text-primary border border-primary-subtle">Admin
                                                            Central</span>
                                                    <?php else: ?>
                                                        <span
                                                            class="badge bg-success-subtle text-success border border-success-subtle">Farmaceuta</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ((int) $u['rol_id'] === 1 || empty($u['centro_salud_id'])): ?>
                                                        <span class="badge bg-light text-secondary border">Red Municipal Completa</span>
                                                    <?php else: ?>
                                                        <div class="fw-semibold text-dark" style="font-size: 0.80rem;">
                                                            <?= htmlspecialchars($u['centro_nombre']) ?>
                                                        </div>
                                                        <span class="code-tag"
                                                            style="font-size: 0.68rem;"><?= htmlspecialchars($u['centro_codigo']) ?></span>
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
                                                        <a href="index.php?tab=usuarios&editar_u=<?= (int) $u['id'] ?>&estado_u=<?= htmlspecialchars($filtroEstadoU) ?>"
                                                            class="btn btn-outline-primary btn-sm py-0 px-2"
                                                            style="font-size: 0.74rem;">Editar</a>
                                                        <?php if ((int) $u['id'] !== (int) $usuario['id']): ?>
                                                            <?php if ((int) $u['activo'] === 1): ?>
                                                                <form method="POST" action="procesar.php" class="d-inline" onsubmit="return confirm('¿Desactivar operador?');">
                                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                                    <input type="hidden" name="accion" value="cambiar_estado_usuario">
                                                                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                                                    <input type="hidden" name="estado" value="0">
                                                                    <button type="submit" class="btn btn-link text-danger p-0 ms-1 text-decoration-none" title="Desactivar" style="font-size: 0.85rem; line-height: 1;">✕</button>
                                                                </form>
                                                            <?php else: ?>
                                                                <form method="POST" action="procesar.php" class="d-inline" onsubmit="return confirm('¿Reactivar operador?');">
                                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                                    <input type="hidden" name="accion" value="cambiar_estado_usuario">
                                                                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                                                    <input type="hidden" name="estado" value="1">
                                                                    <button type="submit" class="btn btn-outline-success btn-sm py-0 px-1 ms-1" style="font-size: 0.74rem;" title="Reactivar">Reactivar</button>
                                                                </form>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <span class="text-muted small ms-1" style="font-size: 0.70rem;"
                                                                title="Tu sesión actual">(Tú)</span>
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
        <!-- PESTAÑA 3: AUDITORÍA FORENSE Y TRAZABILIDAD TRANSACCIONAL (WORM)     -->
        <!-- ==================================================================== -->

        <?php if ($tabActiva === 'auditoria'): ?>
            <!-- Cuadro de Mando de Auditoría (KPIs Institucionales) -->
            <div class="row g-2 mb-3">
                <div class="col-12 col-md-4">
                    <div class="card-sicm p-2.5 text-center shadow-sm">
                        <span class="text-muted small d-block fw-semibold" style="font-size: 0.75rem;">Total de
                            Eventos</span>
                        <div class="fs-4 fw-bold text-dark font-monospace"><?= number_format($totalLogsCount) ?></div>
                        <span class="text-muted small" style="font-size: 0.70rem;">Bitácora general del sistema</span>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card-sicm p-2.5 text-center border-start border-danger border-4 shadow-sm">
                        <span class="text-danger small d-block fw-bold" style="font-size: 0.75rem;">Alertas del
                            Sistema</span>
                        <div class="fs-4 fw-bold text-danger font-monospace"><?= number_format($totalAlertasSeguridad) ?>
                        </div>
                        <span class="text-muted small" style="font-size: 0.70rem;">Incidentes e intentos fallidos</span>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card-sicm p-2.5 text-center border-start border-primary border-4 shadow-sm">
                        <span class="text-primary small d-block fw-bold" style="font-size: 0.75rem;">Modificaciones de
                            Datos</span>
                        <div class="fs-4 fw-bold text-primary font-monospace"><?= number_format($totalDeltasCampos) ?></div>
                        <span class="text-muted small" style="font-size: 0.70rem;">Cambios registrados por campo</span>
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
                    <?php if ($subtabActiva === 'eventos'): ?>
                        <a href="procesar.php?accion=exportar_acta_pericial&<?= $exportQuery ?>"
                            class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1 shadow-sm"
                            style="font-size: 0.75rem;" title="Descargar bitácora de eventos filtrados en formato CSV">
                            <i class="bi bi-file-earmark-arrow-down"></i> Exportar Eventos (CSV)
                        </a>
                    <?php else: ?>
                        <a href="procesar.php?accion=exportar_acta_pericial&<?= $exportQuery ?>"
                            class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1 shadow-sm"
                            style="font-size: 0.75rem;" title="Descargar historial de modificaciones filtradas en formato CSV">
                            <i class="bi bi-file-earmark-arrow-down"></i> Exportar Modificaciones (CSV)
                        </a>
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
                            <label class="form-label text-muted small fw-semibold mb-1 d-block" for="select-tabla-filtro">
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
                                <label class="form-label text-muted small fw-semibold mb-1 d-block" for="select-campo-filtro">
                                    Campo Modificado
                                </label>
                                <select name="campo_filtro" id="select-campo-filtro" class="form-select form-select-sm"
                                    title="Filtrar por nombre de campo modificado">
                                    <option value="todos" <?= $filtroCampo === 'todos' ? 'selected' : '' ?>>-- Todos los campos --</option>
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
                                <label class="form-label text-muted small fw-semibold mb-1 d-block" for="select-tipo-evento">
                                    Tipo de Evento / Severidad
                                </label>
                                <select name="tipo_evento" id="select-tipo-evento" class="form-select form-select-sm"
                                    title="Filtrar por severidad o tipo de evento">
                                    <option value="todos" <?= $filtroTipo === 'todos' ? 'selected' : '' ?>>Todos los eventos
                                    </option>
                                    <option value="alertas" <?= $filtroTipo === 'alertas' ? 'selected' : '' ?>>Solo alertas e
                                        incidentes</option>
                                    <option value="anonimo" <?= $filtroTipo === 'anonimo' ? 'selected' : '' ?>>Sin autenticar /
                                        Intentos anónimos</option>
                                    <option value="cli" <?= $filtroTipo === 'cli' ? 'selected' : '' ?>>Accesos directos por
                                        terminal</option>
                                    <option value="info" <?= $filtroTipo === 'info' ? 'selected' : '' ?>>Operaciones habituales
                                    </option>
                                </select>
                            </div>
                        <?php endif; ?>

                        <!-- Col 3: Operador / Usuario -->
                        <div class="col-md-3 col-sm-6">
                            <label class="form-label text-muted small fw-semibold mb-1 d-block" for="select-usuario-filtro">
                                Operador / Actor
                            </label>
                            <select name="usuario_filtro" id="select-usuario-filtro" class="form-select form-select-sm"
                                title="Filtrar por operador o actor del sistema">
                                <option value="todos" <?= $filtroUsuario === 'todos' ? 'selected' : '' ?>>-- Todos los actores
                                    --</option>
                                <optgroup label="Seguridad y Motor">
                                    <option value="anonimo" <?= $filtroUsuario === 'anonimo' ? 'selected' : '' ?>>[!] Intentos
                                        Anónimos / Sin Sesión</option>
                                    <option value="consola" <?= $filtroUsuario === 'consola' ? 'selected' : '' ?>>[⚙] Consola
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
                <div class="d-flex flex-wrap align-items-center gap-1.5 mb-3 p-2 px-3 bg-white border rounded shadow-xs">
                    <span class="text-muted small fw-semibold me-1">
                        <i class="bi bi-funnel text-primary me-1"></i>Filtros activos:
                    </span>
                    <?php foreach ($filtrosActivosChips as $chip): ?>
                        <span class="badge bg-light text-dark border d-inline-flex align-items-center gap-1 py-1.5 px-2 fw-normal"
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

            <!-- ================================================================ -->
            <!-- SUBPESTAÑA 1: BITÁCORA DE EVENTOS                                -->
            <!-- ================================================================ -->
            <?php if ($subtabActiva === 'eventos'): ?>
                <div class="card-sicm shadow-sm" id="forensic-table-container" data-subtab="eventos"
                    data-page="<?= $paginaActual ?>" data-max-id="<?= $maxLogId ?>"
                    data-has-filters="<?= $hayFiltrosActivos ? '1' : '0' ?>">
                    <div class="card-header-sicm d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-journal-text text-primary"></i>
                            <span class="fw-bold">Bitácora de Eventos</span>
                            <span class="text-muted small fw-normal">(Mostrando <?= count($logs) ?> de
                                <?= number_format($totalFiltrados) ?> eventos)</span>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm table-sicm mb-0">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">#ID</th>
                                    <th style="width: 140px;">Fecha y Hora</th>
                                    <th>Operador / Responsable</th>
                                    <th style="width: 150px;">Acción</th>
                                    <th>Entidad Afectada</th>
                                    <th style="width: 110px;">IP Origen</th>
                                    <th>Canal / Navegador</th>
                                    <th style="width: 80px;" class="text-end pe-3">Detalle</th>
                                </tr>
                            </thead>
                            <tbody id="forensic-tbody">
                                <?php if (empty($logs)): ?>
                                    <tr class="fila-sin-datos">
                                        <td colspan="8" class="text-center py-5">
                                            <div class="py-3">
                                                <div class="mb-2 text-muted">
                                                    <i class="bi bi-search text-secondary" style="font-size: 2.2rem;"></i>
                                                </div>
                                                <h6 class="text-secondary fw-semibold mb-1">No se encontraron eventos que coincidan
                                                    con los criterios de búsqueda seleccionados.</h6>
                                                <p class="text-muted small mb-3">Intenta cambiar los términos de búsqueda, rango de
                                                    fechas o restablece los filtros para ver toda la actividad.</p>
                                                <a href="index.php?tab=auditoria&subtab=eventos"
                                                    class="btn btn-outline-primary btn-sm px-3 shadow-xs">
                                                    <i class="bi bi-arrow-counterclockwise me-1"></i> Restablecer todos los filtros
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php
                                    $prevLogId = null;
                                    foreach ($logs as $l):
                                        $currLogId = (int) $l['log_id'];

                                        // Detección de Discontinuidades en secuencia
                                        if ($prevLogId !== null && ($prevLogId - $currLogId) > 1):
                                            $salto = ($prevLogId - 1) - $currLogId;
                                            ?>
                                            <tr class="table-warning border-start border-danger border-4">
                                                <td colspan="8" class="text-danger fw-bold small py-2 px-3">
                                                    <i class="bi bi-exclamation-triangle-fill text-danger me-1"></i>
                                                    <strong>[ ! ] Discontinuidad detectada:</strong> Salto de secuencia entre ID
                                                    #<?= $currLogId ?> e ID #<?= $prevLogId ?> (<?= $salto ?> registro(s) omitido(s))
                                                </td>
                                            </tr>
                                            <?php
                                        endif;
                                        $prevLogId = $currLogId;

                                        // Semántica cromática de severidad
                                        $accion = (string) $l['accion'];
                                        $ip = (string) $l['ip_origen'];
                                        $severidad = 'INFO';
                                        $badgeColor = 'bg-info text-dark';
                                        $rowBorderClass = 'border-start border-3 border-info';

                                        if (
                                            in_array($accion, ['INTENTO_LOGIN_FALLIDO', 'INTENTO_DOBLE_RETIRO', 'VIOLACION_REGLA_TRIGGER', 'ERROR_GENERAL_BD', 'ACCESO_DENEGADO'], true)
                                            || $ip === 'CONSOLA_DIRECTA'
                                            || str_contains($accion, 'FALLID')
                                            || str_contains($accion, 'TRIGGER')
                                            || str_contains($accion, 'VIOLACION')
                                        ) {
                                            $severidad = 'CRITICAL';
                                            $badgeColor = 'bg-danger text-white';
                                            $rowBorderClass = 'border-start border-4 border-danger table-danger-subtle';
                                        } elseif (
                                            str_contains($accion, 'DESACTIVAR')
                                            || str_contains($accion, 'LOTE_AGOTADO')
                                            || str_contains($accion, 'UMBRAL_MINIMO')
                                            || str_contains($accion, 'REACTIVAR')
                                        ) {
                                            $severidad = 'WARNING';
                                            $badgeColor = 'bg-warning text-dark';
                                            $rowBorderClass = 'border-start border-3 border-warning';
                                        } elseif (
                                            str_contains($accion, 'CREAR')
                                            || str_contains($accion, 'REGISTRAR')
                                            || str_contains($accion, 'INICIO_SESION')
                                            || str_contains($accion, 'DESPACHO_FEFO')
                                            || str_contains($accion, 'INICIALIZAR')
                                        ) {
                                            $severidad = 'INFO';
                                            $badgeColor = 'bg-success text-white';
                                            $rowBorderClass = 'border-start border-3 border-success';
                                        }

                                        $detPreview = [];
                                        try {
                                            $detPreview = json_decode($l['detalles_json'] ?? '{}', true) ?: [];
                                        } catch (Throwable $e) {
                                        }

                                        $regId = (int) ($l['registro_id'] ?? 0);
                                        $esSesion = in_array($accion, ['INICIO_SESION', 'CIERRE_SESION', 'INTENTO_LOGIN_FALLIDO', 'INTENTO_LOGIN_RECHAZADO'], true);
                                        $entidadInfo = resolverEntidadLegible($l['tabla_afectada'] ?? '', $regId ?: null, $detPreview, $diccionariosEntidades);
                                        ?>
                                        <tr class="<?= $rowBorderClass ?>">
                                            <td class="code-tag">#<?= $currLogId ?></td>
                                            <td class="small font-monospace"><?= htmlspecialchars($l['fecha_hora']) ?></td>
                                            <td>
                                                <?php if ($l['usuario_id'] === null): ?>
                                                    <?php if ($ip === 'CONSOLA_DIRECTA'): ?>
                                                        <div><span class="badge bg-dark"><i class="bi bi-terminal-fill me-1"></i> Consola
                                                                DB</span></div>
                                                        <span class="text-danger small fw-semibold" style="font-size: 0.70rem;">Acceso
                                                            directo</span>
                                                    <?php else: ?>
                                                        <div><span class="badge bg-secondary">No autenticado</span></div>
                                                        <span class="text-muted small" style="font-size: 0.70rem;">Intento fallido</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <div class="fw-semibold text-dark">
                                                        <?= htmlspecialchars($l['operador']) ?>
                                                        <?php if (!empty($l['email_operador'])): ?>
                                                            <span
                                                                class="text-muted fw-normal small">(<?= htmlspecialchars($l['email_operador']) ?>)</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="text-secondary small" style="font-size: 0.72rem;">
                                                        <i class="bi bi-person-badge text-muted me-0.5"></i>
                                                        <?= htmlspecialchars($l['rol_operador'] ?? 'Usuario') ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge <?= $badgeColor ?>" style="font-size: 0.72rem;">
                                                    <?= htmlspecialchars($l['accion']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($esSesion): ?>
                                                    <div class="d-flex flex-column">
                                                        <span class="fw-semibold text-dark" style="font-size: 0.80rem;">
                                                            <i class="bi bi-shield-lock text-muted me-1"></i>Sesión de Usuario
                                                        </span>
                                                        <span class="text-muted small" style="font-size: 0.70rem;">
                                                            <?= htmlspecialchars($detPreview['evento'] ?? 'Control de acceso') ?>
                                                        </span>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="d-flex flex-column">
                                                        <span class="fw-bold text-dark text-truncate"
                                                            style="max-width: 260px; font-size: 0.80rem;"
                                                            title="<?= htmlspecialchars($entidadInfo['titulo']) ?>">
                                                            <?= htmlspecialchars($entidadInfo['titulo']) ?>
                                                        </span>
                                                        <div class="mt-0.5 d-flex align-items-center gap-1">
                                                            <span class="text-muted small" style="font-size: 0.70rem;">
                                                                <?= htmlspecialchars($entidadInfo['subtitulo']) ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($ip === 'CONSOLA_DIRECTA'): ?>
                                                    <span class="badge bg-danger text-white font-monospace"
                                                        title="Acceso directo sin servidor web">
                                                        <i class="bi bi-terminal-fill me-1"></i> CLI
                                                    </span>
                                                <?php else: ?>
                                                    <span class="code-tag"><?= htmlspecialchars($ip) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                $uaRaw = $l['user_agent'] ?? 'CLI / Local';
                                                $uaResumen = resumirUserAgent($uaRaw);
                                                ?>
                                                <div class="d-flex flex-column" title="<?= htmlspecialchars($uaRaw) ?>">
                                                    <span class="fw-semibold text-dark" style="font-size: 0.76rem;">
                                                        <?= htmlspecialchars($uaResumen) ?>
                                                    </span>
                                                    <span class="text-muted text-truncate font-monospace"
                                                        style="max-width: 190px; font-size: 0.68rem;"
                                                        title="<?= htmlspecialchars($uaRaw) ?>">
                                                        <?= htmlspecialchars($uaRaw) ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="text-end pe-3">
                                                <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2 btn-ver-json"
                                                    data-id="<?= $currLogId ?>" data-accion="<?= htmlspecialchars($l['accion']) ?>"
                                                    data-tabla="<?= htmlspecialchars($l['tabla_afectada']) ?>"
                                                    data-registro="<?= htmlspecialchars((string) ($l['registro_id'] ?? '')) ?>"
                                                    data-operador="<?= htmlspecialchars($l['operador']) ?>"
                                                    data-rol="<?= htmlspecialchars($l['rol_operador']) ?>"
                                                    data-email="<?= htmlspecialchars($l['email_operador'] ?? '') ?>"
                                                    data-usuario-id="<?= htmlspecialchars((string) ($l['usuario_id'] ?? '')) ?>"
                                                    data-fecha="<?= htmlspecialchars($l['fecha_hora']) ?>"
                                                    data-ip="<?= htmlspecialchars($l['ip_origen']) ?>"
                                                    data-ua="<?= htmlspecialchars($l['user_agent'] ?? 'CLI / Local') ?>"
                                                    data-ua-resumen="<?= htmlspecialchars($uaResumen) ?>"
                                                    data-entidad-titulo="<?= htmlspecialchars($esSesion ? 'Sesión de Usuario' : $entidadInfo['titulo']) ?>"
                                                    data-entidad-subtitulo="<?= htmlspecialchars($esSesion ? 'Control de acceso' : $entidadInfo['subtitulo']) ?>"
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
                        <div
                            class="card-footer bg-light d-flex flex-wrap justify-content-between align-items-center py-2 px-3 border-top">
                            <span class="small text-muted" style="font-size: 0.78rem;">
                                Mostrando <strong><?= count($logs) ?></strong> de
                                <strong><?= number_format($totalFiltrados) ?></strong> eventos (Página <?= $paginaActual ?> de
                                <?= $totalPaginas ?>)
                            </span>
                            <div class="btn-group btn-group-sm">
                                <?php
                                $queryParams = "&subtab=eventos&tabla_filtro=" . urlencode($filtroTabla)
                                    . "&accion_filtro=" . urlencode($filtroAccion)
                                    . "&usuario_filtro=" . urlencode($filtroUsuario)
                                    . "&tipo_evento=" . urlencode($filtroTipo)
                                    . "&registro_id=" . urlencode($filtroRegistroId)
                                    . "&fecha_desde=" . urlencode($filtroFechaDesde)
                                    . "&fecha_hasta=" . urlencode($filtroFechaHasta)
                                    . "&q_log=" . urlencode($busquedaLog);
                                ?>
                                <?php if ($paginaActual > 1): ?>
                                    <a href="index.php?tab=auditoria<?= $queryParams ?>&p=<?= $paginaActual - 1 ?>"
                                        class="btn btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;">« Anterior</a>
                                <?php else: ?>
                                    <button class="btn btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;" disabled>«
                                        Anterior</button>
                                <?php endif; ?>

                                <button class="btn btn-primary py-0 px-2 disabled" style="font-size: 0.75rem;"><?= $paginaActual ?>
                                    / <?= $totalPaginas ?></button>

                                <?php if ($paginaActual < $totalPaginas): ?>
                                    <a href="index.php?tab=auditoria<?= $queryParams ?>&p=<?= $paginaActual + 1 ?>"
                                        class="btn btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;">Siguiente »</a>
                                <?php else: ?>
                                    <button class="btn btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;" disabled>Siguiente
                                        »</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ================================================================ -->
                <!-- SUBPESTAÑA 2: HISTORIAL DE MODIFICACIONES DE DATOS               -->
                <!-- ================================================================ -->
            <?php else: ?>
                <div class="card-sicm shadow-sm" id="forensic-table-container" data-subtab="deltas"
                    data-page="<?= $paginaActual ?>" data-max-id="<?= $maxDeltaId ?>"
                    data-has-filters="<?= $hayFiltrosActivos ? '1' : '0' ?>">
                    <div class="card-header-sicm d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-clock-history text-primary"></i>
                            <span class="fw-bold">Historial de Modificaciones</span>
                            <span class="text-muted small fw-normal">(Mostrando <?= count($deltas) ?> de
                                <?= number_format($totalFiltrados) ?> registros)</span>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm table-sicm mb-0">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">#ID</th>
                                    <th style="width: 140px;">Fecha</th>
                                    <th style="width: 190px;">Elemento modificado</th>
                                    <th style="width: 150px;">Campo</th>
                                    <th>Cambio realizado</th>
                                    <th style="width: 170px;">Canal / Conexión</th>
                                    <th>Modificado por</th>
                                </tr>
                            </thead>
                            <tbody id="forensic-tbody">
                                <?php if (empty($deltas)): ?>
                                    <tr class="fila-sin-datos">
                                        <td colspan="7" class="text-center py-5">
                                            <div class="py-3">
                                                <div class="mb-2 text-muted">
                                                    <i class="bi bi-search text-secondary" style="font-size: 2.2rem;"></i>
                                                </div>
                                                <h6 class="text-secondary fw-semibold mb-1">No se encontraron eventos que coincidan
                                                    con los criterios de búsqueda seleccionados.</h6>
                                                <p class="text-muted small mb-3">Intenta cambiar los términos de búsqueda, rango de
                                                    fechas o restablece los filtros para ver todo el historial de cambios.</p>
                                                <a href="index.php?tab=auditoria&subtab=deltas"
                                                    class="btn btn-outline-primary btn-sm px-3 shadow-xs">
                                                    <i class="bi bi-arrow-counterclockwise me-1"></i> Restablecer todos los filtros
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($deltas as $d): ?>
                                        <?php
                                        $esDirecto = ($d['ip_origen'] === 'CONSOLA_DIRECTA' || $d['usuario_app_id'] === null);
                                        $rowClass = $esDirecto ? 'table-danger-subtle border-start border-4 border-danger' : 'border-start border-3 border-light';
                                        $entidadMod = resolverEntidadLegible($d['tabla_nombre'] ?? '', (int) $d['registro_id'], [], $diccionariosEntidades);
                                        ?>
                                        <tr class="<?= $rowClass ?>">
                                            <td class="code-tag">#<?= (int) $d['id'] ?></td>
                                            <td class="small font-monospace"><?= htmlspecialchars($d['created_at']) ?></td>
                                            <td>
                                                <span
                                                    class="badge bg-light text-dark border"><?= htmlspecialchars($entidadMod['entidad']) ?></span>
                                                <span class="code-tag">ID: #<?= (int) $d['registro_id'] ?></span>
                                                <?php 
                                                $nomDesc = $entidadMod['nombre_descriptivo'] ?: ($entidadMod['nombre_corto'] ?? '');
                                                if (!empty($nomDesc)): 
                                                    $tooltipCompleto = $entidadMod['entidad'] . ': ' . $nomDesc . ' [ID: #' . (int) $d['registro_id'] . ']';
                                                ?>
                                                    <div class="text-dark small fw-semibold text-truncate mt-1"
                                                        style="max-width: 220px; font-size: 0.76rem;"
                                                        title="<?= htmlspecialchars($tooltipCompleto) ?>">
                                                        <i class="bi bi-tag-fill me-1 text-primary opacity-75"></i><?= htmlspecialchars($nomDesc) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <code class="fw-semibold"><?= htmlspecialchars($d['campo_modificado']) ?></code>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center flex-wrap gap-1 font-monospace"
                                                    style="font-size: 0.8rem;">
                                                    <?php if ($d['valor_anterior'] === null): ?>
                                                        <del
                                                            class="text-danger bg-danger-subtle px-1 rounded text-decoration-line-through"><em>(Vacío)</em></del>
                                                    <?php else: ?>
                                                        <del
                                                            class="text-danger bg-danger-subtle px-1 rounded text-decoration-line-through"><?= htmlspecialchars($d['valor_anterior']) ?></del>
                                                    <?php endif; ?>

                                                    <i class="bi bi-arrow-right text-muted mx-1"></i>

                                                    <?php if ($d['valor_nuevo'] === null): ?>
                                                        <ins
                                                            class="text-success fw-bold bg-success-subtle px-1 rounded text-decoration-none"><em>(Vacío)</em></ins>
                                                    <?php else: ?>
                                                        <ins
                                                            class="text-success fw-bold bg-success-subtle px-1 rounded text-decoration-none"><?= htmlspecialchars($d['valor_nuevo']) ?></ins>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($d['ip_origen'] === 'CONSOLA_DIRECTA'): ?>
                                                    <span class="badge bg-danger text-white font-monospace"
                                                        title="Modificación directa en base de datos">
                                                        <i class="bi bi-terminal-fill me-1"></i> CLI /
                                                        <?= htmlspecialchars($d['db_user']) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-secondary border font-monospace"
                                                        title="Modificación desde aplicación web">
                                                        <i class="bi bi-globe me-1"></i> Web [<?= htmlspecialchars($d['ip_origen']) ?>]
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($d['usuario_app_id'] !== null): ?>
                                                    <div class="fw-semibold text-dark">
                                                        <?= htmlspecialchars($d['operador_app']) ?>
                                                        <?php if (!empty($d['email_app'])): ?>
                                                            <span
                                                                class="text-muted fw-normal small">(<?= htmlspecialchars($d['email_app']) ?>)</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="text-secondary small" style="font-size: 0.72rem;">
                                                        <?= htmlspecialchars($d['rol_app'] ?? 'Usuario') ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="badge bg-danger-subtle text-danger border border-danger fw-bold">
                                                        <i class="bi bi-exclamation-octagon-fill me-1"></i> Sin sesión de usuario
                                                    </span>
                                                    <div class="text-muted font-monospace small" style="font-size: 0.70rem;">
                                                        <?= htmlspecialchars($d['db_user']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPaginas > 1): ?>
                        <div
                            class="card-footer bg-light d-flex flex-wrap justify-content-between align-items-center py-2 px-3 border-top">
                            <span class="small text-muted" style="font-size: 0.78rem;">
                                Mostrando <strong><?= count($deltas) ?></strong> de
                                <strong><?= number_format($totalFiltrados) ?></strong> modificaciones (Página <?= $paginaActual ?>
                                de <?= $totalPaginas ?>)
                            </span>
                            <div class="btn-group btn-group-sm">
                                <?php
                                $queryParams = "&subtab=deltas&tabla_filtro=" . urlencode($filtroTabla)
                                    . "&usuario_filtro=" . urlencode($filtroUsuario)
                                    . "&campo_filtro=" . urlencode($filtroCampo)
                                    . "&tipo_evento=" . urlencode($filtroTipo)
                                    . "&registro_id=" . urlencode($filtroRegistroId)
                                    . "&fecha_desde=" . urlencode($filtroFechaDesde)
                                    . "&fecha_hasta=" . urlencode($filtroFechaHasta)
                                    . "&q_log=" . urlencode($busquedaLog);
                                ?>
                                <?php if ($paginaActual > 1): ?>
                                    <a href="index.php?tab=auditoria<?= $queryParams ?>&p=<?= $paginaActual - 1 ?>"
                                        class="btn btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;">« Anterior</a>
                                <?php else: ?>
                                    <button class="btn btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;" disabled>«
                                        Anterior</button>
                                <?php endif; ?>

                                <button class="btn btn-primary py-0 px-2 disabled" style="font-size: 0.75rem;"><?= $paginaActual ?>
                                    / <?= $totalPaginas ?></button>

                                <?php if ($paginaActual < $totalPaginas): ?>
                                    <a href="index.php?tab=auditoria<?= $queryParams ?>&p=<?= $paginaActual + 1 ?>"
                                        class="btn btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;">Siguiente »</a>
                                <?php else: ?>
                                    <button class="btn btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;" disabled>Siguiente
                                        »</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>

        <!-- ==================================================================== -->
        <!-- PESTAÑA: IDENTIDAD INSTITUCIONAL (WHITE-LABELING)                    -->
        <!-- ==================================================================== -->
        <?php if ($tabActiva === 'configuracion' && $esAdmin): ?>
            <div class="row g-3">
                <div class="col-xl-9 col-lg-11 mx-auto">
                    <div class="card-sicm">
                        <div class="card-header-sicm d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-building-gear text-primary fs-5"></i>
                                <span>Identidad Institucional y Parametrización Central (White-labeling)</span>
                            </div>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace" style="font-size: 0.72rem;">
                                Parámetro Único #1
                            </span>
                        </div>
                        <div class="card-body p-3 p-md-4">
                            <p class="text-muted small mb-4">
                                Personalice la identidad gráfica, autoridad sanitaria y umbrales operativos de rotación de la red. Los cambios se sincronizan de inmediato en los encabezados, membretes, barra lateral y pantallas de autenticación, registrándose en la bitácora de auditoría inmutable.
                            </p>

                            <form action="procesar.php?accion=guardar_identidad_institucional" method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <div class="row g-3 mb-4">
                                    <!-- Nombre Oficial del Sistema -->
                                    <div class="col-md-8">
                                        <label for="nombre_sistema" class="form-label fw-semibold small">Nombre Oficial del Sistema <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control form-control-sm" id="nombre_sistema" name="nombre_sistema" 
                                            value="<?= htmlspecialchars($configInst['nombre_sistema']) ?>" required maxlength="120"
                                            placeholder="Ej: Sistema Integrado de Control de Medicamentos">
                                        <div class="form-text" style="font-size: 0.74rem;">Título descriptivo de la plataforma.</div>
                                    </div>

                                    <!-- Siglas Oficiales -->
                                    <div class="col-md-4">
                                        <label for="siglas_sistema" class="form-label fw-semibold small">Siglas Oficiales <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control form-control-sm font-monospace text-uppercase" id="siglas_sistema" name="siglas_sistema" 
                                            value="<?= htmlspecialchars($configInst['siglas_sistema']) ?>" required maxlength="20"
                                            placeholder="Ej: SICM">
                                        <div class="form-text" style="font-size: 0.74rem;">Acrónimo para insignias y títulos resumidos.</div>
                                    </div>

                                    <!-- Ente Rector -->
                                    <div class="col-md-7">
                                        <label for="ente_rector" class="form-label fw-semibold small">Ente Rector / Autoridad Sanitaria <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control form-control-sm" id="ente_rector" name="ente_rector" 
                                            value="<?= htmlspecialchars($configInst['ente_rector']) ?>" required maxlength="150"
                                            placeholder="Ej: Dirección de Salud Municipal - Alcaldía de Baruta">
                                        <div class="form-text" style="font-size: 0.74rem;">Órgano institucional competente rector de la red.</div>
                                    </div>

                                    <!-- Municipio / Entidad Federal -->
                                    <div class="col-md-5">
                                        <label for="municipio" class="form-label fw-semibold small">Municipio / Entidad Federal <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control form-control-sm" id="municipio" name="municipio" 
                                            value="<?= htmlspecialchars($configInst['municipio']) ?>" required maxlength="100"
                                            placeholder="Ej: Municipio Autónomo Baruta">
                                        <div class="form-text" style="font-size: 0.74rem;">Jurisdicción geográfica administrativa.</div>
                                    </div>
                                </div>

                                <hr class="text-secondary opacity-25 my-4">

                                <!-- Semáforo y Reglas Operativas FEFO -->
                                <h6 class="fw-bold text-dark d-flex align-items-center gap-2 mb-3">
                                    <i class="bi bi-clock-history text-warning"></i> Semáforo y Políticas de Almacenamiento FEFO
                                </h6>
                                <div class="row g-3 mb-4">
                                    <div class="col-md-6">
                                        <label for="dias_alerta_fefo" class="form-label fw-semibold small">Días de Anticipación para Alerta FEFO <span class="text-danger">*</span></label>
                                        <div class="input-group input-group-sm">
                                            <input type="number" class="form-control font-monospace" id="dias_alerta_fefo" name="dias_alerta_fefo" 
                                                value="<?= (int)$configInst['dias_alerta_fefo'] ?>" min="1" max="365" required>
                                            <span class="input-group-text">días previos a caducidad</span>
                                        </div>
                                        <div class="form-text" style="font-size: 0.74rem;">
                                            Los lotes farmacéuticos cuya vigencia sea inferior o igual a este plazo activan el semáforo crítico y exigen dispensación prioritaria.
                                        </div>
                                    </div>
                                </div>

                                <hr class="text-secondary opacity-25 my-4">

                                <!-- Logotipo Institucional -->
                                <h6 class="fw-bold text-dark d-flex align-items-center gap-2 mb-3">
                                    <i class="bi bi-image text-info"></i> Logotipo Institucional
                                </h6>
                                <div class="row g-3 mb-4 align-items-center">
                                    <div class="col-md-4 text-center">
                                        <div class="p-3 border rounded bg-light d-flex flex-column align-items-center justify-content-center" style="min-height: 120px;">
                                            <?php if (!empty($configInst['logotipo_ruta'])): ?>
                                                <img src="<?= htmlspecialchars($configInst['logotipo_ruta']) ?>" alt="Logotipo actual" class="img-fluid mb-2" style="max-height: 75px; object-fit: contain;">
                                                <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.7rem;">Logotipo Personalizado</span>
                                            <?php else: ?>
                                                <div class="d-inline-flex align-items-center justify-content-center rounded bg-primary bg-opacity-10 text-primary p-2 mb-2" style="width: 50px; height: 50px;">
                                                    <i class="bi bi-shield-plus fs-3"></i>
                                                </div>
                                                <span class="text-muted small" style="font-size: 0.75rem;">Escudo estándar del sistema</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($configInst['logotipo_ruta'])): ?>
                                            <div class="form-check text-start mt-2">
                                                <input class="form-check-input" type="checkbox" id="eliminar_logo" name="eliminar_logo" value="1">
                                                <label class="form-check-label small text-danger" for="eliminar_logo">
                                                    Restablecer escudo predeterminado
                                                </label>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-8">
                                        <div class="mb-3">
                                            <label for="logotipo_archivo" class="form-label fw-semibold small">Cargar Archivo de Logotipo</label>
                                            <input type="file" class="form-control form-control-sm" id="logotipo_archivo" name="logotipo_archivo" 
                                                accept="image/png,image/jpeg,image/svg+xml,image/webp">
                                            <div class="form-text" style="font-size: 0.74rem;">Formatos aceptados: PNG, JPG, SVG o WEBP (máx. 2 MB). Se recomienda transparencia.</div>
                                        </div>
                                        <div>
                                            <label for="logotipo_ruta" class="form-label fw-semibold small">O Ruta Relativa Existente</label>
                                            <input type="text" class="form-control form-control-sm font-monospace" id="logotipo_ruta" name="logotipo_ruta" 
                                                value="<?= htmlspecialchars($configInst['logotipo_ruta'] ?? '') ?>"
                                                placeholder="public/img/logo.png">
                                        </div>
                                    </div>
                                </div>

                                <!-- Barra de Acciones y Trazabilidad -->
                                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center pt-3 border-top gap-3">
                                    <div class="small text-muted">
                                        <i class="bi bi-clock-history me-1"></i>
                                        <?php if (!empty($configInst['updated_at'])): ?>
                                            Última sincronización: <strong><?= htmlspecialchars($configInst['updated_at']) ?></strong>
                                        <?php else: ?>
                                            Configuración canónica por defecto
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <a href="index.php?tab=centros" class="btn btn-outline-secondary btn-sm px-3">
                                            Cancelar
                                        </a>
                                        <button type="submit" class="btn btn-primary btn-sm px-4 fw-semibold">
                                            <i class="bi bi-check-circle-fill me-1"></i> Guardar Cambios
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
<?php if ($tabActiva === 'pacientes'): ?>

            <!-- Cuadro de Mando del Padrón (3 Tarjetas KPIs Institucionales) -->
            <div class="row g-2 mb-3">
                <div class="col-sm-6 col-md-4">
                    <div class="card bg-white border shadow-sm p-3 d-flex flex-row align-items-center gap-3">
                        <div class="bg-primary-subtle text-primary p-2.5 rounded-3 d-flex align-items-center justify-content-center"
                            style="width: 48px; height: 48px;">
                            <i class="bi bi-people fs-4"></i>
                        </div>
                        <div>
                            <span class="text-muted small d-block fw-semibold text-uppercase"
                                style="font-size: 0.72rem; letter-spacing: 0.04em;">Total Pacientes</span>
                            <div class="fs-4 fw-bold text-dark font-monospace"><?= number_format($kpiTotalPacientes) ?></div>
                            <span class="text-muted small" style="font-size: 0.70rem;">Padrón municipal registrado</span>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-md-4">
                    <div class="card bg-white border shadow-sm p-3 d-flex flex-row align-items-center gap-3">
                        <div class="bg-success-subtle text-success p-2.5 rounded-3 d-flex align-items-center justify-content-center"
                            style="width: 48px; height: 48px;">
                            <i class="bi bi-telephone-forward fs-4 text-success"></i>
                        </div>
                        <div>
                            <span class="text-muted small d-block fw-semibold text-uppercase"
                                style="font-size: 0.72rem; letter-spacing: 0.04em;">Con Vías de Contacto</span>
                            <div class="fs-4 fw-bold text-dark font-monospace"><?= number_format($kpiConContactos) ?></div>
                            <span class="text-muted small" style="font-size: 0.70rem;">Normalizados en 1FN</span>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card bg-white border shadow-sm p-3 d-flex flex-row align-items-center gap-3">
                        <div class="bg-info-subtle text-info-emphasis p-2.5 rounded-3 d-flex align-items-center justify-content-center"
                            style="width: 48px; height: 48px;">
                            <i class="bi bi-person-plus fs-4 text-info"></i>
                        </div>
                        <div>
                            <span class="text-muted small d-block fw-semibold text-uppercase"
                                style="font-size: 0.72rem; letter-spacing: 0.04em;">Registrados este Mes</span>
                            <div class="fs-4 fw-bold text-dark font-monospace"><?= number_format($kpiNuevosMes) ?></div>
                            <span class="text-muted small" style="font-size: 0.70rem;">Altas asistenciales <?= date('m/Y') ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal de Registro / Edición de Paciente -->
            <div class="modal fade" id="modalPaciente" tabindex="-1" aria-labelledby="modalPacienteLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content border-0 shadow">
                        <form action="procesar.php" method="POST" id="form-paciente" novalidate>
                            <div class="modal-header bg-light py-2 px-3 border-bottom">
                                <h6 class="modal-title fw-bold" id="modalPacienteLabel">
                                    <i class="bi <?= $pacienteEdicion ? 'bi-pencil-square text-warning' : 'bi-person-plus-fill text-primary' ?> me-1"></i>
                                    <?= $pacienteEdicion ? 'Editar Paciente #' . (int) $pacienteEdicion['id'] : 'Registrar Nuevo Paciente' ?>
                                </h6>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                            </div>
                            <div class="modal-body p-3">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="accion" value="guardar_paciente">
                                <input type="hidden" name="id" value="<?= $pacienteEdicion ? (int) $pacienteEdicion['id'] : '' ?>">

                                <div class="row g-2 mb-2">
                                    <div class="col-4">
                                        <label for="paciente_tipo_doc" class="form-label small fw-semibold mb-1">Tipo</label>
                                        <select class="form-select form-select-sm" id="paciente_tipo_doc" name="tipo_documento_id" required>
                                            <?php foreach ($catalogoTiposDoc as $td): ?>
                                                <?php $sel = ($pacienteEdicion && (int) $pacienteEdicion['tipo_documento_id'] === (int) $td['id']) ? 'selected' : (!$pacienteEdicion && $td['codigo'] === 'V' ? 'selected' : ''); ?>
                                                <option value="<?= (int) $td['id'] ?>" <?= $sel ?>>
                                                    <?= htmlspecialchars($td['codigo']) ?> - <?= htmlspecialchars($td['nombre']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-8">
                                        <label for="paciente_cedula" class="form-label small fw-semibold mb-1">
                                            Cédula / Documento <span class="text-danger">*</span>
                                        </label>
                                        <input type="text" class="form-control form-control-sm font-monospace" id="paciente_cedula" name="cedula"
                                            placeholder="Solo dígitos (ej. 14285714)" pattern="^[0-9]{5,12}$" maxlength="12" required
                                            value="<?= $pacienteEdicion ? htmlspecialchars($pacienteEdicion['cedula']) : '' ?>"
                                            title="Cédula numérica entre 5 y 12 dígitos">
                                    </div>
                                </div>

                                <div class="mb-2">
                                    <label for="paciente_nombre" class="form-label small fw-semibold mb-1">
                                        Nombre y Apellido Completo <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control form-control-sm" id="paciente_nombre" name="nombre_completo"
                                        placeholder="Ej: Carmen Elena Rivas Parra" required maxlength="120"
                                        value="<?= $pacienteEdicion ? htmlspecialchars($pacienteEdicion['nombre_completo']) : '' ?>">
                                </div>

                                <div class="mb-2">
                                    <label for="paciente_fecha_nac" class="form-label small fw-semibold mb-1">Fecha de Nacimiento</label>
                                    <input type="date" class="form-control form-control-sm" id="paciente_fecha_nac" name="fecha_nacimiento"
                                        max="<?= date('Y-m-d') ?>"
                                        value="<?= $pacienteEdicion ? htmlspecialchars($pacienteEdicion['fecha_nacimiento'] ?? '') : '' ?>">
                                </div>

                                <div class="mb-3">
                                    <label for="paciente_direccion" class="form-label small fw-semibold mb-1">Dirección de Residencia</label>
                                    <textarea class="form-control form-control-sm" id="paciente_direccion" name="direccion_residencia" rows="2"
                                        placeholder="Sector, calle, casa o punto de referencia" maxlength="255"><?= $pacienteEdicion ? htmlspecialchars($pacienteEdicion['direccion_residencia'] ?? '') : '' ?></textarea>
                                </div>

                                <!-- Vías de Contacto Dinámicas -->
                                <div class="p-2.5 mb-2 bg-light rounded border">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="fw-semibold small text-dark">Vías de Contacto</span>
                                        <span class="text-muted small" style="font-size: 0.72rem;">Teléfonos, mensajería y contactos de emergencia</span>
                                    </div>

                                    <!-- Encabezado de columnas de contacto -->
                                    <div class="row g-1 mb-1 text-muted text-uppercase" style="font-size: 0.65rem; font-weight: 600; letter-spacing: 0.3px;">
                                        <div class="col-4">Tipo de Vía</div>
                                        <div class="col-4">Número / Correo</div>
                                        <div class="col">Detalle / Titular</div>
                                        <div class="col-auto" style="width: 32px;"></div>
                                    </div>

                                    <div id="contenedor-contactos-paciente" class="d-flex flex-column gap-1">
                                        <?php if (!empty($contactosEdicion)): ?>
                                            <?php foreach ($contactosEdicion as $idx => $contacto): ?>
                                                <div class="row g-1 align-items-center fila-contacto mb-1">
                                                    <div class="col-4">
                                                        <select name="contactos_tipo[]" class="form-select form-select-sm" required style="font-size: 0.78rem;">
                                                            <?php foreach ($catalogoTiposContacto as $tc): ?>
                                                                <option value="<?= (int) $tc['id'] ?>" <?= ((int) $contacto['tipo_contacto_id'] === (int) $tc['id']) ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($tc['nombre']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-4">
                                                        <input type="text" name="contactos_valor[]" class="form-control form-control-sm font-monospace"
                                                            value="<?= htmlspecialchars($contacto['valor'] ?? '') ?>"
                                                            placeholder="Ej: 0412-1234567" required maxlength="100" style="font-size: 0.8rem;">
                                                    </div>
                                                    <div class="col">
                                                        <input type="text" name="contactos_detalle[]" class="form-control form-control-sm"
                                                            value="<?= htmlspecialchars($contacto['nombre_contacto_emergencia'] ?? ($contacto['titular'] ?? '')) ?>"
                                                            placeholder="Ej: Personal, Hija" maxlength="100" style="font-size: 0.78rem;">
                                                    </div>
                                                    <div class="col-auto text-end" style="width: 32px;">
                                                        <button type="button" class="btn btn-sm btn-outline-danger btn-quitar-fila p-0 d-flex align-items-center justify-content-center"
                                                            title="Quitar vía" style="width: 28px; height: 28px;">
                                                            <i class="bi bi-x-lg" style="font-size: 0.72rem;"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <!-- Fila inicial por defecto (Teléfono personal) -->
                                            <div class="row g-1 align-items-center fila-contacto mb-1">
                                                <div class="col-4">
                                                    <select name="contactos_tipo[]" class="form-select form-select-sm" required style="font-size: 0.78rem;">
                                                        <?php foreach ($catalogoTiposContacto as $tc): ?>
                                                            <option value="<?= (int) $tc['id'] ?>" <?= ($tc['codigo'] === 'TEL_MOVIL' || strpos($tc['nombre'], 'Celular') !== false) ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($tc['nombre']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-4">
                                                    <input type="text" name="contactos_valor[]" class="form-control form-control-sm font-monospace"
                                                        placeholder="Ej: 0412-1234567" required maxlength="100" style="font-size: 0.8rem;">
                                                </div>
                                                <div class="col">
                                                    <input type="text" name="contactos_detalle[]" class="form-control form-control-sm"
                                                        value="Personal" placeholder="Ej: Personal, Familiar" maxlength="100" style="font-size: 0.78rem;">
                                                </div>
                                                <div class="col-auto text-end" style="width: 32px;">
                                                    <button type="button" class="btn btn-sm btn-outline-danger btn-quitar-fila p-0 d-flex align-items-center justify-content-center"
                                                        title="Quitar vía" style="width: 28px; height: 28px;">
                                                        <i class="bi bi-x-lg" style="font-size: 0.72rem;"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="mt-2">
                                        <button type="button" id="btn-agregar-via-contacto" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1.5 py-1 px-2.5" style="font-size: 0.76rem;">
                                            <i class="bi bi-plus-circle"></i> Agregar otra vía de contacto
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer bg-light py-2 px-3 border-top d-flex justify-content-between">
                                <?php if ($pacienteEdicion): ?>
                                    <a href="index.php?tab=pacientes" class="btn btn-outline-secondary btn-sm">Cancelar edición</a>
                                <?php else: ?>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-primary btn-sm px-3 fw-semibold">
                                    <i class="bi <?= $pacienteEdicion ? 'bi-check-circle-fill' : 'bi-person-plus-fill' ?> me-1"></i>
                                    <?= $pacienteEdicion ? 'Actualizar Expediente' : 'Registrar Paciente' ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <!-- Columna: Búsqueda y Padrón de Pacientes (100% de ancho) -->
                <div class="col-12">
                    <!-- Barra de Búsqueda Rápida y Filtros -->
                    <div class="card bg-white border shadow-sm p-3 mb-3">
                        <form method="GET" action="index.php" class="row g-2 align-items-center">
                            <input type="hidden" name="tab" value="pacientes">
                            <div class="col-md-5">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                                    <input type="search" name="q_paciente" class="form-control"
                                        placeholder="Buscar por cédula, nombre o teléfono..."
                                        value="<?= htmlspecialchars($busquedaPaciente) ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <select name="tipo_doc" class="form-select form-select-sm">
                                    <option value="todos">Todos los tipos de documento</option>
                                    <?php foreach ($catalogoTiposDoc as $td): ?>
                                        <option value="<?= (int) $td['id'] ?>" <?= ((string)$filtroTipoDoc === (string)$td['id'] || $filtroTipoDoc === $td['codigo']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($td['codigo']) ?> - <?= htmlspecialchars($td['nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 d-flex gap-1">
                                <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                                    <i class="bi bi-funnel"></i> Filtrar
                                </button>
                                <?php if ($busquedaPaciente !== '' || ($filtroTipoDoc !== 'todos' && $filtroTipoDoc !== '')): ?>
                                    <a href="index.php?tab=pacientes" class="btn btn-outline-secondary btn-sm" title="Limpiar Filtros">
                                        <i class="bi bi-x-circle"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>

                    <!-- Tabla Institucional de Pacientes -->
                    <div class="card-sicm shadow-sm">
                        <div class="card-header-sicm d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <span class="fw-bold">
                                <i class="bi bi-person-lines-fill me-1"></i> Padrón de Pacientes Registrados
                            </span>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace" style="font-size: 0.74rem;">
                                    <?= number_format($totalPacientesFiltrados) ?> pacientes encontrados
                                </span>
                                <button type="button" class="btn btn-primary btn-sm px-3 d-inline-flex align-items-center gap-1.5 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalPaciente">
                                    <i class="bi bi-person-plus-fill me-1"></i>Registrar Paciente
                                </button>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 sicm-table" style="font-size: 0.82rem;">
                                <thead class="table-light text-secondary text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.03em;">
                                    <tr>
                                        <th style="width: 50px;">#ID</th>
                                        <th style="width: 130px;">Documento</th>
                                        <th>Nombre y Apellido</th>
                                        <th>Vías de Contacto</th>
                                        <th>Dirección</th>
                                        <th style="width: 120px;" class="text-center">Récipes</th>
                                        <th style="width: 130px;" class="text-end">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($pacientes)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-muted">
                                                <i class="bi bi-inbox fs-3 d-block text-secondary mb-1"></i>
                                                <span>No se encontraron pacientes que coincidan con los criterios de búsqueda.</span>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($pacientes as $p): ?>
                                            <?php
                                                $edadStr = 'Fecha nac. no registrada';
                                                if (!empty($p['fecha_nacimiento'])) {
                                                    try {
                                                        $fn = new DateTime($p['fecha_nacimiento']);
                                                        $hoy = new DateTime('today');
                                                        if ($fn <= $hoy) {
                                                            $edad = $hoy->diff($fn)->y;
                                                            $edadStr = "{$edad} años • Nac. " . htmlspecialchars($p['fecha_nacimiento']);
                                                        }
                                                    } catch (Exception $e) {
                                                        $edadStr = htmlspecialchars($p['fecha_nacimiento']);
                                                    }
                                                }
                                                $conts = $contactosPorPaciente[$p['id']] ?? [];
                                            ?>
                                            <tr>
                                                <td class="text-muted font-monospace"><?= (int) $p['id'] ?></td>
                                                <td>
                                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace py-1 px-1.5" style="font-size: 0.76rem;">
                                                        <i class="bi bi-person-vcard me-1"></i><?= htmlspecialchars($p['tipo_doc_codigo']) ?>-<?= htmlspecialchars($p['cedula']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($p['nombre_completo']) ?></div>
                                                    <div class="text-muted" style="font-size: 0.73rem;">
                                                        <i class="bi bi-calendar3 me-1"></i><?= $edadStr ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if (empty($conts)): ?>
                                                        <span class="text-muted fst-italic" style="font-size: 0.74rem;">Sin vías registradas</span>
                                                    <?php else: ?>
                                                        <div class="d-flex flex-column gap-1">
                                                            <?php foreach ($conts as $c): ?>
                                                                <?php
                                                                    $isEmg = ($c['tipo_contacto_codigo'] === 'CONTACTO_EMERGENCIA' || ((int)$c['es_principal'] === 0 && !empty($c['nombre_contacto_emergencia']) && $c['nombre_contacto_emergencia'] !== 'Paciente Directo'));
                                                                    $isWp = ($c['tipo_contacto_codigo'] === 'WHATSAPP');
                                                                    $badgeCls = (int)$c['es_principal'] === 1 ? 'bg-primary-subtle text-primary border-primary-subtle' : ($isEmg ? 'bg-danger-subtle text-danger border-danger-subtle' : 'bg-light text-secondary border');
                                                                    $icon = $isEmg ? 'bi-person-heart text-danger' : ($isWp ? 'bi-whatsapp text-success' : 'bi-telephone text-primary');
                                                                ?>
                                                                <div class="badge <?= $badgeCls ?> text-start fw-normal d-inline-flex align-items-center gap-1 py-0.5 px-1.5 text-wrap" style="font-size: 0.72rem; max-width: 210px;">
                                                                    <i class="bi <?= $icon ?>"></i>
                                                                    <span class="font-monospace fw-semibold"><?= htmlspecialchars($c['valor']) ?></span>
                                                                    <?php if (!empty($c['nombre_contacto_emergencia']) && $c['nombre_contacto_emergencia'] !== 'Paciente Directo'): ?>
                                                                        <span class="text-muted ms-0.5" style="font-size: 0.68rem;">(<?= htmlspecialchars($c['nombre_contacto_emergencia']) ?>)</span>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="text-secondary" style="font-size: 0.75rem; line-height: 1.25; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;" title="<?= htmlspecialchars($p['direccion_residencia'] ?? '') ?>">
                                                        <?= htmlspecialchars($p['direccion_residencia'] ?: 'No especificada') ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <div class="d-flex flex-column align-items-center gap-1">
                                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle" style="font-size: 0.70rem;">
                                                            <i class="bi bi-file-earmark-medical me-0.5"></i><?= (int)($p['total_recipes'] ?? 0) ?> Récipes
                                                        </span>
                                                        <a href="index.php?tab=recipes&paciente_id=<?= (int)$p['id'] ?>"
                                                            class="btn btn-outline-primary btn-sm py-0 px-1.5 text-decoration-none"
                                                            style="font-size: 0.72rem;" title="Prescribir o consultar récipe">
                                                            <i class="bi bi-plus-circle me-1"></i>+ Récipe
                                                        </a>
                                                    </div>
                                                </td>
                                                <td class="text-end">
                                                    <div class="d-flex justify-content-end gap-1">
                                                        <a href="index.php?tab=pacientes&editar_id=<?= (int)$p['id'] ?><?= $busquedaPaciente !== '' ? '&q_paciente=' . urlencode($busquedaPaciente) : '' ?><?= $filtroTipoDoc !== 'todos' ? '&tipo_doc=' . urlencode($filtroTipoDoc) : '' ?><?= $paginaActual > 1 ? '&p_paciente=' . $paginaActual : '' ?>"
                                                            class="btn btn-sm btn-outline-primary py-0 px-2"
                                                            style="font-size: 0.74rem;" title="Editar Expediente">
                                                            <i class="bi bi-pencil-square"></i> Editar
                                                        </a>
                                                        <?php if ($esAdmin): ?>
                                                            <a href="index.php?tab=auditoria&subtab=deltas&tabla=pacientes&registro_id=<?= (int)$p['id'] ?>"
                                                                class="btn btn-sm btn-outline-info py-0 px-2 text-decoration-none"
                                                                style="font-size: 0.74rem;" title="Auditoría Delta Forense">
                                                                <i class="bi bi-clock-history"></i> Historial
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Paginación Institucional -->
                        <?php if ($totalPaginas > 1): ?>
                            <?php $pagQuery = "&q_paciente=" . urlencode($busquedaPaciente) . "&tipo_doc=" . urlencode($filtroTipoDoc); ?>
                            <div class="card-footer bg-white border-top d-flex justify-content-between align-items-center py-2 px-3">
                                <span class="text-muted small" style="font-size: 0.74rem;">
                                    Página <?= $paginaActual ?> de <?= $totalPaginas ?> (<?= number_format($totalPacientesFiltrados) ?> registros)
                                </span>
                                <nav aria-label="Navegación del padrón">
                                    <ul class="pagination pagination-sm mb-0">
                                        <li class="page-item <?= ($paginaActual <= 1) ? 'disabled' : '' ?>">
                                            <a class="page-link" href="index.php?tab=pacientes<?= $pagQuery ?>&p_paciente=<?= $paginaActual - 1 ?>">
                                                <i class="bi bi-chevron-left"></i>
                                            </a>
                                        </li>
                                        <?php for ($pg = 1; $pg <= $totalPaginas; $pg++): ?>
                                            <?php if ($pg === 1 || $pg === $totalPaginas || ($pg >= $paginaActual - 2 && $pg <= $paginaActual + 2)): ?>
                                                <li class="page-item <?= ($pg === $paginaActual) ? 'active' : '' ?>">
                                                    <a class="page-link" href="index.php?tab=pacientes<?= $pagQuery ?>&p_paciente=<?= $pg ?>"><?= $pg ?></a>
                                                </li>
                                            <?php elseif ($pg === $paginaActual - 3 || $pg === $paginaActual + 3): ?>
                                                <li class="page-item disabled"><span class="page-link">...</span></li>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                        <li class="page-item <?= ($paginaActual >= $totalPaginas) ? 'disabled' : '' ?>">
                                            <a class="page-link" href="index.php?tab=pacientes<?= $pagQuery ?>&p_paciente=<?= $paginaActual + 1 ?>">
                                                <i class="bi bi-chevron-right"></i>
                                            </a>
                                        </li>
                                    </ul>
                                </nav>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        <?php endif; ?>

        <!-- ==================================================================== -->
        <!-- MÓDULOS CLÍNICOS EN PROCESO DE INTEGRACIÓN                           -->
        <!-- ==================================================================== -->
        <?php if (in_array($tabActiva, ['recipes', 'despachos'], true)): ?>

        <!-- ==================================================================== -->
        <!-- MÓDULO CLÍNICO: RÉCIPES MÉDICOS Y TRATAMIENTOS AUTORIZADOS           -->
        <!-- ==================================================================== -->
        <?php if ($tabActiva === 'recipes'): ?>

            <!-- Cuadro de Mando de Récipes (3 Tarjetas KPIs Institucionales) -->
            <div class="row g-2 mb-3">
                <div class="col-sm-6 col-md-4">
                    <div class="card bg-white border shadow-sm p-3 d-flex flex-row align-items-center gap-3">
                        <div class="bg-primary-subtle text-primary p-2.5 rounded-3 d-flex align-items-center justify-content-center"
                            style="width: 48px; height: 48px;">
                            <i class="bi bi-file-earmark-medical fs-4"></i>
                        </div>
                        <div>
                            <span class="text-muted small d-block fw-semibold text-uppercase"
                                style="font-size: 0.72rem; letter-spacing: 0.04em;">Total Récipes</span>
                            <div class="fs-4 fw-bold text-dark font-monospace"><?= number_format($kpiTotalRecipes) ?></div>
                            <span class="text-muted small" style="font-size: 0.70rem;">Tratamientos autorizados registrados</span>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-md-4">
                    <div class="card bg-white border shadow-sm p-3 d-flex flex-row align-items-center gap-3">
                        <div class="bg-success-subtle text-success p-2.5 rounded-3 d-flex align-items-center justify-content-center"
                            style="width: 48px; height: 48px;">
                            <i class="bi bi-patch-check fs-4 text-success"></i>
                        </div>
                        <div>
                            <span class="text-muted small d-block fw-semibold text-uppercase"
                                style="font-size: 0.72rem; letter-spacing: 0.04em;">Récipes Vigentes</span>
                            <div class="fs-4 fw-bold text-success font-monospace"><?= number_format($kpiRecipesVigentes) ?></div>
                            <span class="text-muted small" style="font-size: 0.70rem;">Activos y aptos para dispensación</span>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card bg-white border shadow-sm p-3 d-flex flex-row align-items-center gap-3">
                        <div class="bg-warning-subtle text-warning-emphasis p-2.5 rounded-3 d-flex align-items-center justify-content-center"
                            style="width: 48px; height: 48px;">
                            <i class="bi bi-clock-history fs-4 text-warning"></i>
                        </div>
                        <div>
                            <span class="text-muted small d-block fw-semibold text-uppercase"
                                style="font-size: 0.72rem; letter-spacing: 0.04em;">Vencidos / A Renovar</span>
                            <div class="fs-4 fw-bold text-warning-emphasis font-monospace"><?= number_format($kpiRecipesVencidos) ?></div>
                            <span class="text-muted small" style="font-size: 0.70rem;">Requieren renovación médica</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal de Registro / Edición de Récipe Médico -->
            <div class="modal fade" id="modalRecipe" tabindex="-1" aria-labelledby="modalRecipeLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content border-0 shadow">
                        <form action="procesar.php" method="POST" id="form-recipe" novalidate>
                            <div class="modal-header bg-light py-2 px-3 border-bottom">
                                <h6 class="modal-title fw-bold" id="modalRecipeLabel">
                                    <i class="bi <?= $recipeEdicion ? 'bi-pencil-square text-warning' : 'bi-file-earmark-medical-fill text-primary' ?> me-1"></i>
                                    <?= $recipeEdicion ? 'Actualizar Récipe Médico #' . (int) $recipeEdicion['id'] : 'Emitir Nuevo Récipe Médico' ?>
                                </h6>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                            </div>
                            <div class="modal-body p-3">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="accion" value="guardar_recipe">
                                <input type="hidden" name="id" value="<?= $recipeEdicion ? (int) $recipeEdicion['id'] : '' ?>">

                                <div class="row g-2 mb-2">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-secondary mb-1">Paciente Titular *</label>
                                        <select name="paciente_id" id="recipe_paciente_id" class="form-select form-select-sm" required>
                                            <option value="">-- Seleccionar Paciente Registrado --</option>
                                            <?php foreach ($catalogoPacientes as $pac): ?>
                                                <?php
                                                    $selPac = ($recipeEdicion && (int)$recipeEdicion['paciente_id'] === (int)$pac['id'])
                                                              || (!$recipeEdicion && $filtroPacienteId && $filtroPacienteId === (int)$pac['id']);
                                                ?>
                                                <option value="<?= (int)$pac['id'] ?>" <?= $selPac ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($pac['tipo_doc_codigo'] ?? 'V') ?>-<?= htmlspecialchars($pac['cedula']) ?> — <?= htmlspecialchars($pac['nombre_completo']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text" style="font-size: 0.68rem;">Paciente registrado en el padrón municipal.</div>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-secondary mb-1">Patología Asociada (CIE-10) *</label>
                                        <select name="patologia_id" id="recipe_patologia_id" class="form-select form-select-sm" required>
                                            <option value="">-- Seleccionar Diagnóstico CIE-10 --</option>
                                            <?php foreach ($catalogoPatologias as $pat): ?>
                                                <?php $selPat = ($recipeEdicion && (int)$recipeEdicion['patologia_id'] === (int)$pat['id']); ?>
                                                <option value="<?= (int)$pat['id'] ?>" <?= $selPat ? 'selected' : '' ?>>
                                                    [<?= htmlspecialchars($pat['codigo_cie10'] ?? 'S/C') ?>] <?= htmlspecialchars($pat['nombre']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text" style="font-size: 0.68rem;">Diagnóstico clínico clasificado bajo estándar internacional CIE-10.</div>
                                    </div>
                                </div>

                                <div class="row g-2 mb-2">
                                    <div class="col-md-8">
                                        <label class="form-label small fw-semibold text-secondary mb-1">Medicamento Prescrito *</label>
                                        <select name="medicamento_id" id="recipe_medicamento_id" class="form-select form-select-sm" required>
                                            <option value="">-- Seleccionar Fármaco del Vademécum --</option>
                                            <?php foreach ($catalogoMedicamentos as $med): ?>
                                                <?php $selMed = ($recipeEdicion && (int)$recipeEdicion['medicamento_id'] === (int)$med['id']); ?>
                                                <option value="<?= (int)$med['id'] ?>" <?= $selMed ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($med['nombre_generico']) ?> <?= htmlspecialchars($med['concentracion']) ?> (<?= htmlspecialchars($med['forma_nombre'] ?? 'Sin forma') ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text" style="font-size: 0.68rem;">Principio activo oficial autorizado en el catálogo farmacéutico.</div>
                                    </div>

                                    <div class="col-md-4">
                                        <label class="form-label small fw-semibold text-secondary mb-1">Dosis Mensual Autorizada *</label>
                                        <div class="input-group input-group-sm">
                                            <input type="number" name="dosis_mensual" id="recipe_dosis_mensual" class="form-control"
                                                min="1" max="999" step="1" required
                                                placeholder="Ej: 30"
                                                value="<?= $recipeEdicion ? (int) $recipeEdicion['dosis_mensual'] : '30' ?>">
                                            <span class="input-group-text small text-muted">unidades</span>
                                        </div>
                                        <div class="form-text" style="font-size: 0.68rem;">Unidades mensuales máximas para dispensación.</div>
                                    </div>
                                </div>

                                <div class="row g-2 mb-2">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-secondary mb-1">Fecha de Emisión *</label>
                                        <input type="date" name="fecha_emision_recipe" id="recipe_fecha_emision" class="form-control form-control-sm"
                                            required
                                            value="<?= $recipeEdicion ? htmlspecialchars($recipeEdicion['fecha_emision_recipe']) : date('Y-m-d') ?>">
                                        <div class="form-text" style="font-size: 0.68rem;">Fecha de expedición médica del récipe.</div>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-secondary mb-1">Fecha de Vencimiento *</label>
                                        <input type="date" name="fecha_vencimiento_recipe" id="recipe_fecha_vencimiento" class="form-control form-control-sm"
                                            required
                                            min="<?= $recipeEdicion ? htmlspecialchars($recipeEdicion['fecha_emision_recipe']) : date('Y-m-d') ?>"
                                            value="<?= $recipeEdicion ? htmlspecialchars($recipeEdicion['fecha_vencimiento_recipe']) : date('Y-m-d', strtotime('+6 months')) ?>">
                                        <div class="form-text" style="font-size: 0.68rem;">Límite temporal de vigencia para despachos asistenciales.</div>
                                    </div>
                                </div>

                                <div class="mb-2">
                                    <label class="form-label small fw-semibold text-secondary mb-1">Médico Prescriptor y Matrícula MPPS *</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text bg-white text-muted"><i class="bi bi-person-badge"></i></span>
                                        <input type="text" name="medico_prescriptor" id="recipe_medico_prescriptor" class="form-control"
                                            maxlength="120" required
                                            placeholder="Ej: Dra. Elena Rivas - MPPS 52.880"
                                            value="<?= $recipeEdicion ? htmlspecialchars($recipeEdicion['medico_prescriptor'] ?? '') : '' ?>">
                                    </div>
                                    <div class="form-text" style="font-size: 0.68rem;">Nombre completo del facultativo tratante y su credencial sanitaria oficial.</div>
                                </div>
                            </div>
                            <div class="modal-footer bg-light py-2 px-3 border-top d-flex justify-content-between">
                                <?php if ($recipeEdicion): ?>
                                    <a href="index.php?tab=recipes" class="btn btn-outline-secondary btn-sm">
                                        <i class="bi bi-x-circle me-1"></i>Cancelar Edición
                                    </a>
                                <?php else: ?>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">
                                        Cerrar
                                    </button>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-primary btn-sm px-3 shadow-sm">
                                    <i class="bi bi-check-lg me-1"></i><?= $recipeEdicion ? 'Actualizar Récipe' : 'Guardar y Emitir Récipe' ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-12">
                    <!-- Barra de Búsqueda y Filtros -->
                    <div class="card bg-white border shadow-sm p-3 mb-3">
                        <form method="GET" action="index.php" class="row g-2 align-items-center">
                            <input type="hidden" name="tab" value="recipes">
                            <?php if ($filtroPacienteId): ?>
                                <input type="hidden" name="paciente_id" value="<?= (int)$filtroPacienteId ?>">
                            <?php endif; ?>
                            <div class="col-md-4">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-white text-muted"><i class="bi bi-search"></i></span>
                                    <input type="search" name="q_recipe" class="form-control"
                                        placeholder="Buscar por cédula, paciente, fármaco o médico..."
                                        value="<?= htmlspecialchars($busquedaRecipe) ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <select name="filtro_estado" class="form-select form-select-sm">
                                    <option value="todos" <?= $filtroEstadoRecipe === 'todos' ? 'selected' : '' ?>>Todos los estados</option>
                                    <option value="vigentes" <?= $filtroEstadoRecipe === 'vigentes' ? 'selected' : '' ?>>Solo Récipes Vigentes</option>
                                    <option value="vencidos" <?= $filtroEstadoRecipe === 'vencidos' ? 'selected' : '' ?>>Vencidos o Inactivos</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select name="filtro_patologia" class="form-select form-select-sm">
                                    <option value="todas">Todas las patologías CIE-10</option>
                                    <?php foreach ($catalogoPatologias as $pat): ?>
                                        <option value="<?= (int) $pat['id'] ?>" <?= ((string)$filtroPatologiaRecipe === (string)$pat['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($pat['codigo_cie10'] ?? 'S/C') ?> - <?= htmlspecialchars($pat['nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex gap-1">
                                <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                                    <i class="bi bi-funnel"></i> Filtrar
                                </button>
                                <?php if ($busquedaRecipe !== '' || $filtroEstadoRecipe !== 'todos' || $filtroPatologiaRecipe !== 'todas' || $filtroPacienteId): ?>
                                    <a href="index.php?tab=recipes" class="btn btn-outline-secondary btn-sm" title="Limpiar Filtros">
                                        <i class="bi bi-x-circle"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>

                    <!-- Tabla Institucional de Récipes Médicos -->
                    <div class="card-sicm shadow-sm">
                        <div class="card-header-sicm d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <span class="fw-bold">
                                <i class="bi bi-file-earmark-medical me-1"></i> Récipes Médicos y Tratamientos Autorizados
                            </span>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace" style="font-size: 0.74rem;">
                                    <?= number_format($totalRecipesFiltrados) ?> récipes registrados
                                </span>
                                <button type="button" class="btn btn-primary btn-sm px-3 d-inline-flex align-items-center gap-1.5 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalRecipe">
                                    <i class="bi bi-plus-circle me-1"></i>Emitir Nuevo Récipe
                                </button>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 sicm-table" style="font-size: 0.82rem;">
                                <thead class="table-light text-secondary text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.03em;">
                                    <tr>
                                        <th style="width: 50px;">#ID</th>
                                        <th style="width: 170px;">Paciente</th>
                                        <th style="width: 180px;">Patología CIE-10</th>
                                        <th>Medicamento y Dosis</th>
                                        <th style="width: 150px;">Vigencia</th>
                                        <th style="width: 180px;">Médico Prescriptor</th>
                                        <th style="width: 90px;" class="text-center">Estado</th>
                                        <th style="width: 100px;" class="text-end">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recipes)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-4 text-muted">
                                                <i class="bi bi-inbox fs-3 d-block text-secondary mb-1"></i>
                                                <span>No se encontraron récipes médicos que coincidan con los criterios de búsqueda.</span>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recipes as $r): ?>
                                            <?php
                                                $vencido = (int) $r['esta_vencido'] === 1;
                                                $activo = (int) $r['activo'] === 1;
                                                $emisionFmt = !empty($r['fecha_emision_recipe']) ? date('d/m/Y', strtotime($r['fecha_emision_recipe'])) : '—';
                                                $vencimientoFmt = !empty($r['fecha_vencimiento_recipe']) ? date('d/m/Y', strtotime($r['fecha_vencimiento_recipe'])) : '—';
                                            ?>
                                            <tr class="<?= !$activo ? 'opacity-75 bg-light' : '' ?>">
                                                <td class="text-muted font-monospace"><?= (int) $r['id'] ?></td>
                                                <td>
                                                    <div class="fw-bold text-dark"><?= htmlspecialchars($r['paciente_nombre']) ?></div>
                                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace py-0.5 px-1.5" style="font-size: 0.72rem;">
                                                        <i class="bi bi-person-vcard me-1"></i><?= htmlspecialchars($r['tipo_doc_codigo'] ?? 'V') ?>-<?= htmlspecialchars($r['paciente_cedula']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary-subtle text-dark border border-secondary-subtle font-monospace py-0.5 px-1.5 mb-1 d-inline-block" style="font-size: 0.70rem;">
                                                        <?= htmlspecialchars($r['patologia_cie10'] ?? 'S/C') ?>
                                                    </span>
                                                    <div class="text-secondary small fw-medium" style="font-size: 0.76rem; line-height: 1.2;">
                                                        <?= htmlspecialchars($r['patologia_nombre']) ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="fw-bold text-dark">
                                                        <?= htmlspecialchars($r['medicamento_nombre']) ?>
                                                        <span class="text-muted fw-normal" style="font-size: 0.76rem;">(<?= htmlspecialchars($r['medicamento_concentracion']) ?>)</span>
                                                    </div>
                                                    <div class="d-flex align-items-center gap-1.5 mt-0.5">
                                                        <span class="badge bg-light text-secondary border py-0.5 px-1.5" style="font-size: 0.70rem;">
                                                            <?= htmlspecialchars($r['forma_nombre'] ?? 'Forma general') ?>
                                                        </span>
                                                        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle font-monospace py-0.5 px-1.5" style="font-size: 0.72rem;">
                                                            <i class="bi bi-capsule me-1"></i><?= (int) $r['dosis_mensual'] ?> und/mes
                                                        </span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-column gap-0.5">
                                                        <div style="font-size: 0.74rem;">
                                                            <span class="text-muted">Emi:</span> <span class="font-monospace fw-semibold text-dark"><?= $emisionFmt ?></span>
                                                        </div>
                                                        <div style="font-size: 0.74rem;">
                                                            <span class="text-muted">Ven:</span> <span class="font-monospace fw-semibold <?= $vencido ? 'text-danger' : 'text-dark' ?>"><?= $vencimientoFmt ?></span>
                                                        </div>
                                                        <div>
                                                            <?php if ($vencido): ?>
                                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle py-0.5 px-1" style="font-size: 0.67rem;">
                                                                    <i class="bi bi-clock-history me-0.5"></i>Vencido
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="badge bg-success-subtle text-success border border-success-subtle py-0.5 px-1" style="font-size: 0.67rem;">
                                                                    <i class="bi bi-check2-circle me-0.5"></i>Vigente
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="small fw-semibold text-dark" style="font-size: 0.78rem;">
                                                        <i class="bi bi-person-badge me-1 text-primary"></i><?= htmlspecialchars($r['medico_prescriptor'] ?? 'No especificado') ?>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($activo): ?>
                                                        <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.70rem;">
                                                            Activo
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle" style="font-size: 0.70rem;">
                                                            Inactivo
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <div class="d-inline-flex align-items-center gap-1">
                                                        <a href="index.php?tab=recipes&editar_id=<?= (int) $r['id'] ?>"
                                                            class="btn btn-outline-secondary btn-sm py-0.5 px-1.5"
                                                            title="Editar Récipe" style="font-size: 0.75rem;">
                                                            <i class="bi bi-pencil-square"></i>
                                                        </a>
                                                        <form action="procesar.php" method="POST" class="d-inline"
                                                            onsubmit="return confirm('¿Confirma que desea <?= $activo ? 'desactivar' : 'reactivar' ?> el récipe #<?= (int) $r['id'] ?>?');">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="accion" value="toggle_estado_recipe">
                                                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                                            <button type="submit"
                                                                class="btn btn-sm py-0.5 px-1.5 <?= $activo ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                                                style="font-size: 0.75rem;"
                                                                title="<?= $activo ? 'Desactivar récipe' : 'Reactivar récipe' ?>">
                                                                <i class="bi <?= $activo ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Paginador Institucional -->
                        <?php if ($totalPaginas > 1): ?>
                            <div class="card-footer bg-light py-2 px-3 border-top d-flex justify-content-between align-items-center">
                                <span class="small text-muted" style="font-size: 0.75rem;">
                                    Página <?= $paginaActual ?> de <?= $totalPaginas ?> (<?= number_format($totalRecipesFiltrados) ?> registros)
                                </span>
                                <nav>
                                    <ul class="pagination pagination-sm mb-0">
                                        <?php
                                            $qs = $_GET;
                                            unset($qs['p_recipe']);
                                            $baseQs = http_build_query($qs);
                                            $urlPag = 'index.php?' . ($baseQs ? $baseQs . '&' : '') . 'p_recipe=';
                                        ?>
                                        <li class="page-item <?= ($paginaActual <= 1) ? 'disabled' : '' ?>">
                                            <a class="page-link" href="<?= $urlPag . ($paginaActual - 1) ?>">«</a>
                                        </li>
                                        <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                                            <li class="page-item <?= ($paginaActual === $i) ? 'active' : '' ?>">
                                                <a class="page-link" href="<?= $urlPag . $i ?>"><?= $i ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        <li class="page-item <?= ($paginaActual >= $totalPaginas) ? 'disabled' : '' ?>">
                                            <a class="page-link" href="<?= $urlPag . ($paginaActual + 1) ?>">»</a>
                                        </li>
                                    </ul>
                                </nav>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- ==================================================================== -->
        <!-- SPRINT 3: DISPENSACIÓN Y CONTROL DE INVENTARIO FEFO                 -->
        <!-- ==================================================================== -->
        <?php if ($tabActiva === 'despachos'): ?>
            <div class="row justify-content-center my-4">
                <div class="col-xl-8 col-lg-10">
                    <div class="card bg-white border rounded-3 p-4 p-md-5 text-center shadow-sm">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-success bg-opacity-10 text-success p-3 mb-3 mx-auto" style="width: 64px; height: 64px;">
                            <i class="bi bi-box-seam fs-2 text-success"></i>
                        </div>
                        <h4 class="fw-bold text-dark mb-2">Módulo de Despacho Asistencial FEFO</h4>
                        <p class="text-muted small mb-4" style="max-width: 580px; margin: 0 auto; line-height: 1.6;">
                            Dispensación física de lotes mediante el procedimiento almacenado transaccional <code>sp_procesar_despacho_fefo</code> con bloqueo estricto contra doble retiro mensual por cédula.
                        </p>
                        <div class="alert alert-success border text-start d-flex align-items-center gap-2 mb-4 py-2 px-3 small mx-auto" style="max-width: 520px;">
                            <i class="bi bi-check-circle-fill text-success fs-5 flex-shrink-0"></i>
                            <div>
                                Lógica del procedimiento almacenado y triggers de auditoría 100% operativos en el motor de base de datos.
                            </div>
                        </div>
                        <div>
                            <a href="index.php?tab=medicamentos" class="btn btn-outline-success btn-sm px-3 fw-semibold">
                                <i class="bi bi-capsule me-1"></i> Ver Catálogo de Medicamentos
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php endif; ?>

        <!-- ==================================================================== -->
        <!-- MÓDULO O SECCIÓN NO RECONOCIDA (MANEJO DEFENSIVO INTEGRADO)          -->
        <!-- ==================================================================== -->
        <?php if ($tabDesconocida): ?>
            <div class="row justify-content-center my-4">
                <div class="col-xl-7 col-lg-9 text-center">
                    <div class="card bg-white border rounded-3 p-4 p-md-5 shadow-sm">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-warning bg-opacity-10 text-warning p-3 mb-3 mx-auto" style="width: 68px; height: 68px;">
                            <i class="bi bi-shield-exclamation fs-2 text-warning"></i>
                        </div>
                        <h4 class="fw-bold text-dark mb-2">Módulo No Localizado</h4>
                        <div class="alert alert-warning border text-start d-flex align-items-center gap-2 mb-4 py-2.5 px-3 small">
                            <i class="bi bi-exclamation-triangle-fill fs-5 text-warning flex-shrink-0"></i>
                            <div>
                                La sección o módulo solicitado ('<strong><?= htmlspecialchars($tabActiva) ?></strong>') no está disponible o no existe.
                            </div>
                        </div>
                        <p class="text-muted small mb-4" style="line-height: 1.6;">
                            El enlace seleccionado no corresponde a ninguno de los subsistemas o catálogos operativos del sistema de salud. Puede reanudar sus operaciones habituales regresando a la red de ambulatorios.
                        </p>
                        <div class="d-flex justify-content-center gap-2">
                            <a href="index.php?tab=centros" class="btn btn-primary btn-sm px-4 fw-semibold d-inline-flex align-items-center gap-2">
                                <i class="bi bi-hospital"></i> Ir a Ambulatorios
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        </main>
    </div>
</div>

<!-- ==================================================================== -->
<!-- MODALES DEL SISTEMA                                                  -->
<!-- ==================================================================== -->

<!-- Modal: Detalle Técnico del Evento (Auditoría Forense) -->
<div class="modal fade" id="modalJson" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white py-2.5 px-3">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-shield-check text-info fs-5"></i>
                    <h6 class="modal-title font-monospace m-0">
                        Detalle del Evento #<span id="mj-id" class="text-info"></span>
                    </h6>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span id="mj-badge-accion" class="badge bg-primary"></span>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Cerrar"></button>
                </div>
            </div>
            <div class="modal-body p-3">
                <!-- Cuadrícula Técnica de Información -->
                <div class="card bg-light border mb-3">
                    <div class="card-body p-3">
                        <div class="row g-2 small">
                            <div class="col-sm-6">
                                <span class="text-muted fw-semibold">Fecha y Hora:</span>
                                <div id="mj-fecha" class="font-monospace fw-bold text-dark"></div>
                            </div>
                            <div class="col-sm-6">
                                <span class="text-muted fw-semibold">Operador Responsable:</span>
                                <div id="mj-actor-block" class="fw-bold text-dark"></div>
                            </div>
                            <div class="col-sm-6">
                                <span class="text-muted fw-semibold">Origen de Red:</span>
                                <div>
                                    <code id="mj-ip" class="font-monospace fw-bold"></code>
                                    <span class="text-muted ms-1" id="mj-ua-summary"></span>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <span class="text-muted fw-semibold">Elemento Afectado:</span>
                                <div>
                                    <span id="mj-canal" class="badge bg-secondary font-monospace"></span>
                                    <span class="text-muted ms-1"><span id="mj-entidad-txt"
                                            class="fw-semibold text-dark"></span><code id="mj-tabla"></code> <span
                                            id="mj-registro" class="text-muted"></span></span>
                                </div>
                            </div>
                            <div class="col-12 mt-2 pt-2 border-top">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-muted fw-semibold" style="font-size: 0.72rem;">Navegador /
                                        Cliente Completo:</span>
                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2"
                                        id="btn-copy-ua" style="font-size: 0.68rem;" title="Copiar User-Agent">
                                        Copiar
                                    </button>
                                </div>
                                <div id="mj-ua"
                                    class="p-2 bg-white border rounded font-monospace small text-break user-select-all"
                                    style="font-size: 0.74rem; max-height: 70px; overflow-y: auto; color: #1e293b; line-height: 1.4;"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Descripción del Evento -->
                <div id="mj-evento-desc" class="alert alert-info py-2 px-3 small mb-3 d-none"></div>

                <!-- Tabla de Diferencias (Visual Diff) -->
                <div id="mj-diff-wrap" class="d-none mb-3">
                    <div class="fw-semibold small text-dark mb-1 d-flex align-items-center gap-1">
                        <i class="bi bi-arrow-left-right text-primary"></i>
                        <span>Cambios Registrados:</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 25%;">Campo</th>
                                    <th style="width: 37.5%;" class="text-danger bg-danger-subtle">Valor Anterior
                                    </th>
                                    <th style="width: 37.5%;" class="text-success bg-success-subtle">Valor Posterior
                                    </th>
                                </tr>
                            </thead>
                            <tbody id="mj-diff-tbody"></tbody>
                        </table>
                    </div>
                </div>

                <!-- Carga Transaccional Desglosada en Pares Clave-Valor -->
                <div id="mj-datos-wrap" class="d-none mb-3">
                    <div class="fw-semibold small text-dark mb-1 d-flex align-items-center gap-1">
                        <i class="bi bi-card-checklist text-primary"></i>
                        <span>Detalle de los Datos:</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm mb-0 small">
                            <tbody id="mj-datos-tbody"></tbody>
                        </table>
                    </div>
                </div>

                <!-- Acordeón para Inspeccionar Payload JSON Crudo -->
                <details class="mt-2">
                    <summary class="text-muted small cursor-pointer user-select-none" style="cursor: pointer;">
                        <i class="bi bi-chevron-right me-1"></i> Ver Datos Técnicos Adicionales (JSON)
                    </summary>
                    <div class="position-relative mt-2">
                        <button type="button"
                            class="btn btn-outline-light btn-sm position-absolute top-0 end-0 m-2 py-0 px-2"
                            id="btn-copy-json" style="font-size: 0.68rem; z-index: 5;">
                            Copiar JSON
                        </button>
                        <pre id="mj-contenido"
                            class="json-viewer p-2.5 bg-dark text-light rounded small font-monospace m-0"
                            style="max-height: 220px; overflow-y: auto;"></pre>
                    </div>
                </details>
            </div>
            <div class="modal-footer py-2 px-3 bg-light d-flex justify-content-between align-items-center">
                <span class="text-muted small" style="font-size: 0.75rem;">
                    <i class="bi bi-shield-check text-success me-1"></i> Registro inmutable de auditoría
                </span>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

    <script src="public/js/validaciones.js"></script>
    <script src="public/js/auditoria_stream.js?v=<?= filemtime(__DIR__ . '/public/js/auditoria_stream.js') ?>"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Modal de Detalle Técnico del Evento
            const modalJ = document.getElementById('modalJson');
            if (modalJ) {
                const bsJ = new bootstrap.Modal(modalJ);

                window.vincularBotonVerJson = function (btn) {
                    btn.addEventListener('click', () => {
                        const id = btn.getAttribute('data-id') || '';
                        const accion = btn.getAttribute('data-accion') || '';
                        const tabla = btn.getAttribute('data-tabla') || '';
                        const registro = btn.getAttribute('data-registro') || '';
                        const operador = btn.getAttribute('data-operador') || '';
                        const rol = btn.getAttribute('data-rol') || '';
                        const email = btn.getAttribute('data-email') || '';
                        const usuarioId = btn.getAttribute('data-usuario-id') || '';
                        const fecha = btn.getAttribute('data-fecha') || '';
                        const ip = btn.getAttribute('data-ip') || '';
                        const ua = btn.getAttribute('data-ua') || '';
                        const uaResumen = btn.getAttribute('data-ua-resumen') || '';
                        const entidadTitulo = btn.getAttribute('data-entidad-titulo') || '';
                        const entidadSub = btn.getAttribute('data-entidad-subtitulo') || '';
                        const raw = btn.getAttribute('data-json') || '{}';

                        document.getElementById('mj-id').textContent = id;
                        document.getElementById('mj-fecha').textContent = fecha ? `${fecha} (UTC)` : '';
                        document.getElementById('mj-tabla').textContent = tabla;
                        document.getElementById('mj-registro').textContent = registro ? `(ID #${registro})` : '';
                        document.getElementById('mj-ip').textContent = ip;
                        document.getElementById('mj-ua-summary').textContent = uaResumen ? `• ${uaResumen}` : '';

                        const entTxt = document.getElementById('mj-entidad-txt');
                        if (entTxt) {
                            entTxt.textContent = entidadTitulo ? `${entidadTitulo} • ` : '';
                        }

                        // Renderizado de Responsable
                        const actorBlock = document.getElementById('mj-actor-block');
                        if (!usuarioId) {
                            if (ip === 'CONSOLA_DIRECTA') {
                                actorBlock.innerHTML = `<span class="badge bg-dark"><i class="bi bi-terminal-fill me-1"></i> Consola DB</span> <span class="text-danger small fw-semibold">Acceso directo</span>`;
                            } else {
                                actorBlock.innerHTML = `<span class="badge bg-secondary">No autenticado</span> <span class="text-muted small">Intento fallido</span>`;
                            }
                        } else {
                            actorBlock.innerHTML = `<span class="fw-bold">${operador}</span> <span class="text-muted fw-normal">${email ? '(' + email + ')' : ''} &bull; ${rol}</span>`;
                        }

                        // Canal / Conexión
                        const canalEl = document.getElementById('mj-canal');
                        if (ip === 'CONSOLA_DIRECTA') {
                            canalEl.className = 'badge bg-danger text-white font-monospace';
                            canalEl.innerHTML = '<i class="bi bi-terminal-fill me-1"></i> CLI / Consola';
                        } else {
                            canalEl.className = 'badge bg-dark text-white font-monospace';
                            canalEl.innerHTML = '<i class="bi bi-globe me-1"></i> Web / HTTP';
                        }

                        // User-Agent estructurado
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

                        // Badge de Acción
                        const badge = document.getElementById('mj-badge-accion');
                        badge.textContent = accion;
                        if (
                            ['INTENTO_LOGIN_FALLIDO', 'INTENTO_DOBLE_RETIRO', 'VIOLACION_REGLA_TRIGGER', 'ERROR_GENERAL_BD', 'ACCESO_DENEGADO'].includes(accion)
                            || ip === 'CONSOLA_DIRECTA' || accion.includes('FALLID') || accion.includes('TRIGGER')
                        ) {
                            badge.className = 'badge bg-danger text-white';
                        } else if (accion.includes('DESACTIVAR') || accion.includes('LOTE_AGOTADO') || accion.includes('UMBRAL_MINIMO') || accion.includes('REACTIVAR')) {
                            badge.className = 'badge bg-warning text-dark';
                        } else {
                            badge.className = 'badge bg-success text-white';
                        }

                        let parsed = {};
                        try { parsed = JSON.parse(raw); } catch (e) { parsed = { raw: raw }; }

                        // Visor JSON Crudo
                        const rawPre = document.getElementById('mj-contenido');
                        rawPre.textContent = JSON.stringify(parsed, null, 2);
                        const copyJsonBtn = document.getElementById('btn-copy-json');
                        if (copyJsonBtn) {
                            copyJsonBtn.onclick = () => {
                                navigator.clipboard.writeText(rawPre.textContent);
                                const orig = copyJsonBtn.textContent;
                                copyJsonBtn.textContent = '¡Copiado!';
                                setTimeout(() => { copyJsonBtn.textContent = orig; }, 1500);
                            };
                        }

                        // Descripción del Evento
                        const descEl = document.getElementById('mj-evento-desc');
                        if (parsed.evento) {
                            descEl.textContent = parsed.evento;
                            descEl.classList.remove('d-none');
                        } else {
                            descEl.classList.add('d-none');
                        }

                        // Diff Table o Pares Clave-Valor
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
                                const ant = typeof val.antes === 'object' ? JSON.stringify(val.antes) : (val.antes ?? '(Vacío)');
                                const des = typeof val.despues === 'object' ? JSON.stringify(val.despues) : (val.despues ?? '(Vacío)');
                                tr.innerHTML = `
                                    <td class="fw-semibold text-secondary"><code>${campo}</code></td>
                                    <td class="bg-danger-subtle font-monospace text-danger"><del>${ant}</del></td>
                                    <td class="bg-success-subtle font-monospace text-success"><ins class="text-decoration-none fw-bold">${des}</ins></td>
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
                                        <th style="width: 32%;" class="table-light"><code>${k}</code></th>
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
                };

                document.querySelectorAll('.btn-ver-json').forEach(btn => {
                    window.vincularBotonVerJson(btn);
                });
            }

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




    <!-- JavaScript para Vías de Contacto Dinámicas y Validación de Paciente -->
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const contenedor = document.getElementById('contenedor-contactos-paciente');
        const btnAgregar = document.getElementById('btn-agregar-via-contacto');

        if (btnAgregar && contenedor) {
            btnAgregar.addEventListener('click', () => {
                const fila = document.createElement('div');
                fila.className = 'row g-1 align-items-center fila-contacto mb-1';
                
                // Generar opciones de tipos de contacto desde el catálogo PHP
                let optionsHtml = '';
                <?php if (!empty($catalogoTiposContacto)): ?>
                    <?php foreach ($catalogoTiposContacto as $tc): ?>
                        optionsHtml += '<option value="<?= (int) $tc['id'] ?>"><?= htmlspecialchars($tc['nombre'], ENT_QUOTES) ?></option>';
                    <?php endforeach; ?>
                <?php endif; ?>

                fila.innerHTML = `
                    <div class="col-4">
                        <select name="contactos_tipo[]" class="form-select form-select-sm" required style="font-size: 0.78rem;">
                            ${optionsHtml}
                        </select>
                    </div>
                    <div class="col-4">
                        <input type="text" name="contactos_valor[]" class="form-control form-control-sm font-monospace" placeholder="Ej: 0412-1234567 o correo" required maxlength="100" style="font-size: 0.8rem;">
                    </div>
                    <div class="col">
                        <input type="text" name="contactos_detalle[]" class="form-control form-control-sm" placeholder="Ej: Personal, Familiar" maxlength="100" style="font-size: 0.78rem;">
                    </div>
                    <div class="col-auto text-end" style="width: 32px;">
                        <button type="button" class="btn btn-sm btn-outline-danger btn-quitar-fila p-0 d-flex align-items-center justify-content-center" title="Quitar vía" style="width: 28px; height: 28px;">
                            <i class="bi bi-x-lg" style="font-size: 0.72rem;"></i>
                        </button>
                    </div>
                `;

                contenedor.appendChild(fila);
                fila.querySelector('input[name="contactos_valor[]"]').focus();
            });

            // Delegación de eventos para eliminar fila
            contenedor.addEventListener('click', (e) => {
                const btnQuitar = e.target.closest('.btn-quitar-fila');
                if (btnQuitar) {
                    const filas = contenedor.querySelectorAll('.fila-contacto');
                    if (filas.length > 1) {
                        btnQuitar.closest('.fila-contacto').remove();
                    } else {
                        // Limpiar campos si es la única fila
                        const fila = filas[0];
                        fila.querySelectorAll('input').forEach(inp => inp.value = '');
                    }
                }
            });
        }
    });
    </script>


    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const btnToggleSidebar = document.getElementById('btn-toggle-sidebar');
            const iconToggleSidebar = document.getElementById('icon-toggle-sidebar');
            const layoutEl = document.getElementById('sicm-layout');
            const sidebarEl = document.getElementById('sidebarMenu');

            function actualizarIconoSidebar(colapsado) {
                if (!iconToggleSidebar) return;
                if (colapsado) {
                    iconToggleSidebar.classList.remove('bi-list');
                    iconToggleSidebar.classList.add('bi-layout-sidebar-inset');
                } else {
                    iconToggleSidebar.classList.remove('bi-layout-sidebar-inset');
                    iconToggleSidebar.classList.add('bi-list');
                }
            }

            // Sincronizar estado inicial
            try {
                if (localStorage.getItem('sicm_sidebar_collapsed') === '1' && window.innerWidth >= 992) {
                    if (layoutEl) layoutEl.classList.add('sidebar-collapsed');
                    actualizarIconoSidebar(true);
                }
            } catch (e) {}

            if (btnToggleSidebar) {
                btnToggleSidebar.addEventListener('click', () => {
                    if (window.innerWidth >= 992) {
                        if (!layoutEl) return;
                        const colapsado = layoutEl.classList.toggle('sidebar-collapsed');
                        try {
                            localStorage.setItem('sicm_sidebar_collapsed', colapsado ? '1' : '0');
                        } catch (e) {}
                        actualizarIconoSidebar(colapsado);
                    } else {
                        if (!sidebarEl) return;
                        const bsOffcanvas = bootstrap.Offcanvas.getOrCreateInstance(sidebarEl);
                        bsOffcanvas.toggle();
                    }
                });
            }
        });
    </script>

    <!-- Auto-apertura de Modales en Modo Edición -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            <?php if ($tabActiva === 'centros' && !empty($centroEdicion)): ?>
            const mCentro = document.getElementById('modalCentro');
            if (mCentro) new bootstrap.Modal(mCentro).show();
            <?php endif; ?>

            <?php if ($tabActiva === 'medicamentos' && !empty($medicamentoEdicion)): ?>
            const mMed = document.getElementById('modalMedicamento');
            if (mMed) new bootstrap.Modal(mMed).show();
            <?php endif; ?>

            <?php if ($tabActiva === 'pacientes' && !empty($pacienteEdicion)): ?>
            const mPac = document.getElementById('modalPaciente');
            if (mPac) new bootstrap.Modal(mPac).show();
            <?php endif; ?>

            <?php if ($tabActiva === 'recipes' && !empty($recipeEdicion)): ?>
            const mRec = document.getElementById('modalRecipe');
            if (mRec) new bootstrap.Modal(mRec).show();
            <?php endif; ?>
        });
    </script>

</body>
</html>
