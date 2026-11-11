<?php
declare(strict_types=1);

/**
 * Action Handler: Auditoría Forense y Exportación Pericial
 * Sistema Integrado de Control de Medicamentos (SICM)
 */

if (!defined('SICM_CONTROLADOR')) {
    http_response_code(403);
    exit('Acceso denegado.');
}

switch ($accion) {
    case 'exportar_acta_pericial':
        if (!tienePermiso('AUDIT_EXPORTAR')) {
            header('Location: index.php?error=' . urlencode('Acceso denegado: Se requieren permisos para exportar trazabilidad de auditoría.'));
            exit;
        }

        $subtab = $_GET['subtab'] ?? 'eventos';
        if (!in_array($subtab, ['eventos', 'deltas'], true)) {
            $subtab = 'eventos';
        }

        // Registrar trazabilidad forense
        registrarAuditoria($pdo, $usuarioSesionId, 'EXPORTAR_AUDITORIA_CSV', 'logs_auditoria', null, [
            'evento' => 'Exportación pericial de auditoría en formato CSV (Streaming O(1))',
            'subtab' => $subtab,
            'filtros' => $_GET,
            'ip_remota' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'CLI / Local'
        ]);

        // Limpieza exhaustiva de búfer de salida
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $fechaArchivo = date('Ymd_His');
        $filename = ($subtab === 'eventos')
            ? "acta_pericial_auditoria_eventos_{$fechaArchivo}.csv"
            : "acta_pericial_auditoria_modificaciones_{$fechaArchivo}.csv";

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        // Inserción de BOM UTF-8 para compatibilidad universal con Excel y herramientas periciales
        fwrite($output, "\xEF\xBB\xBF");

        // Parámetros compartidos de filtrado
        $filtroTabla = $_GET['tabla_filtro'] ?? ($_GET['tabla'] ?? 'todas');
        $filtroUsuario = trim((string) ($_GET['usuario_filtro'] ?? ''));
        if ($filtroUsuario === '') {
            $filtroUsuario = trim((string) ($_GET['q_operador'] ?? 'todos'));
        }
        if ($filtroUsuario === '') {
            $filtroUsuario = 'todos';
        }
        $filtroRegistroId = trim((string) ($_GET['registro_id'] ?? ''));
        $filtroFechaDesde = trim($_GET['fecha_desde'] ?? '');
        $filtroFechaHasta = trim($_GET['fecha_hasta'] ?? '');
        $busquedaLog = trim($_GET['q_log'] ?? '');
        $filtroCampo = trim($_GET['campo_filtro'] ?? 'todos');
        if ($filtroCampo === '') {
            $filtroCampo = 'todos';
        }
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

        if ($subtab === 'eventos') {
            $filtroAccion = $_GET['accion_filtro'] ?? 'todas';
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
                    if (in_array($opTexto, ['anonimo', 'anónimo', 'no autenticado', 'sin usuario', 'sin sesion', 'sin sesión'], true)) {
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

            fputcsv($output, [
                'ID',
                'Fecha y Hora',
                'ID Usuario',
                'Operador',
                'Rol Operador',
                'Email Operador',
                'Acción',
                'Tabla Afectada',
                'ID Registro',
                'IP Origen',
                'User Agent',
                'Detalles JSON'
            ]);

            $stmtExport = $pdo->prepare("
                SELECT l.log_id, l.fecha_hora, l.usuario_id, l.operador, l.rol_operador, l.email_operador,
                       l.accion, l.tabla_afectada, l.registro_id, l.ip_origen, l.user_agent, l.detalles_json
                FROM vw_logs_auditoria l
                {$whereLogs}
                ORDER BY l.log_id DESC
            ");
            $stmtExport->execute($paramsLogs);

            while ($row = $stmtExport->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, [
                    $row['log_id'],
                    $row['fecha_hora'],
                    $row['usuario_id'] ?? 'N/A',
                    $row['operador'] ?? 'Anónimo / Sistema',
                    $row['rol_operador'] ?? 'N/A',
                    $row['email_operador'] ?? 'N/A',
                    $row['accion'],
                    $row['tabla_afectada'] ?? '',
                    $row['registro_id'] ?? '',
                    $row['ip_origen'],
                    $row['user_agent'] ?? '',
                    $row['detalles_json'] ?? ''
                ]);
            }

        } else {
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
                    if (in_array($opTexto, ['anonimo', 'anónimo', 'no autenticado', 'sin usuario', 'sin sesion', 'sin sesión'], true)) {
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

            fputcsv($output, [
                'ID',
                'Fecha y Hora',
                'Tabla',
                'ID Registro',
                'Campo Modificado',
                'Valor Anterior',
                'Valor Nuevo',
                'ID Usuario App',
                'Operador App',
                'Email Operador',
                'Rol Operador',
                'Usuario BD',
                'Connection ID',
                'IP Origen'
            ]);

            $stmtExport = $pdo->prepare("
                SELECT lcc.id, lcc.tabla_nombre, lcc.registro_id, lcc.campo_modificado,
                       lcc.valor_anterior, lcc.valor_nuevo, lcc.usuario_app_id,
                       lcc.db_user, lcc.connection_id, lcc.ip_origen, lcc.created_at,
                       u.nombre AS operador_app, u.email AS email_app, r.nombre AS rol_app
                FROM logs_cambios_campos lcc
                LEFT JOIN usuarios u ON u.id = lcc.usuario_app_id
                LEFT JOIN roles r ON r.id = u.rol_id
                {$whereDeltas}
                ORDER BY lcc.id DESC
            ");
            $stmtExport->execute($paramsDeltas);

            while ($row = $stmtExport->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, [
                    $row['id'],
                    $row['created_at'],
                    $row['tabla_nombre'],
                    $row['registro_id'],
                    $row['campo_modificado'],
                    $row['valor_anterior'] ?? '',
                    $row['valor_nuevo'] ?? '',
                    $row['usuario_app_id'] ?? 'N/A',
                    $row['operador_app'] ?? 'Anónimo / Triggers / Sistema',
                    $row['email_app'] ?? 'N/A',
                    $row['rol_app'] ?? 'N/A',
                    $row['db_user'] ?? '',
                    $row['connection_id'] ?? '',
                    $row['ip_origen']
                ]);
            }
        }

        fclose($output);
        exit;

    default:
        header('Location: index.php?tab=auditoria');
        exit;
}
