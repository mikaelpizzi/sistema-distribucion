<?php
// src/stream_logs.php
// Endpoint ligero de telemetría y transmisión en vivo para la Consola Forense (SICM)
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/auth.php';
require_once __DIR__ . '/helpers/auditoria.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

try {
    $pdo = getDB();
    $usuario = requerirAutenticacion($pdo);

    // Solo personal con rol administrador central puede acceder a la telemetría forense
    if ((int)$usuario['rol_id'] !== 1) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Acceso denegado: Privilegios periciales insuficientes.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $desdeId = max(0, (int)($_GET['desde_id'] ?? 0));
    $subtab  = ($_GET['subtab'] ?? 'eventos') === 'deltas' ? 'deltas' : 'eventos';
    $soloConteo = isset($_GET['solo_conteo']) && $_GET['solo_conteo'] === '1';

    // Modo ultraligero: sondeo pasivo para consultar exclusivamente si hay nuevos registros
    if ($soloConteo) {
        $nuevos = 0;
        if ($desdeId > 0) {
            if ($subtab === 'eventos') {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM logs_auditoria WHERE id > :desde_id");
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM logs_cambios_campos WHERE id > :desde_id");
            }
            $stmt->bindValue(':desde_id', $desdeId, PDO::PARAM_INT);
            $stmt->execute();
            $nuevos = (int)$stmt->fetchColumn();
        }

        echo json_encode([
            'success' => true,
            'subtab'  => $subtab,
            'count'   => $nuevos
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Si desde_id es 0 (ej. primera carga o vista vacía), no volcar el historial histórico de la BD.
    // En su lugar, reportar el ID máximo actual del sistema para anclar el cursor de sondeo en vivo.
    if ($desdeId <= 0) {
        $maxActual = ($subtab === 'eventos')
            ? (int) ($pdo->query("SELECT IFNULL(MAX(id), 0) FROM logs_auditoria")->fetchColumn() ?: 0)
            : (int) ($pdo->query("SELECT IFNULL(MAX(id), 0) FROM logs_cambios_campos")->fetchColumn() ?: 0);

        echo json_encode([
            'success'   => true,
            'subtab'    => $subtab,
            'count'     => 0,
            'ult_id'    => $maxActual,
            'registros' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $registros = [];
    $maxIdRetornado = $desdeId;

    // Diccionarios para resolución de nombres de entidades en telemetría
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
        error_log('[STREAM DICCIONARIOS ERROR]: ' . $e->getMessage());
    }

    $diccionariosEntidades = [
        'centros'      => $nombresCentros,
        'recursos'     => $nombresRecursos,
        'tipos'        => $nombresTipos,
        'usuarios'     => $nombresUsuarios,
        'medicamentos' => $nombresMedicamentos,
        'pacientes'    => $nombresPacientes,
        'patologias'   => $nombresPatologias,
        'lotes'        => $nombresLotes,
    ];

    if ($subtab === 'eventos') {
        $stmt = $pdo->prepare("
            SELECT l.log_id, l.fecha_hora, l.usuario_id, l.operador, l.rol_operador, l.email_operador,
                   l.accion, l.tabla_afectada, l.registro_id, l.ip_origen, l.user_agent, l.detalles_json
            FROM vw_logs_auditoria l
            WHERE l.log_id > :desde_id
            ORDER BY l.log_id ASC
            LIMIT 25
        ");
        $stmt->bindValue(':desde_id', $desdeId, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        foreach ($rows as $r) {
            $id = (int)$r['log_id'];
            if ($id > $maxIdRetornado) {
                $maxIdRetornado = $id;
            }

            $accion = (string)$r['accion'];
            $ip     = (string)$r['ip_origen'];
            $ua     = (string)($r['user_agent'] ?? 'CLI / Local');

            // Clasificación cromática y severidad pericial
            $severidad = 'INFO';
            $badgeColor = 'bg-info text-dark';
            $esCritico  = false;

            if (
                in_array($accion, ['INTENTO_LOGIN_FALLIDO', 'INTENTO_DOBLE_RETIRO', 'VIOLACION_REGLA_TRIGGER', 'ERROR_GENERAL_BD', 'ACCESO_DENEGADO'], true)
                || $ip === 'CONSOLA_DIRECTA'
                || str_contains($accion, 'FALLID')
                || str_contains($accion, 'TRIGGER')
                || str_contains($accion, 'VIOLACION')
            ) {
                $severidad  = 'CRITICAL';
                $badgeColor = 'bg-danger text-white';
                $esCritico  = true;
            } elseif (
                str_contains($accion, 'DESACTIVAR')
                || str_contains($accion, 'LOTE_AGOTADO')
                || str_contains($accion, 'UMBRAL_MINIMO')
                || str_contains($accion, 'REACTIVAR')
            ) {
                $severidad  = 'WARNING';
                $badgeColor = 'bg-warning text-dark';
            } elseif (
                str_contains($accion, 'CREAR')
                || str_contains($accion, 'REGISTRAR')
                || str_contains($accion, 'INICIO_SESION')
                || str_contains($accion, 'DESPACHO_FEFO')
                || str_contains($accion, 'INICIALIZAR')
            ) {
                $severidad  = 'INFO';
                $badgeColor = 'bg-success text-white';
            }

            $detalles = [];
            try {
                $detalles = json_decode($r['detalles_json'] ?? '{}', true) ?: [];
            } catch (Throwable $e) {
                $detalles = [];
            }

            $esSesion = in_array($accion, ['INICIO_SESION', 'CIERRE_SESION', 'INTENTO_LOGIN_FALLIDO', 'INTENTO_LOGIN_RECHAZADO'], true);
            $entidadInfo = resolverEntidadLegible($r['tabla_afectada'] ?? '', $r['registro_id'] !== null ? (int)$r['registro_id'] : null, $detalles, $diccionariosEntidades);

            $registros[] = [
                'log_id'            => $id,
                'fecha_hora'        => $r['fecha_hora'],
                'usuario_id'        => $r['usuario_id'] !== null ? (int)$r['usuario_id'] : null,
                'operador'          => $r['operador'],
                'rol_operador'      => $r['rol_operador'],
                'email_operador'    => $r['email_operador'],
                'accion'            => $accion,
                'tabla_afectada'    => $r['tabla_afectada'],
                'registro_id'       => $r['registro_id'] !== null ? (int)$r['registro_id'] : null,
                'ip_origen'         => $ip,
                'user_agent'        => $ua,
                'ua_resumen'        => resumirUserAgent($ua),
                'detalles_json'     => $r['detalles_json'],
                'evento_desc'       => $detalles['evento'] ?? null,
                'severidad'         => $severidad,
                'badge_color'       => $badgeColor,
                'es_critico'        => $esCritico,
                'es_sesion'         => $esSesion,
                'entidad_titulo'    => $esSesion ? 'Sesión de Usuario' : $entidadInfo['titulo'],
                'entidad_subtitulo' => $esSesion ? 'Control de acceso' : $entidadInfo['subtitulo']
            ];
        }
    } else {
        // Subtab Deltas
        $stmt = $pdo->prepare("
            SELECT lcc.id, lcc.tabla_nombre, lcc.registro_id, lcc.campo_modificado,
                   lcc.valor_anterior, lcc.valor_nuevo, lcc.usuario_app_id,
                   lcc.db_user, lcc.connection_id, lcc.ip_origen, lcc.created_at,
                   u.nombre AS operador_app, u.email AS email_app, r.nombre AS rol_app
            FROM logs_cambios_campos lcc
            LEFT JOIN usuarios u ON u.id = lcc.usuario_app_id
            LEFT JOIN roles r ON r.id = u.rol_id
            WHERE lcc.id > :desde_id
            ORDER BY lcc.id ASC
            LIMIT 25
        ");
        $stmt->bindValue(':desde_id', $desdeId, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        foreach ($rows as $r) {
            $id = (int)$r['id'];
            if ($id > $maxIdRetornado) {
                $maxIdRetornado = $id;
            }

            $esConsolaDirecta = ($r['ip_origen'] === 'CONSOLA_DIRECTA' || $r['usuario_app_id'] === null);
            $entidadInfo = resolverEntidadLegible($r['tabla_nombre'] ?? '', (int)$r['registro_id'], [], $diccionariosEntidades);
            $nomDesc = $entidadInfo['nombre_descriptivo'] ?: ($entidadInfo['nombre_corto'] ?? '');
            $tooltipCompleto = $entidadInfo['entidad'] . ($nomDesc ? ': ' . $nomDesc : '') . ' [ID: #' . (int)$r['registro_id'] . ']';

            $registros[] = [
                'id'                 => $id,
                'tabla_nombre'       => $r['tabla_nombre'],
                'entidad_nombre'     => $entidadInfo['entidad'],
                'entidad_titulo'     => $entidadInfo['titulo'],
                'entidad_subtitulo'  => $entidadInfo['subtitulo'],
                'nombre_descriptivo' => $nomDesc,
                'tooltip_completo'   => $tooltipCompleto,
                'registro_id'        => (int)$r['registro_id'],
                'campo_modificado'   => $r['campo_modificado'],
                'valor_anterior'     => $r['valor_anterior'],
                'valor_nuevo'        => $r['valor_nuevo'],
                'usuario_app_id'     => $r['usuario_app_id'] !== null ? (int)$r['usuario_app_id'] : null,
                'operador_app'       => $r['operador_app'] ?? 'Sistema / Consola',
                'email_app'          => $r['email_app'] ?? null,
                'rol_app'            => $r['rol_app'] ?? 'Usuario',
                'db_user'            => $r['db_user'],
                'connection_id'      => (int)$r['connection_id'],
                'ip_origen'          => $r['ip_origen'],
                'created_at'         => $r['created_at'],
                'es_consola_directa' => $esConsolaDirecta
            ];
        }
    }

    echo json_encode([
        'success'   => true,
        'subtab'    => $subtab,
        'count'     => count($registros),
        'ult_id'    => $maxIdRetornado,
        'registros' => $registros
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Error interno de telemetría pericial: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
