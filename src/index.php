<?php
// src/index.php
// SICM | Sistema Integrado de Control de Medicamentos - Red Municipal de Salud
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/modules.php';
require_once __DIR__ . '/helpers/auth.php';
require_once __DIR__ . '/helpers/auditoria.php';
require_once __DIR__ . '/helpers/configuracion.php';

$pdo = getDB();
$usuario = requerirAutenticacion($pdo);
establecerContextoAuditoriaSQL($pdo, (int) $usuario['id']);
$esAdmin = ((int) $usuario['rol_id'] === 1);
$centroUsuarioId = $usuario['centro_salud_id'];

// Configuración Institucional Centralizada
$configInst = obtenerConfiguracionInstitucional($pdo);

$tabActiva = $_GET['tab'] ?? 'centros';
$mensajeExito = $_GET['msg'] ?? ($_SESSION['mensaje_exito'] ?? null);
$mensajeError = $_GET['error'] ?? ($_SESSION['mensaje_error'] ?? null);
unset($_SESSION['mensaje_exito'], $_SESSION['mensaje_error']);

// Protección RBAC Dinámica: Mapeo de Pestañas a Permisos Atómicos
$permisosPorTab = [
    'centros'       => 'CENTROS_VER',
    'medicamentos'  => 'MED_VER',
    'pacientes'     => 'PACIENTES_VER',
    'recipes'       => 'RECIPES_VER',
    'despachos'     => 'DESP_VER',
    'auditoria'     => 'AUDIT_VER',
    'usuarios'      => 'USR_VER',
    'maestras'      => 'MAESTRAS_GESTIONAR',
    'configuracion' => 'CONFIG_SISTEMA'
];

$puedeVerTodaLaRed = puedeSupervisarTodaLaRed();

// Control de tabs válidas y módulos activos
$tabsValidas = array_keys(array_filter(obtenerModulosDisponibles()));

// Buscar primera pestaña para la cual el usuario tenga autorización activa y el módulo esté activo
$primeraTabAutorizada = null;
foreach ($permisosPorTab as $tKey => $pReq) {
    if (esModuloActivo($tKey) && tienePermiso($pReq)) {
        $primeraTabAutorizada = $tKey;
        break;
    }
}

if ($primeraTabAutorizada === null) {
    die('No posee permisos activos para acceder a ningún módulo del sistema o todos los módulos autorizados se encuentran inactivos.');
}

$tabDesconocida = false;
if (!in_array($tabActiva, $tabsValidas, true) || !esModuloActivo($tabActiva)) {
    // Si la pestaña no es válida o el módulo está inactivo, redirigir a la primera autorizada activa
    if (isset($_GET['tab'])) {
        header("Location: index.php?tab={$primeraTabAutorizada}");
        exit;
    }
    $tabActiva = $primeraTabAutorizada;
} elseif (isset($permisosPorTab[$tabActiva]) && !tienePermiso($permisosPorTab[$tabActiva])) {
    header("Location: index.php?tab={$primeraTabAutorizada}");
    exit;
}

// ============================================================================
// CARGA CONDICIONAL DE DATOS SEGÚN LA PESTAÑA ACTIVA
// ============================================================================
if ($tabActiva === 'centros' && esModuloActivo('centros')) {
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


} elseif ($tabActiva === 'maestras' && esModuloActivo('maestras')) {
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


} elseif ($tabActiva === 'usuarios' && esModuloActivo('usuarios')) {
    if (!tienePermiso('USR_VER')) {
        header('Location: index.php');
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


} elseif ($tabActiva === 'medicamentos' && esModuloActivo('medicamentos')) {
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

} elseif ($tabActiva === 'auditoria' && esModuloActivo('auditoria')) {
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
} elseif ($tabActiva === 'configuracion' && esModuloActivo('configuracion')) {
    if (!tienePermiso('CONFIG_SISTEMA')) {
        header('Location: index.php');
        exit;
    }

} elseif ($tabActiva === 'pacientes' && esModuloActivo('pacientes')) {
    // 1. Catálogos Normalizados de Tipos de Documento y Vías de Contacto (Cero Hardcoding)
    $catalogoTiposDoc = $pdo->query("SELECT * FROM tipos_documento ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $catalogoTiposContacto = $pdo->query("SELECT * FROM tipos_contacto ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    // 2. Métricas del Padrón Municipal (KPIs Institucionales)
    $kpiTotalPacientes = (int) ($pdo->query("SELECT COUNT(*) FROM pacientes")->fetchColumn() ?: 0);
    $kpiConContactos = (int) ($pdo->query("SELECT COUNT(DISTINCT paciente_id) FROM paciente_contactos")->fetchColumn() ?: 0);
    $kpiNuevosMes = (int) ($pdo->query("SELECT COUNT(*) FROM pacientes WHERE DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')")->fetchColumn() ?: 0);

    // 3. Parámetros de Búsqueda y Filtros
    $busquedaPaciente = trim($_GET['q_paciente'] ?? '');
    $filtroTipoDoc = trim($_GET['tipo_doc'] ?? 'todos');

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

} elseif ($tabActiva === 'recipes' && esModuloActivo('recipes')) {
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
    $kpiTotalRecipes = (int) ($pdo->query("SELECT COUNT(*) FROM tratamientos_autorizados")->fetchColumn() ?: 0);
    $kpiRecipesVigentes = (int) ($pdo->query("SELECT COUNT(*) FROM tratamientos_autorizados WHERE activo = 1 AND fecha_vencimiento_recipe >= CURRENT_DATE")->fetchColumn() ?: 0);
    $kpiRecipesVencidos = (int) ($pdo->query("SELECT COUNT(*) FROM tratamientos_autorizados WHERE fecha_vencimiento_recipe < CURRENT_DATE OR activo = 0")->fetchColumn() ?: 0);

    // 3. Parámetros de Búsqueda y Filtros
    $busquedaRecipe = trim($_GET['q_recipe'] ?? '');
    $filtroEstadoRecipe = trim($_GET['filtro_estado'] ?? 'todos');
    $filtroPatologiaRecipe = trim($_GET['filtro_patologia'] ?? 'todas');
    $filtroPacienteId = !empty($_GET['paciente_id']) ? (int) $_GET['paciente_id'] : null;

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
} elseif ($tabActiva === 'despachos' && esModuloActivo('despachos')) {
    // 1. Catálogo de Centros de Salud y Control de Jurisdicción
    $catalogoCentros = $pdo->query("
        SELECT id, codigo_sicm, nombre, direccion
        FROM centros_salud
        WHERE activo = 1
        ORDER BY nombre ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if ($puedeVerTodaLaRed) {
        $centroOperativoId = !empty($_GET['centro_id']) ? (int) $_GET['centro_id'] : ($centroUsuarioId ?: ($catalogoCentros[0]['id'] ?? 1));
    } else {
        $centroOperativoId = (int) $centroUsuarioId;
    }

    $centroOperativo = null;
    foreach ($catalogoCentros as $c) {
        if ((int) $c['id'] === $centroOperativoId) {
            $centroOperativo = $c;
            break;
        }
    }
    if (!$centroOperativo && !empty($catalogoCentros)) {
        $centroOperativo = $catalogoCentros[0];
        $centroOperativoId = (int) $centroOperativo['id'];
    }

    // Comprobar soporte de cadena de frío en el ambulatorio operativo
    $stmtFrio = $pdo->prepare("
        SELECT COUNT(*)
        FROM centro_recursos cr
        JOIN recursos_operativos ro ON cr.recurso_id = ro.id
        WHERE cr.centro_salud_id = :cid
          AND ro.codigo = 'CADENA_FRIO'
          AND cr.operativo = 1
    ");
    $stmtFrio->execute([':cid' => $centroOperativoId]);
    $centroTieneCadenaFrio = ((int) $stmtFrio->fetchColumn()) > 0;

    $periodoActual = date('Y-m');

    // 2. Cuadro de Mando Superior (3 KPIs Institucionales)
    $paramsKpi = [':periodo' => $periodoActual, ':centro_id' => $centroOperativoId];

    // Despachos este Mes
    $stmtKpi1 = $pdo->prepare("
        SELECT COUNT(*)
        FROM despachos
        WHERE periodo_mes_anio = :periodo AND centro_salud_id = :centro_id
    ");
    $stmtKpi1->execute($paramsKpi);
    $kpiDespachosMes = (int) $stmtKpi1->fetchColumn();

    // Pacientes Atendidos
    $stmtKpi2 = $pdo->prepare("
        SELECT COUNT(DISTINCT t.paciente_id)
        FROM despachos d
        JOIN tratamientos_autorizados t ON d.tratamiento_id = t.id
        WHERE d.periodo_mes_anio = :periodo AND d.centro_salud_id = :centro_id
    ");
    $stmtKpi2->execute($paramsKpi);
    $kpiPacientesAtendidos = (int) $stmtKpi2->fetchColumn();

    // Unidades Entregadas
    $stmtKpi3 = $pdo->prepare("
        SELECT COALESCE(SUM(dd.cantidad_entregada), 0)
        FROM despachos d
        JOIN despacho_detalles dd ON dd.despacho_id = d.id
        WHERE d.periodo_mes_anio = :periodo AND d.centro_salud_id = :centro_id
    ");
    $stmtKpi3->execute($paramsKpi);
    $kpiUnidadesEntregadas = (int) $stmtKpi3->fetchColumn();

    // 3. Buscador Asistencial de Paciente
    $busquedaPaciente = trim($_GET['q_paciente'] ?? '');
    $pacienteEncontrado = null;
    $pacientesCoincidentes = [];
    $contactosPaciente = [];
    $recipesPaciente = [];

    if ($busquedaPaciente !== '') {
        $stmtPac = $pdo->prepare("
            SELECT p.id, p.cedula, p.nombre_completo, p.fecha_nacimiento, p.direccion_residencia,
                   td.codigo AS tipo_doc_codigo, td.nombre AS tipo_doc_nombre,
                   TIMESTAMPDIFF(YEAR, p.fecha_nacimiento, CURDATE()) AS edad
            FROM pacientes p
            LEFT JOIN tipos_documento td ON p.tipo_documento_id = td.id
            WHERE p.cedula = :q_exacta
               OR p.cedula LIKE :q_like1
               OR p.nombre_completo LIKE :q_like2
            ORDER BY (p.cedula = :q_exacta2) DESC, p.nombre_completo ASC
            LIMIT 10
        ");
        $stmtPac->execute([
            ':q_exacta' => $busquedaPaciente,
            ':q_exacta2' => $busquedaPaciente,
            ':q_like1' => '%' . $busquedaPaciente . '%',
            ':q_like2' => '%' . $busquedaPaciente . '%'
        ]);
        $pacientesCoincidentes = $stmtPac->fetchAll(PDO::FETCH_ASSOC);

        $pacienteSelId = !empty($_GET['paciente_id']) ? (int) $_GET['paciente_id'] : null;
        if ($pacienteSelId) {
            foreach ($pacientesCoincidentes as $pac) {
                if ((int) $pac['id'] === $pacienteSelId) {
                    $pacienteEncontrado = $pac;
                    break;
                }
            }
            if (!$pacienteEncontrado) {
                $stmtSingle = $pdo->prepare("
                    SELECT p.id, p.cedula, p.nombre_completo, p.fecha_nacimiento, p.direccion_residencia,
                           td.codigo AS tipo_doc_codigo, td.nombre AS tipo_doc_nombre,
                           TIMESTAMPDIFF(YEAR, p.fecha_nacimiento, CURDATE()) AS edad
                    FROM pacientes p
                    LEFT JOIN tipos_documento td ON p.tipo_documento_id = td.id
                    WHERE p.id = :id
                ");
                $stmtSingle->execute([':id' => $pacienteSelId]);
                $pacienteEncontrado = $stmtSingle->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        } elseif (count($pacientesCoincidentes) === 1) {
            $pacienteEncontrado = $pacientesCoincidentes[0];
        }

        if ($pacienteEncontrado) {
            // Vías de Contacto Normalizadas 1FN
            $stmtCont = $pdo->prepare("
                SELECT pc.id, pc.valor, pc.es_principal, pc.nombre_contacto_emergencia,
                       tc.codigo AS tipo_contacto_codigo, tc.nombre AS tipo_contacto_nombre
                FROM paciente_contactos pc
                JOIN tipos_contacto tc ON pc.tipo_contacto_id = tc.id
                WHERE pc.paciente_id = :paciente_id
                ORDER BY pc.es_principal DESC, pc.id ASC
            ");
            $stmtCont->execute([':paciente_id' => $pacienteEncontrado['id']]);
            $contactosPaciente = $stmtCont->fetchAll(PDO::FETCH_ASSOC);

            // Récipes Médicos Autorizados (activos)
            $stmtRec = $pdo->prepare("
                SELECT t.id, t.paciente_id, t.medicamento_id, t.patologia_id, t.dosis_mensual,
                       t.fecha_emision_recipe, t.fecha_vencimiento_recipe, t.medico_prescriptor,
                       t.activo,
                       (t.fecha_vencimiento_recipe < CURRENT_DATE) AS esta_vencido,
                       m.nombre_generico AS medicamento_nombre, m.concentracion AS medicamento_concentracion,
                       m.requiere_cadena_frio,
                       ff.nombre AS forma_farmaceutica,
                       pat.codigo_cie10 AS patologia_cie10, pat.nombre AS patologia_nombre
                FROM tratamientos_autorizados t
                JOIN medicamentos m ON t.medicamento_id = m.id
                LEFT JOIN formas_farmaceuticas ff ON m.forma_farmaceutica_id = ff.id
                JOIN patologias pat ON t.patologia_id = pat.id
                WHERE t.paciente_id = :paciente_id
                  AND t.activo = 1
                ORDER BY t.id DESC
            ");
            $stmtRec->execute([':paciente_id' => $pacienteEncontrado['id']]);
            $recipesCrudos = $stmtRec->fetchAll(PDO::FETCH_ASSOC);

            foreach ($recipesCrudos as $rec) {
                // Comprobar si ya retiró este mes (uk_retiro_mensual)
                $stmtRetiro = $pdo->prepare("
                    SELECT d.id, d.fecha_hora, d.periodo_mes_anio, d.observaciones,
                           cs.nombre AS centro_nombre, u.nombre AS operador_nombre,
                           dd.cantidad_entregada, l.numero_lote
                    FROM despachos d
                    JOIN centros_salud cs ON d.centro_salud_id = cs.id
                    JOIN usuarios u ON d.usuario_id = u.id
                    LEFT JOIN despacho_detalles dd ON dd.despacho_id = d.id
                    LEFT JOIN lotes_inventario l ON dd.lote_id = l.id
                    WHERE d.tratamiento_id = :tid AND d.periodo_mes_anio = :periodo
                    LIMIT 1
                ");
                $stmtRetiro->execute([
                    ':tid' => $rec['id'],
                    ':periodo' => $periodoActual
                ]);
                $despachoPrevio = $stmtRetiro->fetch(PDO::FETCH_ASSOC) ?: null;

                // Consulta de lotes FEFO disponibles en el ambulatorio: fecha_vencimiento ASC, id ASC
                $stmtLotes = $pdo->prepare("
                    SELECT l.id, l.numero_lote, l.fecha_vencimiento, l.cantidad_actual,
                           DATEDIFF(l.fecha_vencimiento, CURDATE()) AS dias_para_vencer,
                           fn_evaluar_caducidad_lote(l.fecha_vencimiento) AS estado_caducidad
                    FROM lotes_inventario l
                    WHERE l.centro_salud_id = :cid
                      AND l.medicamento_id = :mid
                      AND l.cantidad_actual > 0
                      AND l.fecha_vencimiento >= CURDATE()
                    ORDER BY l.fecha_vencimiento ASC, l.id ASC
                ");
                $stmtLotes->execute([
                    ':cid' => $centroOperativoId,
                    ':mid' => $rec['medicamento_id']
                ]);
                $lotesDisponibles = $stmtLotes->fetchAll(PDO::FETCH_ASSOC);

                $stockTotalAmbulatorio = 0;
                foreach ($lotesDisponibles as $lt) {
                    $stockTotalAmbulatorio += (int) $lt['cantidad_actual'];
                }

                $rec['despacho_previo'] = $despachoPrevio;
                $rec['ya_retiro'] = ($despachoPrevio !== null);
                $rec['lotes_fefo'] = $lotesDisponibles;
                $rec['stock_total_centro'] = $stockTotalAmbulatorio;
                $rec['lote_prioritario'] = !empty($lotesDisponibles) ? $lotesDisponibles[0] : null;

                $recipesPaciente[] = $rec;
            }
        }
    }

    // 4. Historial de Despachos Recientes en este Ambulatorio
    $stmtHist = $pdo->prepare("
        SELECT d.id, d.fecha_hora, d.periodo_mes_anio, d.observaciones,
               cs.codigo_sicm AS centro_codigo, cs.nombre AS centro_nombre,
               p.cedula AS paciente_cedula, p.nombre_completo AS paciente_nombre, td.codigo AS tipo_doc_codigo,
               m.nombre_generico AS medicamento_nombre, m.concentracion AS medicamento_concentracion,
               ff.nombre AS forma_farmaceutica,
               GROUP_CONCAT(DISTINCT l.numero_lote SEPARATOR ', ') AS numero_lote,
               MIN(l.fecha_vencimiento) AS lote_vencimiento,
               COALESCE(SUM(dd.cantidad_entregada), 0) AS cantidad_entregada,
               u.nombre AS operador_nombre
        FROM despachos d
        JOIN centros_salud cs ON d.centro_salud_id = cs.id
        JOIN tratamientos_autorizados t ON d.tratamiento_id = t.id
        JOIN pacientes p ON t.paciente_id = p.id
        LEFT JOIN tipos_documento td ON p.tipo_documento_id = td.id
        JOIN medicamentos m ON t.medicamento_id = m.id
        LEFT JOIN formas_farmaceuticas ff ON m.forma_farmaceutica_id = ff.id
        LEFT JOIN despacho_detalles dd ON dd.despacho_id = d.id
        LEFT JOIN lotes_inventario l ON dd.lote_id = l.id
        JOIN usuarios u ON d.usuario_id = u.id
        WHERE d.centro_salud_id = :cid
        GROUP BY d.id, d.fecha_hora, d.periodo_mes_anio, d.observaciones, cs.codigo_sicm, cs.nombre, p.cedula, p.nombre_completo, td.codigo, m.nombre_generico, m.concentracion, ff.nombre, u.nombre
        ORDER BY d.id DESC
        LIMIT 20
    ");
    $stmtHist->execute([':cid' => $centroOperativoId]);
    $ultimosDespachos = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
}


// Determinación del Módulo y Sección Activa para Breadcrumb Superior
$moduloNombre = 'Ambulatorios';
$seccionNombre = 'Vademécum y Red';
if ($tabDesconocida) {
    $moduloNombre = 'Módulo No Localizado';
    $seccionNombre = 'Navegación';
} elseif ($tabActiva === 'centros') {
    $moduloNombre = $puedeVerTodaLaRed ? 'Red de Ambulatorios' : 'Mi Ambulatorio';
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
    <title><?= htmlspecialchars($configInst['siglas_sistema']) ?> -
        <?= htmlspecialchars($configInst['nombre_sistema']) ?></title>
    <link rel="icon" type="image/svg+xml" href="public/img/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="public/css/app.css?v=<?= filemtime(__DIR__ . '/public/css/app.css') ?>" rel="stylesheet">
</head>

<body>

    <div class="sicm-layout" id="sicm-layout">
        <!-- BARRA LATERAL INSTITUCIONAL (SIDEBAR) -->
        <?php require __DIR__ . '/views/layout/sidebar.php'; ?>

        <!-- ÁREA DE CONTENIDO PRINCIPAL -->
        <div class="sicm-main">
            <!-- BARRA SUPERIOR MINIMALISTA Y ALERTAS FLASH -->
            <?php require __DIR__ . '/views/layout/topbar.php'; ?>

            <!-- CONTENEDOR FLUIDO DE TRABAJO -->
            <main class="container-fluid py-3 px-3 px-md-4">
                <?php
                $tabFile = __DIR__ . "/views/tabs/tab_{$tabActiva}.php";
                if (file_exists($tabFile) && esModuloActivo($tabActiva)) {
                    require $tabFile;
                } else {
                    require __DIR__ . '/views/tabs/tab_404.php';
                }
                ?>
            </main>
        </div>
    </div>

    <!-- Scripts Globales de la Plataforma -->
    <script src="public/js/validaciones.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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
            } catch (e) { }

            if (btnToggleSidebar) {
                btnToggleSidebar.addEventListener('click', () => {
                    if (window.innerWidth >= 992) {
                        if (!layoutEl) return;
                        const colapsado = layoutEl.classList.toggle('sidebar-collapsed');
                        try {
                            localStorage.setItem('sicm_sidebar_collapsed', colapsado ? '1' : '0');
                        } catch (e) { }
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
</body>

</html>
